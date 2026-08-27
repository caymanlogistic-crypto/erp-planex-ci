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
        $invoiceId = self::nullablePositiveInt($data['invoice_id'] ?? null);
        $obligationId = self::nullablePositiveInt($data['obligation_id'] ?? null);
        $legacyPaymentId = self::nullablePositiveInt($data['linear_route_payment_id'] ?? null);

        // Backward-compatible settlement class: a true standalone invoice has no invoice links at all.
        // Any linked invoice remains obligation-backed and MUST use the canonical obligation-aware writer.
        if ($invoiceId !== null && $obligationId === null && $legacyPaymentId === null && !self::invoiceHasAnyLinks($pdo, $invoiceId)) {
            $standaloneAllocationId = self::tryCreateStandaloneInvoiceAllocation($pdo, $operationId, $invoiceId, $data, $user);
            if ($standaloneAllocationId !== null) {
                return $standaloneAllocationId;
            }
        }

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

    private static function invoiceHasAnyLinks(PDO $pdo, int $invoiceId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM finance_invoice_links WHERE invoice_id=? LIMIT 1');
        $stmt->execute([$invoiceId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Creates the legacy-compatible invoice-only allocation only while the invoice is still truly standalone.
     * Returns null when invoice links appeared before the transactional invoice lock was acquired; caller then
     * falls back to the obligation-aware writer, which preserves the PR #117 invariant.
     */
    private static function tryCreateStandaloneInvoiceAllocation(PDO $pdo, int $operationId, int $invoiceId, array $data, array $user): ?int
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM finance_operations WHERE id=? FOR UPDATE');
            $stmt->execute([$operationId]);
            $operation = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$operation) {
                throw new \RuntimeException('Операция не найдена.');
            }
            if ((string)($operation['status'] ?? '') !== 'POSTED' || ($operation['cancelled_at'] ?? null) !== null) {
                throw new \RuntimeException('Распределять можно только действующие проведённые операции.');
            }
            $operationType = (string)($operation['operation_type'] ?? '');
            if (!in_array($operationType, ['INCOME','EXPENSE'], true)) {
                throw new \RuntimeException('Эту финансовую операцию нельзя распределять по счёту.');
            }

            $amount = self::normalizeMoneyInput((string)($data['amount'] ?? ''));
            if ($amount === null) {
                throw new \RuntimeException('Сумма распределения должна быть больше нуля.');
            }
            $operationRemaining = self::getOperationRemainingAmount($pdo, $operationId, (string)$operation['amount']);
            if (self::stringCompare($amount, $operationRemaining) > 0) {
                throw new \RuntimeException('Сумма распределения превышает остаток операции.');
            }

            $stmt = $pdo->prepare('SELECT * FROM finance_invoices WHERE id=? FOR UPDATE');
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new \RuntimeException('Счёт не найден.');
            }
            if ((string)($invoice['status'] ?? '') === 'cancelled' || ($invoice['cancelled_at'] ?? null) !== null) {
                throw new \RuntimeException('Нельзя распределить на аннулированный счёт.');
            }
            $expectedDirection = $operationType === 'INCOME' ? 'OUTGOING' : 'INCOMING';
            if ((string)($invoice['direction'] ?? '') !== $expectedDirection) {
                throw new \RuntimeException('Направление финансовой операции не соответствует направлению счёта.');
            }
            if (!self::counterpartyMatches($operation, $invoice)) {
                throw new \RuntimeException('Контрагент финансовой операции не совпадает с контрагентом счёта.');
            }
            $invoiceRemaining = self::getInvoiceRemainingAmount($pdo, $invoiceId, (string)$invoice['amount']);
            if (self::stringCompare($invoiceRemaining, '0.00') <= 0) {
                throw new \RuntimeException('Счёт уже оплачен.');
            }
            if (self::stringCompare($amount, $invoiceRemaining) > 0) {
                throw new \RuntimeException('Сумма распределения превышает остаток счёта.');
            }

            // replaceInvoiceLinks() locks the same invoice row before changing links. Rechecking while holding
            // the invoice lock closes the race between the initial standalone classification and this write.
            $linkStmt = $pdo->prepare('SELECT id FROM finance_invoice_links WHERE invoice_id=? ORDER BY id FOR UPDATE');
            $linkStmt->execute([$invoiceId]);
            if ($linkStmt->fetchColumn() !== false) {
                $pdo->rollBack();
                return null;
            }

            $allocationDate = trim((string)($data['allocation_date'] ?? date('Y-m-d')));
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $allocationDate);
            if (!$date || $date->format('Y-m-d') !== $allocationDate) {
                throw new \RuntimeException('Некорректная дата распределения.');
            }
            $comment = trim((string)($data['comment'] ?? ''));
            $comment = $comment !== '' ? $comment : null;
            $userId = (int)($user['user_id'] ?? 0);
            $roleCode = (string)($user['role_code'] ?? '');

            $insert = $pdo->prepare(
                "INSERT INTO finance_operation_allocations
                    (operation_id,invoice_id,obligation_id,linear_route_id,linear_route_payment_id,
                     amount,allocation_date,method,comment,created_by_user_id,created_by_role)
                 VALUES (?,?,NULL,NULL,NULL, ?,?,'manual',?,?,?)"
            );
            $insert->execute([
                $operationId,
                $invoiceId,
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
                'obligation_id' => null,
                'linear_route_id' => null,
                'linear_route_payment_id' => null,
                'settlement_class' => 'STANDALONE_INVOICE',
            ], $userId, $roleCode);
            FinanceSettlementCascadeService::cascadeAfterAllocationCreate($pdo, $allocationId);

            $pdo->commit();
            return $allocationId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
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
