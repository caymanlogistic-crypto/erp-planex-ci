<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class WasteTripLegacyPrefillService
{
    public const SOURCE_SYSTEM = 'legacy_prefill';

    public static function createFromLegacyPrefill(PDO $legacyPdo, PDO $pdo, int $legacyId, array $input, array $requestBusinessIds, array $sessionUser): int
    {
        if ($legacyId < WasteTripLegacySourceService::MIN_LEGACY_ID) {
            throw new RuntimeException('Legacy ID должен быть больше 243.');
        }
        if (WasteTripLegacySourceService::fetchFlight($legacyPdo, $legacyId) === null) {
            throw new RuntimeException('Legacy-рейс #' . $legacyId . ' не найден.');
        }

        $data = WasteTripService::normalizeInput($input);
        $errors = WasteTripService::validateInput($data);
        $requestBusinessIds = array_values(array_unique(array_map('strval', $requestBusinessIds)));
        if ($requestBusinessIds === []) $errors[] = 'Добавьте хотя бы одну существующую заявку ФЭО.';
        if ($errors !== []) throw new RuntimeException(implode(' ', $errors));
        self::assertEntitiesVisible($pdo, $data, $sessionUser);

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare("SELECT id FROM waste_trips WHERE business_id=? OR (legacy_flight_id=? AND source_system IN ('legacy_flights','legacy_prefill')) FOR UPDATE");
            $dup->execute([$legacyId, $legacyId]);
            if ($dup->fetchColumn() !== false) {
                throw new RuntimeException('Этот legacy-рейс уже перенесён или его business ID уже занят.');
            }

            $resolved = self::resolveRequestsForClaim($pdo, $requestBusinessIds);
            self::assertNoActiveClaims($pdo, array_column($resolved, 'id'));
            $tripId = self::insertTrip($pdo, $legacyId, $data, $legacyId, $sessionUser);
            self::insertRequestLinks($pdo, $tripId, $resolved);
            self::logAudit($pdo, $tripId, $legacyId, $data, $requestBusinessIds, $sessionUser);
            $pdo->commit();
            return $legacyId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function assertEntitiesVisible(PDO $pdo, array $data, array $sessionUser): void
    {
        if (!LinearRouteService::isVisibleEntity($pdo, 'clients', (int) $data['client_id'], $sessionUser, 'client')) {
            throw new RuntimeException('Заказчик недоступен или неактивен.');
        }
        if (!empty($data['route_executor_id'])) {
            $visibleIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), LinearRouteService::fetchVisibleRouteExecutors($pdo, $sessionUser));
            if (!in_array((int) $data['route_executor_id'], $visibleIds, true)) {
                throw new RuntimeException('Исполнитель рейса недоступен или неактивен.');
            }
        }
        $user = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND status='active' AND deleted_at IS NULL");
        $user->execute([(int) $data['responsible_user_id']]);
        if ((int) $user->fetchColumn() !== 1) throw new RuntimeException('Ответственный сотрудник недоступен или неактивен.');
        if (($data['destination_type'] ?? '') === 'utilizer') {
            $util = $pdo->prepare('SELECT COUNT(*) FROM feo_utilizers WHERE id=? AND is_active=1');
            $util->execute([(int) $data['utilizer_id']]);
            if ((int) $util->fetchColumn() !== 1) throw new RuntimeException('Утилизатор недоступен или неактивен.');
        }
    }

    private static function resolveRequestsForClaim(PDO $pdo, array $businessIds): array
    {
        $sorted = $businessIds;
        sort($sorted, SORT_STRING);
        $marks = implode(',', array_fill(0, count($sorted), '?'));
        $stmt = $pdo->prepare("SELECT fr.id,fr.zayavka_id,fr.mass_netto FROM feo_requests fr WHERE fr.zayavka_id IN ({$marks}) ORDER BY fr.id FOR UPDATE");
        $stmt->execute($sorted);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['snapshot_mass_kg'] = WasteTripService::massKg($row['mass_netto'] ?? null);
            $map[(string) $row['zayavka_id']] = $row;
        }
        $missing = array_values(array_filter($businessIds, static fn(string $id): bool => !isset($map[$id])));
        if ($missing !== []) throw new RuntimeException('Заявки отсутствуют в canonical FEO: ' . implode(', ', $missing) . '.');
        return array_map(static fn(string $id): array => $map[$id], $businessIds);
    }

    private static function assertNoActiveClaims(PDO $pdo, array $requestIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
        if ($ids === []) return;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT wtr.feo_request_id,wt.id AS waste_trip_id,fr.zayavka_id FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.feo_request_id IN ({$marks}) AND wt.status<>'cancelled' ORDER BY wtr.feo_request_id FOR UPDATE");
        $stmt->execute($ids);
        $labels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $labels[] = (string) $row['zayavka_id'] . ' (рейс #' . (int) $row['waste_trip_id'] . ')';
        if ($labels !== []) throw new RuntimeException('Заявки уже включены в активный рейс: ' . implode(', ', $labels) . '.');
    }

    private static function insertTrip(PDO $pdo, int $businessId, array $data, int $legacyId, array $sessionUser): int
    {
        $stmt = $pdo->prepare('INSERT INTO waste_trips (business_id,client_id,route_executor_id,responsible_user_id,destination_type,utilizer_id,status,planned_start_mode,planned_start_date,planned_start_date_from,planned_start_date_to,planned_finish_date,actual_start_date,actual_end_date,comment,cancellation_reason,source_system,legacy_flight_id,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $actor = (int) ($sessionUser['user_id'] ?? 0) ?: null;
        $role = (string) ($sessionUser['role_code'] ?? '');
        $stmt->execute([
            $businessId,$data['client_id'],$data['route_executor_id'],$data['responsible_user_id'],$data['destination_type'],$data['utilizer_id'],$data['status'],$data['planned_start_mode'],$data['planned_start_date'],$data['planned_start_date_from'],$data['planned_start_date_to'],$data['planned_finish_date'],$data['actual_start_date'],$data['actual_end_date'],$data['comment'],$data['cancellation_reason'],self::SOURCE_SYSTEM,$legacyId,$actor,$role,$actor,$role,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private static function insertRequestLinks(PDO $pdo, int $tripId, array $resolvedRows): void
    {
        $stmt = $pdo->prepare('INSERT INTO waste_trip_requests (waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES (?,?,?,?)');
        foreach (array_values($resolvedRows) as $position => $row) {
            $stmt->execute([$tripId, (int) $row['id'], number_format((float) $row['snapshot_mass_kg'], 3, '.', ''), $position]);
        }
    }

    private static function logAudit(PDO $pdo, int $tripId, int $legacyId, array $data, array $requestIds, array $sessionUser): void
    {
        $after = json_encode(['business_id'=>$legacyId,'source_system'=>self::SOURCE_SYSTEM,'legacy_flight_id'=>$legacyId,'trip'=>$data,'request_ids'=>$requestIds], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare('INSERT INTO waste_trip_audit_log (waste_trip_id,action,before_json,after_json,actor_user_id,actor_role) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$tripId,'create',null,$after,(int)($sessionUser['user_id']??0)?:null,(string)($sessionUser['role_code']??'')]);
    }
}
