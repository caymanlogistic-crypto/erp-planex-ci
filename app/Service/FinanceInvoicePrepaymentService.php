<?php

namespace App\Service;

use PDO;
use RuntimeException;

/**
 * Reconciles direct obligation settlements with invoices that are attached later.
 *
 * A payment may legitimately happen before a supplier/client invoice is registered.
 * When that invoice is later linked to the already-settled obligation, the existing
 * allocation is re-attributed to the invoice instead of creating a second economic
 * liability in invoice-based reports. The underlying finance operation and total
 * amount allocated to the obligation never change.
 */
final class FinanceInvoicePrepaymentService
{
    public const METHOD_INHERITED_PREPAYMENT = 'prepayment_inherit';

    /**
     * @return array{allocations:int,amount:string}
     */
    public static function reconcileInvoice(PDO $pdo, int $invoiceId, array $user = []): array
    {
        if ($invoiceId <= 0 || !FinanceObligationService::schemaReady($pdo)) {
            return ['allocations' => 0, 'amount' => '0.00'];
        }

        $invoiceStmt = $pdo->prepare('SELECT * FROM finance_invoices WHERE id=? FOR UPDATE');
        $invoiceStmt->execute([$invoiceId]);
        $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Счёт не найден для переноса предоплаты.');
        }
        if (($invoice['cancelled_at'] ?? null) !== null || (string)($invoice['status'] ?? '') === 'cancelled') {
            return ['allocations' => 0, 'amount' => '0.00'];
        }

        $linksStmt = $pdo->prepare(
            "SELECT l.id,l.obligation_id,l.amount
               FROM finance_invoice_links l
               JOIN finance_obligations o ON o.id=l.obligation_id AND o.cancelled_at IS NULL
              WHERE l.invoice_id=? AND l.obligation_id IS NOT NULL
              ORDER BY l.id
              FOR UPDATE"
        );
        $linksStmt->execute([$invoiceId]);
        $links = $linksStmt->fetchAll(PDO::FETCH_ASSOC);
        if ($links === []) {
            FinanceSettlementCascadeService::recalculateInvoice(
                $pdo,
                $invoiceId,
                (int)($user['user_id'] ?? 0),
                (string)($user['role_code'] ?? '') ?: 'system'
            );
            return ['allocations' => 0, 'amount' => '0.00'];
        }

        $invoicePaid = self::invoicePostedPaidAmount($pdo, $invoiceId);
        $invoiceRemaining = self::maxZero(self::subMoney((string)$invoice['amount'], $invoicePaid));
        if (self::compareMoney($invoiceRemaining, '0.00') <= 0) {
            FinanceSettlementCascadeService::recalculateInvoice(
                $pdo,
                $invoiceId,
                (int)($user['user_id'] ?? 0),
                (string)($user['role_code'] ?? '') ?: 'system'
            );
            return ['allocations' => 0, 'amount' => '0.00'];
        }

        $movedCount = 0;
        $movedAmount = '0.00';
        $userId = (int)($user['user_id'] ?? 0);
        $roleCode = (string)($user['role_code'] ?? '') ?: 'system';

