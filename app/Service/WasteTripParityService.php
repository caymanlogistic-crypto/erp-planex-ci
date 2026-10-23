<?php

namespace App\Service;

use PDO;
use Throwable;

final class WasteTripParityService
{
    public static function attentionCount(PDO $tenant, ?PDO $legacyPdo = null): int
    {
        $state = self::state($tenant, $legacyPdo);
        if (empty($state['available'])) return 1;

        return count(array_unique(array_merge(
            $state['missing_ids'], $state['extra_ids'],
            $state['status_mismatch_ids'], $state['sync_error_ids']
        )));
    }

    public static function state(PDO $tenant, ?PDO $legacyPdo = null): array
    {
        try {
            $legacy = $legacyPdo ?? WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacy instanceof PDO) return ['available'=>false,'message'=>'Подключение к старой базе не настроено.'];
            $sourceRows = $legacy->query('SELECT id,status FROM flights WHERE id > 225 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            $sourceIds = array_map('intval', array_column($sourceRows, 'id'));
            $sourceStatuses = [];
            foreach ($sourceRows as $row) $sourceStatuses[(int)$row['id']] = (string)$row['status'];
            $rows = $tenant->query("SELECT business_id,legacy_flight_id,source_system,status,legacy_sync_error FROM waste_trips WHERE deleted_at IS NULL ORDER BY business_id")->fetchAll(PDO::FETCH_ASSOC);
            $linkedIds = [];
            $independentCount = 0;
            $statusMismatchIds = [];
            $syncErrorIds = [];
            foreach ($rows as $row) {
                if (!WasteTripService::isSynchronizableLegacyTrip($row)) { $independentCount++; continue; }
                $id = (int)$row['legacy_flight_id'];
                $linkedIds[] = $id;
                if (!isset($sourceStatuses[$id])) continue;
                try { $expected = WasteTripLegacyMappingService::status($sourceStatuses[$id]); }
                catch (Throwable) { $expected = null; }
                if ($expected === null || $expected !== (string)$row['status']) $statusMismatchIds[] = $id;
                if (trim((string)($row['legacy_sync_error'] ?? '')) !== '') $syncErrorIds[] = $id;
            }
            $missing = array_values(array_diff($sourceIds, $linkedIds));
            $extra = array_values(array_diff($linkedIds, $sourceIds));
            sort($missing, SORT_NUMERIC);
            sort($extra, SORT_NUMERIC);
            sort($statusMismatchIds, SORT_NUMERIC);
            sort($syncErrorIds, SORT_NUMERIC);
            return [
                'available'=>true,
                'legacy_count'=>count($sourceIds),
                'erp_linked_count'=>count($linkedIds),
                'erp_independent_count'=>$independentCount,
                'missing_ids'=>$missing,
                'extra_ids'=>$extra,
                'status_mismatch_ids'=>$statusMismatchIds,
                'sync_error_ids'=>$syncErrorIds,
                'mismatch'=>count($sourceIds)!==count($linkedIds) || $missing!==[] || $extra!==[] || $statusMismatchIds!==[] || $syncErrorIds!==[],
            ];
        } catch (Throwable $error) {
            error_log('waste trip parity failed: '.$error->getMessage());
            return ['available'=>false,'message'=>'Не удалось прочитать старую базу для сверки рейсов.'];
        }
    }
}
