<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class WasteTripService
{
    public const STATUS_SEARCHING = 'searching_executor';
    public const STATUS_ASSIGNED = 'executor_assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::STATUS_SEARCHING => 'Поиск исполнителя',
        self::STATUS_ASSIGNED => 'Исполнитель назначен',
        self::STATUS_IN_PROGRESS => 'Вывоз начался',
        self::STATUS_COMPLETED => 'Груз сдан',
        self::STATUS_CANCELLED => 'Отменён',
    ];

    private const TRANSITIONS = [
        self::STATUS_SEARCHING => [self::STATUS_SEARCHING, self::STATUS_ASSIGNED, self::STATUS_CANCELLED],
        self::STATUS_ASSIGNED => [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [self::STATUS_COMPLETED],
        self::STATUS_CANCELLED => [self::STATUS_CANCELLED],
    ];

    public static function schemaReady(PDO $pdo): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('waste_trips','waste_trip_requests','waste_trip_legacy_import_audits','waste_trip_audit_log')"
        );
        $stmt->execute();
        return (int) $stmt->fetchColumn() === 4;
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    public static function statusBadgeClass(string $status): string
    {
        return match ($status) {
            self::STATUS_COMPLETED => 'badge badge-ok',
            self::STATUS_IN_PROGRESS, self::STATUS_ASSIGNED => 'badge badge-warning',
            self::STATUS_CANCELLED => 'badge badge-danger',
            default => 'badge badge-neutral',
        };
    }

    public static function parseRequestIds(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $ids = [];
        $seen = [];
        $duplicates = [];
        $invalid = [];
        foreach ($parts as $part) {
            $id = trim((string) $part);
            if ($id === '' || !preg_match('/^[A-Za-zА-Яа-яЁё0-9._\/-]+$/u', $id)) {
                if ($id !== '') {
                    $invalid[] = $id;
                }
                continue;
            }
            if (isset($seen[$id])) {
                $duplicates[] = $id;
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }
        return [
            'ids' => $ids,
            'duplicates' => array_values(array_unique($duplicates)),
            'invalid' => array_values(array_unique($invalid)),
        ];
    }

    public static function normalizeInput(array $input): array
    {
        return [
            'client_id' => (int) ($input['client_id'] ?? 0),
            'route_executor_id' => (int) ($input['route_executor_id'] ?? 0) ?: null,
            'responsible_user_id' => (int) ($input['responsible_user_id'] ?? 0),
            'destination_type' => trim((string) ($input['destination_type'] ?? '')),
            'utilizer_id' => (int) ($input['utilizer_id'] ?? 0) ?: null,
            'status' => trim((string) ($input['status'] ?? self::STATUS_SEARCHING)),
            'planned_start_mode' => trim((string) ($input['planned_start_mode'] ?? '')),
            'planned_start_date' => self::normalizeDate($input['planned_start_date'] ?? null),
            'planned_start_date_from' => self::normalizeDate($input['planned_start_date_from'] ?? null),
            'planned_start_date_to' => self::normalizeDate($input['planned_start_date_to'] ?? null),
            'actual_start_date' => self::normalizeDate($input['actual_start_date'] ?? null),
            'actual_end_date' => self::normalizeDate($input['actual_end_date'] ?? null),
            'comment' => self::nullableText($input['comment'] ?? null, 500),
            'cancellation_reason' => self::nullableText($input['cancellation_reason'] ?? null, 500),
        ];
    }

    public static function validateInput(array $data, bool $allowUnspecifiedPlan = false): array
    {
        $errors = [];
        if ((int) ($data['client_id'] ?? 0) <= 0) {
            $errors[] = 'Выберите заказчика.';
        }
        if ((int) ($data['responsible_user_id'] ?? 0) <= 0) {
            $errors[] = 'Выберите ответственного сотрудника.';
        }

        $status = (string) ($data['status'] ?? '');
        if (!array_key_exists($status, self::STATUS_LABELS)) {
            $errors[] = 'Недопустимый статус рейса.';
        }
        $executorId = (int) ($data['route_executor_id'] ?? 0);
        if (in_array($status, [self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED], true) && $executorId <= 0) {
            $errors[] = 'Для выбранного статуса требуется исполнитель рейса.';
        }

        $destination = (string) ($data['destination_type'] ?? '');
        if (!in_array($destination, ['utilizer', 'warehouse'], true)) {
            $errors[] = 'Выберите назначение выгрузки.';
        } elseif ($destination === 'utilizer' && (int) ($data['utilizer_id'] ?? 0) <= 0) {
            $errors[] = 'Для выгрузки у утилизатора выберите утилизатора.';
        } elseif ($destination === 'warehouse' && !empty($data['utilizer_id'])) {
            $errors[] = 'Для выгрузки на склад утилизатор не указывается.';
        }

        $mode = (string) ($data['planned_start_mode'] ?? '');
        $exact = $data['planned_start_date'] ?? null;
        $from = $data['planned_start_date_from'] ?? null;
        $to = $data['planned_start_date_to'] ?? null;
        if ($mode === 'exact') {
            if ($exact === null) {
                $errors[] = 'Для точной плановой даты укажите дату.';
            }
            if ($from !== null || $to !== null) {
                $errors[] = 'Для точной даты диапазон не заполняется.';
            }
        } elseif ($mode === 'range') {
            if ($from === null || $to === null) {
                $errors[] = 'Для диапазона нужны обе границы дат.';
            }
            if ($exact !== null) {
                $errors[] = 'Для диапазона точная дата не заполняется.';
            }
            if ($from !== null && $to !== null && $from > $to) {
                $errors[] = 'Начало планового диапазона позже окончания.';
            }
        } elseif (!($allowUnspecifiedPlan && $mode === 'unspecified' && $exact === null && $from === null && $to === null)) {
            $errors[] = 'Выберите режим плановой даты: точная дата или диапазон.';
        }

        $actualStart = $data['actual_start_date'] ?? null;
        $actualEnd = $data['actual_end_date'] ?? null;
        if ($actualStart !== null && $actualEnd !== null && $actualStart > $actualEnd) {
            $errors[] = 'Фактическая дата начала позже даты окончания.';
        }
        if ($status === self::STATUS_IN_PROGRESS && $actualStart === null) {
            $errors[] = 'Для статуса «Вывоз начался» укажите фактическую дату начала.';
        }
        if ($status === self::STATUS_COMPLETED && ($actualStart === null || $actualEnd === null)) {
            $errors[] = 'Для завершённого рейса нужны обе фактические даты.';
        }
        if ($status === self::STATUS_CANCELLED && trim((string) ($data['cancellation_reason'] ?? '')) === '') {
            $errors[] = 'Для отмены рейса укажите причину.';
        }

        return $errors;
    }

    public static function allowedStatusTransitions(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!isset(self::TRANSITIONS[$from]) || !in_array($to, self::TRANSITIONS[$from], true)) {
            throw new RuntimeException('Недопустимый переход статуса: ' . self::statusLabel($from) . ' → ' . self::statusLabel($to) . '.');
        }
    }

    public static function create(PDO $pdo, array $input, array $requestBusinessIds, array $sessionUser, ?array $legacyEvidence = null): int
    {
        $data = self::normalizeInput($input);
        $errors = self::validateInput($data, $legacyEvidence !== null);
        if ($requestBusinessIds === []) {
            $errors[] = 'Добавьте хотя бы одну заявку ФЭО.';
        }
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        self::assertEntitiesVisible($pdo, $data, $sessionUser);
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $resolved = $legacyEvidence === null
                ? self::resolveCurrentRequestsForClaim($pdo, $requestBusinessIds)
                : self::resolveRequestsForClaim($pdo, $requestBusinessIds, false);
            self::assertNoActiveClaims($pdo, array_column($resolved, 'id'));

            $insert = $pdo->prepare(
                "INSERT INTO waste_trips
                 (client_id, route_executor_id, responsible_user_id, destination_type, utilizer_id, status, planned_start_mode,
                  planned_start_date, planned_start_date_from, planned_start_date_to, actual_start_date, actual_end_date,
                  comment, cancellation_reason, source_system, legacy_flight_id,
                  created_by_user_id, created_by_role, updated_by_user_id, updated_by_role)
                 VALUES
                 (:client_id, :route_executor_id, :responsible_user_id, :destination_type, :utilizer_id, :status, :planned_start_mode,
                  :planned_start_date, :planned_start_date_from, :planned_start_date_to, :actual_start_date, :actual_end_date,
                  :comment, :cancellation_reason, :source_system, :legacy_flight_id,
                  :created_user_id, :created_role_code, :updated_user_id, :updated_role_code)"
            );
            $insert->execute([
                ':client_id' => $data['client_id'],
                ':route_executor_id' => $data['route_executor_id'],
                ':responsible_user_id' => $data['responsible_user_id'],
                ':destination_type' => $data['destination_type'],
                ':utilizer_id' => $data['utilizer_id'],
                ':status' => $data['status'],
                ':planned_start_mode' => $data['planned_start_mode'],
                ':planned_start_date' => $data['planned_start_date'],
                ':planned_start_date_from' => $data['planned_start_date_from'],
                ':planned_start_date_to' => $data['planned_start_date_to'],
                ':actual_start_date' => $data['actual_start_date'],
                ':actual_end_date' => $data['actual_end_date'],
                ':comment' => $data['comment'],
                ':cancellation_reason' => $data['cancellation_reason'],
                ':source_system' => $legacyEvidence['source_system'] ?? null,
                ':legacy_flight_id' => $legacyEvidence['legacy_flight_id'] ?? null,
                ':created_user_id' => (int) ($sessionUser['user_id'] ?? 0) ?: null,
                ':created_role_code' => (string) ($sessionUser['role_code'] ?? ''),
                ':updated_user_id' => (int) ($sessionUser['user_id'] ?? 0) ?: null,
                ':updated_role_code' => (string) ($sessionUser['role_code'] ?? ''),
            ]);
            $tripId = (int) $pdo->lastInsertId();

            self::insertRequestLinks($pdo, $tripId, $resolved);
            self::logAudit($pdo, $tripId, $legacyEvidence !== null ? 'import' : 'create', null, [
                'trip' => $data,
                'request_ids' => array_values($requestBusinessIds),
                'snapshot_total_mass_kg' => round(array_sum(array_map(static fn(array $row): float => (float) $row['snapshot_mass_kg'], $resolved)), 3),
            ], $sessionUser);
            if ($legacyEvidence !== null) {
                self::insertLegacyAudit($pdo, $tripId, $legacyEvidence, $data, $resolved, $sessionUser);
            }

            if ($ownTransaction) {
                $pdo->commit();
            }
            return $tripId;
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (self::isDuplicateKey($e) && $legacyEvidence !== null) {
                throw new RuntimeException('Этот legacy-рейс уже импортирован.', 0, $e);
            }
            throw $e;
        }
    }

    public static function update(PDO $pdo, int $tripId, array $input, array $requestBusinessIds, int $expectedVersion, array $sessionUser): void
    {
        if ($tripId <= 0 || $expectedVersion <= 0) {
            throw new RuntimeException('Некорректная версия рейса. Обновите страницу и повторите действие.');
        }
        if ($requestBusinessIds === []) {
            throw new RuntimeException('Добавьте хотя бы одну заявку ФЭО.');
        }

        $pdo->beginTransaction();
        try {
            $trip = self::fetchTripForUpdate($pdo, $tripId);
            if ($trip === null) {
                throw new RuntimeException('Рейс не найден.');
            }
            self::assertCanAccessTrip($pdo, $trip, $sessionUser);
            if ((int) $trip['lock_version'] !== $expectedVersion) {
                throw new RuntimeException('Рейс уже был изменён другим пользователем. Обновите страницу.');
            }

            $data = self::normalizeInput($input);
            $allowUnspecifiedPlan = ($trip['source_system'] ?? null) !== null && ($trip['planned_start_mode'] ?? '') === 'unspecified';
            $errors = self::validateInput($data, $allowUnspecifiedPlan);
            if ($errors !== []) {
                throw new RuntimeException(implode(' ', $errors));
            }
            self::assertEntitiesVisible($pdo, $data, $sessionUser);
            self::assertTransition((string) $trip['status'], $data['status']);

            $existingLinks = self::fetchRequestLinks($pdo, $tripId, true);
            $existingByBusinessId = [];
            foreach ($existingLinks as $link) {
                $existingByBusinessId[(string) $link['zayavka_id']] = $link;
            }
            $oldRequestIds = array_values(array_keys($existingByBusinessId));
            $compositionChanged = $oldRequestIds !== array_values($requestBusinessIds);
            if ($compositionChanged) {
                $oldStatus = (string) $trip['status'];
                if ($oldStatus === self::STATUS_COMPLETED || $oldStatus === self::STATUS_CANCELLED) {
                    throw new RuntimeException('Состав завершённого или отменённого рейса менять нельзя.');
                }
                if ($oldStatus === self::STATUS_IN_PROGRESS) {
                    $confirmed = (string) ($input['confirm_composition_correction'] ?? '') === '1';
                    if (($sessionUser['role_code'] ?? '') !== 'company_owner' || !$confirmed) {
                        throw new RuntimeException('После начала вывоза изменение состава разрешено только руководителю с явным подтверждением корректировки.');
                    }
                }
            }

            $submittedSet = array_fill_keys($requestBusinessIds, true);
            $newBusinessIds = array_values(array_filter(
                $requestBusinessIds,
                static fn(string $id): bool => !isset($existingByBusinessId[$id])
            ));
            if ($newBusinessIds !== []) {
                $resolvedNew = self::resolveCurrentRequestsForClaim($pdo, $newBusinessIds);
                self::assertNoActiveClaims($pdo, array_column($resolvedNew, 'id'), $tripId);
                self::insertRequestLinks($pdo, $tripId, $resolvedNew, count($existingLinks));
            }

            $removeIds = [];
            foreach ($existingByBusinessId as $businessId => $link) {
                if (!isset($submittedSet[$businessId])) {
                    $removeIds[] = (int) $link['id'];
                }
            }
            if ($removeIds !== []) {
                $placeholders = implode(',', array_fill(0, count($removeIds), '?'));
                $delete = $pdo->prepare("DELETE FROM waste_trip_requests WHERE waste_trip_id = ? AND id IN ({$placeholders})");
                $delete->execute(array_merge([$tripId], $removeIds));
            }
            self::renumberLinks($pdo, $tripId, $requestBusinessIds);

            $update = $pdo->prepare(
                "UPDATE waste_trips SET
                    client_id=:client_id, route_executor_id=:route_executor_id, responsible_user_id=:responsible_user_id,
                    destination_type=:destination_type, utilizer_id=:utilizer_id, status=:status, planned_start_mode=:planned_start_mode,
                    planned_start_date=:planned_start_date, planned_start_date_from=:planned_start_date_from,
                    planned_start_date_to=:planned_start_date_to, actual_start_date=:actual_start_date, actual_end_date=:actual_end_date,
                    comment=:comment, cancellation_reason=:cancellation_reason,
                    updated_by_user_id=:user_id, updated_by_role=:role_code, lock_version=lock_version+1
                  WHERE id=:id AND lock_version=:expected_version"
            );
            $update->execute([
                ':client_id' => $data['client_id'],
                ':route_executor_id' => $data['route_executor_id'],
                ':responsible_user_id' => $data['responsible_user_id'],
                ':destination_type' => $data['destination_type'],
                ':utilizer_id' => $data['utilizer_id'],
                ':status' => $data['status'],
                ':planned_start_mode' => $data['planned_start_mode'],
                ':planned_start_date' => $data['planned_start_date'],
                ':planned_start_date_from' => $data['planned_start_date_from'],
                ':planned_start_date_to' => $data['planned_start_date_to'],
                ':actual_start_date' => $data['actual_start_date'],
                ':actual_end_date' => $data['actual_end_date'],
                ':comment' => $data['comment'],
                ':cancellation_reason' => $data['cancellation_reason'],
                ':user_id' => (int) ($sessionUser['user_id'] ?? 0) ?: null,
                ':role_code' => (string) ($sessionUser['role_code'] ?? ''),
                ':id' => $tripId,
                ':expected_version' => $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Конфликт изменения рейса. Обновите страницу.');
            }

            $newLinks = self::fetchRequestLinks($pdo, $tripId, true);
            $before = [
                'trip' => $trip,
                'request_ids' => $oldRequestIds,
                'snapshot_total_mass_kg' => round(array_sum(array_map(static fn(array $row): float => (float) $row['snapshot_mass_kg'], $existingLinks)), 3),
            ];
            $after = [
                'trip' => $data,
                'request_ids' => array_values($requestBusinessIds),
                'snapshot_total_mass_kg' => round(array_sum(array_map(static fn(array $row): float => (float) $row['snapshot_mass_kg'], $newLinks)), 3),
            ];
            self::logAudit(
                $pdo,
                $tripId,
                $compositionChanged && (string) $trip['status'] === self::STATUS_IN_PROGRESS ? 'composition_correction' : 'update',
                $before,
                $after,
                $sessionUser
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function cancel(PDO $pdo, int $tripId, int $expectedVersion, string $reason, array $sessionUser): void
    {
        $reason = self::nullableText($reason, 500);
        if ($reason === null) {
            throw new RuntimeException('Укажите причину отмены рейса.');
        }
        $pdo->beginTransaction();
        try {
            $trip = self::fetchTripForUpdate($pdo, $tripId);
            if ($trip === null) {
                throw new RuntimeException('Рейс не найден.');
            }
            self::assertCanAccessTrip($pdo, $trip, $sessionUser);
            if ((int) $trip['lock_version'] !== $expectedVersion) {
                throw new RuntimeException('Рейс уже был изменён. Обновите страницу.');
            }
            self::assertTransition((string) $trip['status'], self::STATUS_CANCELLED);
            $stmt = $pdo->prepare(
                "UPDATE waste_trips
                    SET status='cancelled', cancellation_reason=?, cancelled_at=NOW(), cancelled_by_user_id=?, cancelled_by_role=?,
                        updated_by_user_id=?, updated_by_role=?, lock_version=lock_version+1
                  WHERE id=? AND lock_version=?"
            );
            $actorId = (int) ($sessionUser['user_id'] ?? 0) ?: null;
            $role = (string) ($sessionUser['role_code'] ?? '');
            $stmt->execute([$reason, $actorId, $role, $actorId, $role, $tripId, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Конфликт отмены рейса. Обновите страницу.');
            }
            self::logAudit($pdo, $tripId, 'cancel', ['trip' => $trip], [
                'status' => self::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
            ], $sessionUser);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function canAccessTrip(PDO $pdo, array $trip, array $sessionUser): bool
    {
        if (AccessControlService::canSeeAllCompanyData($sessionUser)) {
            return true;
        }
        $clientId = (int) ($trip['client_id'] ?? 0);
        if (!LinearRouteService::isVisibleEntity($pdo, 'clients', $clientId, $sessionUser, 'client')) {
            return false;
        }
        $executorId = (int) ($trip['route_executor_id'] ?? 0);
        if ($executorId > 0) {
            $visibleExecutors = LinearRouteService::fetchVisibleRouteExecutors($pdo, $sessionUser);
            foreach ($visibleExecutors as $executor) {
                if ((int) ($executor['id'] ?? 0) === $executorId) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    public static function previewSelection(PDO $pdo, array $requestBusinessIds): array
    {
        $ids = array_values(array_unique(array_map('strval', $requestBusinessIds)));
        if ($ids === []) {
            return ['input_count' => 0, 'found_count' => 0, 'missing' => [], 'occupied' => [], 'total_mass_kg' => 0.0, 'requests' => []];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT fr.id, fr.zayavka_id, fr.mass_netto, fr.naim_oo_gruzootpravitel, fr.mno_adres_pogruzki
               FROM feo_placement_current pc
               JOIN feo_requests fr ON fr.zayavka_id=pc.zayavka_id
              WHERE fr.zayavka_id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $byBusiness = [];
        foreach ($rows as $row) {
            $row['snapshot_mass_kg'] = self::massKg($row['mass_netto'] ?? null);
            $byBusiness[(string) $row['zayavka_id']] = $row;
        }
        $requestIds = array_values(array_map(static fn(array $row): int => (int) $row['id'], $rows));
        $claims = [];
        if ($requestIds !== []) {
            $claimPlaceholders = implode(',', array_fill(0, count($requestIds), '?'));
            $claimStmt = $pdo->prepare(
                "SELECT wtr.feo_request_id, fr.zayavka_id, wt.id AS waste_trip_id
                   FROM waste_trip_requests wtr
                   JOIN waste_trips wt ON wt.id=wtr.waste_trip_id
                   JOIN feo_requests fr ON fr.id=wtr.feo_request_id
                  WHERE wtr.feo_request_id IN ({$claimPlaceholders}) AND wt.status <> 'cancelled'"
            );
            $claimStmt->execute($requestIds);
            foreach ($claimStmt->fetchAll(PDO::FETCH_ASSOC) as $claim) {
                $claims[(string) $claim['zayavka_id']] = (int) $claim['waste_trip_id'];
            }
        }
        $ordered = [];
        $missing = [];
        $totalMass = 0.0;
        foreach ($ids as $id) {
            if (!isset($byBusiness[$id])) {
                $missing[] = $id;
                continue;
            }
            $row = $byBusiness[$id];
            $row['occupied_trip_id'] = $claims[$id] ?? null;
            $ordered[] = $row;
            $totalMass += (float) $row['snapshot_mass_kg'];
        }
        return [
            'input_count' => count($ids),
            'found_count' => count($ordered),
            'missing' => $missing,
            'occupied' => $claims,
            'total_mass_kg' => round($totalMass, 3),
            'requests' => $ordered,
        ];
    }

    public static function fetchTrips(PDO $pdo, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && isset(self::STATUS_LABELS[$status])) {
            $where[] = 'wt.status = ?';
            $params[] = $status;
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $term = '%' . $search . '%';
            $where[] = "(
                CAST(wt.id AS CHAR) LIKE ?
                OR CAST(wt.legacy_flight_id AS CHAR) LIKE ?
                OR c.name LIKE ?
                OR u.full_name LIKE ?
                OR ct.name LIKE ?
                OR fr.zayavka_id LIKE ?
                OR wt.status LIKE ?
                OR wt.destination_type LIKE ?
                OR CAST(wt.planned_start_date AS CHAR) LIKE ?
                OR CAST(wt.planned_start_date_from AS CHAR) LIKE ?
                OR CAST(wt.planned_start_date_to AS CHAR) LIKE ?
                OR CAST(wt.actual_start_date AS CHAR) LIKE ?
                OR CAST(wt.actual_end_date AS CHAR) LIKE ?
                OR EXISTS (
                    SELECT 1 FROM crew_drivers cd
                    JOIN drivers d ON d.id=cd.driver_id
                    WHERE cd.crew_id=wt.route_executor_id AND d.full_name LIKE ?
                )
                OR EXISTS (
                    SELECT 1 FROM crews sc
                    JOIN driver_vehicle_blocks sdvb ON sdvb.id=sc.driver_vehicle_block_id
                    JOIN vehicle_sets svs ON svs.id=sdvb.vehicle_set_id
                    LEFT JOIN vehicle_units sv1 ON sv1.id=svs.primary_vehicle_unit_id
                    LEFT JOIN vehicle_units sv2 ON sv2.id=svs.secondary_vehicle_unit_id
                    WHERE sc.id=wt.route_executor_id
                      AND (sv1.plate_number LIKE ? OR sv2.plate_number LIKE ? OR sv1.brand LIKE ? OR sv2.brand LIKE ?)
                )
            )";
            for ($i = 0; $i < 18; $i++) {
                $params[] = $term;
            }
        }

        $sql = "SELECT wt.*,
                       c.name AS client_name,
                       u.full_name AS responsible_name,
                       fu.short_name AS utilizer_short_name,
                       fu.full_name AS utilizer_full_name,
                       ct.name AS executor_contractor_name,
                       GROUP_CONCAT(DISTINCT fr.zayavka_id ORDER BY wtr.position_no, wtr.id SEPARATOR ', ') AS request_ids,
                       COUNT(DISTINCT wtr.id) AS request_count,
                       COALESCE(SUM(wtr.snapshot_mass_kg),0) AS snapshot_total_mass_kg
                  FROM waste_trips wt
                  JOIN clients c ON c.id = wt.client_id
                  JOIN users u ON u.id = wt.responsible_user_id
             LEFT JOIN feo_utilizers fu ON fu.id = wt.utilizer_id
             LEFT JOIN crews cr ON cr.id = wt.route_executor_id
             LEFT JOIN contractors ct ON ct.id = cr.contractor_id
             LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id = wt.id
             LEFT JOIN feo_requests fr ON fr.id = wtr.feo_request_id
                 WHERE " . implode(' AND ', $where) . "
              GROUP BY wt.id, c.name, u.full_name, fu.short_name, fu.full_name, ct.name
              ORDER BY wt.id DESC
                 LIMIT 500";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $trips = $stmt->fetchAll(PDO::FETCH_ASSOC);
        self::enrichExecutorDisplay($pdo, $trips);
        return $trips;
    }

    public static function fetchTrip(PDO $pdo, int $tripId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT wt.*, c.name AS client_name, u.full_name AS responsible_name,
                    fu.short_name AS utilizer_short_name, fu.full_name AS utilizer_full_name,
                    ct.name AS executor_contractor_name
               FROM waste_trips wt
               JOIN clients c ON c.id=wt.client_id
               JOIN users u ON u.id=wt.responsible_user_id
          LEFT JOIN feo_utilizers fu ON fu.id=wt.utilizer_id
          LEFT JOIN crews cr ON cr.id=wt.route_executor_id
          LEFT JOIN contractors ct ON ct.id=cr.contractor_id
              WHERE wt.id=? LIMIT 1"
        );
        $stmt->execute([$tripId]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($trip === null) {
            return null;
        }
        $rows = [$trip];
        self::enrichExecutorDisplay($pdo, $rows);
        $trip = $rows[0];
        $trip['requests'] = self::fetchRequestLinks($pdo, $tripId, false);
        $audit = $pdo->prepare(
            'SELECT source_system, legacy_flight_id, mapping_json, snapshot_request_count, snapshot_total_mass_kg,
                    mass_delta_kg, warnings_json, legacy_total_mass_kg, legacy_cost, legacy_payment_terms, created_at
               FROM waste_trip_legacy_import_audits WHERE waste_trip_id=? LIMIT 1'
        );
        $audit->execute([$tripId]);
        $trip['legacy_audit'] = $audit->fetch(PDO::FETCH_ASSOC) ?: null;
        return $trip;
    }

    public static function fetchActiveUsers(PDO $pdo): array
    {
        return $pdo->query("SELECT id, full_name, role_code FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY full_name")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetchActiveUtilizers(PDO $pdo): array
    {
        return $pdo->query("SELECT id, short_name, full_name, address FROM feo_utilizers WHERE is_active=1 ORDER BY sort_order, short_name, id")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function assertCanAccessTrip(PDO $pdo, array $trip, array $sessionUser): void
    {
        if (!self::canAccessTrip($pdo, $trip, $sessionUser)) {
            throw new RuntimeException('У вас нет доступа к этому рейсу.');
        }
    }

    private static function assertEntitiesVisible(PDO $pdo, array $data, array $sessionUser): void
    {
        if (!LinearRouteService::isVisibleEntity($pdo, 'clients', (int) $data['client_id'], $sessionUser, 'client')) {
            throw new RuntimeException('Заказчик недоступен или неактивен.');
        }
        if (!empty($data['route_executor_id'])) {
            $visibleExecutors = LinearRouteService::fetchVisibleRouteExecutors($pdo, $sessionUser);
            $visibleIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $visibleExecutors);
            if (!in_array((int) $data['route_executor_id'], $visibleIds, true)) {
                throw new RuntimeException('Исполнитель рейса недоступен или неактивен.');
            }
        }
        $userStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND status='active' AND deleted_at IS NULL");
        $userStmt->execute([(int) $data['responsible_user_id']]);
        if ((int) $userStmt->fetchColumn() !== 1) {
            throw new RuntimeException('Ответственный сотрудник недоступен или неактивен.');
        }
        if (($data['destination_type'] ?? '') === 'utilizer') {
            $utilizerStmt = $pdo->prepare('SELECT COUNT(*) FROM feo_utilizers WHERE id=? AND is_active=1');
            $utilizerStmt->execute([(int) $data['utilizer_id']]);
            if ((int) $utilizerStmt->fetchColumn() !== 1) {
                throw new RuntimeException('Утилизатор недоступен или неактивен.');
            }
        }
    }

    private static function resolveCurrentRequestsForClaim(PDO $pdo, array $businessIds): array
    {
        return self::resolveRequestsForClaim($pdo, $businessIds, true);
    }

    private static function resolveRequestsForClaim(PDO $pdo, array $businessIds, bool $requireCurrentPlacement): array
    {
        $businessIds = array_values(array_unique(array_map('strval', $businessIds)));
        sort($businessIds, SORT_STRING);
        if ($businessIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($businessIds), '?'));
        $placementJoin = $requireCurrentPlacement
            ? 'JOIN feo_placement_current pc ON pc.zayavka_id=fr.zayavka_id'
            : '';
        $stmt = $pdo->prepare(
            "SELECT fr.id, fr.zayavka_id, fr.mass_netto, fr.naim_oo_gruzootpravitel, fr.mno_adres_pogruzki
               FROM feo_requests fr
               {$placementJoin}
              WHERE fr.zayavka_id IN ({$placeholders})
              ORDER BY fr.id
              FOR UPDATE"
        );
        $stmt->execute($businessIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $found = array_map(static fn(array $row): string => (string) $row['zayavka_id'], $rows);
        $missing = array_values(array_diff($businessIds, $found));
        if ($missing !== []) {
            $prefix = $requireCurrentPlacement
                ? 'Заявки отсутствуют в текущем пуле ФЭО: '
                : 'Заявки отсутствуют в canonical FEO: ';
            throw new RuntimeException($prefix . implode(', ', $missing) . '.');
        }
        foreach ($rows as &$row) {
            $row['snapshot_mass_kg'] = self::massKg($row['mass_netto'] ?? null);
        }
        unset($row);
        return $rows;
    }

    private static function assertNoActiveClaims(PDO $pdo, array $requestIds, ?int $ignoreTripId = null): void
    {
        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
        if ($requestIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $sql = "SELECT fr.zayavka_id, wt.id AS waste_trip_id
                  FROM waste_trip_requests wtr
                  JOIN waste_trips wt ON wt.id=wtr.waste_trip_id
                  JOIN feo_requests fr ON fr.id=wtr.feo_request_id
                 WHERE wtr.feo_request_id IN ({$placeholders})
                   AND wt.status <> 'cancelled'";
        $params = $requestIds;
        if ($ignoreTripId !== null) {
            $sql .= ' AND wt.id <> ?';
            $params[] = $ignoreTripId;
        }
        $sql .= ' ORDER BY wtr.feo_request_id FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($claims !== []) {
            $labels = array_map(
                static fn(array $row): string => (string) $row['zayavka_id'] . ' (рейс #' . (int) $row['waste_trip_id'] . ')',
                $claims
            );
            throw new RuntimeException('Заявки уже включены в активный рейс: ' . implode(', ', $labels) . '.');
        }
    }

    private static function insertRequestLinks(PDO $pdo, int $tripId, array $resolvedRows, int $startPosition = 0): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO waste_trip_requests (waste_trip_id, feo_request_id, snapshot_mass_kg, position_no) VALUES (?,?,?,?)'
        );
        $position = $startPosition;
        foreach ($resolvedRows as $row) {
            $stmt->execute([
                $tripId,
                (int) $row['id'],
                number_format((float) $row['snapshot_mass_kg'], 3, '.', ''),
                $position++,
            ]);
        }
    }

    private static function insertLegacyAudit(PDO $pdo, int $tripId, array $evidence, array $data, array $resolvedRows, array $sessionUser): void
    {
        $payload = $evidence['payload'] ?? [];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $mappingJson = json_encode([
            'client_id' => (int) $data['client_id'],
            'route_executor_id' => $data['route_executor_id'],
            'responsible_user_id' => (int) $data['responsible_user_id'],
            'destination_type' => (string) $data['destination_type'],
            'utilizer_id' => $data['utilizer_id'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $snapshotMass = round(array_sum(array_map(static fn(array $row): float => (float) $row['snapshot_mass_kg'], $resolvedRows)), 3);
        $legacyMass = isset($evidence['legacy_total_mass_kg']) ? (float) $evidence['legacy_total_mass_kg'] : null;
        $delta = $legacyMass !== null ? round($snapshotMass - $legacyMass, 3) : null;
        $warnings = [];
        if ($delta !== null && abs($delta) >= 0.001) {
            $warnings[] = 'legacy_mass_delta_kg=' . number_format($delta, 3, '.', '');
        }
        $warningsJson = $warnings !== []
            ? json_encode($warnings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            : null;
        $stmt = $pdo->prepare(
            'INSERT INTO waste_trip_legacy_import_audits
             (waste_trip_id, source_system, legacy_flight_id, legacy_payload_json, mapping_json,
              snapshot_request_count, snapshot_total_mass_kg, mass_delta_kg, warnings_json,
              legacy_total_mass_kg, legacy_cost, legacy_payment_terms, imported_by_user_id, imported_by_role)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $tripId,
            (string) $evidence['source_system'],
            (int) $evidence['legacy_flight_id'],
            $json,
            $mappingJson,
            count($resolvedRows),
            number_format($snapshotMass, 3, '.', ''),
            $delta !== null ? number_format($delta, 3, '.', '') : null,
            $warningsJson,
            $evidence['legacy_total_mass_kg'] ?? null,
            $evidence['legacy_cost'] ?? null,
            self::nullableText($evidence['legacy_payment_terms'] ?? null, 500),
            (int) ($sessionUser['user_id'] ?? 0) ?: null,
            (string) ($sessionUser['role_code'] ?? ''),
        ]);
    }

    private static function logAudit(PDO $pdo, int $tripId, string $action, ?array $before, ?array $after, array $sessionUser): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO waste_trip_audit_log (waste_trip_id, action, before_json, after_json, actor_user_id, actor_role)
             VALUES (?,?,?,?,?,?)'
        );
        $encode = static fn(?array $value): ?string => $value === null
            ? null
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt->execute([
            $tripId,
            $action,
            $encode($before),
            $encode($after),
            (int) ($sessionUser['user_id'] ?? 0) ?: null,
            (string) ($sessionUser['role_code'] ?? ''),
        ]);
    }

    private static function fetchTripForUpdate(PDO $pdo, int $tripId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM waste_trips WHERE id=? FOR UPDATE');
        $stmt->execute([$tripId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function fetchRequestLinks(PDO $pdo, int $tripId, bool $forUpdate): array
    {
        $sql = "SELECT wtr.id, wtr.feo_request_id, wtr.snapshot_mass_kg, wtr.position_no,
                       fr.zayavka_id, fr.mass_netto, fr.naim_oo_gruzootpravitel, fr.mno_adres_pogruzki,
                       fr.naim_otkhoda_fkko, fr.nomer_opasnogo_gr
                  FROM waste_trip_requests wtr
                  JOIN feo_requests fr ON fr.id=wtr.feo_request_id
                 WHERE wtr.waste_trip_id=?
              ORDER BY wtr.position_no, wtr.id";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function renumberLinks(PDO $pdo, int $tripId, array $businessIds): void
    {
        $lookup = $pdo->prepare(
            'SELECT wtr.id, fr.zayavka_id FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id=?'
        );
        $lookup->execute([$tripId]);
        $byBusinessId = [];
        foreach ($lookup->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byBusinessId[(string) $row['zayavka_id']] = (int) $row['id'];
        }
        $update = $pdo->prepare('UPDATE waste_trip_requests SET position_no=? WHERE waste_trip_id=? AND id=?');
        foreach (array_values($businessIds) as $position => $businessId) {
            if (isset($byBusinessId[$businessId])) {
                $update->execute([$position, $tripId, $byBusinessId[$businessId]]);
            }
        }
    }

    private static function enrichExecutorDisplay(PDO $pdo, array &$trips): void
    {
        $crewIds = array_values(array_unique(array_filter(array_map(
            static fn(array $trip): int => (int) ($trip['route_executor_id'] ?? 0),
            $trips
        ))));
        if ($crewIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($crewIds), '?'));
        $drivers = $pdo->prepare(
            "SELECT cd.crew_id, GROUP_CONCAT(d.full_name ORDER BY cd.position SEPARATOR ' / ') AS driver_names
               FROM crew_drivers cd JOIN drivers d ON d.id=cd.driver_id
              WHERE cd.crew_id IN ({$placeholders}) GROUP BY cd.crew_id"
        );
        $drivers->execute($crewIds);
        $driverMap = [];
        foreach ($drivers->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $driverMap[(int) $row['crew_id']] = trim((string) $row['driver_names']);
        }
        $vehicles = $pdo->prepare(
            "SELECT c.id AS crew_id,
                    vu1.brand AS primary_brand, vu1.plate_number AS primary_plate,
                    vu2.brand AS secondary_brand, vu2.plate_number AS secondary_plate
               FROM crews c
          LEFT JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id
          LEFT JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id
          LEFT JOIN vehicle_units vu1 ON vu1.id=vs.primary_vehicle_unit_id
          LEFT JOIN vehicle_units vu2 ON vu2.id=vs.secondary_vehicle_unit_id
              WHERE c.id IN ({$placeholders})"
        );
        $vehicles->execute($crewIds);
        $vehicleMap = [];
        foreach ($vehicles->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $parts = [];
            foreach (['primary', 'secondary'] as $prefix) {
                $label = trim(implode(' ', array_filter([
                    trim((string) ($row[$prefix . '_brand'] ?? '')),
                    trim((string) ($row[$prefix . '_plate'] ?? '')),
                ])));
                if ($label !== '') {
                    $parts[] = $label;
                }
            }
            $vehicleMap[(int) $row['crew_id']] = implode(' + ', $parts);
        }
        foreach ($trips as &$trip) {
            $crewId = (int) ($trip['route_executor_id'] ?? 0);
            $trip['executor_driver_names'] = $driverMap[$crewId] ?? '';
            $trip['executor_vehicle_label'] = $vehicleMap[$crewId] ?? '';
        }
        unset($trip);
    }

    private static function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new RuntimeException('Некорректный формат даты.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            throw new RuntimeException('Некорректная календарная дата.');
        }
        return $value;
    }

    private static function nullableText(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    public static function massKg(mixed $massNetto): float
    {
        $value = str_replace(',', '.', trim((string) $massNetto));
        if ($value === '' || !is_numeric($value)) {
            return 0.0;
        }
        return round(max(0.0, (float) $value) * 1000, 3);
    }

    private static function isDuplicateKey(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'duplicate') || str_contains($message, '1062');
    }
}
