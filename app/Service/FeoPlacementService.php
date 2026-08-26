<?php

namespace App\Service;

use PDO;

final class FeoPlacementService
{
    private const TABLES = ['feo_placement_blocks','feo_placement_block_requests','feo_placement_current'];
    private const CHUNK = 800;

    public function moduleReady(PDO $pdo): bool
    {
        $names = ['feo_requests', ...self::TABLES];
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($names), '?')) . ')');
        $q->execute($names);
        return (int)$q->fetchColumn() === count($names);
    }

    public function pageState(PDO $pdo): array
    {
        $blocks = $this->listBlocks($pdo);
        $current = $this->currentSummary($pdo);
        $consistency = $this->consistency($pdo);
        return ['blocks' => $blocks, 'current' => $current, 'consistency' => $consistency];
    }

    public function listBlocks(PDO $pdo): array
    {
        $sql = "SELECT b.*,
                       COUNT(br.zayavka_id) request_count,
                       COALESCE(SUM(COALESCE(r.mass_netto,0))*1000,0) total_mass_kg,
                       SUM(CASE WHEN r.zayavka_id IS NULL THEN 1 ELSE 0 END) missing_request_count
                  FROM feo_placement_blocks b
             LEFT JOIN feo_placement_block_requests br ON br.block_id=b.id
             LEFT JOIN feo_requests r ON r.zayavka_id=br.zayavka_id
              GROUP BY b.id
              ORDER BY b.id DESC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $requestIds = [];
        if ($rows !== []) {
            foreach ($pdo->query('SELECT block_id,zayavka_id FROM feo_placement_block_requests ORDER BY block_id,position_no,id')->fetchAll(PDO::FETCH_ASSOC) as $requestRow) {
                $requestIds[(int)$requestRow['block_id']][] = (string)$requestRow['zayavka_id'];
            }
        }
        foreach ($rows as &$row) {
            $row['request_count'] = (int)$row['request_count'];
            $row['total_mass_kg'] = round((float)$row['total_mass_kg'], 3);
            $row['missing_request_count'] = (int)$row['missing_request_count'];
            $row['request_ids'] = $requestIds[(int)$row['id']] ?? [];
            $row['request_ids_text'] = implode(', ', $row['request_ids']);
        }
        unset($row);
        return $rows;
    }

    public function block(PDO $pdo, int $id): ?array
    {
        if ($id <= 0) return null;
        $q = $pdo->prepare('SELECT * FROM feo_placement_blocks WHERE id=? LIMIT 1');
        $q->execute([$id]);
        $block = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$block) return null;
        $r = $pdo->prepare("SELECT br.zayavka_id,COALESCE(fr.mass_netto,0)*1000 mass_kg,fr.zayavka_id IS NULL missing
                              FROM feo_placement_block_requests br
                         LEFT JOIN feo_requests fr ON fr.zayavka_id=br.zayavka_id
                             WHERE br.block_id=? ORDER BY br.position_no,br.id");
        $r->execute([$id]);
        $requests = $r->fetchAll(PDO::FETCH_ASSOC);
        $block['requests'] = array_map(static fn(array $row): array => [
            'zayavka_id' => (string)$row['zayavka_id'],
            'mass_kg' => round((float)$row['mass_kg'], 3),
            'missing' => (bool)$row['missing'],
        ], $requests);
        $block['request_count'] = count($requests);
        $block['total_mass_kg'] = round(array_sum(array_column($block['requests'], 'mass_kg')), 3);
        return $block;
    }

    public function currentSummary(PDO $pdo): array
    {
        $row = $pdo->query("SELECT COUNT(*) request_count,
                                   COALESCE(SUM(COALESCE(r.mass_netto,0))*1000,0) total_mass_kg,
                                   SUM(CASE WHEN r.zayavka_id IS NULL THEN 1 ELSE 0 END) missing_request_count
                              FROM feo_placement_current c
                         LEFT JOIN feo_requests r ON r.zayavka_id=c.zayavka_id")->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'request_count' => (int)($row['request_count'] ?? 0),
            'total_mass_kg' => round((float)($row['total_mass_kg'] ?? 0), 3),
            'missing_request_count' => (int)($row['missing_request_count'] ?? 0),
        ];
    }

    public function currentRows(PDO $pdo, int $limit = 5000): array
    {
        $limit = max(1, min(20000, $limit));
        $stmt = $pdo->query("SELECT c.zayavka_id,c.source_block_id,COALESCE(r.mass_netto,0)*1000 mass_kg,
                                    r.mno_region,r.mno_mo,r.mno_adres_pogruzki,r.zayavka_id IS NULL missing
                               FROM feo_placement_current c
                          LEFT JOIN feo_requests r ON r.zayavka_id=c.zayavka_id
                           ORDER BY c.zayavka_id LIMIT {$limit}");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function previewCreate(PDO $pdo, array $ids, string $blockType): array
    {
        $blockType = $this->normalizeType($blockType);
        $rows = $this->requestRows($pdo, $ids);
        $missing = array_values(array_diff($ids, array_keys($rows)));
        if ($missing !== []) {
            throw new \RuntimeException('Не найдены заявки ФЭО: ' . implode(', ', array_slice($missing, 0, 50)) . (count($missing) > 50 ? ' и ещё ' . (count($missing) - 50) : '') . '.');
        }
        $current = $this->currentSet($pdo);
        $after = $current;
        $repeat = [];
        foreach ($ids as $id) {
            if ($blockType === 'addition') {
                if (isset($after[$id])) $repeat[] = $id;
                $after[$id] = 0;
            } else {
                if (!isset($after[$id])) $repeat[] = $id;
                unset($after[$id]);
            }
        }
        $delta = $this->delta($pdo, $current, $after);
        return [
            'block_type' => $blockType,
            'selected_count' => count($ids),
            'selected_mass_kg' => $this->massForIds($rows, $ids),
            'repeat_ids' => $repeat,
            'effect' => $delta,
            'state_hash' => $this->stateHash($pdo),
        ];
    }

    public function createBlock(PDO $pdo, array $payload, string $expectedStateHash): array
    {
        $ids = array_values(array_unique(array_map('strval', (array)($payload['ids'] ?? []))));
        if ($ids === []) throw new \RuntimeException('Состав блока пуст.');
        $type = $this->normalizeType((string)($payload['block_type'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        if (mb_strlen($name) > 255) throw new \RuntimeException('Название блока слишком длинное.');
        $sourceType = (string)($payload['source_type'] ?? 'manual');
        if (!in_array($sourceType, ['manual','xls','xlsx'], true)) throw new \RuntimeException('Некорректный источник блока.');
        $sourceFilename = trim((string)($payload['source_filename'] ?? '')) ?: null;
        $sourceFileKey = trim((string)($payload['source_file_key'] ?? '')) ?: null;
        $actorId = isset($payload['created_by_user_id']) ? (int)$payload['created_by_user_id'] : null;
        $actorRole = trim((string)($payload['created_by_role'] ?? '')) ?: null;

        $lock = $this->acquireLock($pdo);
        try {
            $pdo->beginTransaction();
            if (!hash_equals($expectedStateHash, $this->stateHash($pdo))) throw new \RuntimeException('Состав изменился после предварительной проверки. Выполните проверку ещё раз.');
            $preview = $this->previewCreate($pdo, $ids, $type);
            $stmt = $pdo->prepare('INSERT INTO feo_placement_blocks(name,block_type,status,source_type,source_filename,source_file_key,created_by_user_id,created_by_role) VALUES(?,?,\'applied\',?,?,?,?,?)');
            $stmt->execute([$name !== '' ? $name : null, $type, $sourceType, $sourceFilename, $sourceFileKey, $actorId ?: null, $actorRole]);
            $blockId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO feo_placement_block_requests(block_id,zayavka_id,position_no) VALUES(?,?,?)');
            foreach ($ids as $i => $id) $ins->execute([$blockId, $id, $i + 1]);
            $expected = $this->replay($pdo);
            $this->replaceCurrent($pdo, $expected);
            $afterSummary = $this->currentSummary($pdo);
            $pdo->commit();
            return ['block_id' => $blockId, 'preview' => $preview, 'current' => $afterSummary];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $this->releaseLock($pdo, $lock);
        }
    }

    public function previewStatusChange(PDO $pdo, int $blockId, string $targetStatus): array
    {
        $targetStatus = $this->normalizeStatus($targetStatus);
        $q = $pdo->prepare('SELECT * FROM feo_placement_blocks WHERE id=? LIMIT 1');
        $q->execute([$blockId]);
        $block = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$block) throw new \RuntimeException('Блок не найден.');
        if (($block['status'] ?? '') === $targetStatus) throw new \RuntimeException('Блок уже имеет выбранный статус.');
        $before = $this->currentSet($pdo);
        $after = $this->replay($pdo, [$blockId => $targetStatus]);
        return [
            'block_id' => $blockId,
            'block_type' => (string)$block['block_type'],
            'current_status' => (string)$block['status'],
            'target_status' => $targetStatus,
            'effect' => $this->delta($pdo, $before, $after),
            'state_hash' => $this->stateHash($pdo),
        ];
    }

    public function changeStatus(PDO $pdo, int $blockId, string $targetStatus, string $expectedStateHash): array
    {
        $targetStatus = $this->normalizeStatus($targetStatus);
        $lock = $this->acquireLock($pdo);
        try {
            $pdo->beginTransaction();
            if (!hash_equals($expectedStateHash, $this->stateHash($pdo))) throw new \RuntimeException('Состав изменился после подтверждения. Повторите операцию.');
            $preview = $this->previewStatusChange($pdo, $blockId, $targetStatus);
            $q = $pdo->prepare('UPDATE feo_placement_blocks SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $q->execute([$targetStatus, $blockId]);
            if ($q->rowCount() !== 1) throw new \RuntimeException('Не удалось изменить статус блока.');
            $this->replaceCurrent($pdo, $this->replay($pdo));
            $current = $this->currentSummary($pdo);
            $pdo->commit();
            return ['preview' => $preview, 'current' => $current];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $this->releaseLock($pdo, $lock);
        }
    }

    public function previewDelete(PDO $pdo, int $blockId): array
    {
        $block = $this->block($pdo, $blockId);
        if (!$block) throw new \RuntimeException('Блок не найден.');
        if (($block['status'] ?? '') !== 'disabled') throw new \RuntimeException('Удалить можно только отключённый блок.');
        $q = $pdo->prepare('SELECT COUNT(*) FROM feo_placement_current WHERE source_block_id=?');
        $q->execute([$blockId]);
        if ((int)$q->fetchColumn() > 0) throw new \RuntimeException('Отключённый блок всё ещё связан с актуальным составом. Сначала пересчитайте состав.');
        return [
            'block_id' => $blockId,
            'name' => (string)($block['name'] ?? ''),
            'request_count' => (int)$block['request_count'],
            'total_mass_kg' => (float)$block['total_mass_kg'],
            'source_filename' => $block['source_filename'] ?? null,
            'source_file_key' => $block['source_file_key'] ?? null,
            'state_hash' => $this->stateHash($pdo),
        ];
    }

    public function deleteDisabledBlock(PDO $pdo, int $blockId, string $expectedStateHash): array
    {
        $lock = $this->acquireLock($pdo);
        try {
            $pdo->beginTransaction();
            if (!hash_equals($expectedStateHash, $this->stateHash($pdo))) throw new \RuntimeException('Состав изменился после подтверждения. Повторите удаление.');
            $q = $pdo->prepare('SELECT id,status FROM feo_placement_blocks WHERE id=? FOR UPDATE');
            $q->execute([$blockId]);
            $row = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) throw new \RuntimeException('Блок не найден.');
            if (($row['status'] ?? '') !== 'disabled') throw new \RuntimeException('Удалить можно только отключённый блок.');
            $q = $pdo->prepare('SELECT COUNT(*) FROM feo_placement_current WHERE source_block_id=?');
            $q->execute([$blockId]);
            if ((int)$q->fetchColumn() > 0) throw new \RuntimeException('Отключённый блок всё ещё связан с актуальным составом. Сначала пересчитайте состав.');
            $preview = $this->previewDelete($pdo, $blockId);
            $q = $pdo->prepare("DELETE FROM feo_placement_blocks WHERE id=? AND status='disabled'");
            $q->execute([$blockId]);
            if ($q->rowCount() !== 1) throw new \RuntimeException('Не удалось удалить отключённый блок.');
            $current = $this->currentSummary($pdo);
            $pdo->commit();
            return ['deleted_block' => $preview, 'current' => $current];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $this->releaseLock($pdo, $lock);
        }
    }

    public function consistency(PDO $pdo): array
    {
        $expected = $this->replay($pdo);
        $actual = $this->currentSet($pdo);
        $added = array_values(array_diff(array_keys($expected), array_keys($actual)));
        $removed = array_values(array_diff(array_keys($actual), array_keys($expected)));
        return [
            'is_consistent' => $added === [] && $removed === [],
            'expected_count' => count($expected),
            'actual_count' => count($actual),
            'missing_in_current' => $added,
            'extra_in_current' => $removed,
            'state_hash' => $this->stateHash($pdo),
        ];
    }

    public function previewReconcile(PDO $pdo): array
    {
        $before = $this->currentSet($pdo);
        $after = $this->replay($pdo);
        return [
            'effect' => $this->delta($pdo, $before, $after),
            'state_hash' => $this->stateHash($pdo),
            'is_consistent' => array_keys($before) === array_keys($after),
        ];
    }

    public function reconcile(PDO $pdo, string $expectedStateHash): array
    {
        $lock = $this->acquireLock($pdo);
        try {
            $pdo->beginTransaction();
            if (!hash_equals($expectedStateHash, $this->stateHash($pdo))) throw new \RuntimeException('Состав изменился после подтверждения. Повторите пересчёт.');
            $before = $this->currentSet($pdo);
            $after = $this->replay($pdo);
            $effect = $this->delta($pdo, $before, $after);
            $this->replaceCurrent($pdo, $after);
            $current = $this->currentSummary($pdo);
            $pdo->commit();
            return ['effect' => $effect, 'current' => $current];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $this->releaseLock($pdo, $lock);
        }
    }

    private function normalizeType(string $type): string
    {
        if (!in_array($type, ['addition','exclusion'], true)) throw new \RuntimeException('Выберите тип блока: добавление или исключение.');
        return $type;
    }

    private function normalizeStatus(string $status): string
    {
        if (!in_array($status, ['applied','disabled'], true)) throw new \RuntimeException('Некорректный статус блока.');
        return $status;
    }

    private function requestRows(PDO $pdo, array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            if ($chunk === []) continue;
            $q = $pdo->prepare('SELECT zayavka_id,mass_netto FROM feo_requests WHERE zayavka_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(string)$row['zayavka_id']] = $row;
        }
        return $out;
    }

    private function massForIds(array $rows, array $ids): float
    {
        $mass = 0.0;
        foreach ($ids as $id) $mass += ((float)($rows[$id]['mass_netto'] ?? 0)) * 1000;
        return round($mass, 3);
    }

    private function massByIds(PDO $pdo, array $ids): float
    {
        if ($ids === []) return 0.0;
        return $this->massForIds($this->requestRows($pdo, $ids), $ids);
    }

    private function currentSet(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT zayavka_id,source_block_id FROM feo_placement_current ORDER BY zayavka_id')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) $out[(string)$row['zayavka_id']] = (int)$row['source_block_id'];
        return $out;
    }

    private function replay(PDO $pdo, array $statusOverrides = []): array
    {
        $blocks = $pdo->query('SELECT id,block_type,status FROM feo_placement_blocks ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
        $set = [];
        $q = $pdo->prepare('SELECT zayavka_id FROM feo_placement_block_requests WHERE block_id=? ORDER BY position_no,id');
        foreach ($blocks as $block) {
            $id = (int)$block['id'];
            $status = $statusOverrides[$id] ?? (string)$block['status'];
            if ($status !== 'applied') continue;
            $q->execute([$id]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $requestId) {
                $requestId = (string)$requestId;
                if (($block['block_type'] ?? '') === 'addition') $set[$requestId] = $id;
                else unset($set[$requestId]);
            }
        }
        ksort($set, SORT_STRING);
        return $set;
    }

    private function replaceCurrent(PDO $pdo, array $set): void
    {
        $pdo->exec('DELETE FROM feo_placement_current');
        if ($set === []) return;
        $q = $pdo->prepare('INSERT INTO feo_placement_current(zayavka_id,source_block_id) VALUES(?,?)');
        foreach ($set as $requestId => $blockId) $q->execute([$requestId, $blockId]);
    }

    private function delta(PDO $pdo, array $before, array $after): array
    {
        $added = array_values(array_diff(array_keys($after), array_keys($before)));
        $removed = array_values(array_diff(array_keys($before), array_keys($after)));
        return [
            'added_count' => count($added),
            'added_mass_kg' => $this->massByIds($pdo, $added),
            'removed_count' => count($removed),
            'removed_mass_kg' => $this->massByIds($pdo, $removed),
            'changed_count' => count($added) + count($removed),
            'changed_mass_kg' => round($this->massByIds($pdo, $added) + $this->massByIds($pdo, $removed), 3),
        ];
    }

    private function stateHash(PDO $pdo): string
    {
        $blocks = $pdo->query('SELECT id,block_type,status,updated_at FROM feo_placement_blocks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $current = $pdo->query('SELECT zayavka_id,source_block_id FROM feo_placement_current ORDER BY zayavka_id')->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256', json_encode([$blocks,$current], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function acquireLock(PDO $pdo): string
    {
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $name = 'planex_feo_placement_' . substr(hash('sha256', $database), 0, 20);
        $q = $pdo->prepare('SELECT GET_LOCK(?,10)');
        $q->execute([$name]);
        if ((int)$q->fetchColumn() !== 1) throw new \RuntimeException('Не удалось получить блокировку размещения ФЭО.');
        return $name;
    }

    private function releaseLock(PDO $pdo, string $name): void
    {
        $q = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $q->execute([$name]);
    }
}
