<?php

namespace App\Service;

use PDO;

/**
 * Canonical projection for the part of active payment obligations that is not
 * represented by a non-cancelled invoice yet.
 *
 * This keeps regular route payments and additional-service payments on the
 * same Finance V2 path and prevents reports from double-counting invoice-linked
 * obligation amounts.
 */
final class FinanceUninvoicedObligationService
{
    public static function fetchOpen(PDO $pdo, bool $sync = true): array
    {
        if (!FinanceObligationService::schemaReady($pdo)) {
            return [];
        }
        if ($sync) {
            FinanceObligationService::syncAllOperationalObligations($pdo);
        }

        $stmt = $pdo->query(
            "SELECT o.*,
                    COALESCE((
                        SELECT SUM(l.amount)
                          FROM finance_invoice_links l
                          JOIN finance_invoices i ON i.id=l.invoice_id
                         WHERE l.obligation_id=o.id
                           AND i.status<>'cancelled'
                    ),0) AS linked_invoice_amount,
                    COALESCE((
                        SELECT SUM(a.amount)
                          FROM finance_operation_allocations a
                          JOIN finance_operations op ON op.id=a.operation_id
                         WHERE a.obligation_id=o.id
                           AND a.cancelled_at IS NULL
                           AND op.status='POSTED'
                           AND (
                               a.invoice_id IS NULL
                               OR NOT EXISTS (
                                   SELECT 1
                                     FROM finance_invoices ai
                                    WHERE ai.id=a.invoice_id
                                      AND ai.status<>'cancelled'
                               )
                           )
                    ),0) AS direct_paid_amount,
                    (
                        SELECT MAX(a2.allocation_date)
                          FROM finance_operation_allocations a2
                          JOIN finance_operations op2 ON op2.id=a2.operation_id
                         WHERE a2.obligation_id=o.id
                           AND a2.cancelled_at IS NULL
                           AND op2.status='POSTED'
                           AND (
                               a2.invoice_id IS NULL
                               OR NOT EXISTS (
                                   SELECT 1
                                     FROM finance_invoices ai2
                                    WHERE ai2.id=a2.invoice_id
                                      AND ai2.status<>'cancelled'
                               )
                           )
                    ) AS max_direct_paid_date
               FROM finance_obligations o
              WHERE o.cancelled_at IS NULL
              ORDER BY COALESCE(o.due_date,o.forecast_due_date),o.id"
        );

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = self::money((string)($row['amount'] ?? '0.00'));
            $linked = self::money((string)($row['linked_invoice_amount'] ?? '0.00'));
            $available = self::maxZero(self::sub($amount, $linked));
            if (self::compare($available, '0.00') <= 0) {
                continue;
            }

            $directPaid = self::money((string)($row['direct_paid_amount'] ?? '0.00'));
            if (self::compare($directPaid, $available) > 0) {
                $directPaid = $available;
            }
            $remaining = self::maxZero(self::sub($available, $directPaid));
            if (self::compare($remaining, '0.00') <= 0) {
                continue;
            }

            $row['uninvoiced_amount'] = $available;
            $row['uninvoiced_paid_amount'] = $directPaid;
            $row['uninvoiced_remaining_amount'] = $remaining;
            $row['effective_due_date'] = self::validDate($row['due_date'] ?? null)
                ?? self::validDate($row['forecast_due_date'] ?? null);
            $result[] = $row;
        }

        FinanceObligationService::enrichParentLabels($pdo,$result);
        return $result;
    }

    private static function validDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
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
        $whole = ltrim($whole, '0');
        if ($whole === '') {
            $whole = '0';
        }
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

    private static function sub(string $left, string $right): string
    {
        return self::fromCents(self::cents($left) - self::cents($right));
    }

    private static function compare(string $left, string $right): int
    {
        return self::cents($left) <=> self::cents($right);
    }

    private static function maxZero(string $value): string
    {
        return self::compare($value, '0.00') < 0 ? '0.00' : self::money($value);
    }
}
