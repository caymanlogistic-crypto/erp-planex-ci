<?php

namespace App\Service;

use PDO;

final class FinancePaymentPlanFactService
{
    public static function formatAmount(mixed $value): string
    {
        return FinanceOperationService::formatAmount($value);
    }

    public static function fetchPlanFactData(PDO $localPdo, array $filters = []): array
    {
        $rows = [];

        foreach (self::fetchInvoiceRows($localPdo, $filters) as $row) {
            $rows[] = self::computeRow($row);
        }

        foreach (self::fetchUninvoicedObligationRows($localPdo, $filters) as $row) {
            $rows[] = self::computeRow($row);
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['planned_date'] === null && $b['planned_date'] === null) {
                return 0;
            }
            if ($a['planned_date'] === null) {
                return 1;
            }
            if ($b['planned_date'] === null) {
                return -1;
            }
            return strcmp((string)$a['planned_date'], (string)$b['planned_date']);
        });

        return $rows;
    }

    public static function getTotals(PDO $localPdo, array $filters = []): array
    {
        $rows = self::fetchPlanFactData($localPdo, $filters);

        $expectedIncome = '0.00';
        $receivedIncome = '0.00';
        $remainingIncome = '0.00';
        $expectedExpense = '0.00';
        $paidExpense = '0.00';
        $remainingExpense = '0.00';
        $overdueIncome = '0.00';
        $overdueExpense = '0.00';

        foreach ($rows as $row) {
            $side = (string)$row['side'];
            $planned = (string)$row['planned_amount'];
            $paid = (string)$row['paid_amount'];
            $remaining = (string)$row['remaining'];
            $overdueDays = (int)$row['overdue_days'];

            if ($side === 'INCOME') {
                $expectedIncome = self::stringAdd($expectedIncome, $planned);
                $receivedIncome = self::stringAdd($receivedIncome, $paid);
                $remainingIncome = self::stringAdd($remainingIncome, $remaining);
                if ($overdueDays > 0) {
                    $overdueIncome = self::stringAdd($overdueIncome, $remaining);
                }
            } else {
                $expectedExpense = self::stringAdd($expectedExpense, $planned);
                $paidExpense = self::stringAdd($paidExpense, $paid);
                $remainingExpense = self::stringAdd($remainingExpense, $remaining);
                if ($overdueDays > 0) {
                    $overdueExpense = self::stringAdd($overdueExpense, $remaining);
                }
            }
        }

        return [
            'expected_income' => $expectedIncome,
            'received_income' => $receivedIncome,
            'remaining_income' => $remainingIncome,
            'expected_expense' => $expectedExpense,
            'paid_expense' => $paidExpense,
            'remaining_expense' => $remainingExpense,
            'overdue_income' => $overdueIncome,
            'overdue_expense' => $overdueExpense,
            'net_plan' => self::stringSub($expectedIncome, $expectedExpense),
            'net_fact' => self::stringSub($receivedIncome, $paidExpense),
            'net_remaining' => self::stringSub($remainingIncome, $remainingExpense),
        ];
    }

    private static function computeRow(array $source): array
    {
        $today = date('Y-m-d');
        $plannedAmount = self::money((string)($source['planned_amount'] ?? '0.00'));
        $paidAmount = self::money((string)($source['paid_amount'] ?? '0.00'));
        $remaining = self::stringSubPositive($plannedAmount, $paidAmount);
        $plannedDate = self::validDate($source['planned_date'] ?? null);

        $status = 'planned';
        if (!self::isPositiveAmount($remaining)) {
            $remaining = '0.00';
            $status = 'paid';
        } elseif ($plannedDate !== null && $plannedDate < $today) {
            $status = 'overdue';
        } elseif (self::isPositiveAmount($paidAmount)) {
            $status = 'partial';
        }

        $overdueDays = 0;
        if ($remaining !== '0.00' && self::isPositiveAmount($remaining) && $plannedDate !== null && $plannedDate < $today) {
            $overdueDays = (int)((strtotime($today) - strtotime($plannedDate)) / 86400);
        }

        $actualClosedDate = null;
        if ($remaining === '0.00') {
            $actualClosedDate = self::validDate($source['max_allocation_date'] ?? null)
                ?? self::validDate($source['paid_at'] ?? null);
        }

        return [
            'source_id' => $source['source_id'],
            'source_type' => $source['source_type'],
            'source_label' => $source['source_label'],
            'side' => $source['side'],
            'counterparty' => $source['counterparty'] ?? '—',
            'route_label' => $source['route_label'] ?? null,
            'planned_amount' => $plannedAmount,
            'planned_date' => $plannedDate,
            'paid_amount' => $paidAmount,
            'remaining' => $remaining,
            'actual_closed_date' => $actualClosedDate,
            'overdue_days' => $overdueDays,
            'status' => $status,
        ];
    }

    private static function fetchInvoiceRows(PDO $localPdo, array $filters): array
    {
        $sql = "SELECT
                    fi.id AS source_id,
                    'invoice' AS source_type,
                    CASE fi.direction
                        WHEN 'OUTGOING' THEN 'INCOME'
                        WHEN 'INCOMING' THEN 'EXPENSE'
                    END AS side,
                    'Счёт' AS source_label,
                    fi.counterparty_name AS counterparty,
                    NULL AS route_label,
                    fi.amount AS planned_amount,
                    COALESCE(fi.planned_payment_date, fi.invoice_date) AS planned_date,
                    CASE
                        WHEN (SELECT COUNT(*) FROM finance_operation_allocations foa2 WHERE foa2.invoice_id = fi.id AND foa2.cancelled_at IS NULL) > 0
                        THEN (SELECT SUM(foa3.amount) FROM finance_operation_allocations foa3 WHERE foa3.invoice_id = fi.id AND foa3.cancelled_at IS NULL)
                        ELSE COALESCE(fi.paid_amount, 0)
                    END AS paid_amount,
                    (SELECT MAX(foa4.allocation_date) FROM finance_operation_allocations foa4 WHERE foa4.invoice_id = fi.id AND foa4.cancelled_at IS NULL) AS max_allocation_date,
                    NULL AS paid_at
                FROM finance_invoices fi
                WHERE fi.status NOT IN ('cancelled')";

        $params = [];
        $dateFrom = (string)($filters['date_from'] ?? '');
        $dateTo = (string)($filters['date_to'] ?? '');
        if ($dateFrom !== '') {
            $sql .= " AND COALESCE(fi.planned_payment_date, fi.invoice_date) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= " AND COALESCE(fi.planned_payment_date, fi.invoice_date) <= ?";
            $params[] = $dateTo;
        }

        $stmt = $localPdo->prepare($sql);
        $stmt->execute($params);
        return self::filterRows($stmt->fetchAll(PDO::FETCH_ASSOC), $filters);
    }

    private static function fetchUninvoicedObligationRows(PDO $localPdo, array $filters): array
    {
        $result = [];
        $dateFrom = (string)($filters['date_from'] ?? '');
        $dateTo = (string)($filters['date_to'] ?? '');

        foreach (FinanceUninvoicedObligationService::fetchOpen($localPdo) as $obligation) {
            $plannedDate = $obligation['effective_due_date'] ?? null;
            if ($dateFrom !== '' && ($plannedDate === null || $plannedDate < $dateFrom)) {
                continue;
            }
            if ($dateTo !== '' && ($plannedDate === null || $plannedDate > $dateTo)) {
                continue;
            }

            $parentType=(string)($obligation['source_parent_type']??'');
            $routeId = $parentType === 'LINEAR_ROUTE' ? (int)($obligation['source_parent_id'] ?? 0) : 0;
            $isService = (string)($obligation['source_type'] ?? '') === 'LINEAR_ROUTE_SERVICE_PAYMENT';
            $sourceLabel = $routeId > 0
                ? ($isService ? 'Доп. услуга · Рейс #' . $routeId : 'Рейс #' . $routeId)
                : (string)($obligation['parent_label'] ?? 'Платёжное обязательство');

            $result[] = [
                'source_id' => (int)$obligation['id'],
                'source_type' => 'obligation',
                'side' => (string)$obligation['direction'] === FinanceObligationService::DIRECTION_RECEIVABLE ? 'INCOME' : 'EXPENSE',
                'source_label' => $sourceLabel,
                'counterparty' => (string)($obligation['counterparty_name'] ?? '—'),
                'route_label' => $routeId > 0 ? 'Рейс #' . $routeId : (($parentType==='WASTE_TRIP') ? (string)($obligation['parent_label']??'') : null),
                'planned_amount' => (string)$obligation['uninvoiced_amount'],
                'planned_date' => $plannedDate,
                'paid_amount' => (string)$obligation['uninvoiced_paid_amount'],
                'max_allocation_date' => $obligation['max_direct_paid_date'] ?? null,
                'paid_at' => null,
            ];
        }

        return self::filterRows($result, $filters);
    }

    private static function filterRows(array $rows, array $filters): array
    {
        $directionFilter = (string)($filters['direction'] ?? '');
        $statusFilter = (string)($filters['status'] ?? '');
        $searchQuery = mb_strtolower(trim((string)($filters['search'] ?? '')), 'UTF-8');
        $today = date('Y-m-d');

        $result = [];
        foreach ($rows as $row) {
            $plannedAmount = self::money((string)($row['planned_amount'] ?? '0.00'));
            $paidAmount = self::money((string)($row['paid_amount'] ?? '0.00'));
            $remaining = self::stringSubPositive($plannedAmount, $paidAmount);
            $plannedDate = self::validDate($row['planned_date'] ?? null);

            $status = 'planned';
            if (!self::isPositiveAmount($remaining)) {
                $status = 'paid';
            } elseif ($plannedDate !== null && $plannedDate < $today) {
                $status = 'overdue';
            } elseif (self::isPositiveAmount($paidAmount)) {
                $status = 'partial';
            }

            if ($directionFilter !== '' && $directionFilter !== 'all' && ($row['side'] ?? '') !== $directionFilter) {
                continue;
            }
            if ($statusFilter !== '' && $statusFilter !== 'all' && $status !== $statusFilter) {
                continue;
            }
            if ($searchQuery !== '') {
                $found = false;
                foreach (['counterparty', 'source_label', 'route_label'] as $field) {
                    if (str_contains(mb_strtolower((string)($row[$field] ?? ''), 'UTF-8'), $searchQuery)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    continue;
                }
            }

            $result[] = $row;
        }

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

    private static function stringSubPositive(string $left, string $right): string
    {
        $value = self::parseCents($left) - self::parseCents($right);
        return self::formatCents(max(0, $value));
    }

    private static function isPositiveAmount(string $value): bool
    {
        return self::parseCents($value) > 0;
    }
}
