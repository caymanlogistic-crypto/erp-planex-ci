<?php

namespace App\Service;

use PDO;

final class FinanceDashboardService
{
    public static function formatAmount(mixed $value): string
    {
        return FinanceOperationService::formatAmount($value);
    }

    public static function computeDashboard(PDO $localPdo): array
    {
        FinanceObligationService::syncAllOperationalObligations($localPdo);
        $uninvoicedObligations = FinanceUninvoicedObligationService::fetchOpen($localPdo, false);

        $bankAccounts = self::fetchBankAccountBalances($localPdo);
        $cashAccounts = self::fetchCashAccountBalances($localPdo);
        $totalCash = self::add($bankAccounts['total'], $cashAccounts['total']);
        $expectedInflow = self::fetchExpectedFlow($localPdo, 'OUTGOING', FinanceObligationService::DIRECTION_RECEIVABLE, $uninvoicedObligations);
        $expectedOutflow = self::fetchExpectedFlow($localPdo, 'INCOMING', FinanceObligationService::DIRECTION_PAYABLE, $uninvoicedObligations);
        $overdueReceivables = self::fetchOverdueFlow($localPdo, 'OUTGOING', FinanceObligationService::DIRECTION_RECEIVABLE, $uninvoicedObligations);
        $overduePayables = self::fetchOverdueFlow($localPdo, 'INCOMING', FinanceObligationService::DIRECTION_PAYABLE, $uninvoicedObligations);
        $unallocatedCount = self::fetchUnallocatedOperationCount($localPdo);
        $cashGap = self::fetchNearestCashGap($localPdo, $totalCash, $uninvoicedObligations);

        return [
            'bank_accounts' => $bankAccounts['accounts'],
            'bank_total' => $bankAccounts['total'],
            'cash_accounts' => $cashAccounts['accounts'],
            'cash_total' => $cashAccounts['total'],
            'total_cash' => $totalCash,
            'expected_inflow' => $expectedInflow,
            'expected_outflow' => $expectedOutflow,
            'overdue_receivables' => $overdueReceivables,
            'overdue_payables' => $overduePayables,
            'unallocated_count' => $unallocatedCount,
            'cash_gap_date' => $cashGap['date'],
            'cash_gap_amount' => $cashGap['amount'],
        ];
    }

    private static function fetchBankAccountBalances(PDO $localPdo): array
    {
        $accounts = [];
        $total = '0.00';
        foreach (FinanceBalanceService::allBalances($localPdo) as $account) {
            if (($account['type'] ?? '') !== 'BANK') {
                continue;
            }
            $accounts[] = [
                'id' => $account['id'],
                'name' => $account['name'],
                'balance' => $account['balance'],
            ];
            $total = self::add($total, (string)$account['balance']);
        }
        return ['accounts' => $accounts, 'total' => $total];
    }

    private static function fetchCashAccountBalances(PDO $localPdo): array
    {
        $accounts = [];
        $total = '0.00';
        foreach (FinanceBalanceService::allBalances($localPdo) as $account) {
            if (($account['type'] ?? '') !== 'CASH') {
                continue;
            }
            $accounts[] = [
                'id' => $account['id'],
                'name' => $account['name'],
                'balance' => $account['balance'],
            ];
            $total = self::add($total, (string)$account['balance']);
        }
        return ['accounts' => $accounts, 'total' => $total];
    }

    private static function fetchExpectedFlow(PDO $localPdo, string $invoiceDirection, string $obligationDirection, array $obligations): string
    {
        $total = self::fetchOpenInvoiceTotal($localPdo, $invoiceDirection, null);
        foreach ($obligations as $obligation) {
            if ((string)($obligation['direction'] ?? '') === $obligationDirection) {
                $total = self::add($total, (string)$obligation['uninvoiced_remaining_amount']);
            }
        }
        return $total;
    }

    private static function fetchOverdueFlow(PDO $localPdo, string $invoiceDirection, string $obligationDirection, array $obligations): string
    {
        $today = date('Y-m-d');
        $total = self::fetchOpenInvoiceTotal($localPdo, $invoiceDirection, $today);
        foreach ($obligations as $obligation) {
            if ((string)($obligation['direction'] ?? '') !== $obligationDirection) {
                continue;
            }
            $status = (string)($obligation['status'] ?? '');
            if (in_array($status, [FinanceObligationService::STATUS_OVERDUE, FinanceObligationService::STATUS_OVERDUE_PARTIAL], true)) {
                $total = self::add($total, (string)$obligation['uninvoiced_remaining_amount']);
            }
        }
        return $total;
    }

