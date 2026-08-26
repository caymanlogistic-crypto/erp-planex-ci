<?php

namespace App\Service;

use PDO;

final class FinanceManagementBalanceService
{
    public static function formatAmount(mixed $value): string
    {
        return FinanceOperationService::formatAmount($value);
    }

    public static function computeBalance(PDO $localPdo, string $asOfDate): array
    {
        FinanceObligationService::syncAllLinearRoutes($localPdo);

        $moneyAccounts = self::fetchMoneyAccountBalances($localPdo, $asOfDate);
        $receivables = self::fetchReceivables($localPdo, $asOfDate);
        $payables = self::fetchPayables($localPdo, $asOfDate);

        $totalCash = '0.00';
        foreach ($moneyAccounts as $account) {
            $totalCash = self::stringAdd($totalCash, (string)$account['balance']);
        }

        $totalReceivables = '0.00';
        foreach ($receivables as $row) {
            $totalReceivables = self::stringAdd($totalReceivables, (string)$row['remaining']);
        }

        $totalPayables = '0.00';
        foreach ($payables as $row) {
            $totalPayables = self::stringAdd($totalPayables, (string)$row['remaining']);
        }

        $totalAssets = self::stringAdd($totalCash, $totalReceivables);
        $netAssets = self::stringSub($totalAssets, $totalPayables);

        return [
            'as_of_date' => $asOfDate,
            'money_accounts' => $moneyAccounts,
            'receivables' => $receivables,
            'payables' => $payables,
            'total_cash' => $totalCash,
            'total_receivables' => $totalReceivables,
            'total_payables' => $totalPayables,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalPayables,
            'net_assets' => $netAssets,
            'advances_given' => '0.00',
            'advances_received' => '0.00',
        ];
    }

    private static function fetchMoneyAccountBalances(PDO $localPdo, string $asOfDate): array
    {
        $accounts = [];
        foreach (FinanceBalanceService::allBalances($localPdo, $asOfDate) as $account) {
            $accounts[] = [
                'id' => $account['id'],
                'name' => $account['name'],
                'type' => $account['type'] === 'CASH' ? 'Касса' : 'Расчётный счёт',
                'balance' => $account['balance'],
            ];
        }
        return $accounts;
    }

    private static function fetchReceivables(PDO $localPdo, string $asOfDate): array
    {
        $rows = self::fetchOpenInvoices($localPdo, 'OUTGOING', 'Дебиторская задолженность', $asOfDate);
        foreach (FinanceUninvoicedObligationService::fetchOpen($localPdo, false) as $obligation) {
            if ((string)$obligation['direction'] !== FinanceObligationService::DIRECTION_RECEIVABLE) {
                continue;
            }
            if (!self::isVisibleAsOf($obligation['effective_due_date'] ?? null, $asOfDate)) {
                continue;
            }
            $rows[] = self::obligationBalanceRow($obligation, 'Дебиторская задолженность');
        }
        return $rows;
    }

    private static function fetchPayables(PDO $localPdo, string $asOfDate): array
    {
        $rows = self::fetchOpenInvoices($localPdo, 'INCOMING', 'Кредиторская задолженность', $asOfDate);
        foreach (FinanceUninvoicedObligationService::fetchOpen($localPdo, false) as $obligation) {
            if ((string)$obligation['direction'] !== FinanceObligationService::DIRECTION_PAYABLE) {
                continue;
            }
            if (!self::isVisibleAsOf($obligation['effective_due_date'] ?? null, $asOfDate)) {
                continue;
            }
            $rows[] = self::obligationBalanceRow($obligation, 'Кредиторская задолженность');
        }
        return $rows;
    }

    private static function fetchOpenInvoices(PDO $localPdo, string $direction, string $group, string $asOfDate): array
    {
        $stmt = $localPdo->prepare(
            "SELECT id, number, counterparty_name,
                    amount,
                    COALESCE((SELECT SUM(foa.amount)
                                FROM finance_operation_allocations foa
                               WHERE foa.invoice_id=finance_invoices.id
                                 AND foa.cancelled_at IS NULL),0) AS paid_amount,
                    (amount-COALESCE((SELECT SUM(foa.amount)
                                       FROM finance_operation_allocations foa
                                      WHERE foa.invoice_id=finance_invoices.id
                                        AND foa.cancelled_at IS NULL),0)) AS remaining
               FROM finance_invoices
              WHERE direction=?
                AND status NOT IN ('cancelled','paid')
                AND (amount-COALESCE((SELECT SUM(foa.amount)
                                       FROM finance_operation_allocations foa
                                      WHERE foa.invoice_id=finance_invoices.id
                                        AND foa.cancelled_at IS NULL),0))>0
                AND (invoice_date IS NULL OR invoice_date<=?)
              ORDER BY number"
        );
        $stmt->execute([$direction, $asOfDate]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
            $rows[] = [
                'group' => $group,
                'name' => 'Счёт ' . ($invoice['number'] ?? '№' . $invoice['id']),
                'counterparty' => $invoice['counterparty_name'] ?? '—',
                'source' => 'Счёт на оплату',
                'remaining' => self::money((string)$invoice['remaining']),
                'drilldown_url' => app_url('/company/finance/invoices'),
            ];
        }
        return $rows;
    }

    private static function obligationBalanceRow(array $obligation, string $group): array
    {
        $routeId = (string)($obligation['source_parent_type'] ?? '') === 'LINEAR_ROUTE'
            ? (int)($obligation['source_parent_id'] ?? 0)
            : 0;
        $isService = (string)($obligation['source_type'] ?? '') === 'LINEAR_ROUTE_SERVICE_PAYMENT';

        if ($routeId > 0) {
            $name = ($isService ? 'Доп. услуга по рейсу #' : 'Платёж по рейсу #') . $routeId;
            $source = $isService ? 'Дополнительная услуга рейса' : 'План платежа рейса';
            $url = app_url('/company/trips/linear');
        } else {
            $name = 'Платёжное обязательство #' . (int)$obligation['id'];
            $source = 'Платёжное обязательство';
            $url = app_url('/company/finance/invoices');
        }

        return [
            'group' => $group,
            'name' => $name,
            'counterparty' => $obligation['counterparty_name'] ?? '—',
            'source' => $source,
            'remaining' => self::money((string)$obligation['uninvoiced_remaining_amount']),
            'drilldown_url' => $url,
        ];
    }

    private static function isVisibleAsOf(mixed $dueDate, string $asOfDate): bool
    {
        $dueDate = trim((string)$dueDate);
        return $dueDate === '' || $dueDate <= $asOfDate;
    }

    private static function money(string $value): string
    {
        $value = str_replace(',', '.', trim($value));
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
        $value = self::money($value);
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

    private static function stringAdd(string $left, string $right): string
    {
        return self::formatCents(self::parseCents($left) + self::parseCents($right));
    }

    private static function stringSub(string $left, string $right): string
    {
        return self::formatCents(self::parseCents($left) - self::parseCents($right));
    }
}
