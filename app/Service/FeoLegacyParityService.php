<?php

namespace App\Service;

use PDO;
use Throwable;

/**
 * Read-only parity diagnostic between the ERP placement-current pool and the
 * historical FEO table. It never writes either source.
 */
final class FeoLegacyParityService
{
    private const MASS_TOLERANCE_KG = 0.0005;
    private const LEGACY_AVAILABLE_BLOCK_MIN_ID = 128;

    /** @var array<string,array<string,mixed>> */
    private static array $requestCache = [];

    public function state(PDO $tenantPdo, ?PDO $legacyPdo = null): array
    {
        $cacheKey = $legacyPdo === null ? $this->tenantCacheKey($tenantPdo) : '';
        if ($cacheKey !== '' && isset(self::$requestCache[$cacheKey])) return self::$requestCache[$cacheKey];

        try {
            $legacy = $legacyPdo ?? $this->legacyConnection();
            if (!$legacy instanceof PDO) {
                return $this->cache($cacheKey, $this->unavailable('Подключение к старой базе не настроено.'));
            }

            $columns = $this->legacyColumns($legacy);
            $idColumn = $this->pickColumn($columns, ['zayavka_id', 'Заявка, ID', 'Заявка ID', 'request_id']);
            $massColumn = $this->pickColumn($columns, ['mass_netto', 'Суммарная масса (нетто), т (п. 3)']);
            if ($idColumn === null || $massColumn === null) {
                return $this->cache($cacheKey, $this->unavailable('Структура таблицы заявок старой базы не поддерживает сверку ID и массы.'));
            }

            $legacyRows = $this->legacyRows($legacy, $idColumn, $massColumn);
            $duplicateIds = $this->duplicateIds($legacyRows);
            if ($duplicateIds !== []) {
                return $this->cache($cacheKey, $this->unavailable(
                    'В старой базе обнаружены повторяющиеся ID заявок. Сверка остановлена, чтобы не показывать недостоверный итог.',
                    ['duplicate_ids'=>$duplicateIds]
                ));
            }

            $newRows = $this->currentRows($tenantPdo);
            $comparison = $this->compareRows($newRows, $legacyRows);
            $mismatchIds = array_values(array_unique(array_merge(
                array_column($comparison['only_new'], 'zayavka_id'),
                array_column($comparison['only_legacy'], 'zayavka_id'),
                array_column($comparison['mass_mismatches'], 'zayavka_id')
            )));
            $history = $this->blockHistory($tenantPdo, $mismatchIds);
            foreach (['only_new','only_legacy','mass_mismatches'] as $section) {
                foreach ($comparison[$section] as &$row) {
                    $id = (string)$row['zayavka_id'];
                    $row['blocks'] = $history[$id] ?? [];
                }
                unset($row);
            }

            $comparison['available'] = true;
            $comparison['source_error'] = false;
            $comparison['message'] = '';
            $comparison['legacy_filter'] = 'Блоки старой базы: ID > ' . self::LEGACY_AVAILABLE_BLOCK_MIN_ID;
            $comparison['checked_at'] = date(DATE_ATOM);
            return $this->cache($cacheKey, $comparison);
        } catch (Throwable) {
            return $this->cache($cacheKey, $this->unavailable('Не удалось прочитать старую базу для контрольной сверки.'));
        }
    }

    public function attentionCount(PDO $tenantPdo): int
    {
        $state = $this->state($tenantPdo);
        if (empty($state['available'])) return 1;
        return max(0, (int)($state['mismatch_count'] ?? 0));
    }