    private static function fetchOpenInvoiceTotal(PDO $localPdo, string $direction, ?string $overdueBefore): string
    {
        $sql = "SELECT COALESCE(SUM(fi.amount-COALESCE((
                            SELECT SUM(foa.amount)
                              FROM finance_operation_allocations foa
                             WHERE foa.invoice_id=fi.id
                               AND foa.cancelled_at IS NULL
                        ),0)),0) AS total
                  FROM finance_invoices fi
                 WHERE fi.direction=?
                   AND fi.status NOT IN ('cancelled','paid')
                   AND (fi.amount-COALESCE((
                        SELECT SUM(foa.amount)
                          FROM finance_operation_allocations foa
                         WHERE foa.invoice_id=fi.id
                           AND foa.cancelled_at IS NULL
                   ),0))>0";
        $params = [$direction];
        if ($overdueBefore !== null) {
            $sql .= " AND (
                EXISTS (
                    SELECT 1
                      FROM finance_invoice_links fil
                      JOIN finance_obligations o ON o.id=fil.obligation_id AND o.cancelled_at IS NULL
                     WHERE fil.invoice_id=fi.id
                       AND o.status IN ('overdue','overdue_partial')
                )
                OR (
                    NOT EXISTS (
                        SELECT 1 FROM finance_invoice_links fil0
                         WHERE fil0.invoice_id=fi.id AND fil0.obligation_id IS NOT NULL
                    )
                    AND fi.planned_payment_date IS NOT NULL
                    AND fi.planned_payment_date<?
                )
            )";
            $params[] = $overdueBefore;
        }
        $stmt = $localPdo->prepare($sql);
        $stmt->execute($params);
        return self::normalizeAmount($stmt->fetchColumn() ?: '0.00');
    }

    private static function fetchUnallocatedOperationCount(PDO $localPdo): int
    {
        $stmt = $localPdo->query(
            "SELECT COUNT(*) AS cnt
               FROM finance_operations fo
              WHERE fo.status='POSTED'
                AND (SELECT COALESCE(SUM(foa.amount),0)
                       FROM finance_operation_allocations foa
                      WHERE foa.operation_id=fo.id
                        AND foa.cancelled_at IS NULL)=0
                AND fo.operation_type IN ('INCOME','EXPENSE')"
        );
        return (int)($stmt->fetch(PDO::FETCH_ASSOC)['cnt'] ?? 0);
    }

    private static function fetchNearestCashGap(PDO $localPdo, string $currentCash, array $obligations): array
    {
        if (!self::isPositiveOrZero($currentCash)) {
            return ['date' => date('Y-m-d'), 'amount' => self::abs($currentCash)];
        }

        $rows = [];
        $stmt = $localPdo->query(
            "SELECT fi.id,
                    COALESCE(fi.planned_payment_date,fi.invoice_date) AS due_date,
                    CASE WHEN fi.direction='OUTGOING' THEN 'INFLOW' ELSE 'OUTFLOW' END AS flow,
                    (fi.amount-COALESCE((
                        SELECT SUM(foa.amount)
                          FROM finance_operation_allocations foa
                         WHERE foa.invoice_id=fi.id
                           AND foa.cancelled_at IS NULL
                    ),0)) AS remaining
               FROM finance_invoices fi
              WHERE fi.status NOT IN ('cancelled','paid')
                AND (fi.amount-COALESCE((
                    SELECT SUM(foa.amount)
                      FROM finance_operation_allocations foa
                     WHERE foa.invoice_id=fi.id
                       AND foa.cancelled_at IS NULL
                ),0))>0
                AND COALESCE(fi.planned_payment_date,fi.invoice_date) IS NOT NULL"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $row;
        }

        foreach ($obligations as $obligation) {
            $dueDate = trim((string)($obligation['effective_due_date'] ?? ''));
            if ($dueDate === '') {
                continue;
            }
            $rows[] = [
                'id' => (int)$obligation['id'],
                'due_date' => $dueDate,
                'flow' => (string)$obligation['direction'] === FinanceObligationService::DIRECTION_RECEIVABLE ? 'INFLOW' : 'OUTFLOW',
                'remaining' => (string)$obligation['uninvoiced_remaining_amount'],
            ];
        }

        usort($rows, static fn(array $left, array $right): int => strcmp((string)$left['due_date'], (string)$right['due_date']));

        $runningBalance = $currentCash;
        foreach ($rows as $row) {
            $amount = self::normalizeAmount($row['remaining'] ?? '0.00');
            $runningBalance = ($row['flow'] ?? '') === 'INFLOW'
                ? self::add($runningBalance, $amount)
                : self::sub($runningBalance, $amount);
            if (!self::isPositiveOrZero($runningBalance)) {
                return [
                    'date' => $row['due_date'],
                    'amount' => self::abs($runningBalance),
                ];
            }
        }

        return ['date' => null, 'amount' => '0.00'];
    }

    private static function normalizeAmount(mixed $value): string
    {
        $value = str_replace(',', '.', trim((string)$value));
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

    private static function parseCents(string $value): int
    {
        $value = self::normalizeAmount($value);
        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }
        [$whole, $fraction] = explode('.', $value, 2);
        $cents = ((int)$whole * 100) + (int)$fraction;
        return $negative ? -$cents : $cents;
    }

    private static function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function add(string $left, string $right): string
    {
        return self::formatCents(self::parseCents($left) + self::parseCents($right));
    }

    private static function sub(string $left, string $right): string
    {
        return self::formatCents(self::parseCents($left) - self::parseCents($right));
    }

    private static function isPositiveOrZero(string $value): bool
    {
        return self::parseCents($value) >= 0;
    }

    private static function abs(string $value): string
    {
        return self::formatCents(abs(self::parseCents($value)));
    }
}
