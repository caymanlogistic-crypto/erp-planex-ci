<?php

namespace App\Service;

use PDO;

final class LinearTripServiceLineService
{
    public static function schemaReady(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT 1 FROM linear_route_services LIMIT 1');
            $pdo->query('SELECT 1 FROM linear_route_service_payments LIMIT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{services:array<int,array<string,mixed>>,errors:array<string,string>} */
    public static function normalizeSubmitted(PDO $pdo, array $rows, array $sessionUser, bool $includeFinance): array
    {
        $services = [];
        $errors = [];
        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) continue;
            $id = (int)($row['id'] ?? 0);
            $contractorId = (int)($row['contractor_id'] ?? 0);
            $serviceName = trim((string)($row['service_name'] ?? ''));
            $description = trim((string)($row['description'] ?? ''));
            $paymentRows = (array)($row['payments'] ?? []);
            $empty = $contractorId <= 0 && $serviceName === '' && $description === '' && $paymentRows === [];
            if ($empty) continue;

            if ($serviceName === '') $errors["trip_services.$index.service_name"] = 'Укажите название услуги.';
            if ($contractorId <= 0) {
                $errors["trip_services.$index.contractor_id"] = 'Выберите подрядчика.';
            } elseif (!LinearRouteService::isVisibleEntity($pdo, 'contractors', $contractorId, $sessionUser, 'contractor')) {
                $errors["trip_services.$index.contractor_id"] = 'Подрядчик недоступен.';
            }

            $payments = [];
            if ($includeFinance) {
                foreach (array_values($paymentRows) as $paymentIndex => $payment) {
                    if (!is_array($payment)) continue;
                    $amountRaw = trim((string)($payment['amount'] ?? ''));
                    $method = trim((string)($payment['payment_method'] ?? 'cashless'));
                    $vatRaw = trim((string)($payment['vat_rate'] ?? ''));
                    $condition = trim((string)($payment['condition_type'] ?? ''));
                    $daysRaw = trim((string)($payment['days_count'] ?? ''));
                    $daysKind = trim((string)($payment['days_kind'] ?? ''));
                    $specificRaw = trim((string)($payment['specific_due_date'] ?? ''));
                    $comment = trim((string)($payment['condition_comment'] ?? ''));
                    if ($amountRaw === '' && $condition === '') continue;

                    $amount = LinearRouteService::parseAmount($amountRaw);
                    if ($amount === null) $errors["trip_services.$index.payments.$paymentIndex.amount"] = 'Укажите корректную сумму.';
                    if (!in_array($method, ['cashless', 'cash'], true)) $errors["trip_services.$index.payments.$paymentIndex.payment_method"] = 'Выберите способ оплаты.';
                    $vat = $vatRaw === '' ? null : $vatRaw;
                    if ($vat !== null && !in_array($vat, ['0','5','7','20','22'], true)) $errors["trip_services.$index.payments.$paymentIndex.vat_rate"] = 'Выберите ставку НДС.';
                    if (!in_array($condition, LinearRouteService::CONDITION_TYPES, true)) $errors["trip_services.$index.payments.$paymentIndex.condition_type"] = 'Выберите срок оплаты.';

                    $days = null;
                    if (LinearRouteService::conditionTypeRequiresDays($condition)) {
                        if ($daysRaw === '' || !ctype_digit($daysRaw) || (int)$daysRaw <= 0) $errors["trip_services.$index.payments.$paymentIndex.days_count"] = 'Укажите количество дней.';
                        else $days = (int)$daysRaw;
                        if (!array_key_exists($daysKind, LinearRouteService::PAYMENT_DUE_DAYS_KINDS)) $errors["trip_services.$index.payments.$paymentIndex.days_kind"] = 'Выберите тип дней.';
                    } else {
                        $daysKind = '';
                    }
                    $specific = null;
                    if (LinearRouteService::conditionTypeRequiresSpecificDate($condition)) {
                        $specific = LinearRouteService::normalizeDate($specificRaw);
                        if ($specific === null) $errors["trip_services.$index.payments.$paymentIndex.specific_due_date"] = 'Укажите конкретную дату.';
                    }
                    $payments[] = [
                        'amount' => $amount,
                        'payment_method' => $method,
                        'vat_rate' => $vat,
                        'payment_type' => LinearRouteService::legacyPaymentTypeFromMethodAndVat($method, $vat),
                        'condition_type' => $condition,
                        'days_count' => $days,
                        'days_kind' => $daysKind !== '' ? $daysKind : null,
                        'specific_due_date' => $specific,
                        'condition_comment' => $comment !== '' ? $comment : null,
                    ];
                }
                if ($payments === []) $errors["trip_services.$index.payments"] = 'Добавьте хотя бы одну оплату для услуги.';
            }

            $services[] = [
                'id' => $id,
                'contractor_id' => $contractorId,
                'service_name' => $serviceName,
                'description' => $description !== '' ? $description : null,
                'payments' => $payments,
            ];
        }
        return ['services' => $services, 'errors' => $errors];
    }

