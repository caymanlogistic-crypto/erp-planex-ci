<?php

namespace App\Service;

use PDO;
use Throwable;

final class FinanceSettlementStateService
{
    /**
     * Returns effective bank classification statuses derived from completed invoice allocations.
     * This is read-only and lets the register reflect the actual settlement state even for
     * allocations created before status synchronization was introduced.
     *
     * @return array<int,string> bank_transaction_id => AUTO|MANUAL
     */
    public static function effectiveBankStatuses(PDO $pdo): array
    {
        $result = [];
        foreach (self::settlementSnapshots($pdo) as $row) {
            if (!self::isFullySettledByInvoices($row)) {
                continue;
            }
            $result[(int)$row['bank_transaction_id']] = self::resolvedStatus($row);
        }
        return $result;
    }

    /**
     * A client receipt is pending until its whole amount is linked to invoices.
     * Cash-flow categorization alone must not clear the bank register warning.
     *
     * @param array<int,string> $fullySettledStatuses
     * @return array<int,true>
     */
    public static function unallocatedClientReceiptIds(PDO $pdo, array $fullySettledStatuses): array
    {
        try {
            $stmt = $pdo->query(
                "SELECT DISTINCT fo.bank_transaction_id
                   FROM finance_operations fo
                   JOIN bank_transactions bt ON bt.id=fo.bank_transaction_id
                  WHERE fo.status='POSTED' AND fo.operation_type='INCOME'
                    AND (fo.counterparty_entity_type='client'
                         OR EXISTS (
                             SELECT 1 FROM clients c
                              WHERE c.inn=COALESCE(NULLIF(TRIM(fo.counterparty_inn),''),TRIM(bt.counterparty_inn))
                         ))"
            );
            $pending = [];
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $id = (int)$id;
                if ($id > 0 && !isset($fullySettledStatuses[$id])) {
                    $pending[$id] = true;
                }
            }
            return $pending;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Persists the settlement state after automatic/manual invoice allocation and repairs
     * previously created allocations whose bank/invoice status was left stale.
     */
    public static function syncPersistedStatuses(PDO $pdo): array
    {
        $summary = ['transactions' => 0, 'invoices' => 0, 'auto' => 0, 'manual' => 0];
        $invoiceIds = [];

        foreach (self::settlementSnapshots($pdo) as $row) {
            if (!self::isFullySettledByInvoices($row)) {
                continue;
            }

            $status = self::resolvedStatus($row);
            $locked = $status === 'MANUAL' ? 1 : 0;
            $bankTransactionId = (int)$row['bank_transaction_id'];
            $operationId = (int)$row['operation_id'];

            $stmt = $pdo->prepare(
                'UPDATE bank_transactions
                    SET classification_status=?, classification_rule_id=NULL,
                        classification_locked=?, classification_updated_at=NOW()
                  WHERE id=?'
            );
            $stmt->execute([$status, $locked, $bankTransactionId]);

            $stmt = $pdo->prepare(
                'UPDATE finance_operations
                    SET classification_status=?, classification_rule_id=NULL,
                        classification_locked=?, classification_updated_at=NOW()
                  WHERE id=?'
            );
            $stmt->execute([$status, $locked, $operationId]);

            foreach (self::parseInvoiceIds((string)($row['invoice_ids'] ?? '')) as $invoiceId) {
                $invoiceIds[$invoiceId] = true;
            }

            $summary['transactions']++;
            $summary[strtolower($status)]++;
        }

        foreach (array_keys($invoiceIds) as $invoiceId) {
            FinanceSettlementCascadeService::recalculateInvoice($pdo, (int)$invoiceId, 0, 'system');
            $summary['invoices']++;
        }

        return $summary;
    }

    private static function settlementSnapshots(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query(
                "SELECT fo.id AS operation_id,
                        fo.bank_transaction_id,
                        fo.amount AS operation_amount,
                        COALESCE(SUM(CASE WHEN a.cancelled_at IS NULL THEN a.amount ELSE 0 END),0) AS allocated_total,
                        COALESCE(SUM(CASE WHEN a.cancelled_at IS NULL AND a.invoice_id IS NOT NULL THEN a.amount ELSE 0 END),0) AS invoice_allocated_total,
                        MAX(CASE WHEN a.cancelled_at IS NULL AND a.invoice_id IS NOT NULL AND COALESCE(a.method,'') NOT LIKE 'auto%' THEN 1 ELSE 0 END) AS has_manual_allocation,
                        MAX(CASE WHEN bt.classification_locked=1 OR UPPER(COALESCE(bt.classification_status,''))='MANUAL'
                                      OR fo.classification_locked=1 OR UPPER(COALESCE(fo.classification_status,''))='MANUAL'
                                 THEN 1 ELSE 0 END) AS manually_protected,
                        GROUP_CONCAT(DISTINCT CASE WHEN a.cancelled_at IS NULL AND a.invoice_id IS NOT NULL THEN a.invoice_id END ORDER BY a.invoice_id SEPARATOR ',') AS invoice_ids
                   FROM finance_operations fo
                   JOIN bank_transactions bt ON bt.id=fo.bank_transaction_id
              LEFT JOIN finance_operation_allocations a ON a.operation_id=fo.id
                  WHERE fo.status='POSTED'
                    AND fo.bank_transaction_id IS NOT NULL
               GROUP BY fo.id,fo.bank_transaction_id,fo.amount
                 HAVING invoice_allocated_total>0"
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private static function isFullySettledByInvoices(array $row): bool
    {
        $amount = self::cents((string)($row['operation_amount'] ?? '0'));
        $allocated = self::cents((string)($row['allocated_total'] ?? '0'));
        $invoiceAllocated = self::cents((string)($row['invoice_allocated_total'] ?? '0'));
        return $amount > 0 && $allocated === $amount && $invoiceAllocated === $amount;
    }

    private static function resolvedStatus(array $row): string
    {
        return !empty($row['manually_protected']) || !empty($row['has_manual_allocation']) ? 'MANUAL' : 'AUTO';
    }

    /** @return int[] */
    private static function parseInvoiceIds(string $value): array
    {
        $ids = [];
        foreach (explode(',', $value) as $part) {
            $id = (int)trim($part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    private static function cents(string $value): int
    {
        $value = trim(str_replace(',', '.', $value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            return 0;
        }
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $result = ((int)$whole * 100) + (int)$fraction;
        return $negative ? -$result : $result;
    }
}
