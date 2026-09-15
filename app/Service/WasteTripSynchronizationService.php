<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

/** Explicit company-owner reconciliation; the existing hosting scheduler is separate. */
final class WasteTripSynchronizationService
{
    public static function run(PDO $legacy, PDO $tenant, PDO $central, int $companyId, array $actor): array
    {
        if (($actor['role_code'] ?? '') !== 'company_owner') throw new RuntimeException('Синхронизация доступна руководителю компании.');
        RegulatedDataScopeService::assertCanCreate('waste_trip');
        if (!WasteTripService::schemaReady($tenant)) throw new RuntimeException('Для синхронизации требуется актуальная схема рейсов (136).');
        if ($companyId <= 0) throw new RuntimeException('Компания не выбрана.');
        $name = (string)$tenant->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'erp_waste_trip_legacy_sync_' . substr(hash('sha256', $name), 0, 24);
        $lock = $tenant->prepare('SELECT GET_LOCK(?,0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Синхронизация уже выполняется. Повторите позже.');
        try {
            // No pagination/LIMIT: deletion may use only a completely fetched source set.
            // A connection/query/fetch failure escapes before any tenant mutations.
            $flights = $legacy->query(
                'SELECT f.id,f.zayavki_ids,f.comment,f.status,f.cost,f.payment_terms,f.driver_id,
                 f.planned_start_date,f.planned_start_date_from,f.planned_start_date_to,
                 f.actual_start_date,f.actual_end_date,f.unload_type,f.assigned_manager_id,
                 u.`Фамилия` AS manager_surname,u.`Имя` AS manager_first_name,
                 d.full_name AS driver_name,d.vehicle_make_plate
                 FROM flights f LEFT JOIN drivers d ON d.id=f.driver_id
                 LEFT JOIN users u ON u.id=f.assigned_manager_id WHERE f.id > 225 ORDER BY f.id'
            )->fetchAll(PDO::FETCH_ASSOC);
            $ids = [];
            foreach ($flights as $flight) {
                $id = filter_var($flight['id'], FILTER_VALIDATE_INT);
                if ($id === false || $id < 226 || isset($ids[$id])) throw new RuntimeException('Некорректный полный список ID старых рейсов; синхронизация остановлена.');
                $ids[$id] = true;
            }
            $candidates = self::executorCandidates($tenant, $actor);
            $clientId = WasteTripLegacyMappingService::customer(LinearRouteService::fetchVisibleClients($tenant, $actor));
            if ($clientId === null) throw new RuntimeException('В новой ERP не найден единственный активный заказчик ООО «МГ ЛОГИСТИК».');
            $memberships = EntityResponsibilityService::eligibleRecipients($central, $tenant, $companyId);
            $managerIds = [];
            $result = ['source_count'=>count($flights),'created'=>0,'updated'=>0,'deleted'=>0,'failed'=>0,'warnings'=>[],'errors'=>[]];
            $existing = $tenant->query("SELECT * FROM waste_trips WHERE source_system IN ('legacy_flights','legacy_prefill','legacy_sync') AND legacy_flight_id > 225 ORDER BY business_id")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($existing as $trip) {
                if (isset($ids[(int)$trip['legacy_flight_id']]) || !empty($trip['deleted_at'])) continue;
                try {
                    WasteTripService::deleteMissingLegacyTrip($tenant, (int)$trip['id'], $actor);
                    $result['deleted']++;
                } catch (Throwable $error) {
                    self::recordFailure($result, (int)$trip['legacy_flight_id'], $error, 'delete');
                }
            }
            foreach ($flights as $flight) {
                $id = (int)$flight['id'];
                try {
                    $state = WasteTripLegacyMappingService::state($flight);
                    $state['client_id'] = $clientId;
                    $membershipId = WasteTripLegacyMappingService::manager((string)$flight['manager_surname'], (string)$flight['manager_first_name'], $memberships);
                    if ($membershipId === null) throw new RuntimeException('Менеджер не найден однозначно в новой ERP: '.trim($flight['manager_surname'].' '.$flight['manager_first_name']).'.');
                    $managerIds[$membershipId] ??= (new UserSyncService([]))->resolveMembershipTenantUserId($central, $tenant, $companyId, $membershipId);
                    $state['responsible_user_id'] = $managerIds[$membershipId];
                    $state['destination_type'] = match ((string)$flight['unload_type']) {
                        'SKLAD' => 'warehouse', 'OO' => 'utilizer',
                        default => throw new RuntimeException('Неизвестное назначение выгрузки в старой БД.'),
                    };
                    $executor = WasteTripLegacyMappingService::executor($flight, $candidates);
                    $state['route_executor_id'] = $executor['id'];
                    $state['executor_warning'] = $executor['warning'];
                    $outcome = WasteTripService::reconcileLegacyTrip($tenant, $state, $actor);
                    $result[$outcome]++;
                    if ($executor['warning'] !== null) $result['warnings'][] = 'Рейс #'.$id.': '.$executor['warning'];
                } catch (Throwable $error) {
                    self::recordFailure($result, $id, $error, 'upsert');
                }
            }
            return $result;
        } finally {
            $release = $tenant->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private static function executorCandidates(PDO $pdo, array $actor): array
    {
        $visible = LinearRouteService::fetchVisibleRouteExecutors($pdo, $actor);
        if ($visible === []) return [];
        $ids = array_column($visible, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $query = $pdo->prepare("SELECT c.id AS crew_id,d.full_name,vu.plate_number AS primary_plate
            FROM crews c JOIN crew_drivers cd ON cd.crew_id=c.id JOIN drivers d ON d.id=cd.driver_id
            JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id
            JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id
            JOIN vehicle_units vu ON vu.id=vs.primary_vehicle_unit_id
            WHERE c.id IN ({$marks}) AND c.status='active'");
        $query->execute($ids);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function recordFailure(array &$result, int $id, Throwable $error, string $operation): void
    {
        $result['failed']++;
        $message = $error instanceof RuntimeException && !$error instanceof \PDOException
            ? $error->getMessage()
            : 'Ошибка синхронизации. Код: '.WasteTripIncidentService::record($error, ['legacy_id'=>$id,'operation'=>'legacy_sync_'.$operation]);
        $result['errors'][] = 'Рейс #'.$id.': '.$message;
    }
}
