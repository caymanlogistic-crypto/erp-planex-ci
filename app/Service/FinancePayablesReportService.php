<?php

namespace App\Service;

use DateTimeImmutable;
use PDO;
use Throwable;

/** Read-only Accounts Payable projection built on universal PAYABLE obligations. */
final class FinancePayablesReportService
{
    public static function build(PDO $pdo): array
    {
        $empty = ['rows'=>[], 'summary'=>self::emptySummary()];
        if (!FinanceObligationService::schemaReady($pdo)) {
            return $empty;
        }

        FinanceObligationService::syncAllOperationalObligations($pdo);

        try {
            $stmt = $pdo->query(
                "SELECT o.*,
                        COALESCE((SELECT SUM(l.amount)
                                    FROM finance_invoice_links l
                                    JOIN finance_invoices i ON i.id=l.invoice_id
                                   WHERE l.obligation_id=o.id AND i.status<>'cancelled'),0) AS invoiced_amount,
                        COALESCE((SELECT GROUP_CONCAT(DISTINCT i.number ORDER BY i.invoice_date SEPARATOR ', ')
                                    FROM finance_invoice_links l
                                    JOIN finance_invoices i ON i.id=l.invoice_id
                                   WHERE l.obligation_id=o.id AND i.status<>'cancelled'),'') AS invoice_numbers,
                        COALESCE((SELECT SUM(a.amount)
                                    FROM finance_operation_allocations a
                                    JOIN finance_operations fo ON fo.id=a.operation_id
                                   WHERE a.obligation_id=o.id AND a.cancelled_at IS NULL AND fo.status='POSTED'),0) AS actual_paid_amount,
                        COALESCE((SELECT GROUP_CONCAT(DISTINCT
                                    CASE
                                      WHEN eip.id IS NOT NULL THEN 'Сотрудник'
                                      WHEN fo.bank_transaction_id IS NOT NULL THEN 'Расчётный счёт'
                                      WHEN fma.type='CASH' THEN 'Касса'
                                      ELSE 'Финансовая операция'
                                    END SEPARATOR ', ')
                                    FROM finance_operation_allocations a
                                    JOIN finance_operations fo ON fo.id=a.operation_id
                               LEFT JOIN finance_money_accounts fma ON fma.id=fo.money_account_id
                               LEFT JOIN finance_employee_invoice_payments eip
                                      ON eip.expense_finance_operation_id=fo.id AND eip.status='POSTED'
                                   WHERE a.obligation_id=o.id AND a.cancelled_at IS NULL AND fo.status='POSTED'),'') AS payment_channels
                   FROM finance_obligations o
                  WHERE o.direction='PAYABLE' AND o.cancelled_at IS NULL
                  ORDER BY CASE WHEN COALESCE(o.due_date,o.forecast_due_date) IS NULL THEN 1 ELSE 0 END,
                           COALESCE(o.due_date,o.forecast_due_date),o.id"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // Older compatible tenants can still show the AP register without
            // the employee channel enrichment.
            try {
                $stmt = $pdo->query(
                    "SELECT o.*,
                            COALESCE((SELECT SUM(l.amount)
                                        FROM finance_invoice_links l
                                        JOIN finance_invoices i ON i.id=l.invoice_id
                                       WHERE l.obligation_id=o.id AND i.status<>'cancelled'),0) AS invoiced_amount,
                            COALESCE((SELECT GROUP_CONCAT(DISTINCT i.number ORDER BY i.invoice_date SEPARATOR ', ')
                                        FROM finance_invoice_links l
                                        JOIN finance_invoices i ON i.id=l.invoice_id
                                       WHERE l.obligation_id=o.id AND i.status<>'cancelled'),'') AS invoice_numbers,
                            COALESCE((SELECT SUM(a.amount)
                                        FROM finance_operation_allocations a
                                        JOIN finance_operations fo ON fo.id=a.operation_id
                                       WHERE a.obligation_id=o.id AND a.cancelled_at IS NULL AND fo.status='POSTED'),0) AS actual_paid_amount,
                            COALESCE((SELECT GROUP_CONCAT(DISTINCT
                                        CASE WHEN fo.bank_transaction_id IS NOT NULL THEN 'Расчётный счёт'
                                             WHEN fma.type='CASH' THEN 'Касса'
                                             ELSE 'Финансовая операция' END SEPARATOR ', ')
                                        FROM finance_operation_allocations a
                                        JOIN finance_operations fo ON fo.id=a.operation_id
                                   LEFT JOIN finance_money_accounts fma ON fma.id=fo.money_account_id
                                       WHERE a.obligation_id=o.id AND a.cancelled_at IS NULL AND fo.status='POSTED'),'') AS payment_channels
                       FROM finance_obligations o
                      WHERE o.direction='PAYABLE' AND o.cancelled_at IS NULL
                      ORDER BY CASE WHEN COALESCE(o.due_date,o.forecast_due_date) IS NULL THEN 1 ELSE 0 END,
                               COALESCE(o.due_date,o.forecast_due_date),o.id"
                );
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                return $empty;
            }
        }

        FinanceObligationService::enrichParentLabels($pdo,$rows);
        $summary = self::emptySummary();
        $today = new DateTimeImmutable('today');
        foreach ($rows as &$row) {
            $paid = self::money((string)($row['actual_paid_amount'] ?? '0.00'));
            $amount = self::money((string)($row['amount'] ?? '0.00'));
            $remaining = self::maxZero(self::subMoney($amount, $paid));
            $row['paid_amount'] = $paid;
            $row['remaining_amount'] = $remaining;
            $row['condition_label'] = DateCalculationService::CONDITION_LABELS[(string)($row['condition_type'] ?? '')] ?? '—';

            $summary['total'] = self::addMoney($summary['total'], $remaining);
            $days = 0;
            $due = self::validDate($row['due_date'] ?? null) ?? self::validDate($row['forecast_due_date'] ?? null);
            if ($due !== null && self::compareMoney($remaining, '0.00') > 0) {
                $dueDate = new DateTimeImmutable($due);
                if ($dueDate < $today) {
                    $days = (int)$dueDate->diff($today)->format('%a');
                    $summary['overdue'] = self::addMoney($summary['overdue'], $remaining);
                    if ($days <= 7) $summary['aging_1_7'] = self::addMoney($summary['aging_1_7'], $remaining);
                    elseif ($days <= 30) $summary['aging_8_30'] = self::addMoney($summary['aging_8_30'], $remaining);
                    elseif ($days <= 60) $summary['aging_31_60'] = self::addMoney($summary['aging_31_60'], $remaining);
                    else $summary['aging_61_plus'] = self::addMoney($summary['aging_61_plus'], $remaining);
                }
            }
            $row['overdue_days'] = $days;
        }
        unset($row);

        return ['rows'=>$rows, 'summary'=>$summary];
    }

    private static function emptySummary(): array
    {
        return ['total'=>'0.00','overdue'=>'0.00','aging_1_7'=>'0.00','aging_8_30'=>'0.00','aging_31_60'=>'0.00','aging_61_plus'=>'0.00'];
    }

    private static function validDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function money(string $value): string
    {
        $value = trim(str_replace(',', '.', $value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) return '0.00';
        $negative = str_starts_with($value, '-');
        if ($negative) $value = substr($value, 1);
        [$whole,$fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0'); if ($whole === '') $whole='0';
        $fraction = str_pad(substr($fraction,0,2),2,'0');
        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }

    private static function cents(string $value): int
    {
        $value = self::money($value); $negative = str_starts_with($value,'-');
        if ($negative) $value=substr($value,1);
        [$whole,$fraction]=explode('.',$value,2);
        $cents=((int)$whole*100)+(int)$fraction;
        return $negative ? -$cents : $cents;
    }

    private static function fromCents(int $value): string
    {
        $sign=$value<0?'-':''; $value=abs($value);
        return $sign . intdiv($value,100) . '.' . str_pad((string)($value%100),2,'0',STR_PAD_LEFT);
    }

    private static function addMoney(string $a,string $b): string { return self::fromCents(self::cents($a)+self::cents($b)); }
    private static function subMoney(string $a,string $b): string { return self::fromCents(self::cents($a)-self::cents($b)); }
    private static function compareMoney(string $a,string $b): int { return self::cents($a)<=>self::cents($b); }
    private static function maxZero(string $value): string { return self::cents($value)<0?'0.00':self::money($value); }
}
