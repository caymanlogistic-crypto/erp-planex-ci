<?php

namespace App\Service;

use PDO;

final class FinanceAllocationService
{
    public const ALLOCATION_METHODS = [
        'manual' => 'Вручную',
        'auto_exact' => 'Автоточное',
        'rule' => 'По правилу',
    ];

    public const ALLOCATION_STATUSES = [
        'active' => 'Активно',
        'cancelled' => 'Отменено',
    ];

    public static function normalizeMoneyInput(string $value): ?string
    {
        return FinanceOperationService::normalizeMoneyInput($value);
    }

    public static function formatAmount(mixed $value): string
    {
        return FinanceOperationService::formatAmount($value);
    }

    public static function fetchOperationCard(PDO $pdo, int $operationId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT fo.*,
                    fma.name AS account_name,
                    fma.type AS account_type
               FROM finance_operations fo
               JOIN finance_money_accounts fma ON fma.id = fo.money_account_id
              WHERE fo.id = ?"
        );
        $stmt->execute([$operationId]);
        $operation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$operation) return null;

        $allocated = self::getOperationAllocatedAmount($pdo, $operationId);
        $remaining = self::getOperationRemainingAmount($pdo, $operationId, $operation['amount']);
        $operation['allocated_amount'] = $allocated;
        $operation['remaining_amount'] = $remaining;
        $operation['allocation_status'] = $remaining === '0.00'
            ? 'allocated'
            : ($allocated === '0.00' ? 'unallocated' : 'partially_allocated');
        return $operation;
    }

    public static function fetchOperationAllocations(PDO $pdo, int $operationId): array
    {
        $stmt = $pdo->prepare(
            "SELECT foa.*,
                    fi.number AS invoice_number,
                    fi.direction AS invoice_direction,
                    lr.route_type, lr.planned_loading_date,
                    o.source_type AS obligation_source_type,
                    o.counterparty_name AS obligation_counterparty_name
               FROM finance_operation_allocations foa
          LEFT JOIN finance_invoices fi ON fi.id = foa.invoice_id
          LEFT JOIN linear_routes lr ON lr.id = foa.linear_route_id
          LEFT JOIN finance_obligations o ON o.id = foa.obligation_id
              WHERE foa.operation_id = ?
                AND foa.cancelled_at IS NULL
              ORDER BY foa.created_at ASC"
        );
        $stmt->execute([$operationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getOperationAllocatedAmount(PDO $pdo, int $operationId): string
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(foa.amount), 0)
               FROM finance_operation_allocations foa
              WHERE foa.operation_id = ? AND foa.cancelled_at IS NULL"
        );
        $stmt->execute([$operationId]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null ? (string)$val : '0.00';
    }

    public static function getOperationRemainingAmount(PDO $pdo, int $operationId, ?string $operationAmount = null): string
    {
        if ($operationAmount === null) {
            $stmt = $pdo->prepare("SELECT amount FROM finance_operations WHERE id = ?");
            $stmt->execute([$operationId]);
            $operationAmount = (string)($stmt->fetchColumn() ?: '0.00');
        }
        return self::stringSub($operationAmount, self::getOperationAllocatedAmount($pdo, $operationId));
    }

    public static function getInvoicePaidAmount(PDO $pdo, int $invoiceId): string
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(foa.amount), 0)
               FROM finance_operation_allocations foa
              WHERE foa.invoice_id = ? AND foa.cancelled_at IS NULL"
        );
        $stmt->execute([$invoiceId]);
        $val = $stmt->fetchColumn();
        return $val !== false && $val !== null ? (string)$val : '0.00';
    }

    public static function getInvoiceRemainingAmount(PDO $pdo, int $invoiceId, ?string $invoiceAmount = null): string
    {
        if ($invoiceAmount === null) {
            $stmt = $pdo->prepare("SELECT amount FROM finance_invoices WHERE id = ?");
            $stmt->execute([$invoiceId]);
            $invoiceAmount = (string)($stmt->fetchColumn() ?: '0.00');
        }
        return self::stringSub($invoiceAmount, self::getInvoicePaidAmount($pdo, $invoiceId));
    }

    public static function recalculateInvoiceStatus(PDO $pdo, int $invoiceId): void
    {
        $stmt = $pdo->prepare("SELECT * FROM finance_invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || ($invoice['status'] ?? '') === 'cancelled') return;

        $paid = self::getInvoicePaidAmount($pdo, $invoiceId);
        $amount = (string)($invoice['amount'] ?? '0.00');
        $remaining = self::stringSub($amount, $paid);
        $today = date('Y-m-d');
        $plannedDate = $invoice['planned_payment_date'] ?? null;
        $oldStatus = $invoice['status'] ?? '';

        if (self::stringCompare($remaining, '0.00') <= 0) $newStatus = 'paid';
        elseif (self::stringCompare($paid, '0.00') > 0) $newStatus = 'partially_paid';
        elseif ($oldStatus !== 'draft' && $plannedDate !== null && $plannedDate < $today) $newStatus = 'overdue';
        else $newStatus = $oldStatus;

        $upd = $pdo->prepare("UPDATE finance_invoices SET status=:status,paid_amount=:paid_amount,updated_at=NOW() WHERE id=:id");
        $upd->execute([':status'=>$newStatus, ':paid_amount'=>$paid, ':id'=>$invoiceId]);
    }

    public static function fetchInvoicesForAllocation(PDO $pdo, string $operationType, ?string $counterpartyInn = null): array
    {
        $direction = $operationType === 'INCOME' ? 'OUTGOING' : 'INCOMING';
        $sql = "SELECT fi.* FROM finance_invoices fi
                 WHERE fi.direction=? AND fi.status NOT IN ('cancelled','paid')";
        $params = [$direction];
        if ($counterpartyInn !== null && $counterpartyInn !== '') {
            $sql .= " AND fi.counterparty_inn=?";
            $params[] = $counterpartyInn;
        }
        $sql .= " ORDER BY fi.invoice_date DESC,fi.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['paid_amount'] = self::getInvoicePaidAmount($pdo, (int)$row['id']);
            $row['remaining_amount'] = self::getInvoiceRemainingAmount($pdo, (int)$row['id'], $row['amount']);
            $result[] = $row;
        }
        return $result;
    }

    public static function fetchRoutesForAllocation(PDO $pdo, array $user): array
    {
        $baseSql = "SELECT lr.id,lr.route_type,lr.planned_loading_date,ct.name AS client_name,carrier.name AS carrier_name
                      FROM linear_routes lr
                      JOIN clients ct ON ct.id=lr.client_id
                      JOIN contractors carrier ON carrier.id=lr.carrier_contractor_id
                     WHERE lr.deleted_at IS NULL";
        $roleCode = (string)($user['role_code'] ?? '');
        if ($roleCode === 'logist') {
            $userId = (int)($user['user_id'] ?? 0);
            $stmt = $pdo->prepare($baseSql . " AND (lr.created_by_user_id=? OR lr.id IN (
                SELECT entity_id FROM entity_access_grants
                 WHERE entity_type='linear_route' AND granted_to_user_id=?
                   AND access_level IN ('view','edit') AND revoked_at IS NULL
            )) ORDER BY lr.created_at DESC");
            $stmt->execute([$userId, $userId]);
        } else {
            $stmt = $pdo->query($baseSql . " ORDER BY lr.created_at DESC");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @deprecated Kept only for stale clients. New manual UI uses obligation_id. */
    public static function fetchRoutePaymentsForAllocation(PDO $pdo, ?int $routeId, string $operationType): array
    {
        if ($routeId === null) return [];
        $side = $operationType === 'INCOME' ? ['customer','principal'] : ['carrier'];
        $placeholders = implode(',', array_fill(0, count($side), '?'));
        $stmt = $pdo->prepare("SELECT lrp.* FROM linear_route_payments lrp
              WHERE lrp.linear_route_id=? AND lrp.party_role IN ({$placeholders}) AND lrp.deleted_at IS NULL
              ORDER BY lrp.sort_order ASC");
        $stmt->execute(array_merge([$routeId], $side));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function fetchObligationsForAllocation(PDO $pdo, int $operationId, int $routeId, array $user): array
    {
        return FinanceManualAllocationService::fetchObligationsForAllocation($pdo, $operationId, $routeId, $user);
    }

    public static function createAllocation(PDO $pdo, int $operationId, array $data, array $user): int
    {
        return FinanceManualAllocationService::createAllocation($pdo, $operationId, $data, $user);
    }

    public static function cancelAllocation(PDO $pdo, int $allocationId, array $user, string $reason): int
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM finance_operation_allocations WHERE id=? FOR UPDATE");
            $stmt->execute([$allocationId]);
            $allocation = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$allocation) throw new \RuntimeException('Распределение не найдено.');
            if ($allocation['cancelled_at'] !== null) throw new \RuntimeException('Распределение уже отменено.');
            $reason = trim($reason);
            if ($reason === '') throw new \RuntimeException('Укажите причину отмены распределения.');

            $userId = (int)($user['user_id'] ?? 0);
            $roleCode = (string)($user['role_code'] ?? '');
            $upd = $pdo->prepare("UPDATE finance_operation_allocations
                SET cancelled_at=NOW(),cancelled_by_user_id=:user_id,cancelled_by_role=:role,cancel_reason=:reason
                WHERE id=:id");
            $upd->execute([':user_id'=>$userId, ':role'=>$roleCode, ':reason'=>$reason, ':id'=>$allocationId]);

            FinanceAuditLogService::log($pdo, 'finance_allocation', $allocationId, 'allocation_cancel', [
                'amount'=>$allocation['amount'],
                'operation_id'=>$allocation['operation_id'],
                'invoice_id'=>$allocation['invoice_id'],
                'obligation_id'=>$allocation['obligation_id'] ?? null,
                'linear_route_payment_id'=>$allocation['linear_route_payment_id'],
            ], [
                'cancelled_at'=>date('Y-m-d H:i:s'),
                'cancel_reason'=>$reason,
            ], $userId, $roleCode);

            FinanceSettlementCascadeService::cascadeAfterAllocationCancel($pdo, $allocationId);
            $pdo->commit();
            return (int)$allocation['operation_id'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function stringSub(string $left, string $right): string
    {
        if (!preg_match('/^-?\d+(\.\d+)?$/', $left) || !preg_match('/^-?\d+(\.\d+)?$/', $right)) return '0.00';
        $result = self::toCents($left) - self::toCents($right);
        $sign = $result < 0 ? '-' : '';
        $abs = abs($result);
        return $sign . intdiv($abs,100) . '.' . str_pad((string)($abs%100),2,'0',STR_PAD_LEFT);
    }

    private static function stringCompare(string $left, string $right): int
    {
        return self::toCents($left) <=> self::toCents($right);
    }

    private static function toCents(string $value): int
    {
        $parts = explode('.', $value);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '' || $intPart === '-') $intPart = $intPart === '-' ? '-0' : '0';
        $decPart = str_pad(substr(($parts[1] ?? ''),0,2),2,'0');
        return (int)($intPart . $decPart);
    }
}