    /**
     * Deterministic comparison used by runtime and regression tests.
     * New rows use mass_kg; legacy rows use mass_netto in tonnes.
     *
     * @param list<array<string,mixed>> $newRows
     * @param list<array<string,mixed>> $legacyRows
     * @return array<string,mixed>
     */
    public function compareRows(array $newRows, array $legacyRows): array
    {
        $new = [];
        foreach ($newRows as $row) {
            $id = trim((string)($row['zayavka_id'] ?? ''));
            if ($id === '') continue;
            $new[$id] = [
                'zayavka_id'=>$id,
                'mass_kg'=>round((float)($row['mass_kg'] ?? 0), 3),
                'source_block_id'=>isset($row['source_block_id']) ? (int)$row['source_block_id'] : null,
                'source_block_name'=>(string)($row['source_block_name'] ?? ''),
            ];
        }
        ksort($new, SORT_STRING);

        $legacy = [];
        foreach ($legacyRows as $row) {
            $id = trim((string)($row['zayavka_id'] ?? ''));
            if ($id === '') continue;
            $legacy[$id] = [
                'zayavka_id'=>$id,
                'mass_kg'=>round(((float)($row['mass_netto'] ?? 0)) * 1000, 3),
                'legacy_block_ids'=>array_values(array_unique(array_map('intval', (array)($row['legacy_block_ids'] ?? [])))),
            ];
        }
        ksort($legacy, SORT_STRING);

        $onlyNew = [];
        foreach (array_diff_key($new, $legacy) as $id=>$row) {
            $id = (string)$id;
            $onlyNew[] = [
                'zayavka_id'=>$id,
                'new_mass_kg'=>$row['mass_kg'],
                'legacy_mass_kg'=>null,
                'mass_delta_kg'=>$row['mass_kg'],
                'source_block_id'=>$row['source_block_id'],
                'source_block_name'=>$row['source_block_name'],
            ];
        }
        $onlyLegacy = [];
        foreach (array_diff_key($legacy, $new) as $id=>$row) {
            $id = (string)$id;
            $onlyLegacy[] = [
                'zayavka_id'=>$id,
                'new_mass_kg'=>null,
                'legacy_mass_kg'=>$row['mass_kg'],
                'mass_delta_kg'=>-$row['mass_kg'],
                'source_block_id'=>null,
                'source_block_name'=>'',
                'legacy_block_ids'=>$row['legacy_block_ids'],
            ];
        }

        $massMismatches = [];
        foreach (array_intersect_key($new, $legacy) as $id=>$row) {
            $id = (string)$id;
            $legacyMass = (float)$legacy[$id]['mass_kg'];
            $newMass = (float)$row['mass_kg'];
            if (abs($newMass - $legacyMass) <= self::MASS_TOLERANCE_KG) continue;
            $massMismatches[] = [
                'zayavka_id'=>$id,
                'new_mass_kg'=>$newMass,
                'legacy_mass_kg'=>$legacyMass,
                'mass_delta_kg'=>round($newMass - $legacyMass, 3),
                'source_block_id'=>$row['source_block_id'],
                'source_block_name'=>$row['source_block_name'],
                'legacy_block_ids'=>$legacy[$id]['legacy_block_ids'],
            ];
        }

        $newMass = round(array_sum(array_column($new, 'mass_kg')), 3);
        $legacyMass = round(array_sum(array_column($legacy, 'mass_kg')), 3);
        $onlyNewMass = round(array_sum(array_map(static fn(array $r): float => (float)$r['new_mass_kg'], $onlyNew)), 3);
        $onlyLegacyMass = round(array_sum(array_map(static fn(array $r): float => (float)$r['legacy_mass_kg'], $onlyLegacy)), 3);
        $mismatchCount = count($onlyNew) + count($onlyLegacy) + count($massMismatches);

        return [
            'is_consistent'=>$mismatchCount === 0,
            'mismatch_count'=>$mismatchCount,
            'new'=>['request_count'=>count($new),'total_mass_kg'=>$newMass],
            'legacy'=>['request_count'=>count($legacy),'total_mass_kg'=>$legacyMass],
            'delta'=>[
                'request_count'=>count($new)-count($legacy),
                'total_mass_kg'=>round($newMass-$legacyMass, 3),
            ],
            'only_new'=>$onlyNew,
            'only_new_summary'=>['request_count'=>count($onlyNew),'total_mass_kg'=>$onlyNewMass],
            'only_legacy'=>$onlyLegacy,
            'only_legacy_summary'=>['request_count'=>count($onlyLegacy),'total_mass_kg'=>$onlyLegacyMass],
            'mass_mismatches'=>$massMismatches,
            'mass_mismatch_count'=>count($massMismatches),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function currentRows(PDO $pdo): array
    {
        return $pdo->query("SELECT c.zayavka_id,
                                   COALESCE(r.mass_netto,0)*1000 mass_kg,
                                   c.source_block_id,
                                   COALESCE(b.name,'') source_block_name
                              FROM feo_placement_current c
                         LEFT JOIN feo_requests r ON r.zayavka_id=c.zayavka_id
                         LEFT JOIN feo_placement_blocks b ON b.id=c.source_block_id
                          ORDER BY c.zayavka_id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> */
    private function legacyColumns(PDO $pdo): array
    {
        $q = $pdo->query("SELECT COLUMN_NAME
                            FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='feo'
                           ORDER BY ORDINAL_POSITION");
        $columns = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
        if ($columns === []) throw new \RuntimeException('Legacy FEO table missing.');
        return $columns;
    }

    private function pickColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($columns as $column) {
                if (mb_strtolower(trim((string)$column), 'UTF-8') === mb_strtolower($candidate, 'UTF-8')) return (string)$column;
            }
        }
        return null;
    }

    /** @return list<array{zayavka_id:string,mass_netto:mixed,legacy_block_ids:list<int>}> */
    private function legacyRows(PDO $pdo, string $idColumn, string $massColumn): array
    {
        $blocks = $pdo->prepare("SELECT id,zayavki_ids
                                   FROM `status_blocks`
                                  WHERE id > ?
                               ORDER BY id");
        $blocks->execute([self::LEGACY_AVAILABLE_BLOCK_MIN_ID]);

        $blockIdsByRequest = [];
        foreach ($blocks->fetchAll(PDO::FETCH_ASSOC) as $block) {
            $blockId = (int)($block['id'] ?? 0);
            foreach (preg_split('/[,;\s]+/u', (string)($block['zayavki_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rawId) {
                $requestId = trim((string)$rawId);
                if ($requestId === '') continue;
                $blockIdsByRequest[$requestId][] = $blockId;
            }
        }
        if ($blockIdsByRequest === []) return [];

        foreach ($blockIdsByRequest as &$ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids, SORT_NUMERIC);
        }
        unset($ids);

        $id = $this->quoteIdentifier($idColumn);
        $mass = $this->quoteIdentifier($massColumn);
        $rows = [];
        $requestIds = array_keys($blockIdsByRequest);
        $found = [];
        foreach (array_chunk($requestIds, 800) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT TRIM(CAST({$id} AS CHAR)) zayavka_id, {$mass} mass_netto
                      FROM `feo`
                     WHERE {$id} IN ({$marks})
                  ORDER BY {$id}";
            $q = $pdo->prepare($sql);
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $requestId = trim((string)($row['zayavka_id'] ?? ''));
                if ($requestId === '') continue;
                $row['legacy_block_ids'] = $blockIdsByRequest[$requestId] ?? [];
                $rows[] = $row;
                $found[$requestId] = true;
            }
        }
        $missing = array_values(array_diff($requestIds, array_keys($found)));
        if ($missing !== []) {
            throw new \RuntimeException('Legacy status block references request IDs missing from feo.');
        }
        return $rows;
    }

    /** @return list<string> */
    private function duplicateIds(array $rows): array
    {
        $seen = [];
        $dupes = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['zayavka_id'] ?? ''));
            if ($id === '') continue;
            if (isset($seen[$id])) $dupes[$id] = true;
            $seen[$id] = true;
        }
        return array_map('strval', array_keys($dupes));
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function blockHistory(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), static fn(string $id): bool => $id !== '')));
        $out = [];
        foreach ($ids as $id) $out[$id] = [];
        foreach (array_chunk($ids, 800) as $chunk) {
            if ($chunk === []) continue;
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare("SELECT br.zayavka_id,b.id,b.name,b.block_type,b.status
                                  FROM feo_placement_block_requests br
                                  JOIN feo_placement_blocks b ON b.id=br.block_id
                                 WHERE br.zayavka_id IN ({$marks})
                              ORDER BY b.id DESC");
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (string)$row['zayavka_id'];
                $out[$id][] = [
                    'id'=>(int)$row['id'],
                    'name'=>(string)($row['name'] ?? ''),
                    'block_type'=>(string)$row['block_type'],
                    'status'=>(string)$row['status'],
                ];
            }
        }
        return $out;
    }

    private function legacyConnection(): ?PDO
    {
        if (!class_exists(WasteTripLegacySourceService::class)) {
            require_once base_path('app/Service/WasteTripLegacySourceService.php');
        }
        return WasteTripLegacySourceService::connectionFromEnvironment();
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function tenantCacheKey(PDO $pdo): string
    {
        try { $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn(); }
        catch (Throwable) { $db = (string)spl_object_id($pdo); }
        return 'env:' . $db;
    }

    private function cache(string $key, array $state): array
    {
        if ($key !== '') self::$requestCache[$key] = $state;
        return $state;
    }

    private function unavailable(string $message, array $extra = []): array
    {
        return $extra + [
            'available'=>false,
            'source_error'=>true,
            'is_consistent'=>false,
            'mismatch_count'=>0,
            'message'=>$message,
            'checked_at'=>date(DATE_ATOM),
            'new'=>['request_count'=>0,'total_mass_kg'=>0.0],
            'legacy'=>['request_count'=>0,'total_mass_kg'=>0.0],
            'delta'=>['request_count'=>0,'total_mass_kg'=>0.0],
            'only_new'=>[],
            'only_new_summary'=>['request_count'=>0,'total_mass_kg'=>0.0],
            'only_legacy'=>[],
            'only_legacy_summary'=>['request_count'=>0,'total_mass_kg'=>0.0],
            'mass_mismatches'=>[],
            'mass_mismatch_count'=>0,
            'legacy_filter'=>'Блоки старой базы: ID > ' . self::LEGACY_AVAILABLE_BLOCK_MIN_ID,
        ];
    }
}
