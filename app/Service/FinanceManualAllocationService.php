<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

/** Canonical manual settlement writer. Every active manual allocation targets an obligation. */
final class FinanceManualAllocationService
{
    public static function createAllocation(PDO $pdo, int $operationId, array $data, array $user): int
    {
        $pdo->beginTransaction();
        try {
            $operation = self::lockOperation($pdo, $operationId);
            $amount = FinanceAllocationService::normalizeMoneyInput((string)($data['amount'] ?? ''));
            if ($amount === null) {
                throw new RuntimeException('Сумма распределения должна быть больше нуля.');
            }

            $operationRemaining = FinanceAllocationService::getOperationRemainingAmount($pdo, $operationId, (string)$operation['amount']);
            if (self::compare($amount, $operationRemaining) > 0) {
                throw new RuntimeException('Сумма распределения превышает остаток операции.');
            }

            $invoiceId = self::nullablePositiveInt($data['invoice_id'] ?? null);
            $obligationId = self::nullablePositiveInt($data['obligation_id'] ?? null);
            $routeId = self::nullablePositiveInt($data['linear_route_id'] ?? null);
            $legacyPaymentId = self::nullablePositiveInt($data['linear_route_payment_id'] ?? null);

            if ($obligationId === null && $legacyPaymentId !== null) {
                [$obligationId, $routeId] = self::resolveLegacyPaymentObligation($pdo, $legacyPaymentId, $routeId);
            }
            if ($invoiceId === null && $obligationId === null) {
                throw new RuntimeException('Укажите счёт или платёжное обязательство для распределения.');
            }

            $invoice = null;
            if ($invoiceId !== null) {
                $invoice = self::lockInvoice($pdo, $invoiceId, $operation, $amount);
            }

            $allocationDate = self::validDate((string)($data['allocation_date'] ?? date('Y-m-d')));
            $comment = trim((string)($data['comment'] ?? ''));
            $comment = $comment !== '' ? $comment : null;
            $userId = (int)($user['user_id'] ?? 0);
            $roleCode = (string)($user['role_code'] ?? '');

            $allocationIds = [];
            if ($obligationId !== null) {
                $obligation = self::lockObligation($pdo, $obligationId);
                self::validateObligation($pdo, $obligation, $operation, $user, $routeId);
                $routeId = (int)$obligation['source_parent_id'];

                $obligationRemaining = self::obligationRemaining($pdo, $obligationId, (string)$obligation['amount']);
                if (self::compare($amount, $obligationRemaining) > 0) {
                    throw new RuntimeException('Сумма распределения превышает остаток платёжного обязательства.');
                }

                if ($invoice !== null) {
                    $linkCapacity = self::invoiceObligationCapacity($pdo, (int)$invoice['id'], $obligationId, true);
                    if ($linkCapacity === null) {
                        throw new RuntimeException('Платёжное обязательство не связано с выбранным счётом через finance_invoice_links.');
                    }
                    if (self::compare($amount, $linkCapacity) > 0) {
                        throw new RuntimeException('Сумма распределения превышает остаток связи счёта с платёжным обязательством.');
                    }
                }

                $allocationIds[] = self::insertAllocation(
                    $pdo,
                    $operationId,
                    $invoiceId,
                    $obligation,
                    $amount,
                    $allocationDate,
                    $comment,
                    $userId,
                    $roleCode
                );
            } else {
                $allocationIds = self::allocateInvoiceAcrossObligations(
                    $pdo,
                    $operation,
                    $invoice,
                    $amount,
                    $allocationDate,
                    $comment,
                    $user,
                    $userId,
                    $roleCode
                );
            }

            if ($allocationIds === []) {
                throw new RuntimeException('Не удалось создать распределение по платёжным обязательствам.');
            }

            $pdo->commit();
            return $allocationIds[0];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function fetchObligationsForAllocation(PDO $pdo, int $operationId, int $routeId, array $user): array
    {
        if ($operationId <= 0 || $routeId <= 0) {
            return [];
        }
        $stmt = $pdo->prepare("SELECT * FROM finance_operations WHERE id=? LIMIT 1");
        $stmt->execute([$operationId]);
        $operation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$operation || (string)($operation['status'] ?? '') !== 'POSTED' || ($operation['cancelled_at'] ?? null) !== null) {
            return [];
        }
        self::assertRouteAccess($pdo, $routeId, $user);

        FinanceObligationService::syncLinearRoute($pdo, $routeId);
        LinearTripServiceFinanceService::syncRoute($pdo, $routeId);

        $direction = self::operationDirection($operation);
        $stmt = $pdo->prepare(
            "SELECT o.*,
                    s.service_name,
                    COALESCE((SELECT SUM(a.amount)
                                FROM finance_operation_allocations a
                                JOIN finance_operations op ON op.id=a.operation_id
                               WHERE a.obligation_id=o.id
                                 AND a.cancelled_at IS NULL
                                 AND op.status='POSTED'),0) AS actual_paid
               FROM finance_obligations o
          LEFT JOIN linear_route_service_payments sp
                 ON o.source_type='LINEAR_ROUTE_SERVICE_PAYMENT' AND sp.id=o.source_id AND sp.deleted_at IS NULL
          LEFT JOIN linear_route_services s
                 ON s.id=sp.linear_route_service_id AND s.deleted_at IS NULL
              WHERE o.source_parent_type='LINEAR_ROUTE'
                AND o.source_parent_id=?
                AND o.direction=?
                AND o.cancelled_at IS NULL
              ORDER BY COALESCE(o.due_date,o.forecast_due_date),o.id"
        );
        $stmt->execute([$routeId, $direction]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!self::counterpartyMatches($operation, $row)) {
                continue;
            }
            if (!self::sourceActive($pdo, $row)) {
                continue;
            }
            $remaining = self::sub((string)$row['amount'], (string)$row['actual_paid']);
            if (self::compare($remaining, '0.00') <= 0) {
                continue;
            }
            $row['remaining_amount'] = $remaining;
            $prefix = (string)$row['source_type'] === 'LINEAR_ROUTE_SERVICE_PAYMENT'
                ? 'Доп. услуга · ' . trim((string)($row['service_name'] ?? 'Услуга'))
                : 'Перевозка';
            $due = trim((string)($row['due_date'] ?? $row['forecast_due_date'] ?? ''));
            $row['allocation_label'] = sprintf(
                '%s · %s · остаток %s%s',
                $prefix,
                trim((string)($row['counterparty_name'] ?? 'Контрагент')),
                FinanceAllocationService::formatAmount($remaining),
                $due !== '' ? ' · срок ' . $due : ''
            );
            $result[] = $row;
        }
        return $result;
    }

