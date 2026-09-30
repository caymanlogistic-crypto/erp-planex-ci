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

        return count($state['missing_ids']) + count($state['extra_ids']);
    }

    public static function state(PDO $tenant, ?PDO $legacyPdo = null): array
    {
        try {
            $legacy = $legacyPdo ?? WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacy instanceof PDO) return ['available'=>false,'message'=>'Подключение к старой базе не настроено.'];
            $sourceIds = array_map('intval', $legacy->query('SELECT id FROM flights WHERE id > 225 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            $rows = $tenant->query("SELECT business_id,legacy_flight_id,source_system FROM waste_trips WHERE deleted_at IS NULL ORDER BY business_id")->fetchAll(PDO::FETCH_ASSOC);
            $linkedIds = [];
            $independentCount = 0;
            foreach ($rows as $row) {
                if (WasteTripService::isSynchronizableLegacyTrip($row)) $linkedIds[] = (int)$row['legacy_flight_id'];
                else $independentCount++;
            }
            $missing = array_values(array_diff($sourceIds, $linkedIds));
            $extra = array_values(array_diff($linkedIds, $sourceIds));
            sort($missing, SORT_NUMERIC);
            sort($extra, SORT_NUMERIC);
            return [
                'available'=>true,
                'legacy_count'=>count($sourceIds),
                'erp_linked_count'=>count($linkedIds),
                'erp_independent_count'=>$independentCount,
                'missing_ids'=>$missing,
                'extra_ids'=>$extra,
                'mismatch'=>count($sourceIds)!==count($linkedIds) || $missing!==[] || $extra!==[],
            ];
        } catch (Throwable $error) {
            error_log('waste trip parity failed: '.$error->getMessage());
            return ['available'=>false,'message'=>'Не удалось прочитать старую базу для сверки рейсов.'];
        }
    }
}
