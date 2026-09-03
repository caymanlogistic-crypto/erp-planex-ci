<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class WasteTripLegacySyncService
{
    private const MAX_RUNTIME_SECONDS = 240;
    private const MAX_TRIPS = 500;

    public static function materialize(PDO $legacyPdo, PDO $tenantPdo, int $legacyId, array $operationalInput, array $sessionUser): int
    {
        WasteTripService::assertNamespaceSafe($tenantPdo, $legacyPdo);
        $flight = WasteTripLegacySourceService::fetchFlight($legacyPdo, $legacyId);
        if ($flight === null) throw new RuntimeException('Legacy-рейс не найден.');
        $state = WasteTripLegacySourceService::authoritativeState($flight);
        if ((int) $state['business_id'] !== $legacyId) throw new RuntimeException('Legacy identity mismatch.');
        return WasteTripService::materializeLinked($tenantPdo, $legacyId, $operationalInput, $state, $sessionUser);
    }

    public static function syncAll(PDO $legacyPdo, PDO $tenantPdo): array
    {
        $databaseName = (string) $tenantPdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'erp_waste_trip_legacy_sync_' . substr(hash('sha256', $databaseName), 0, 24);
        $lock = $tenantPdo->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) return ['skipped_overlap'=>true,'checked'=>0,'success'=>0,'failed'=>0];
        $started=microtime(true);$checked=0;$success=0;$failed=0;
        try {
            WasteTripService::assertNamespaceSafe($tenantPdo,$legacyPdo);
            foreach(WasteTripService::linkedTripsForSync($tenantPdo,self::MAX_TRIPS)as$trip){
                if((microtime(true)-$started)>=self::MAX_RUNTIME_SECONDS)break;
                $tripId=(int)$trip['id'];$legacyId=(int)$trip['legacy_flight_id'];$checked++;
                try{
                    $flight=WasteTripLegacySourceService::fetchFlight($legacyPdo,$legacyId);
                    if($flight===null){WasteTripService::markLegacySyncError($tenantPdo,$tripId,'Legacy source missing: flights.id='.$legacyId);$failed++;continue;}
                    $state=WasteTripLegacySourceService::authoritativeState($flight);WasteTripService::syncLegacyOwnedState($tenantPdo,$tripId,$state);$success++;
                }catch(Throwable $e){WasteTripService::markLegacySyncError($tenantPdo,$tripId,$e->getMessage());error_log('Waste Trip legacy sync failed trip='.$tripId.' legacy='.$legacyId.': '.$e->getMessage());$failed++;}
            }
            return['skipped_overlap'=>false,'checked'=>$checked,'success'=>$success,'failed'=>$failed];
        } finally { try{$release=$tenantPdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable){} }
    }
}
