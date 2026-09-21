<?php

namespace App\Service;

require_once __DIR__ . '/FeoLocationOverrideService.php';
require_once __DIR__ . '/FeoLegacyImportSync.php';
require_once __DIR__ . '/FeoImportTransaction.php';
require_once __DIR__ . '/FeoWeightChangeNotificationService.php';

use App\Core\Database;
use PDO;

final class FeoRequestService
{
    private const MAX_FILTER_IDS = 500;
    private const DEFAULT_BATCH_SIZE = 80;

    public function __construct(
        private readonly array $config,
        private readonly Database $centralDb
    ) {}

    public function getCompanyId(): int
    {
        return (int)(getSessionCompanyId() ?? 0);
    }

    public function loadCompany(int $companyId): ?array
    {
        if ($companyId <= 0) return null;
        $stmt = $this->centralDb->connection()->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function localPdo(array $company): PDO
    {
        $db = new Database(companyDatabaseConfig($this->config, $company));
        return $db->connection();
    }

    public function moduleReady(PDO $pdo): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('feo_requests','feo_imports','feo_import_changes')");
        $stmt->execute();
        return (int)$stmt->fetchColumn() === 3;
    }

    public function listRequestsBatch(PDO $pdo, string $idFilter, string $contentSearch, int $offset, int $limit = self::DEFAULT_BATCH_SIZE): array
    {
        $offset = max(0, $offset);
        $limit = max(20, min(100, $limit));
        $contentSearch = trim($contentSearch);
        $requestedIds = $this->parseRequestIds($idFilter);
        $hasRawIdFilter = trim($idFilter) !== '';

        if ($hasRawIdFilter && $requestedIds === []) {
            return [
                'rows' => [],
                'total' => 0,
                'offset' => $offset,
                'limit' => $limit,
                'has_more' => false,
                'requested_count' => 0,
                'found_ids' => [],
                'missing_ids' => [],
                'filter_error' => 'Не найдено корректных номеров заявок. Введите номера заявок через пробел, запятую или точку с запятой.',
            ];
        }

        $where = [];
        $params = [];
        if ($requestedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($requestedIds), '?'));
            $where[] = "zayavka_id IN ({$placeholders})";
            array_push($params, ...$requestedIds);
        }

        if ($contentSearch !== '') {
            $searchColumns = [
                'zayavka_id',
                'polnoe_naim_zakazchika', 'ur_adres_zakazchika', 'inn_zakazchika', 'nomer_dogovora',
                'gruzopoluchatel', 'inn_op_gruzopoluchatelya', 'adres_dostavki_otkhodov', 'ur_adres_op_gruzopoluchatelya',
                'inn_oo_gruzootpravitel', 'naim_oo_gruzootpravitel', 'ur_adres_oo_gruzootpravitelya',
                'mno_region', 'mno_mo', 'mno_adres_pogruzki', 'naim_otkhoda_fkko', 'vid_tary', 'nomer_opasnogo_gr',
                'adres_vygruzki_otkhodov', 'naim_ot_gruzoperevozchik', 'inn_ot', 'ur_adres_ot',
                'zakaz_s_ot_status', 'nalichie_transport_nakl', 'zakaz_s_op_status', 'id_zakaza_s_op',
                'kontakt_tel', 'tel_dopolnitelnyy', 'kontakt_email', 'mno_sh', 'mno_d',
            ];
            $needle = '%' . $contentSearch . '%';
            $parts = [];
            foreach ($searchColumns as $column) {
                $parts[] = '`' . $column . '` LIKE ?';
                $params[] = $needle;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $pdo->prepare('SELECT COUNT(*) FROM feo_requests' . $whereSql);
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id,zayavka_id,mass_netto,mno_region,mno_mo,naim_oo_gruzootpravitel,mno_adres_pogruzki '
            . 'FROM feo_requests' . $whereSql . ' ORDER BY id DESC LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $foundIds = [];
        $missingIds = [];
        if ($requestedIds !== []) {
            $placeholders = implode(',', array_fill(0, count($requestedIds), '?'));
            $found = $pdo->prepare("SELECT zayavka_id FROM feo_requests WHERE zayavka_id IN ({$placeholders})");
            $found->execute($requestedIds);
            $foundIds = array_map('strval', $found->fetchAll(PDO::FETCH_COLUMN));
            $foundLookup = array_fill_keys($foundIds, true);
            foreach ($requestedIds as $id) {
                if (!isset($foundLookup[$id])) $missingIds[] = $id;
            }
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => ($offset + count($rows)) < $total,
            'requested_count' => count($requestedIds),
            'found_ids' => $foundIds,
            'missing_ids' => $missingIds,
            'filter_error' => null,
        ];
    }

    public function parseRequestIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $parts = preg_split('/[,;\s]+/u', $raw) ?: [];
        $ids = [];
        $seen = [];
        foreach ($parts as $part) {
            $id = preg_replace('/\D+/u', '', trim((string)$part)) ?? '';
            if ($id === '' || isset($seen[$id])) continue;
            $seen[$id] = true;
            $ids[] = $id;
            if (count($ids) > self::MAX_FILTER_IDS) {
                throw new \RuntimeException('За один поиск можно проверить не более 500 номеров заявок.');
            }
        }
        return $ids;
    }

    public function freshnessState(PDO $pdo): array
    {
        $this->recoverPendingImport($pdo);
        $stmt = $pdo->query(
            "SELECT filename,created_at,TIMESTAMPDIFF(DAY,created_at,NOW()) AS age_days,"
            . "CASE WHEN created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END AS is_stale "
            . "FROM feo_imports WHERE status='applied' ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['is_problem'=>true,'has_active_import'=>false,'latest_at'=>null,'latest_filename'=>null,'age_days'=>null];
        }
        return [
            'is_problem' => (int)($row['is_stale'] ?? 0) === 1,
            'has_active_import' => true,
            'latest_at' => (string)$row['created_at'],
            'latest_filename' => (string)$row['filename'],
            'age_days' => (int)($row['age_days'] ?? 0),
        ];
    }

    public function request(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM feo_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listImports(PDO $pdo, int $limit = 40): array
    {
        $this->recoverPendingImport($pdo);
        $stmt = $pdo->query('SELECT * FROM feo_imports ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $latestImportId = isset($rows[0]['id']) ? (int)$rows[0]['id'] : null;
        $latestAppliedId = null;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'applied') {
                $latestAppliedId = (int)$row['id'];
                break;
            }
        }
        $restoreCandidateId = $this->restoreCandidateId($pdo);
        foreach ($rows as &$row) {
            $row['can_rollback'] = ($row['status'] ?? '') === 'applied' && (int)$row['id'] === $latestAppliedId;
            $row['can_restore'] = ($row['status'] ?? '') === 'rolled_back' && $restoreCandidateId !== null && (int)$row['id'] === $restoreCandidateId;
            $row['can_delete'] = $latestImportId !== null && (int)$row['id'] === $latestImportId;
        }
        unset($row);
        return $rows;
    }

    public function analyze(PDO $pdo, array $rows, string $fileHash): array
    {
        $locationOverrides = FeoLocationOverrideService::activeIds($pdo);
        $existingRows = $pdo->query('SELECT * FROM feo_requests')->fetchAll(PDO::FETCH_ASSOC);
        $existing = [];
        foreach ($existingRows as $row) {
            $existing[(string)$row['zayavka_id']] = $this->businessRow($row);
        }

        $stats = ['rows_total' => count($rows), 'unique_requests' => count($rows), 'new_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0, 'missing_count' => 0];
        $incomingIds = [];
        foreach ($rows as $row) {
            $id = (string)$row['zayavka_id'];
            if (isset($locationOverrides[$id], $existing[$id])) $row = FeoLocationOverrideService::preserve($row, $existing[$id]);
            $incomingIds[$id] = true;
            if (!isset($existing[$id])) {
                $stats['new_count']++;
            } elseif ($this->sameBusinessRow($existing[$id], $row)) {
                $stats['unchanged_count']++;
            } else {
                $stats['updated_count']++;
            }
        }
        foreach ($existing as $id => $_) {
            if (!isset($incomingIds[$id])) $stats['missing_count']++;
        }

        $dup = $pdo->prepare("SELECT COUNT(*) FROM feo_imports WHERE file_hash = ? AND status = 'applied'");
        $dup->execute([$fileHash]);
        $stats['duplicate_file'] = (int)$dup->fetchColumn() > 0;
        return $stats;
    }

    public function applyImport(PDO $pdo, array $rows, string $filename, string $fileHash, array $actor, bool $overwriteExisting = true, ?callable $weightEvent = null): array
    {
        if ($rows === []) throw new \RuntimeException('Нет заявок для импорта.');
        $lockName = $this->acquireLock($pdo, 'импорта');

        try {
            $transaction = FeoImportTransaction::begin($pdo);
            $dup = $pdo->prepare("SELECT COUNT(*) FROM feo_imports WHERE file_hash = ? AND status = 'applied'");
            $dup->execute([$fileHash]);
            if ((int)$dup->fetchColumn() > 0) {
                throw new \RuntimeException('Этот файл уже применён и не был откатан.');
            }

            $legacySync = $transaction->sync();
            $legacySync?->validate();
            $locationOverrides = FeoLocationOverrideService::activeIds($pdo);
            $userId = (int)($actor['user_id'] ?? 0) ?: null;
            $role = (string)($actor['role_code'] ?? '');
            $insertImport = $pdo->prepare('INSERT INTO feo_imports (filename,file_hash,created_by_user_id,created_by_role) VALUES (?,?,?,?)');
            $insertImport->execute([$filename, $fileHash, $userId, $role !== '' ? $role : null]);
            $importId = (int)$pdo->lastInsertId();

            $fields = FeoXlsxParser::fields();
            $insertCols = implode(',', array_map(static fn(string $f): string => '`' . $f . '`', $fields));
            $insertPlaceholders = implode(',', array_fill(0, count($fields), '?'));
            $insertRequest = $pdo->prepare("INSERT INTO feo_requests ({$insertCols},current_import_id) VALUES ({$insertPlaceholders},?)");
            $updateSql = implode(',', array_map(static fn(string $f): string => '`' . $f . '`=?', array_filter($fields, static fn(string $f): bool => $f !== 'zayavka_id')));
            $updateRequest = $pdo->prepare("UPDATE feo_requests SET {$updateSql}, current_import_id=? WHERE zayavka_id=?");
            $find = $pdo->prepare('SELECT * FROM feo_requests WHERE zayavka_id=? FOR UPDATE');
            $insertChange = $pdo->prepare('INSERT INTO feo_import_changes (import_id,zayavka_id,action,previous_import_id,before_json,after_json) VALUES (?,?,?,?,?,?)');

            $stats = ['rows_total' => count($rows), 'unique_requests' => count($rows), 'new_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0, 'missing_count' => 0];
            $incomingIds = [];
            $weightChanges = [];
            foreach ($rows as $row) {
                $id = (string)$row['zayavka_id'];
                $incomingIds[$id] = true;
                $find->execute([$id]);
                $beforeDb = $find->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($beforeDb === null) {
                    $values = array_map(static fn(string $f) => $row[$f] ?? null, $fields);
                    $insertRequest->execute([...$values, $importId]);
                    $legacyJournal = $legacySync?->apply($row, $overwriteExisting);
                    $insertChange->execute([$importId, $id, 'insert', null, null, $this->encodeRow($row, $legacyJournal)]);
                    $stats['new_count']++;
                    continue;
                }

                $before = $this->businessRow($beforeDb);
                if (!$overwriteExisting) $row = $before;
                if (isset($locationOverrides[$id])) $row = FeoLocationOverrideService::preserve($row, $before);
                if ($this->sameBusinessRow($before, $row)) {
                    $stats['unchanged_count']++;
                    continue;
                }

                $updateFields = array_values(array_filter($fields, static fn(string $f): bool => $f !== 'zayavka_id'));
                $values = array_map(static fn(string $f) => $row[$f] ?? null, $updateFields);
                $updateRequest->execute([...$values, $importId, $id]);
                $legacyJournal = $legacySync?->apply($row, $overwriteExisting,
                    isset($locationOverrides[$id]) ? ['mno_adres_pogruzki','mno_sh','mno_d'] : []);
                $insertChange->execute([
                    $importId,
                    $id,
                    'update',
                    $beforeDb['current_import_id'] !== null ? (int)$beforeDb['current_import_id'] : null,
                    $this->encodeRow($before),
                    $this->encodeRow($row, $legacyJournal),
                ]);
                $weightChange = FeoWeightChangeNotificationService::change($before, $row);
                if ($weightChange !== null) $weightChanges[] = $weightChange;
                $stats['updated_count']++;
            }

            if ($incomingIds !== []) {
                $placeholders = implode(',', array_fill(0, count($incomingIds), '?'));
                $missing = $pdo->prepare("SELECT COUNT(*) FROM feo_requests WHERE zayavka_id NOT IN ({$placeholders})");
                $missing->execute(array_keys($incomingIds));
                $stats['missing_count'] = (int)$missing->fetchColumn();
            }

            $updateImport = $pdo->prepare('UPDATE feo_imports SET rows_total=?,unique_requests=?,new_count=?,updated_count=?,unchanged_count=?,missing_count=? WHERE id=?');
            $updateImport->execute([$stats['rows_total'],$stats['unique_requests'],$stats['new_count'],$stats['updated_count'],$stats['unchanged_count'],$stats['missing_count'],$importId]);
            if ($weightChanges !== [] && $weightEvent !== null) $weightEvent($weightChanges, $importId);
            $transaction->commit();
            return ['import_id' => $importId, 'legacy_synced' => $legacySync !== null] + $stats;
        } catch (\Throwable $e) {
            ($transaction ?? null)?->rollback();
            throw $e;
        } finally {
            $this->releaseLock($pdo, $lockName);
        }
    }

    public function rollbackLatest(PDO $pdo, int $importId, array $actor, ?callable $weightEvent = null): array
    {
        if ($importId <= 0) throw new \RuntimeException('Некорректная загрузка.');
        $lockName = $this->acquireLock($pdo, 'отката');
        try {
            $transaction = FeoImportTransaction::begin($pdo);
            $latest = $pdo->query("SELECT * FROM feo_imports WHERE status='applied' ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$latest || (int)$latest['id'] !== $importId) {
                throw new \RuntimeException('Откатывать можно только последнюю активную загрузку ФЭО.');
            }
            $result = $this->rollbackAppliedImportInTransaction($pdo, $importId, $actor, $transaction, $weightEvent);
            $transaction->commit();
            return $result;
        } catch (\Throwable $e) {
            ($transaction ?? null)?->rollback();
            throw $e;
        } finally {
            $this->releaseLock($pdo, $lockName);
        }
    }

    public function restoreRolledBackImport(PDO $pdo, int $importId, array $actor, ?callable $weightEvent = null): array
    {
        if ($importId <= 0) throw new \RuntimeException('Некорректная загрузка.');
        $lockName = $this->acquireLock($pdo, 'возврата');
        try {
            $transaction = FeoImportTransaction::begin($pdo);
            $locationOverrides = FeoLocationOverrideService::activeIds($pdo);
            $candidateId = $this->restoreCandidateId($pdo, true);
            if ($candidateId === null || $candidateId !== $importId) {
                throw new \RuntimeException('Вернуть можно только следующую откатанную загрузку в строгой последовательности ФЭО.');
            }
            $import = $pdo->prepare("SELECT * FROM feo_imports WHERE id=? AND status='rolled_back' FOR UPDATE");
            $import->execute([$importId]);
            if (!$import->fetch(PDO::FETCH_ASSOC)) throw new \RuntimeException('Откатанная загрузка ФЭО не найдена.');

            $changesStmt = $pdo->prepare('SELECT * FROM feo_import_changes WHERE import_id=? ORDER BY id ASC');
            $changesStmt->execute([$importId]);
            $changes = $changesStmt->fetchAll(PDO::FETCH_ASSOC);
            $legacySync = $this->syncForChanges($transaction, $changes);
            $fields = FeoXlsxParser::fields();
            $insertCols = implode(',', array_map(static fn(string $f): string => '`' . $f . '`', $fields));
            $insertPlaceholders = implode(',', array_fill(0, count($fields), '?'));
            $insert = $pdo->prepare("INSERT INTO feo_requests ({$insertCols},current_import_id) VALUES ({$insertPlaceholders},?)");
            $updateFields = array_values(array_filter($fields, static fn(string $f): bool => $f !== 'zayavka_id'));
            $updateSql = implode(',', array_map(static fn(string $f): string => '`' . $f . '`=?', $updateFields));
            $update = $pdo->prepare("UPDATE feo_requests SET {$updateSql},current_import_id=? WHERE zayavka_id=?");
            $find = $pdo->prepare('SELECT * FROM feo_requests WHERE zayavka_id=? FOR UPDATE');

            $inserted = 0;
            $updated = 0;
            $weightChanges = [];
            foreach ($changes as $change) {
                $id = (string)$change['zayavka_id'];
                $after = json_decode((string)$change['after_json'], true);
                if (!is_array($after)) throw new \RuntimeException('Повреждён журнал возврата ФЭО.');
                if (isset($after[FeoLegacyImportSync::JOURNAL_KEY])) {
                    $legacySync->replay($after[FeoLegacyImportSync::JOURNAL_KEY], $id, true);
                }
                $find->execute([$id]);
                $current = $find->fetch(PDO::FETCH_ASSOC) ?: null;

                if (($change['action'] ?? '') === 'insert') {
                    if ($current !== null) throw new \RuntimeException("Заявка {$id} уже существует; безопасный возврат остановлен.");
                    $values = array_map(static fn(string $f) => $after[$f] ?? null, $fields);
                    $insert->execute([...$values, $importId]);
                    $inserted++;
                    continue;
                }

                if ($current === null) throw new \RuntimeException("Заявка {$id} отсутствует; безопасный возврат остановлен.");
                $before = json_decode((string)$change['before_json'], true);
                if (!is_array($before)) throw new \RuntimeException('Повреждён журнал возврата ФЭО.');
                if (isset($locationOverrides[$id])) {
                    $before = FeoLocationOverrideService::preserve($before, $current);
                    $after = FeoLocationOverrideService::preserve($after, $current);
                }
                $expectedPrevious = $change['previous_import_id'] !== null ? (int)$change['previous_import_id'] : null;
                $currentPrevious = $current['current_import_id'] !== null ? (int)$current['current_import_id'] : null;
                if ($currentPrevious !== $expectedPrevious || !$this->sameBusinessRow($current, $before)) {
                    throw new \RuntimeException("Заявка {$id} изменилась после отката; безопасный возврат остановлен.");
                }
                $values = array_map(static fn(string $f) => $after[$f] ?? null, $updateFields);
                $update->execute([...$values, $importId, $id]);
                $weightChange = FeoWeightChangeNotificationService::change($current, $after);
                if ($weightChange !== null) $weightChanges[] = $weightChange;
                $updated++;
            }

            $mark = $pdo->prepare("UPDATE feo_imports SET status='applied',rolled_back_by_user_id=NULL,rolled_back_by_role=NULL,rolled_back_at=NULL WHERE id=? AND status='rolled_back'");
            $mark->execute([$importId]);
            if ($mark->rowCount() !== 1) throw new \RuntimeException('Не удалось вернуть загрузку ФЭО.');
            if ($weightChanges !== [] && $weightEvent !== null) $weightEvent($weightChanges, $importId);
            $transaction->commit();
            return ['import_id'=>$importId,'inserted'=>$inserted,'updated'=>$updated];
        } catch (\Throwable $e) {
            ($transaction ?? null)?->rollback();
            throw $e;
        } finally {
            $this->releaseLock($pdo, $lockName);
        }
    }

    public function deleteLatestImport(PDO $pdo, int $importId, array $actor, ?callable $weightEvent = null): array
    {
        if ($importId <= 0) throw new \RuntimeException('Некорректная загрузка.');
        $lockName = $this->acquireLock($pdo, 'удаления');
        try {
            $transaction = FeoImportTransaction::begin($pdo);
            $latest = $pdo->query('SELECT * FROM feo_imports ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
            if (!$latest || (int)$latest['id'] !== $importId) {
                throw new \RuntimeException('Полностью удалять загрузки ФЭО можно только строго с последней записи истории.');
            }
            $wasApplied = ($latest['status'] ?? '') === 'applied';
            $rollback = ['deleted'=>0,'restored'=>0,'import_id'=>$importId];
            if ($wasApplied) {
                $latestApplied = $pdo->query("SELECT id FROM feo_imports WHERE status='applied' ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn();
                if ((int)$latestApplied !== $importId) throw new \RuntimeException('Активную загрузку можно удалить только после более новых активных загрузок.');
                $rollback = $this->rollbackAppliedImportInTransaction($pdo, $importId, $actor, $transaction, $weightEvent);
            }
            $currentRefs = $pdo->prepare('SELECT COUNT(*) FROM feo_requests WHERE current_import_id=?');
            $currentRefs->execute([$importId]);
            if ((int)$currentRefs->fetchColumn() !== 0) throw new \RuntimeException('Удаление остановлено: на загрузку ещё ссылаются текущие заявки ФЭО.');
            $historyRefs = $pdo->prepare('SELECT COUNT(*) FROM feo_import_changes WHERE previous_import_id=? AND import_id<>?');
            $historyRefs->execute([$importId,$importId]);
            if ((int)$historyRefs->fetchColumn() !== 0) throw new \RuntimeException('Удаление остановлено: загрузка ещё нужна для цепочки восстановления ФЭО.');
            $delete = $pdo->prepare('DELETE FROM feo_imports WHERE id=?');
            $delete->execute([$importId]);
            if ($delete->rowCount() !== 1) throw new \RuntimeException('Не удалось удалить загрузку ФЭО.');
            $transaction->commit();
            return ['import_id'=>$importId,'was_applied'=>$wasApplied,'deleted'=>(int)$rollback['deleted'],'restored'=>(int)$rollback['restored']];
        } catch (\Throwable $e) {
            ($transaction ?? null)?->rollback();
            throw $e;
        } finally {
            $this->releaseLock($pdo, $lockName);
        }
    }

    private function restoreCandidateId(PDO $pdo, bool $forUpdate = false): ?int
    {
        $sql = 'SELECT id,status FROM feo_imports ORDER BY id ASC' . ($forUpdate ? ' FOR UPDATE' : '');
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $latestAppliedId = 0;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'applied') $latestAppliedId = max($latestAppliedId, (int)$row['id']);
        }
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'rolled_back' && (int)$row['id'] > $latestAppliedId) return (int)$row['id'];
        }
        return null;
    }

    private function rollbackAppliedImportInTransaction(PDO $pdo, int $importId, array $actor, FeoImportTransaction $transaction, ?callable $weightEvent = null): array
    {
        if (!$pdo->inTransaction()) throw new \RuntimeException('Откат ФЭО должен выполняться внутри транзакции.');
        $locationOverrides = FeoLocationOverrideService::activeIds($pdo);
        $changesStmt = $pdo->prepare('SELECT * FROM feo_import_changes WHERE import_id=? ORDER BY id DESC');
        $changesStmt->execute([$importId]);
        $changes = $changesStmt->fetchAll(PDO::FETCH_ASSOC);
        $legacySync = $this->syncForChanges($transaction, $changes);
        $fields = FeoXlsxParser::fields();
        $restoreFields = array_values(array_filter($fields, static fn(string $f): bool => $f !== 'zayavka_id'));
        $restoreSql = implode(',', array_map(static fn(string $f): string => '`' . $f . '`=?', $restoreFields));
        $restoreWithImport = $pdo->prepare("UPDATE feo_requests SET {$restoreSql},current_import_id=? WHERE zayavka_id=? AND current_import_id=?");
        $restoreWithoutImport = $pdo->prepare("UPDATE feo_requests SET {$restoreSql},current_import_id=NULL WHERE zayavka_id=? AND current_import_id=?");
        $delete = $pdo->prepare('DELETE FROM feo_requests WHERE zayavka_id=? AND current_import_id=?');
        $deleted = 0; $restored = 0; $weightChanges = [];
        foreach ($changes as $change) {
            $id = (string)$change['zayavka_id'];
            $after = json_decode((string)$change['after_json'], true, 512, JSON_THROW_ON_ERROR);
            if (isset($after[FeoLegacyImportSync::JOURNAL_KEY])) {
                $legacySync->replay($after[FeoLegacyImportSync::JOURNAL_KEY], $id, false);
            }
            if (($change['action'] ?? '') === 'insert') {
                if (isset($locationOverrides[$id])) throw new \RuntimeException("Заявка {$id} перемещена. Сначала верните пакет перемещения, затем откатывайте загрузку.");
                $delete->execute([$id,$importId]);
                if ($delete->rowCount() !== 1) throw new \RuntimeException("Заявка {$id} была изменена после загрузки; безопасный откат остановлен.");
                $deleted++;
                continue;
            }
            $before = json_decode((string)$change['before_json'], true);
            if (!is_array($before)) throw new \RuntimeException('Повреждён журнал отката ФЭО.');
            if (isset($locationOverrides[$id])) {
                $location = $pdo->prepare('SELECT mno_adres_pogruzki,mno_sh,mno_d FROM feo_requests WHERE zayavka_id=? FOR UPDATE');
                $location->execute([$id]);
                $currentLocation = $location->fetch(PDO::FETCH_ASSOC);
                if (!$currentLocation) throw new \RuntimeException("Заявка {$id} отсутствует.");
                $before = FeoLocationOverrideService::preserve($before, $currentLocation);
            }
            $weightChange = FeoWeightChangeNotificationService::change($after, $before);
            if ($weightChange !== null) $weightChanges[] = $weightChange;
            $values = array_map(static fn(string $f) => $before[$f] ?? null, $restoreFields);
            if ($change['previous_import_id'] !== null) {
                $restoreWithImport->execute([...$values,(int)$change['previous_import_id'],$id,$importId]);
                $affected = $restoreWithImport->rowCount();
            } else {
                $restoreWithoutImport->execute([...$values,$id,$importId]);
                $affected = $restoreWithoutImport->rowCount();
            }
            if ($affected === 0) throw new \RuntimeException("Заявка {$id} была изменена после загрузки; безопасный откат остановлен.");
            $restored++;
        }
        $userId = (int)($actor['user_id'] ?? 0) ?: null;
        $role = (string)($actor['role_code'] ?? '');
        $mark = $pdo->prepare("UPDATE feo_imports SET status='rolled_back',rolled_back_by_user_id=?,rolled_back_by_role=?,rolled_back_at=NOW() WHERE id=? AND status='applied'");
        $mark->execute([$userId,$role !== '' ? $role : null,$importId]);
        if ($mark->rowCount() !== 1) throw new \RuntimeException('Не удалось зафиксировать откат загрузки ФЭО.');
        if ($weightChanges !== [] && $weightEvent !== null) $weightEvent($weightChanges, $importId);
        return ['deleted'=>$deleted,'restored'=>$restored,'import_id'=>$importId];
    }

    private function recoverPendingImport(PDO $pdo): void
    {
        if (!FeoImportTransaction::pending($pdo)) return;
        $lockName=$this->acquireLock($pdo, 'восстановления');
        try { FeoImportTransaction::recoverPending($pdo); }
        finally { $this->releaseLock($pdo,$lockName); }
    }

    private function acquireLock(PDO $pdo, string $operation): string
    {
        $lockName = 'planex_feo_' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new \RuntimeException("Не удалось получить блокировку {$operation} ФЭО.");
        return $lockName;
    }

    private function releaseLock(PDO $pdo, string $lockName): void
    {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }

    private function businessRow(array $row): array
    {
        $out = [];
        foreach (FeoXlsxParser::fields() as $field) {
            $value = $row[$field] ?? null;
            if ($value === '') $value = null;
            $out[$field] = $value === null ? null : (string)$value;
        }
        return $out;
    }

    private function sameBusinessRow(array $a, array $b): bool
    {
        return $this->encodeRow($this->businessRow($a)) === $this->encodeRow($this->businessRow($b));
    }

    private function syncForChanges(FeoImportTransaction $transaction, array $changes): ?FeoLegacyImportSync
    {
        foreach ($changes as $change) {
            $after = json_decode((string)$change['after_json'], true, 512, JSON_THROW_ON_ERROR);
            if (array_key_exists(FeoLegacyImportSync::JOURNAL_KEY, $after)) {
                $sync = $transaction->sync();
                if ($sync === null) throw new \RuntimeException('Для этой загрузки требуется подключение синхронизации со старой базой.');
                $sync->validate();
                return $sync;
            }
        }
        return null;
    }

    private function encodeRow(array $row, ?array $legacyJournal = null): string
    {
        $snapshot = $this->businessRow($row);
        if ($legacyJournal !== null) $snapshot[FeoLegacyImportSync::JOURNAL_KEY] = $legacyJournal;
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new \RuntimeException('Не удалось сериализовать данные ФЭО.');
        return $json;
    }
}