    private static function allocateInvoiceAcrossObligations(
        PDO $pdo,
        array $operation,
        ?array $invoice,
        string $amount,
        string $allocationDate,
        ?string $comment,
        array $user,
        int $userId,
        string $roleCode
    ): array {
        if ($invoice === null) {
            throw new RuntimeException('Для распределения без явного обязательства необходимо выбрать счёт.');
        }
        $invoiceId = (int)$invoice['id'];
        $links = self::lockInvoiceLinks($pdo, $invoiceId);
        if ($links === []) {
            throw new RuntimeException('У счёта нет активных связей с платёжными обязательствами.');
        }

        $byObligation = [];
        foreach ($links as $link) {
            $oid = (int)($link['obligation_id'] ?? 0);
            if ($oid <= 0) {
                continue;
            }
            if (!isset($byObligation[$oid])) {
                $byObligation[$oid] = ['bounded' => '0.00', 'unbounded' => false];
            }
            if ($link['amount'] === null) {
                $byObligation[$oid]['unbounded'] = true;
            } else {
                $byObligation[$oid]['bounded'] = self::add($byObligation[$oid]['bounded'], (string)$link['amount']);
            }
        }
        if ($byObligation === []) {
            throw new RuntimeException('У счёта нет canonical obligation_id связей. Пересохраните связи счёта перед оплатой.');
        }
        ksort($byObligation, SORT_NUMERIC);

        $left = $amount;
        $allocationIds = [];
        foreach ($byObligation as $obligationId => $capacityInfo) {
            if (self::compare($left, '0.00') <= 0) {
                break;
            }
            $obligation = self::lockObligation($pdo, (int)$obligationId);
            self::validateObligation($pdo, $obligation, $operation, $user, null);
            if (!self::counterpartyMatches($invoice, $obligation)) {
                throw new RuntimeException('Контрагент обязательства не совпадает с контрагентом выбранного счёта.');
            }

            $obligationRemaining = self::obligationRemaining($pdo, (int)$obligationId, (string)$obligation['amount']);
            if (self::compare($obligationRemaining, '0.00') <= 0) {
                continue;
            }
            $linkRemaining = $capacityInfo['unbounded']
                ? $obligationRemaining
                : self::sub($capacityInfo['bounded'], self::invoiceObligationPaid($pdo, $invoiceId, (int)$obligationId));
            if (self::compare($linkRemaining, '0.00') <= 0) {
                continue;
            }
            $chunk = self::minMoney($left, self::minMoney($obligationRemaining, $linkRemaining));
            if (self::compare($chunk, '0.00') <= 0) {
                continue;
            }

            $allocationIds[] = self::insertAllocation(
                $pdo,
                (int)$operation['id'],
                $invoiceId,
                $obligation,
                $chunk,
                $allocationDate,
                $comment,
                $userId,
                $roleCode
            );
            $left = self::sub($left, $chunk);
        }

        if (self::compare($left, '0.00') > 0) {
            throw new RuntimeException('Сумма распределения превышает доступный остаток связанных обязательств счёта.');
        }
        return $allocationIds;
    }

