<?php

namespace App\Service;

use App\Core\Database;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CompanyDeletionService
{
    public static function delete(PDO $centralPdo, array $company, array $config, int $actorUserId): array
    {
        $companyId = (int)$company['id'];
        $dbIdentifier = $company['db_identifier'] ?? '';
        $isPoolDb = !empty($company['db_username']);

        $result = [
            'success' => true,
            'errors' => [],
            'steps' => [],
            'verification' => [],
        ];

        // No destructive operation may begin without durable evidence and a
        // fresh, signed backup of this exact company and both data locations.
        try {
            $backup = CompanyDeletionBackupVerifier::verify(
                $company,
                (string)getenv('PLANEX_COMPANY_DELETION_BACKUP_ROOT'),
                (string)getenv('PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY')
            );
            if ($isPoolDb) {
                // Read-only presence check: CREATE TABLE during the later
                // central transaction would cause an implicit MySQL commit.
                $centralPdo->query('SELECT 1 FROM company_db_pool_usage LIMIT 1');
            }
            $runId = CompanyDeletionJournal::begin($centralPdo, $company, $actorUserId, $backup);
            $result['steps'][] = ['step' => 'backup_verified', 'status' => 'success'];
        } catch (\Throwable $e) {
            $result['success'] = false;
            $result['errors'][] = 'Удаление остановлено: проверенная резервная копия и журнал обязательны.';
            $result['steps'][] = ['step' => 'backup_and_journal', 'status' => 'fail', 'code' => $e->getMessage()];
            return $result;
        }
        $stage = 'backup_verified';

        $storageAbs = storage_path('companies/' . $companyId);
        $storageAbsReal = realpath($storageAbs);
        $projectRoot = realpath(base_path(''));

        if ($storageAbsReal !== false && strpos($storageAbsReal, $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'companies' . DIRECTORY_SEPARATOR) === 0) {
            $resolvedStorage = $storageAbsReal;
        } else {
            $resolvedStorage = false;
        }

        $storageExists = $resolvedStorage !== false && is_dir($resolvedStorage);
        if (is_link($storageAbs)
            || ($storageAbsReal !== false && $resolvedStorage === false)
            || (file_exists($storageAbs) && !is_dir($storageAbs))) {
            CompanyDeletionJournal::fail($centralPdo, $runId, $stage, 'STORAGE_PATH_INVALID');
            $result['success'] = false;
            $result['errors'][] = 'Удаление остановлено: путь хранилища компании некорректен.';
            return $result;
        }

        // === STEP 1: Clean pool DB or drop separate DB ===
        if ($isPoolDb && $dbIdentifier !== '') {
            try {
                $localDbCfg = self::companyDatabaseConfig($config, $company);
                $localDb = new Database($localDbCfg);
                $localPdoClean = $localDb->connection();

                // Verify pool DB is accessible before cleaning
                $localPdoClean->query('SELECT 1');

                cleanCompanyPoolDatabase($localPdoClean);

                // Verify tenant DB has zero base tables/views/triggers/routines/events
                $remaining = 0;
                $dbName = $localPdoClean->query('SELECT DATABASE()')->fetchColumn();
                if ($dbName) {
                    $tables = $localPdoClean->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
                    $remaining += count($tables);
                    $views = $localPdoClean->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(\PDO::FETCH_COLUMN);
                    $remaining += count($views);
                    $triggers = $localPdoClean->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = " . $localPdoClean->quote($dbName))->fetchAll(\PDO::FETCH_COLUMN);
                    $remaining += count($triggers);
                    $routines = $localPdoClean->query("SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = " . $localPdoClean->quote($dbName))->fetchAll(\PDO::FETCH_COLUMN);
                    $remaining += count($routines);
                    $events = $localPdoClean->query("SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = " . $localPdoClean->quote($dbName))->fetchAll(\PDO::FETCH_COLUMN);
                    $remaining += count($events);
                }
                if ($remaining > 0) {
                    throw new \RuntimeException("Pool DB cleanup incomplete: {$remaining} objects remain in database.");
                }

                $result['steps'][] = ['step' => 'pool_db_clean', 'status' => 'success', 'db' => $dbIdentifier];
            } catch (\Exception $e) {
                CompanyDeletionJournal::fail($centralPdo, $runId, $stage, 'TENANT_DELETE_FAILED');
                $msg = 'Ошибка очистки pool БД: ' . $e->getMessage();
                $result['steps'][] = ['step' => 'pool_db_clean', 'status' => 'fail', 'error' => $msg];
                $result['errors'][] = $msg;
                $result['success'] = false;
                return $result;
            }
        } elseif (!empty($dbIdentifier) && !$isPoolDb) {
            try {
                $dbConfig = $config['database'];
                $dbConfig['database'] = '';
                $sysDb = new Database($dbConfig);
                $sysPdo = $sysDb->connection();
                $sysPdo->exec("DROP DATABASE IF EXISTS `{$dbIdentifier}`");
                $result['steps'][] = ['step' => 'drop_database', 'status' => 'success', 'db' => $dbIdentifier];
            } catch (\Exception $e) {
                CompanyDeletionJournal::fail($centralPdo, $runId, $stage, 'TENANT_DELETE_FAILED');
                $msg = 'Ошибка удаления отдельной БД: ' . $e->getMessage();
                $result['steps'][] = ['step' => 'drop_database', 'status' => 'fail', 'error' => $msg];
                $result['errors'][] = $msg;
                $result['success'] = false;
                return $result;
            }
        } else {
            $result['steps'][] = ['step' => 'drop_database', 'status' => 'skipped', 'reason' => 'no separate or pool DB'];
        }
        CompanyDeletionJournal::advance($centralPdo, $runId, $stage, 'tenant_deleted');
        $stage = 'tenant_deleted';

        // === STEP 2: Delete storage/companies/{id} ===
        if ($storageExists) {
            try {
                self::removeDirectory($resolvedStorage);
                if (is_dir($resolvedStorage)) {
                    throw new \RuntimeException('Директория storage не была удалена: ' . $resolvedStorage);
                }
                $result['steps'][] = ['step' => 'delete_storage', 'status' => 'success'];
            } catch (\Exception $e) {
                CompanyDeletionJournal::fail($centralPdo, $runId, $stage, 'STORAGE_DELETE_FAILED');
                $msg = 'Ошибка удаления storage: ' . $e->getMessage();
                $result['steps'][] = ['step' => 'delete_storage', 'status' => 'fail', 'error' => $msg];
                $result['errors'][] = $msg;
                $result['success'] = false;
                return $result;
            }
        } else {
            $result['steps'][] = ['step' => 'delete_storage', 'status' => 'skipped', 'reason' => 'storage directory does not exist'];
        }
        CompanyDeletionJournal::advance($centralPdo, $runId, $stage, 'storage_deleted');
        $stage = 'storage_deleted';

        // === STEP 3: Central transaction — delete all company-owned records ===
        try {
            $centralPdo->beginTransaction();

            // Release pool DB inside the same central transaction before commit
            if ($isPoolDb && $dbIdentifier !== '') {
                $release = $centralPdo->prepare(
                    "UPDATE company_db_pool_usage
                     SET company_id=NULL, released_at=NOW(), note='superadmin_delete'
                     WHERE db_identifier=? AND company_id=? AND released_at IS NULL"
                );
                $release->execute([$dbIdentifier, $companyId]);
                if ($release->rowCount() !== 1) {
                    throw new \RuntimeException('Pool DB reservation changed during deletion.');
                }
            }

            // OWNER 2026-11-01: preserve prior deleted_entities for audit even
            // after the company row is gone. The table has no company FK.
            $delEntStmt = $centralPdo->prepare('SELECT COUNT(*) FROM deleted_entities WHERE company_id = ?');
            $delEntStmt->execute([$companyId]);
            $deletedEntitiesCount = (int)$delEntStmt->fetchColumn();

            // Delete company_features
            $centralPdo->prepare('DELETE FROM company_features WHERE company_id = ?')->execute([$companyId]);

            // Delete company_users
            $centralPdo->prepare('DELETE FROM company_users WHERE company_id = ?')->execute([$companyId]);

            // Delete the company record itself (last)
            $centralPdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$companyId]);

            // The company removal and its durable stage change commit together.
            CompanyDeletionJournal::advance($centralPdo, $runId, $stage, 'central_deleted');

            $centralPdo->commit();
            $stage = 'central_deleted';

            $result['steps'][] = [
                'step' => 'central_delete',
                'status' => 'success',
                'details' => 'companies+company_features+company_users; deleted_entities retained',
            ];
        } catch (\Exception $e) {
            if ($centralPdo->inTransaction()) {
                $centralPdo->rollBack();
            }
            CompanyDeletionJournal::fail($centralPdo, $runId, $stage, 'CENTRAL_DELETE_FAILED');
            $msg = 'Ошибка центрального удаления: ' . $e->getMessage();
            $result['steps'][] = ['step' => 'central_delete', 'status' => 'fail', 'error' => $msg];
            $result['errors'][] = $msg;
            $result['success'] = false;
            return $result;
        }

        // === STEP 4: Verification ===
        try {
        $verifyErrors = [];

        // Verify company record absent
        $checkStmt = $centralPdo->prepare('SELECT COUNT(*) FROM companies WHERE id = ?');
        $checkStmt->execute([$companyId]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            $verifyErrors[] = 'Запись компании всё ещё существует в companies';
        }

        // Verify no company_features rows
        $cfStmt = $centralPdo->prepare('SELECT COUNT(*) FROM company_features WHERE company_id = ?');
        $cfStmt->execute([$companyId]);
        if ((int)$cfStmt->fetchColumn() > 0) {
            $verifyErrors[] = 'Остались записи в company_features для этой компании';
        }

        // Verify no company_users rows
        $cuStmt = $centralPdo->prepare('SELECT COUNT(*) FROM company_users WHERE company_id = ?');
        $cuStmt->execute([$companyId]);
        if ((int)$cuStmt->fetchColumn() > 0) {
            $verifyErrors[] = 'Остались записи в company_users для этой компании';
        }

        // Verify the historical deletion archive survived unchanged.
        $deStmt = $centralPdo->prepare('SELECT COUNT(*) FROM deleted_entities WHERE company_id = ?');
        $deStmt->execute([$companyId]);
        if ((int)$deStmt->fetchColumn() !== $deletedEntitiesCount) {
            $verifyErrors[] = 'Журнал deleted_entities компании изменился при удалении';
        }

        // Verify pool DB was released: company_id=NULL, released_at set, row preserved
        if ($dbIdentifier !== '') {
            $puCheck = $centralPdo->prepare('SELECT company_id, released_at FROM company_db_pool_usage WHERE db_identifier = ?');
            $puCheck->execute([$dbIdentifier]);
            $poolRow = $puCheck->fetch(PDO::FETCH_ASSOC);
            if ($poolRow) {
                if ($poolRow['company_id'] !== null) {
                    $verifyErrors[] = 'company_id в company_db_pool_usage не NULL после освобождения';
                }
                if ($poolRow['released_at'] === null) {
                    $verifyErrors[] = 'released_at не установлен после освобождения pool БД';
                }
            } elseif ($isPoolDb) {
                $verifyErrors[] = 'Запись в company_db_pool_usage не найдена для данной pool БД';
            }
        }

        // Verify storage absent
        if (is_dir($resolvedStorage ?: $storageAbs)) {
            $verifyErrors[] = 'Директория storage всё ещё существует';
        }

        if (!empty($verifyErrors)) {
            CompanyDeletionJournal::fail($centralPdo, $runId, $stage, 'VERIFICATION_FAILED');
            $result['verification'] = $verifyErrors;
            $result['errors'] = array_merge($result['errors'], $verifyErrors);
            $result['success'] = false;
        } else {
            CompanyDeletionJournal::advance($centralPdo, $runId, $stage, 'completed');
            $result['verification'] = ['Все проверки пройдены: компания и все связанные данные удалены.'];
        }
        } catch (\Throwable $e) {
            try {
                CompanyDeletionJournal::fail($centralPdo, $runId, $stage, 'VERIFICATION_QUERY_FAILED');
            } catch (\Throwable $ignored) {
                // The prior in-progress stage remains as durable recovery evidence.
            }
            $result['success'] = false;
            $result['errors'][] = 'Не удалось завершить проверку удаления; требуется ручная сверка журнала.';
        }

        return $result;
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!rmdir($item->getPathname())) {
                    throw new \RuntimeException('Не удалось удалить директорию: ' . $item->getPathname());
                }
            } else {
                if (!unlink($item->getPathname())) {
                    throw new \RuntimeException('Не удалось удалить файл: ' . $item->getPathname());
                }
            }
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('Не удалось удалить корневую директорию: ' . $path);
        }
    }

    private static function companyDatabaseConfig(array $config, array $company): array
    {
        $cfg = $config['database'];
        $cfg['database'] = $company['db_identifier'] ?? $cfg['database'];
        if (!empty($company['db_host'])) {
            $cfg['host'] = $company['db_host'];
        }
        if (!empty($company['db_port'])) {
            $cfg['port'] = $company['db_port'];
        }
        if (!empty($company['db_username'])) {
            $cfg['username'] = $company['db_username'];
        }
        if (!empty($company['db_password'])) {
            $cfg['password'] = companyDbResolvePassword((string) $company['db_password']);
        }
        return $cfg;
    }
}
