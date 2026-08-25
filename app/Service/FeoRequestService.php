<?php

namespace App\Service;

use App\Core\Database;
use PDO;

final class FeoRequestService
{
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

    public function listRequests(PDO $pdo, string $search, int $page, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(20, min(100, $perPage));
        $params = [];
        $where = '';
        if ($search !== '') {
            $needle = '%' . $search . '%';
            $where = " WHERE zayavka_id LIKE ? OR polnoe_naim_zakazchika LIKE ? OR naim_oo_gruzootpravitel LIKE ? OR mno_region LIKE ? OR mno_mo LIKE ? OR mno_adres_pogruzki LIKE ? OR naim_otkhoda_fkko LIKE ? OR inn_zakazchika LIKE ? OR inn_oo_gruzootpravitel LIKE ? ";
            $params = array_fill(0, 9, $needle);
        }

        $count = $pdo->prepare('SELECT COUNT(*) FROM feo_requests' . $where);
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT id,zayavka_id,data_zayavki,polnoe_naim_zakazchika,naim_oo_gruzootpravitel,mno_region,mno_mo,mno_adres_pogruzki,naim_otkhoda_fkko,mass_brutto,zayavka_zhdv_po,zakaz_s_ot_status,zakaz_s_op_status,updated_at FROM feo_requests' . $where . ' ORDER BY id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
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
        $stmt = $pdo->query('SELECT * FROM feo_imports ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $latestAppliedId = null;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') === 'applied') {
                $latestAppliedId = (int)$row['id'];
                break;
            }
        }
        foreach ($rows as &$row) {
            $row['can_rollback'] = ($row['status'] ?? '') === 'applied' && (int)$row['id'] === $latestAppliedId;
        }
        unset($row);
        return $rows;
    }

    public function analyze(PDO $pdo, array $rows, string $fileHash): array
    {
        $existingRows = $pdo->query('SELECT * FROM feo_requests')->fetchAll(PDO::FETCH_ASSOC);
        $existing = [];
        foreach ($existingRows as $row) {
            $existing[(string)$row['zayavka_id']] = $this->businessRow($row);
        }

        $stats = ['rows_total' => count($rows), 'unique_requests' => count($rows), 'new_count' => 0, 'updated_count' => 0, 'unchanged_count' => 0, 'missing_count' => 0];
        $incomingIds = [];
        foreach ($rows as $row) {
            $id = (string)$row['zayavka_id'];
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

    public function applyImport(PDO $pdo, array $rows, string $filename, string $fileHash, array $actor): array
    {
        if ($rows === []) throw new \RuntimeException('Нет заявок для импорта.');
        $lockName = 'planex_feo_' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new \RuntimeException('Не удалось получить блокировку импорта ФЭО.');

        try {
            $dup = $pdo->prepare("SELECT COUNT(*) FROM feo_imports WHERE file_hash = ? AND status = 'applied'");
            $dup->execute([$fileHash]);
            if ((int)$dup->fetchColumn() > 0) {
                throw new \RuntimeException('Этот файл уже применён и не был откатан.');
            }

            $pdo->beginTransaction();
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
            foreach ($rows as $row) {
                $id = (string)$row['zayavka_id'];
                $incomingIds[$id] = true;
                $find->execute([$id]);
                $beforeDb = $find->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($beforeDb === null) {
                    $values = array_map(static fn(string $f) => $row[$f] ?? null, $fields);
                    $insertRequest->execute([...$values, $importId]);
                    $insertChange->execute([$importId, $id, 'insert', null, null, $this->encodeRow($row)]);
                    $stats['new_count']++;
                    continue;
                }

                $before = $this->businessRow($beforeDb);
                if ($this->sameBusinessRow($before, $row)) {
                    $stats['unchanged_count']++;
                    continue;
                }

                $updateFields = array_values(array_filter($fields, static fn(string $f): bool => $f !== 'zayavka_id'));
                $values = array_map(static fn(string $f) => $row[$f] ?? null, $updateFields);
                $updateRequest->execute([...$values, $importId, $id]);
                $insertChange->execute([
                    $importId,
                    $id,
                    'update',
                    $beforeDb['current_import_id'] !== null ? (int)$beforeDb['current_import_id'] : null,
                    $this->encodeRow($before),
                    $this->encodeRow($row),
                ]);
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
            $pdo->commit();
            return ['import_id' => $importId] + $stats;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    public function rollbackLatest(PDO $pdo, int $importId, array $actor): array
    {
        if ($importId <= 0) throw new \RuntimeException('Некорректная загрузка.');
        $lockName = 'planex_feo_' . substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new \RuntimeException('Не удалось получить блокировку отката ФЭО.');

        try {
            $pdo->beginTransaction();
            $latest = $pdo->query("SELECT * FROM feo_imports WHERE status='applied' ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$latest || (int)$latest['id'] !== $importId) {
                throw new \RuntimeException('Откатывать можно только последнюю активную загрузку ФЭО.');
            }

            $changesStmt = $pdo->prepare('SELECT * FROM feo_import_changes WHERE import_id=? ORDER BY id DESC');
            $changesStmt->execute([$importId]);
            $changes = $changesStmt->fetchAll(PDO::FETCH_ASSOC);
            $fields = FeoXlsxParser::fields();
            $restoreFields = array_values(array_filter($fields, static fn(string $f): bool => $f !== 'zayavka_id'));
            $restoreSql = implode(',', array_map(static fn(string $f): string => '`' . $f . '`=?', $restoreFields));
            $restore = $pdo->prepare("UPDATE feo_requests SET {$restoreSql}, current_import_id=? WHERE zayavka_id=? AND current_import_id=?");
            $delete = $pdo->prepare('DELETE FROM feo_requests WHERE zayavka_id=? AND current_import_id=?');

            $deleted = 0;
            $restored = 0;
            foreach ($changes as $change) {
                $id = (string)$change['zayavka_id'];
                if ($change['action'] === 'insert') {
                    $delete->execute([$id, $importId]);
                    $deleted += $delete->rowCount();
                    continue;
                }
                $before = json_decode((string)$change['before_json'], true);
                if (!is_array($before)) throw new \RuntimeException('Повреждён журнал отката ФЭО.');
                $values = array_map(static fn(string $f) => $before[$f] ?? null, $restoreFields);
                $restore->execute([...$values, $change['previous_import_id'] !== null ? (int)$change['previous_import_id'] : null, $id, $importId]);
                if ($restore->rowCount() === 0) throw new \RuntimeException("Заявка {$id} была изменена после загрузки; безопасный откат остановлен.");
                $restored++;
            }

            $userId = (int)($actor['user_id'] ?? 0) ?: null;
            $role = (string)($actor['role_code'] ?? '');
            $mark = $pdo->prepare("UPDATE feo_imports SET status='rolled_back',rolled_back_by_user_id=?,rolled_back_by_role=?,rolled_back_at=NOW() WHERE id=? AND status='applied'");
            $mark->execute([$userId, $role !== '' ? $role : null, $importId]);
            if ($mark->rowCount() !== 1) throw new \RuntimeException('Не удалось зафиксировать откат загрузки ФЭО.');
            $pdo->commit();
            return ['deleted' => $deleted, 'restored' => $restored, 'import_id' => $importId];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
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

    private function encodeRow(array $row): string
    {
        $json = json_encode($this->businessRow($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new \RuntimeException('Не удалось сериализовать данные ФЭО.');
        return $json;
    }
}
