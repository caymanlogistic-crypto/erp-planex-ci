<?php

namespace App\Service;

use PDO;
use RuntimeException;

require_once __DIR__ . '/FeoImportTransaction.php';

final class FeoWeightBatchService
{
    public function ready(PDO $pdo): bool
    {
        if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('feo_requests','feo_weight_batches','feo_weight_items')")->fetchColumn() !== 3) return false;
        $column = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo_weight_items' AND COLUMN_NAME='legacy_snapshot_json'");
        return (int)$column->fetchColumn() === 1;
    }

    public static function parseIds(string $raw): array
    {
        return FeoTransferService::parseIds($raw);
    }

    public static function tonnesFromKg(mixed $input): ?string
    {
        if (!is_string($input) && !is_numeric($input) && $input !== null) throw new RuntimeException('Некорректный вес.');
        $value = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], trim((string)$input));
        if ($value === '') return null;
        if (!preg_match('/^\d{1,13}(?:\.\d)?$/D', $value)) throw new RuntimeException('Введите вес в килограммах: неотрицательное число с точностью до 0,1 кг.');
        $tonnes = (float)$value / 1000;
        if ($tonnes > 9999999999.9999) throw new RuntimeException('Вес превышает допустимое значение.');
        return number_format($tonnes, 4, '.', '');
    }

    public function preview(PDO $pdo, array $ids, bool $lock = false): array
    {
        if (!$this->ready($pdo)) throw new RuntimeException('Изменение веса ожидает обновления базы компании (162).');
        $ids = self::parseIds(implode("\n", $ids))['ids'];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $query = $pdo->prepare("SELECT id,zayavka_id,mass_netto,mass_brutto FROM feo_requests WHERE zayavka_id IN ($marks) ORDER BY zayavka_id" . ($lock ? ' FOR UPDATE' : ''));
        $query->execute($ids);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $found = array_column($rows, 'zayavka_id');
        $missing = array_values(array_diff($ids, $found));
        if ($missing) throw new RuntimeException('Заявки не найдены: '.implode(', ', array_slice($missing, 0, 30)).'. Блок не сохранён.');
        return ['rows'=>$rows, 'count'=>count($rows), 'state_hash'=>$this->stateHash($rows)];
    }

    public function create(PDO $pdo, array $preview, array $targets, array $actor, string $operationKey, string $name, string $comment, ?callable $event = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $operationKey)) throw new RuntimeException('Некорректный ключ операции.');
        $name = trim($name); $comment = trim($comment);
        if (mb_strlen($name) > 180 || mb_strlen($comment) > 2000) throw new RuntimeException('Сократите название или комментарий блока.');
        if ((int)($actor['created_by_user_id'] ?? 0) <= 0) throw new RuntimeException('Не удалось определить автора изменения.');
        return $this->transaction($pdo, function (?FeoLegacyImportSync $legacy) use ($pdo, $preview, $targets, $actor, $operationKey, $name, $comment, $event): array {
            $query = $pdo->prepare('SELECT id FROM feo_weight_batches WHERE operation_key=?');
            $query->execute([$operationKey]);
            if ($id = $query->fetchColumn()) return ['batch_id'=>(int)$id, 'already_applied'=>true];
            $fresh = $this->preview($pdo, array_column($preview['rows'], 'zayavka_id'), true);
            if (!hash_equals((string)$preview['state_hash'], $fresh['state_hash'])) throw new RuntimeException('Вес заявок изменился после проверки. Проверьте блок заново.');
            $changes = [];
            foreach ($fresh['rows'] as $row) {
                $id = (string)$row['zayavka_id'];
                if (!isset($targets[$id]) || !is_array($targets[$id]) || !array_key_exists('netto_kg', $targets[$id]) || !array_key_exists('brutto_kg', $targets[$id])) throw new RuntimeException('Укажите оба веса для заявки №'.$id.'.');
                $afterNetto = self::tonnesFromKg($targets[$id]['netto_kg']);
                $afterBrutto = self::tonnesFromKg($targets[$id]['brutto_kg']);
                if ($this->same($row['mass_netto'], $afterNetto) && $this->same($row['mass_brutto'], $afterBrutto)) continue;
                $changes[] = ['row'=>$row, 'netto'=>$afterNetto, 'brutto'=>$afterBrutto];
            }
            if (count($targets) !== count($fresh['rows'])) throw new RuntimeException('Состав заявок изменился. Проверьте блок заново.');
            if (!$changes) throw new RuntimeException('Вес не изменён ни у одной заявки.');
            $query = $pdo->prepare('INSERT INTO feo_weight_batches(name,comment,request_count,operation_key,created_by_user_id,created_by_name) VALUES(?,?,?,?,?,?)');
            $query->execute([$name ?: 'Изменение веса заявок', $comment, count($changes), $operationKey, $actor['created_by_user_id'], $actor['created_by_display_name']]);
            $batchId = (int)$pdo->lastInsertId();
            $insert = $pdo->prepare('INSERT INTO feo_weight_items(batch_id,zayavka_id,request_row_id,before_netto,before_brutto,after_netto,after_brutto,legacy_snapshot_json) VALUES(?,?,?,?,?,?,?,?)');
            $update = $pdo->prepare('UPDATE feo_requests SET mass_netto=?,mass_brutto=?,current_import_id=NULL WHERE id=?');
            $eventChanges = [];
            foreach ($changes as $change) {
                $row = $change['row'];
                $snapshot = $legacy?->writeWeight((string)$row['zayavka_id'], ['mass_netto'=>$change['netto'], 'mass_brutto'=>$change['brutto']]);
                $insert->execute([$batchId, $row['zayavka_id'], $row['id'], $row['mass_netto'], $row['mass_brutto'], $change['netto'], $change['brutto'], $snapshot === null ? null : json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                $update->execute([$change['netto'], $change['brutto'], $row['id']]);
                $notification = FeoWeightChangeNotificationService::change($row, ['zayavka_id'=>$row['zayavka_id'], 'mass_netto'=>$change['netto'], 'mass_brutto'=>$change['brutto']]);
                if ($notification) $eventChanges[] = $notification;
            }
            if ($event) $event($batchId, $eventChanges);
            return ['batch_id'=>$batchId, 'count'=>count($changes)];
        });
    }

    public function revert(PDO $pdo, int $batchId, array $actor, ?callable $event = null): array
    {
        return $this->transaction($pdo, function (?FeoLegacyImportSync $legacy) use ($pdo, $batchId, $actor, $event): array {
            $query = $pdo->prepare('SELECT * FROM feo_weight_batches WHERE id=? FOR UPDATE');
            $query->execute([$batchId]);
            $batch = $query->fetch(PDO::FETCH_ASSOC);
            if (!$batch) throw new RuntimeException('Блок не найден.');
            if ($batch['status'] === 'reverted') return ['batch_id'=>$batchId, 'already_reverted'=>true];
            $items = $this->items($pdo, $batchId);
            $synced = array_filter($items, static fn(array $item): bool => $item['legacy_snapshot_json'] !== null);
            if ($synced !== [] && count($synced) !== count($items)) throw new RuntimeException('История синхронизации блока неполна. Возврат остановлен.');
            $find = $pdo->prepare('SELECT id,zayavka_id,mass_netto,mass_brutto FROM feo_requests WHERE zayavka_id=? FOR UPDATE');
            $update = $pdo->prepare('UPDATE feo_requests SET mass_netto=?,mass_brutto=?,current_import_id=NULL WHERE id=?');
            $eventChanges = [];
            foreach ($items as $item) {
                $find->execute([$item['zayavka_id']]);
                $current = $find->fetch(PDO::FETCH_ASSOC);
                if (!$current || (int)$current['id'] !== (int)$item['request_row_id'] || !$this->same($current['mass_netto'], $item['after_netto']) || !$this->same($current['mass_brutto'], $item['after_brutto'])) {
                    throw new RuntimeException('Вес заявки №'.$item['zayavka_id'].' изменён после блока. Возврат всего блока остановлен.');
                }
                if ($item['legacy_snapshot_json'] !== null) {
                    if ($legacy === null) throw new RuntimeException('Для возврата блока требуется подключение к старой базе ФЭО.');
                    $legacy->revertWeight((string)$item['zayavka_id'], json_decode((string)$item['legacy_snapshot_json'], true, 32, JSON_THROW_ON_ERROR));
                }
                $update->execute([$item['before_netto'], $item['before_brutto'], $current['id']]);
                $notification = FeoWeightChangeNotificationService::change($current, ['zayavka_id'=>$item['zayavka_id'], 'mass_netto'=>$item['before_netto'], 'mass_brutto'=>$item['before_brutto']]);
                if ($notification) $eventChanges[] = $notification;
            }
            $query = $pdo->prepare("UPDATE feo_weight_batches SET status='reverted',reverted_by_user_id=?,reverted_by_name=?,reverted_at=NOW() WHERE id=?");
            $query->execute([$actor['created_by_user_id'], $actor['created_by_display_name'], $batchId]);
            if ($event) $event($batchId, $eventChanges);
            return ['batch_id'=>$batchId, 'count'=>count($items)];
        });
    }

    public function batches(PDO $pdo, int $page): array
    {
        $offset = (max(1, $page) - 1) * 50;
        return $pdo->query("SELECT * FROM feo_weight_batches ORDER BY id DESC LIMIT 50 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Complete request IDs and historic totals for the visible registry page. */
    public function batchIndexItems(PDO $pdo, array $batches): array
    {
        if ($batches === []) return [];
        $ids = array_map(static fn(array $batch): int => (int)$batch['id'], $batches);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $query = $pdo->prepare("SELECT batch_id,zayavka_id,before_netto,before_brutto,after_netto,after_brutto
            FROM feo_weight_items WHERE batch_id IN ($marks) ORDER BY batch_id DESC,zayavka_id");
        $query->execute($ids);
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = ['ids'=>[], 'before_net_kg'=>0.0, 'after_net_kg'=>0.0,
                'before_gross_kg'=>0.0, 'after_gross_kg'=>0.0];
        }
        $complete = [];
        foreach ($ids as $id) $complete[$id] = array_fill_keys(['before_net','after_net','before_gross','after_gross'], true);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $id = (int)$item['batch_id'];
            $result[$id]['ids'][] = (string)$item['zayavka_id'];
            foreach (['before_net'=>'before_netto', 'after_net'=>'after_netto',
                'before_gross'=>'before_brutto', 'after_gross'=>'after_brutto'] as $kind=>$field) {
                if ($item[$field] === null) $complete[$id][$kind] = false;
                else $result[$id][$kind.'_kg'] += (float)$item[$field] * 1000;
            }
        }
        foreach ($result as $id=>&$summary) {
            foreach (['before_net','after_net','before_gross','after_gross'] as $kind) {
                $summary[$kind.'_kg'] = $summary['ids'] !== [] && $complete[$id][$kind]
                    ? round($summary[$kind.'_kg'], 1) : null;
            }
        }
        unset($summary);
        return $result;
    }

    public function items(PDO $pdo, int $batchId): array
    {
        $query = $pdo->prepare('SELECT zayavka_id,request_row_id,before_netto,before_brutto,after_netto,after_brutto,legacy_snapshot_json FROM feo_weight_items WHERE batch_id=? ORDER BY zayavka_id');
        $query->execute([$batchId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function stateHash(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function same(mixed $a, mixed $b): bool
    {
        return $a === null && $b === null || $a !== null && $b !== null && number_format((float)$a, 4, '.', '') === number_format((float)$b, 4, '.', '');
    }

    private function transaction(PDO $pdo, callable $action): array
    {
        if ($pdo->inTransaction()) throw new RuntimeException('Изменение веса должно выполняться отдельной операцией.');
        $lock = 'planex_feo_'.substr(hash('sha256', (string)$pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
        $query = $pdo->prepare('SELECT GET_LOCK(?,10)'); $query->execute([$lock]);
        if ((int)$query->fetchColumn() !== 1) throw new RuntimeException('Заявки сейчас изменяются. Повторите позже.');
        try {
            $syncTenant = trim((string)env('FEO_LEGACY_SYNC_TENANT_DATABASE', ''));
            if ($syncTenant !== '' && $syncTenant === (string)$pdo->query('SELECT DATABASE()')->fetchColumn()) {
                return FeoImportTransaction::runWithLegacy($pdo, $action);
            }
            $pdo->beginTransaction();
            try { $result = $action(null); $pdo->commit(); return $result; }
            catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        }
        finally { $query = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$lock]); }
    }
}
