<?php

namespace App\Service;

require_once __DIR__.'/WasteTripClosingService.php';

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class FinanceSettlementCascadeService
{
    private const STATUS_UNPAID = 'unpaid';
    private const STATUS_PARTIALLY_PAID = 'partially_paid';
    private const STATUS_PAID = 'paid';
    private const STATUS_OVERDUE = 'overdue';
    private const STATUS_OVERDUE_PARTIAL = 'overdue_partial';
    private const STATUS_CANCELLED = 'cancelled';

    public static function recalculateInvoice(PDO $pdo, int $invoiceId, int $userId = 0, string $roleCode = 'system'): void
    {
        self::transactional($pdo, function () use ($pdo, $invoiceId, $userId, $roleCode): void {
            $stmt = $pdo->prepare('SELECT * FROM finance_invoices WHERE id = ? FOR UPDATE');
            $stmt->execute([$invoiceId]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                throw new RuntimeException('Счёт не найден для пересчёта.');
            }
            if (($invoice['cancelled_at'] ?? null) !== null || ($invoice['status'] ?? null) === self::STATUS_CANCELLED) {
                return;
            }

            $paid = self::getInvoicePaidAmount($pdo, $invoiceId);
            $amount = self::normalizeMoney((string)($invoice['amount'] ?? ''));
            $remaining = self::stringSub($amount, $paid);
            $today = date('Y-m-d');
            $plannedDate = $invoice['planned_payment_date'] ?? null;
            if ($plannedDate !== null && !self::isValidDate((string)$plannedDate)) {
                throw new RuntimeException('Некорректная плановая дата оплаты счёта.');
            }
            $oldStatus = (string)($invoice['status'] ?? self::STATUS_UNPAID);

            if (self::stringCompare($remaining, '0.00') <= 0) {
                $newStatus = self::STATUS_PAID;
            } elseif (self::stringCompare($paid, '0.00') > 0) {
                $newStatus = $plannedDate !== null && $plannedDate < $today
                    ? self::STATUS_OVERDUE_PARTIAL
                    : self::STATUS_PARTIALLY_PAID;
            } elseif ($oldStatus !== 'draft' && $plannedDate !== null && $plannedDate < $today) {
                $newStatus = self::STATUS_OVERDUE;
            } else {
                $newStatus = self::invoiceBaseStatus($invoice, $oldStatus);
            }

            $firstPaidAt = $invoice['first_paid_at'] ?? null;
            $fullyPaidAt = $invoice['fully_paid_at'] ?? null;
            if (self::stringCompare($paid, '0.00') > 0 && $firstPaidAt === null) {
                $firstPaidAt = self::findFirstAllocationDate($pdo, $invoiceId);
            }
            if ($newStatus === self::STATUS_PAID && $fullyPaidAt === null) {
                $fullyPaidAt = $today;
            } elseif ($newStatus !== self::STATUS_PAID) {
                $fullyPaidAt = null;
            }

            $upd = $pdo->prepare(
                'UPDATE finance_invoices
                    SET status = :status, paid_amount = :paid_amount,
                        first_paid_at = :first_paid_at, fully_paid_at = :fully_paid_at,
                        updated_at = NOW()
                  WHERE id = :id'
            );
            $upd->execute([
                ':status' => $newStatus, ':paid_amount' => $paid,
                ':first_paid_at' => $firstPaidAt, ':fully_paid_at' => $fullyPaidAt,
                ':id' => $invoiceId,
            ]);

            if ($newStatus !== $oldStatus) {
                FinanceAuditLogService::logStatusChange($pdo, 'finance_invoice', $invoiceId, $oldStatus, $newStatus, $userId, $roleCode);
            }
        });
    }

    public static function recalculateRoutePayment(PDO $pdo, int $paymentId, int $userId = 0, string $roleCode = 'system'): void
    {
        self::transactional($pdo, function () use ($pdo, $paymentId, $userId, $roleCode): void {
            $stmt = $pdo->prepare(
                'SELECT lrp.*, lr.closing_documents_received_date
                   FROM linear_route_payments lrp
                   JOIN linear_routes lr ON lr.id = lrp.linear_route_id
                  WHERE lrp.id = ? AND lrp.deleted_at IS NULL
                  FOR UPDATE'
            );
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                throw new RuntimeException('Платёжная строка не найдена для пересчёта.');
            }

            $paid = self::getRoutePaymentPaidAmount($pdo, $paymentId);
            $amount = self::normalizeMoney((string)($payment['amount'] ?? ''));
            $oldPaidAmount = self::normalizeMoney((string)($payment['paid_amount'] ?? '0.00'), true);
            $oldStatus = (string)($payment['payment_status'] ?? RoutePaymentStatusService::STATUS_PLANNED);

            $computedStatus = RoutePaymentStatusService::computeStatus(
                $amount,
                $paid,
                $payment['calculated_due_date'] ?? null,
                $payment['cancelled_at'] ?? null,
                $payment['closing_documents_received_date'] ?? null,
                $payment['condition_type'] ?? null
            );

            $paidAt = $payment['paid_at'] ?? null;
            if ($computedStatus === RoutePaymentStatusService::STATUS_PAID && $paidAt === null) {
                $paidAt = date('Y-m-d');
            } elseif ($computedStatus !== RoutePaymentStatusService::STATUS_PAID) {
                $paidAt = null;
            }

            $upd = $pdo->prepare(
                'UPDATE linear_route_payments
                    SET paid_amount = :paid_amount, payment_status = :payment_status,
                        paid_at = :paid_at,
                        status_updated_at = CASE WHEN :status_for_compare != :old_status THEN NOW() ELSE status_updated_at END
                  WHERE id = :id'
            );
            $upd->execute([
                ':paid_amount' => $paid, ':payment_status' => $computedStatus,
                ':paid_at' => $paidAt, ':status_for_compare' => $computedStatus,
                ':old_status' => $oldStatus, ':id' => $paymentId,
            ]);

            if ($computedStatus !== $oldStatus || $paid !== $oldPaidAmount) {
                FinanceAuditLogService::log(
                    $pdo,
                    'linear_route_payment',
                    $paymentId,
                    'settlement_recalculate',
                    ['payment_status' => $oldStatus, 'paid_amount' => $oldPaidAmount],
                    ['payment_status' => $computedStatus, 'paid_amount' => $paid],
                    $userId,
                    $roleCode
                );
            }
        });
    }

    public static function cascadeAfterAllocationCreate(PDO $pdo, int $allocationId): void
    {
        self::cascade($pdo, $allocationId, false);
    }

    public static function cascadeAfterAllocationCancel(PDO $pdo, int $allocationId): void
    {
        self::cascade($pdo, $allocationId, true);
    }

    private static function cascade(PDO $pdo, int $allocationId, bool $cancelled): void
    {
        self::transactional($pdo, function () use ($pdo, $allocationId, $cancelled): void {
            $stmt = $pdo->prepare('SELECT * FROM finance_operation_allocations WHERE id = ? FOR UPDATE');
            $stmt->execute([$allocationId]);
            $allocation = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$allocation) {
                throw new RuntimeException('Распределение не найдено для каскадного пересчёта.');
            }
            $userId = (int)($cancelled
                ? ($allocation['cancelled_by_user_id'] ?? 0)
                : ($allocation['created_by_user_id'] ?? 0));
            $roleCode = (string)($cancelled
                ? ($allocation['cancelled_by_role'] ?? 'system')
                : ($allocation['created_by_role'] ?? 'system'));
            if ($roleCode === '') {
                $roleCode = 'system';
            }

            $obligation = self::allocationObligation($pdo, $allocation);
            $isAdditionalService = $obligation !== null
                && (string)($obligation['source_type'] ?? '') === 'LINEAR_ROUTE_SERVICE_PAYMENT'
                && (string)($obligation['source_parent_type'] ?? '') === 'LINEAR_ROUTE'
                && (int)($obligation['source_parent_id'] ?? 0) > 0;
            $isWasteTrip = $obligation !== null
                && (string)($obligation['source_parent_type'] ?? '') === 'WASTE_TRIP'
                && in_array((string)($obligation['source_type'] ?? ''), ['WASTE_TRIP_CUSTOMER_PAYMENT','WASTE_TRIP_CARRIER_PAYMENT'], true)
                && (int)($obligation['source_parent_id'] ?? 0) > 0;

            if (($allocation['invoice_id'] ?? null) !== null) {
                self::recalculateInvoice($pdo, (int)$allocation['invoice_id'], $userId, $roleCode);
            }
            if (($allocation['linear_route_payment_id'] ?? null) !== null) {
                self::recalculateRoutePayment($pdo, (int)$allocation['linear_route_payment_id'], $userId, $roleCode);
            } elseif ($isAdditionalService) {
                LinearTripServiceFinanceService::syncRoute($pdo, (int)$obligation['source_parent_id']);
            } elseif ($isWasteTrip) {
                FinanceObligationService::syncWasteTrip($pdo, (int)$obligation['source_parent_id']);
                if ((string)$obligation['source_type']==='WASTE_TRIP_CUSTOMER_PAYMENT') {
                    WasteTripClosingService::syncFinance($pdo, (int)$obligation['source_parent_id']);
                }
            } elseif (($allocation['linear_route_id'] ?? null) !== null) {
                foreach (self::getRoutePaymentsForRoute($pdo, (int)$allocation['linear_route_id']) as $payment) {
                    self::recalculateRoutePayment($pdo, (int)$payment['id'], $userId, $roleCode);
                }
            }
        });
    }

    private static function allocationObligation(PDO $pdo, array $allocation): ?array
    {
        $obligationId = (int)($allocation['obligation_id'] ?? 0);
        if ($obligationId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT id,source_type,source_parent_type,source_parent_id
               FROM finance_obligations
              WHERE id=?
              LIMIT 1'
        );
        $stmt->execute([$obligationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function getInvoicePaidAmount(PDO $pdo, int $invoiceId): string
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(foa.amount), 0)
               FROM finance_operation_allocations foa
               JOIN finance_operations fo ON fo.id = foa.operation_id
              WHERE foa.invoice_id = ?
                AND foa.cancelled_at IS NULL
                AND fo.status = 'POSTED'"
        );
        $stmt->execute([$invoiceId]);
        return self::normalizeMoney((string)($stmt->fetchColumn() ?: '0.00'), true);
    }

    public static function getInvoiceRemainingAmount(PDO $pdo, int $invoiceId, ?string $invoiceAmount = null): string
    {
        if ($invoiceAmount === null) {
            $stmt = $pdo->prepare('SELECT amount FROM finance_invoices WHERE id = ?');
            $stmt->execute([$invoiceId]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                throw new RuntimeException('Счёт не найден.');
            }
            $invoiceAmount = (string)$value;
        }
        return self::stringSub(self::normalizeMoney($invoiceAmount), self::getInvoicePaidAmount($pdo, $invoiceId));
    }

    public static function getRoutePaymentPaidAmount(PDO $pdo, int $paymentId): string
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(foa.amount), 0)
               FROM finance_operation_allocations foa
               JOIN finance_operations fo ON fo.id = foa.operation_id
              WHERE foa.linear_route_payment_id = ?
                AND foa.cancelled_at IS NULL
                AND fo.status = 'POSTED'"
        );
        $stmt->execute([$paymentId]);
        return self::normalizeMoney((string)($stmt->fetchColumn() ?: '0.00'), true);
    }

    public static function getRoutePaymentRemainingAmount(PDO $pdo, int $paymentId, ?string $paymentAmount = null): string
    {
        if ($paymentAmount === null) {
            $stmt = $pdo->prepare('SELECT amount FROM linear_route_payments WHERE id = ?');
            $stmt->execute([$paymentId]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                throw new RuntimeException('Платёжная строка не найдена.');
            }
            $paymentAmount = (string)$value;
        }
        return self::stringSub(self::normalizeMoney($paymentAmount), self::getRoutePaymentPaidAmount($pdo, $paymentId));
    }

    public static function computeInvoiceDisplayStatus(?string $status, ?string $paid, ?string $amount, ?string $plannedDate, ?string $cancelledAt): array
    {
        $paid = self::normalizeMoney($paid ?? '0.00', true);
        $amount = self::normalizeMoney($amount ?? '0.00', true);
        if ($plannedDate !== null && !self::isValidDate($plannedDate)) {
            throw new InvalidArgumentException('Некорректная плановая дата оплаты.');
        }
        $remaining = self::stringSub($amount, $paid);
        $displayStatus = self::STATUS_UNPAID;
        $label = 'Не оплачен';

        if ($cancelledAt !== null || $status === self::STATUS_CANCELLED) {
            return ['status' => self::STATUS_CANCELLED, 'label' => 'Отменён'];
        }
        if (self::stringCompare($remaining, '0.00') <= 0) {
            return ['status' => self::STATUS_PAID, 'label' => 'Оплачен'];
        }
        if (self::stringCompare($paid, '0.00') > 0) {
            return $plannedDate !== null && $plannedDate < date('Y-m-d')
                ? ['status' => self::STATUS_OVERDUE_PARTIAL, 'label' => 'Частично оплачен, просрочен']
                : ['status' => self::STATUS_PARTIALLY_PAID, 'label' => 'Частично оплачен'];
        }
        if ($plannedDate !== null && $plannedDate < date('Y-m-d') && $status !== 'draft') {
            $displayStatus = self::STATUS_OVERDUE;
            $label = 'Просрочен';
        }
        return ['status' => $displayStatus, 'label' => $label];
    }

    public static function getRoutePaymentsForRoute(PDO $pdo, int $routeId): array
    {
        $stmt = $pdo->prepare('SELECT id FROM linear_route_payments WHERE linear_route_id = ? AND deleted_at IS NULL FOR UPDATE');
        $stmt->execute([$routeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function invoiceBaseStatus(array $invoice, string $oldStatus): string
    {
        if (in_array($oldStatus, ['draft', 'issued', 'received'], true)) {
            return $oldStatus;
        }
        return match (strtoupper((string)($invoice['direction'] ?? ''))) {
            'OUTGOING' => 'issued',
            'INCOMING' => 'received',
            default => throw new RuntimeException('Не удалось определить базовый статус счёта.'),
        };
    }

    private static function findFirstAllocationDate(PDO $pdo, int $invoiceId): ?string
    {
        $stmt = $pdo->prepare(
            "SELECT MIN(foa.allocation_date)
               FROM finance_operation_allocations foa
               JOIN finance_operations fo ON fo.id = foa.operation_id
              WHERE foa.invoice_id = ? AND foa.cancelled_at IS NULL AND fo.status = 'POSTED'"
        );
        $stmt->execute([$invoiceId]);
        $value = $stmt->fetchColumn();
        return $value !== false && $value !== null ? (string)$value : null;
    }

    private static function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && (!is_array($errors) || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            && $parsed->format('Y-m-d') === $date;
    }

    private static function transactional(PDO $pdo, callable $callback): void
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function normalizeMoney(string $value, bool $allowZero = false): string
    {
        if (preg_match('/^\d+(?:\.\d{1,2})?$/D', $value) !== 1) {
            throw new InvalidArgumentException('Некорректное денежное значение.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $normalized = ltrim($whole, '0');
        $normalized = ($normalized === '' ? '0' : $normalized) . '.' . str_pad($fraction, 2, '0');
        if (!$allowZero && $normalized === '0.00') {
            throw new InvalidArgumentException('Сумма должна быть больше нуля.');
        }
        return $normalized;
    }

    private static function stringSub(string $left, string $right): string
    {
        $result = self::toCents($left) - self::toCents($right);
        $sign = $result < 0 ? '-' : '';
        $absolute = abs($result);
        return $sign . intdiv($absolute, 100) . '.' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function stringCompare(string $left, string $right): int
    {
        return self::toCents($left) <=> self::toCents($right);
    }

    private static function toCents(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        if (preg_match('/^\d+(?:\.\d{1,2})?$/D', $unsigned) !== 1) {
            throw new InvalidArgumentException('Некорректное денежное значение.');
        }
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $cents = ((int)$whole * 100) + (int)str_pad($fraction, 2, '0');
        return $negative ? -$cents : $cents;
    }
}