    private static function lockOperation(PDO $pdo, int $operationId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM finance_operations WHERE id=? FOR UPDATE");
        $stmt->execute([$operationId]);
        $operation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$operation) {
            throw new RuntimeException('Операция не найдена.');
        }
        if ((string)($operation['status'] ?? '') !== 'POSTED' || ($operation['cancelled_at'] ?? null) !== null) {
            throw new RuntimeException('Распределять можно только действующие проведённые операции.');
        }
        self::operationDirection($operation);
        return $operation;
    }

    private static function lockInvoice(PDO $pdo, int $invoiceId, array $operation, string $amount): array
    {
        $stmt = $pdo->prepare("SELECT * FROM finance_invoices WHERE id=? FOR UPDATE");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Счёт не найден.');
        }
        if ((string)($invoice['status'] ?? '') === 'cancelled' || ($invoice['cancelled_at'] ?? null) !== null) {
            throw new RuntimeException('Нельзя распределить на аннулированный счёт.');
        }
        $expected = self::operationDirection($operation) === 'RECEIVABLE' ? 'OUTGOING' : 'INCOMING';
        if ((string)($invoice['direction'] ?? '') !== $expected) {
            throw new RuntimeException('Направление финансовой операции не соответствует направлению счёта.');
        }
        if (!self::counterpartyMatches($operation, $invoice)) {
            throw new RuntimeException('Контрагент финансовой операции не совпадает с контрагентом счёта.');
        }
        $remaining = FinanceAllocationService::getInvoiceRemainingAmount($pdo, $invoiceId, (string)$invoice['amount']);
        if (self::compare($remaining, '0.00') <= 0) {
            throw new RuntimeException('Счёт уже оплачен.');
        }
        if (self::compare($amount, $remaining) > 0) {
            throw new RuntimeException('Сумма распределения превышает остаток счёта.');
        }
        return $invoice;
    }

    private static function lockObligation(PDO $pdo, int $obligationId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM finance_obligations WHERE id=? FOR UPDATE");
        $stmt->execute([$obligationId]);
        $obligation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$obligation) {
            throw new RuntimeException('Платёжное обязательство не найдено.');
        }
        return $obligation;
    }

    private static function validateObligation(PDO $pdo, array $obligation, array $operation, array $user, ?int $routeId): void
    {
        if (($obligation['cancelled_at'] ?? null) !== null || (string)($obligation['status'] ?? '') === 'cancelled') {
            throw new RuntimeException('Платёжное обязательство отменено.');
        }
        if ((string)($obligation['direction'] ?? '') !== self::operationDirection($operation)) {
            throw new RuntimeException('Направление операции не соответствует платёжному обязательству.');
        }
        if (!self::counterpartyMatches($operation, $obligation)) {
            throw new RuntimeException('Контрагент операции не совпадает с контрагентом платёжного обязательства.');
        }
        if ((string)($obligation['source_parent_type'] ?? '') !== 'LINEAR_ROUTE') {
            throw new RuntimeException('Ручное распределение поддерживает только обязательства доступного линейного рейса.');
        }
        $derivedRouteId = (int)($obligation['source_parent_id'] ?? 0);
        if ($derivedRouteId <= 0 || ($routeId !== null && $routeId !== $derivedRouteId)) {
            throw new RuntimeException('Платёжное обязательство не принадлежит указанному рейсу.');
        }
        self::assertRouteAccess($pdo, $derivedRouteId, $user);
        if (!self::sourceActive($pdo, $obligation)) {
            throw new RuntimeException('Источник платёжного обязательства удалён или отменён.');
        }
    }

    private static function sourceActive(PDO $pdo, array $obligation): bool
    {
        $sourceId = (int)($obligation['source_id'] ?? 0);
        return match ((string)($obligation['source_type'] ?? '')) {
            'LINEAR_ROUTE_PAYMENT' => self::rowExists($pdo, 'linear_route_payments', $sourceId, 'deleted_at IS NULL AND cancelled_at IS NULL'),
            'LINEAR_ROUTE_SERVICE_PAYMENT' => self::servicePaymentActive($pdo, $sourceId),
            default => false,
        };
    }

    private static function servicePaymentActive(PDO $pdo, int $sourceId): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1
               FROM linear_route_service_payments sp
               JOIN linear_route_services s ON s.id=sp.linear_route_service_id
              WHERE sp.id=? AND sp.deleted_at IS NULL AND s.deleted_at IS NULL
              LIMIT 1"
        );
        $stmt->execute([$sourceId]);
        return (bool)$stmt->fetchColumn();
    }

    private static function rowExists(PDO $pdo, string $table, int $id, string $predicate): bool
    {
        if ($id <= 0) return false;
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id=? AND {$predicate} LIMIT 1");
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }

    private static function resolveLegacyPaymentObligation(PDO $pdo, int $paymentId, ?int $routeId): array
    {
        $stmt = $pdo->prepare("SELECT linear_route_id FROM linear_route_payments WHERE id=? AND deleted_at IS NULL AND cancelled_at IS NULL");
        $stmt->execute([$paymentId]);
        $derivedRouteId = (int)($stmt->fetchColumn() ?: 0);
        if ($derivedRouteId <= 0) {
            throw new RuntimeException('Платёжная строка не найдена или отменена.');
        }
        if ($routeId !== null && $routeId !== $derivedRouteId) {
            throw new RuntimeException('Платёжная строка не принадлежит указанному рейсу.');
        }
        FinanceObligationService::syncLinearRoute($pdo, $derivedRouteId);
        $stmt = $pdo->prepare("SELECT id FROM finance_obligations WHERE source_type='LINEAR_ROUTE_PAYMENT' AND source_id=? AND cancelled_at IS NULL LIMIT 1 FOR UPDATE");
        $stmt->execute([$paymentId]);
        $obligationId = (int)($stmt->fetchColumn() ?: 0);
        if ($obligationId <= 0) {
            throw new RuntimeException('Canonical платёжное обязательство для платёжной строки не найдено.');
        }
        return [$obligationId, $derivedRouteId];
    }

    private static function lockInvoiceLinks(PDO $pdo, int $invoiceId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM finance_invoice_links WHERE invoice_id=? ORDER BY obligation_id,id FOR UPDATE");
        $stmt->execute([$invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function invoiceObligationCapacity(PDO $pdo, int $invoiceId, int $obligationId, bool $lock): ?string
    {
        $sql = "SELECT amount FROM finance_invoice_links WHERE invoice_id=? AND obligation_id=? ORDER BY id" . ($lock ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$invoiceId, $obligationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($rows === []) {
            return null;
        }
        $bounded = '0.00';
        $unbounded = false;
        foreach ($rows as $value) {
            if ($value === null) {
                $unbounded = true;
            } else {
                $bounded = self::add($bounded, (string)$value);
            }
        }
        if ($unbounded) {
            return self::obligationRemaining($pdo, $obligationId, null);
        }
        return self::sub($bounded, self::invoiceObligationPaid($pdo, $invoiceId, $obligationId));
    }

    private static function invoiceObligationPaid(PDO $pdo, int $invoiceId, int $obligationId): string
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(a.amount),0)
               FROM finance_operation_allocations a
               JOIN finance_operations op ON op.id=a.operation_id
              WHERE a.invoice_id=? AND a.obligation_id=?
                AND a.cancelled_at IS NULL AND op.status='POSTED'"
        );
        $stmt->execute([$invoiceId, $obligationId]);
        return self::money((string)$stmt->fetchColumn());
    }

    private static function obligationRemaining(PDO $pdo, int $obligationId, ?string $obligationAmount): string
    {
        if ($obligationAmount === null) {
            $stmt = $pdo->prepare("SELECT amount FROM finance_obligations WHERE id=?");
            $stmt->execute([$obligationId]);
            $obligationAmount = (string)($stmt->fetchColumn() ?: '0.00');
        }
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(a.amount),0)
               FROM finance_operation_allocations a
               JOIN finance_operations op ON op.id=a.operation_id
              WHERE a.obligation_id=? AND a.cancelled_at IS NULL AND op.status='POSTED'"
        );
        $stmt->execute([$obligationId]);
        return self::sub($obligationAmount, (string)$stmt->fetchColumn());
    }

    private static function insertAllocation(
        PDO $pdo,
        int $operationId,
        ?int $invoiceId,
        array $obligation,
        string $amount,
        string $allocationDate,
        ?string $comment,
        int $userId,
        string $roleCode
    ): int {
        $routeId = (int)$obligation['source_parent_id'];
        $legacyPaymentId = (string)$obligation['source_type'] === 'LINEAR_ROUTE_PAYMENT'
            ? (int)$obligation['source_id']
            : null;
        $stmt = $pdo->prepare(
            "INSERT INTO finance_operation_allocations
                (operation_id,invoice_id,obligation_id,linear_route_id,linear_route_payment_id,
                 amount,allocation_date,method,comment,created_by_user_id,created_by_role)
             VALUES (?,?,?,?,?, ?,?,'manual',?,?,?)"
        );
        $stmt->execute([
            $operationId,
            $invoiceId,
            (int)$obligation['id'],
            $routeId,
            $legacyPaymentId,
            $amount,
            $allocationDate,
            $comment,
            $userId,
            $roleCode,
        ]);
        $allocationId = (int)$pdo->lastInsertId();
        FinanceAuditLogService::log($pdo, 'finance_allocation', $allocationId, 'allocation_create', null, [
            'amount' => $amount,
            'operation_id' => $operationId,
            'invoice_id' => $invoiceId,
            'obligation_id' => (int)$obligation['id'],
            'linear_route_id' => $routeId,
            'linear_route_payment_id' => $legacyPaymentId,
        ], $userId, $roleCode);
        FinanceSettlementCascadeService::cascadeAfterAllocationCreate($pdo, $allocationId);
        return $allocationId;
    }

    private static function assertRouteAccess(PDO $pdo, int $routeId, array $user): void
    {
        $stmt = $pdo->prepare("SELECT id,created_by_user_id FROM linear_routes WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$routeId]);
        $route = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$route) {
            throw new RuntimeException('Рейс не найден или удалён.');
        }
        if (class_exists(\App\Security\AccessRuntime::class) && \App\Security\AccessRuntime::current()->isActive()) {
            if (!RegulatedDataScopeService::canAccess($pdo, 'linear_trip', 'linear_routes', $routeId)) {
                throw new RuntimeException('Нет доступа к указанному рейсу.');
            }
            return;
        }
        $role = (string)($user['role_code'] ?? '');
        if ($role !== 'logist') {
            return;
        }
        $userId = (int)($user['user_id'] ?? 0);
        if ($userId > 0 && (int)($route['created_by_user_id'] ?? 0) === $userId) {
            return;
        }
        $stmt = $pdo->prepare(
            "SELECT 1 FROM entity_access_grants
              WHERE entity_type='linear_route' AND entity_id=? AND granted_to_user_id=?
                AND access_level IN ('view','edit') AND revoked_at IS NULL LIMIT 1"
        );
        $stmt->execute([$routeId, $userId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Нет доступа к указанному рейсу.');
        }
    }

    private static function operationDirection(array $operation): string
    {
        return match ((string)($operation['operation_type'] ?? '')) {
            'INCOME' => 'RECEIVABLE',
            'EXPENSE' => 'PAYABLE',
            default => throw new RuntimeException('Эту финансовую операцию нельзя распределять по платёжным обязательствам.'),
        };
    }

    private static function counterpartyMatches(array $left, array $right): bool
    {
        $leftType = strtolower(trim((string)($left['counterparty_entity_type'] ?? '')));
        $rightType = strtolower(trim((string)($right['counterparty_entity_type'] ?? '')));
        $leftId = (int)($left['counterparty_entity_id'] ?? 0);
        $rightId = (int)($right['counterparty_entity_id'] ?? 0);
        if ($leftType !== '' && $rightType !== '' && $leftId > 0 && $rightId > 0) {
            return $leftType === $rightType && $leftId === $rightId;
        }
        $leftInn = preg_replace('/\D+/', '', (string)($left['counterparty_inn'] ?? '')) ?? '';
        $rightInn = preg_replace('/\D+/', '', (string)($right['counterparty_inn'] ?? '')) ?? '';
        if ($leftInn !== '' && $rightInn !== '') {
            return hash_equals($leftInn, $rightInn);
        }
        $leftName = self::normalizeName((string)($left['counterparty_name'] ?? ''));
        $rightName = self::normalizeName((string)($right['counterparty_name'] ?? ''));
        return $leftName !== '' && $rightName !== '' && $leftName === $rightName;
    }

    private static function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $value = (int)$value;
        return $value > 0 ? $value : null;
    }

    private static function validDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Некорректная дата распределения.');
        }
        return $value;
    }

    private static function money(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) return '0.00';
        $negative = str_starts_with($value, '-');
        if ($negative) $value = substr($value, 1);
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    private static function cents(string $value): int
    {
        $value = self::money($value);
        $negative = str_starts_with($value, '-');
        if ($negative) $value = substr($value, 1);
        [$whole, $fraction] = explode('.', $value, 2);
        $cents = ((int)$whole * 100) + (int)$fraction;
        return $negative ? -$cents : $cents;
    }

    private static function fromCents(int $value): string
    {
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);
        return $sign . intdiv($value, 100) . '.' . str_pad((string)($value % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function add(string $left, string $right): string { return self::fromCents(self::cents($left) + self::cents($right)); }
    private static function sub(string $left, string $right): string { return self::fromCents(self::cents($left) - self::cents($right)); }
    private static function compare(string $left, string $right): int { return self::cents($left) <=> self::cents($right); }
    private static function minMoney(string $left, string $right): string { return self::compare($left, $right) <= 0 ? self::money($left) : self::money($right); }
}