        foreach ($links as $link) {
            if (self::compareMoney($invoiceRemaining, '0.00') <= 0) {
                break;
            }
            $obligationId = (int)($link['obligation_id'] ?? 0);
            if ($obligationId <= 0) {
                continue;
            }

            $alreadyOnLink = self::invoiceObligationPostedPaidAmount($pdo, $invoiceId, $obligationId);
            $linkRemaining = self::subMoney((string)$link['amount'], $alreadyOnLink);
            if (self::compareMoney($linkRemaining, '0.00') < 0) {
                throw new RuntimeException('Оплата по обязательству превышает сумму его связи со счётом.');
            }
            if (self::compareMoney($linkRemaining, '0.00') <= 0) {
                continue;
            }

            $needed = self::minMoney($linkRemaining, $invoiceRemaining);
            $candidates = self::directCandidates($pdo, $obligationId);
            foreach ($candidates as $allocation) {
                if (self::compareMoney($needed, '0.00') <= 0) {
                    break;
                }
                $available = self::money((string)($allocation['amount'] ?? '0.00'));
                if (self::compareMoney($available, '0.00') <= 0) {
                    continue;
                }
                $take = self::minMoney($available, $needed);
                $allocationId = (int)$allocation['id'];
                $oldInvoiceId = isset($allocation['invoice_id']) ? (int)$allocation['invoice_id'] : null;

                if (self::compareMoney($take, $available) === 0) {
                    $update = $pdo->prepare(
                        'UPDATE finance_operation_allocations
                            SET invoice_id=?,method=?,comment=?
                          WHERE id=? AND cancelled_at IS NULL'
                    );
                    $update->execute([
                        $invoiceId,
                        self::METHOD_INHERITED_PREPAYMENT,
                        self::inheritComment((string)($allocation['comment'] ?? ''), $invoiceId),
                        $allocationId,
                    ]);

                    FinanceAuditLogService::log(
                        $pdo,
                        'finance_allocation',
                        $allocationId,
                        'prepayment_inherit_invoice',
                        ['invoice_id' => $oldInvoiceId, 'amount' => $available, 'method' => $allocation['method'] ?? null],
                        ['invoice_id' => $invoiceId, 'amount' => $available, 'method' => self::METHOD_INHERITED_PREPAYMENT],
                        $userId,
                        $roleCode
                    );
                    $movedCount++;
                } else {
                    $directRemainder = self::subMoney($available, $take);
                    $pdo->prepare('UPDATE finance_operation_allocations SET amount=? WHERE id=? AND cancelled_at IS NULL')
                        ->execute([$directRemainder, $allocationId]);

                    $insert = $pdo->prepare(
                        "INSERT INTO finance_operation_allocations
                            (operation_id,invoice_id,obligation_id,linear_route_id,linear_route_payment_id,
                             amount,allocation_date,method,comment,created_by_user_id,created_by_role)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                    );
                    $insert->execute([
                        (int)$allocation['operation_id'],
                        $invoiceId,
                        $obligationId,
                        $allocation['linear_route_id'] !== null ? (int)$allocation['linear_route_id'] : null,
                        $allocation['linear_route_payment_id'] !== null ? (int)$allocation['linear_route_payment_id'] : null,
                        $take,
                        (string)$allocation['allocation_date'],
                        self::METHOD_INHERITED_PREPAYMENT,
                        self::inheritComment((string)($allocation['comment'] ?? ''), $invoiceId),
                        $allocation['created_by_user_id'] !== null ? (int)$allocation['created_by_user_id'] : null,
                        (string)($allocation['created_by_role'] ?? '') ?: null,
                    ]);
                    $newAllocationId = (int)$pdo->lastInsertId();

                    FinanceAuditLogService::log(
                        $pdo,
                        'finance_allocation',
                        $allocationId,
                        'prepayment_split_direct_remainder',
                        ['amount' => $available, 'invoice_id' => $oldInvoiceId],
                        ['amount' => $directRemainder, 'invoice_id' => $oldInvoiceId],
                        $userId,
                        $roleCode
                    );
                    FinanceAuditLogService::log(
                        $pdo,
                        'finance_allocation',
                        $newAllocationId,
                        'prepayment_inherit_invoice',
                        null,
                        ['source_allocation_id' => $allocationId, 'invoice_id' => $invoiceId, 'amount' => $take, 'method' => self::METHOD_INHERITED_PREPAYMENT],
                        $userId,
                        $roleCode
                    );
                    $movedCount++;
                }

                $movedAmount = self::addMoney($movedAmount, $take);
                $needed = self::subMoney($needed, $take);
                $invoiceRemaining = self::subMoney($invoiceRemaining, $take);
            }
        }

        FinanceSettlementCascadeService::recalculateInvoice($pdo, $invoiceId, $userId, $roleCode);
        return ['allocations' => $movedCount, 'amount' => $movedAmount];
    }

    public static function directPrepaymentAmount(PDO $pdo, int $obligationId): string
    {
        if ($obligationId <= 0) {
            return '0.00';
        }
        $total = '0.00';
        foreach (self::directCandidates($pdo, $obligationId) as $allocation) {
            $total = self::addMoney($total, (string)$allocation['amount']);
        }
        return $total;
    }

    private static function directCandidates(PDO $pdo, int $obligationId): array
    {
        $stmt = $pdo->prepare(
            "SELECT a.*
               FROM finance_operation_allocations a
               JOIN finance_operations op ON op.id=a.operation_id
          LEFT JOIN finance_invoices i ON i.id=a.invoice_id
              WHERE a.obligation_id=?
                AND a.cancelled_at IS NULL
                AND op.status='POSTED'
                AND (
                    a.invoice_id IS NULL
                    OR i.id IS NULL
                    OR i.status='cancelled'
                    OR i.cancelled_at IS NOT NULL
                )
              ORDER BY a.allocation_date,a.id
              FOR UPDATE"
        );
        $stmt->execute([$obligationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function invoicePostedPaidAmount(PDO $pdo, int $invoiceId): string
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(a.amount),0)
               FROM finance_operation_allocations a
               JOIN finance_operations op ON op.id=a.operation_id
              WHERE a.invoice_id=? AND a.cancelled_at IS NULL AND op.status='POSTED'"
        );
        $stmt->execute([$invoiceId]);
        return self::money((string)$stmt->fetchColumn());
    }

    private static function invoiceObligationPostedPaidAmount(PDO $pdo, int $invoiceId, int $obligationId): string
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

    private static function inheritComment(string $comment, int $invoiceId): string
    {
        $comment = trim($comment);
        $marker = 'Предоплата автоматически отнесена на счёт #' . $invoiceId . '.';
        if ($comment === '') {
            return $marker;
        }
        if (str_contains($comment, $marker)) {
            return $comment;
        }
        return $comment . ' ' . $marker;
    }

    private static function money(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            return '0.00';
        }
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    private static function cents(string $value): int
    {
        $value = self::money($value);
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }
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

    private static function addMoney(string $left, string $right): string
    {
        return self::fromCents(self::cents($left) + self::cents($right));
    }

    private static function subMoney(string $left, string $right): string
    {
        return self::fromCents(self::cents($left) - self::cents($right));
    }

    private static function compareMoney(string $left, string $right): int
    {
        return self::cents($left) <=> self::cents($right);
    }

    private static function minMoney(string $left, string $right): string
    {
        return self::compareMoney($left, $right) <= 0 ? self::money($left) : self::money($right);
    }

    private static function maxZero(string $value): string
    {
        return self::compareMoney($value, '0.00') < 0 ? '0.00' : self::money($value);
    }
}