    public static function replaceForRoute(PDO $pdo, int $routeId, array $services, array $user, array $dates, bool $includeFinance): void
    {
        if (!self::schemaReady($pdo)) {
            throw new \RuntimeException('Схема дополнительных услуг рейса не установлена.');
        }
        $userId = (int)($user['user_id'] ?? 0);
        $role = (string)($user['role_code'] ?? '');
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $existingStmt = $pdo->prepare('SELECT id FROM linear_route_services WHERE linear_route_id=? AND deleted_at IS NULL FOR UPDATE');
            $existingStmt->execute([$routeId]);
            $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
            $keepIds = [];

            foreach (array_values($services) as $sort => $service) {
                $serviceId = (int)($service['id'] ?? 0);
                if ($serviceId > 0 && in_array($serviceId, $existingIds, true)) {
                    $stmt = $pdo->prepare('UPDATE linear_route_services SET contractor_id=?,service_name=?,description=?,sort_order=?,updated_by_user_id=?,updated_by_role=?,deleted_at=NULL,deleted_by_user_id=NULL,deleted_by_role=NULL WHERE id=? AND linear_route_id=?');
                    $stmt->execute([(int)$service['contractor_id'], $service['service_name'], $service['description'], $sort, $userId ?: null, $role ?: null, $serviceId, $routeId]);
                } else {
                    $stmt = $pdo->prepare('INSERT INTO linear_route_services (linear_route_id,contractor_id,service_name,description,sort_order,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([$routeId,(int)$service['contractor_id'],$service['service_name'],$service['description'],$sort,$userId ?: null,$role ?: null,$userId ?: null,$role ?: null]);
                    $serviceId = (int)$pdo->lastInsertId();
                }
                $keepIds[] = $serviceId;
                if ($includeFinance) self::replacePayments($pdo, $serviceId, (array)($service['payments'] ?? []), $userId, $role, $dates);
            }

            foreach ($existingIds as $oldId) {
                if (in_array($oldId, $keepIds, true)) continue;
                $pdo->prepare('UPDATE linear_route_services SET deleted_at=NOW(),deleted_by_user_id=?,deleted_by_role=? WHERE id=?')->execute([$userId ?: null,$role ?: null,$oldId]);
                $pdo->prepare('UPDATE linear_route_service_payments SET deleted_at=NOW(),deleted_by_user_id=?,deleted_by_role=? WHERE linear_route_service_id=? AND deleted_at IS NULL')->execute([$userId ?: null,$role ?: null,$oldId]);
            }
            if ($ownTx) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function replacePayments(PDO $pdo, int $serviceId, array $payments, int $userId, string $role, array $dates): void
    {
        $pdo->prepare('UPDATE linear_route_service_payments SET deleted_at=NOW(),deleted_by_user_id=?,deleted_by_role=? WHERE linear_route_service_id=? AND deleted_at IS NULL')->execute([$userId ?: null,$role ?: null,$serviceId]);
        $insert = $pdo->prepare('INSERT INTO linear_route_service_payments (linear_route_service_id,amount,payment_type,payment_method,vat_rate,condition_type,days_count,days_kind,specific_due_date,condition_comment,calculated_due_date,status,sort_order,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach (array_values($payments) as $sort => $payment) {
            $due = LinearRouteService::computeCalculatedDueDateNew(
                $payment['condition_type'] ?? null,
                $payment['days_count'] ?? null,
                (string)($payment['days_kind'] ?? 'calendar'),
                $dates['planned_loading_date'] ?? null,
                $dates['planned_unloading_date'] ?? null,
                $dates['actual_loading_date'] ?? null,
                $dates['actual_unloading_date'] ?? null,
                null,
                $payment['specific_due_date'] ?? null
            );
            $status = LinearRouteService::computePaymentStatusNew((string)($payment['amount'] ?? '0.00'), '0.00', $due, null, null, $payment['condition_type'] ?? null);
            $insert->execute([$serviceId,$payment['amount'],$payment['payment_type'],$payment['payment_method'],$payment['vat_rate'],$payment['condition_type'],$payment['days_count'],$payment['days_kind'],$payment['specific_due_date'],$payment['condition_comment'],$due,$status,$sort,$userId ?: null,$role ?: null,$userId ?: null,$role ?: null]);
        }
    }

    public static function fetchByRouteIds(PDO $pdo, array $routeIds, bool $includeFinance): array
    {
        $routeIds = array_values(array_unique(array_filter(array_map('intval', $routeIds), static fn(int $id): bool => $id > 0)));
        $result = [];
        foreach ($routeIds as $routeId) $result[$routeId] = [];
        if ($routeIds === [] || !self::schemaReady($pdo)) return $result;
        $ph = implode(',', array_fill(0, count($routeIds), '?'));
        $stmt = $pdo->prepare("SELECT s.*,c.name contractor_name FROM linear_route_services s JOIN contractors c ON c.id=s.contractor_id WHERE s.linear_route_id IN ($ph) AND s.deleted_at IS NULL ORDER BY s.linear_route_id,s.sort_order,s.id");
        $stmt->execute($routeIds);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $serviceIds = [];
        foreach ($services as &$service) {
            $service['id'] = (int)$service['id'];
            $service['linear_route_id'] = (int)$service['linear_route_id'];
            $service['contractor_id'] = (int)$service['contractor_id'];
            $service['payments'] = [];
            $serviceIds[] = (int)$service['id'];
        }
        unset($service);
        if ($includeFinance && $serviceIds !== []) {
            $pph = implode(',', array_fill(0, count($serviceIds), '?'));
            $ps = $pdo->prepare("SELECT * FROM linear_route_service_payments WHERE linear_route_service_id IN ($pph) AND deleted_at IS NULL ORDER BY linear_route_service_id,sort_order,id");
            $ps->execute($serviceIds);
            $byService = [];
            foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $payment) $byService[(int)$payment['linear_route_service_id']][] = $payment;
            foreach ($services as &$service) $service['payments'] = $byService[(int)$service['id']] ?? [];
            unset($service);
        }
        foreach ($services as $service) $result[(int)$service['linear_route_id']][] = $service;
        return $result;
    }
}
