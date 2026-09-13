<?php

namespace App\Service;

use PDO;

final class FinancePaymentCalendarService
{
    public static function formatAmount(mixed $value): string
    {
        return FinanceOperationService::formatAmount($value);
    }

    public static function fetchCalendarData(PDO $localPdo, array $filters = []): array
    {
        if (!FinanceObligationService::schemaReady($localPdo)) {
            return [];
        }
        FinanceObligationService::syncAllOperationalObligations($localPdo);

        $sql = "SELECT o.id AS source_id,
                       'obligation' AS source_type,
                       CASE o.direction WHEN 'RECEIVABLE' THEN 'INCOME' ELSE 'EXPENSE' END AS direction,
                       'Платёжное обязательство' AS source_label,
                       o.counterparty_name AS counterparty,
                       CASE WHEN o.source_parent_type='LINEAR_ROUTE' THEN CONCAT('Рейс #',o.source_parent_id) ELSE o.source_parent_type END AS route_label,
                       o.amount AS amount,
                       o.paid_amount AS paid,
                       (o.amount-o.paid_amount) AS remaining,
                       COALESCE(o.due_date,o.forecast_due_date) AS due_date,
                       o.status AS obligation_status,
                       o.source_parent_type,
                       o.source_parent_id,
                       COALESCE((SELECT GROUP_CONCAT(DISTINCT i.number ORDER BY i.invoice_date SEPARATOR ', ')
                                   FROM finance_invoice_links l
                                   JOIN finance_invoices i ON i.id=l.invoice_id
                                  WHERE l.obligation_id=o.id AND i.status<>'cancelled'),'') AS invoice_numbers
                  FROM finance_obligations o
             LEFT JOIN linear_routes lr ON o.source_parent_type='LINEAR_ROUTE' AND lr.id=o.source_parent_id
                 WHERE o.cancelled_at IS NULL
                   AND o.status<>'paid'
                   AND o.amount>o.paid_amount";
        $params = [];

        $roleCode = (string)($filters['role_code'] ?? '');
        if (class_exists(\App\Security\AccessRuntime::class) && \App\Security\AccessRuntime::current()->isActive()) {
            $access = RegulatedDataScopeService::condition('linear_trip', 'lr.id', 'lr.created_by_membership_id');
            $sql .= " AND (o.source_parent_type<>'LINEAR_ROUTE' OR ({$access['sql']}))";
            array_push($params, ...$access['params']);
        } elseif ($roleCode === 'logist') {
            $userId = (int)($filters['user_id'] ?? 0);
            $sql .= " AND (o.source_parent_type<>'LINEAR_ROUTE' OR lr.created_by_user_id=? OR lr.id IN (
                            SELECT entity_id FROM entity_access_grants
                             WHERE entity_type='linear_route' AND granted_to_user_id=?
                               AND access_level IN ('view','edit') AND revoked_at IS NULL
                       ))";
            $params[] = $userId;
            $params[] = $userId;
        }

        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));
        if ($dateFrom !== '') {
            $sql .= ' AND COALESCE(o.due_date,o.forecast_due_date)>=?';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= ' AND COALESCE(o.due_date,o.forecast_due_date)<=?';
            $params[] = $dateTo;
        }
        $sql .= ' ORDER BY CASE WHEN COALESCE(o.due_date,o.forecast_due_date) IS NULL THEN 1 ELSE 0 END, COALESCE(o.due_date,o.forecast_due_date), o.id';

        $stmt = $localPdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        FinanceObligationService::enrichParentLabels($localPdo, $rows);
        $result = [];
        foreach ($rows as $row) {
            $status = (string)($row['obligation_status'] ?? '');
            $row['calendar_status'] = match ($status) {
                FinanceObligationService::STATUS_OVERDUE,
                FinanceObligationService::STATUS_OVERDUE_PARTIAL => 'overdue',
                FinanceObligationService::STATUS_PARTIALLY_PAID => 'partial',
                FinanceObligationService::STATUS_WAITING_EVENT,
                FinanceObligationService::STATUS_CALENDAR_MISSING => 'waiting_event',
                default => ($row['due_date'] ?? null) === null ? 'waiting_event' : 'planned',
            };
            $row['source_label'] = ($row['invoice_numbers'] ?? '') !== ''
                ? 'Счёт ' . $row['invoice_numbers']
                : 'Платёжное обязательство';
            if (!self::matchesPostFilters($row, $filters)) {
                continue;
            }
            $row['amount'] = self::money((string)$row['amount']);
            $row['paid'] = self::money((string)$row['paid']);
            $row['remaining'] = self::money((string)$row['remaining']);
            $result[] = $row;
        }
        return $result;
    }

    public static function getSummary(PDO $localPdo, array $filters = []): array
    {
        $expectedIncome = '0.00';
        $expectedExpense = '0.00';
        $overdueIncome = '0.00';
        $overdueExpense = '0.00';
        foreach (self::fetchCalendarData($localPdo, $filters) as $row) {
            $remaining = (string)$row['remaining'];
            if ($row['direction'] === 'INCOME') {
                $expectedIncome = self::add($expectedIncome, $remaining);
                if ($row['calendar_status'] === 'overdue') {
                    $overdueIncome = self::add($overdueIncome, $remaining);
                }
            } else {
                $expectedExpense = self::add($expectedExpense, $remaining);
                if ($row['calendar_status'] === 'overdue') {
                    $overdueExpense = self::add($overdueExpense, $remaining);
                }
            }
        }
        return [
            'expected_income' => $expectedIncome,
            'expected_expense' => $expectedExpense,
            'overdue_income' => $overdueIncome,
            'overdue_expense' => $overdueExpense,
            'net_plan' => self::sub($expectedIncome, $expectedExpense),
        ];
    }

    private static function matchesPostFilters(array $row, array $filters): bool
    {
        $direction = trim((string)($filters['direction'] ?? ''));
        if ($direction !== '' && $direction !== 'all' && $row['direction'] !== $direction) {
            return false;
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all' && $row['calendar_status'] !== $status) {
            return false;
        }
        $search = mb_strtolower(trim((string)($filters['search'] ?? '')), 'UTF-8');
        if ($search === '') {
            return true;
        }
        foreach (['counterparty','source_label','route_label','invoice_numbers'] as $field) {
            if (str_contains(mb_strtolower((string)($row[$field] ?? ''), 'UTF-8'), $search)) {
                return true;
            }
        }
        return false;
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

    private static function add(string $left, string $right): string
    {
        return self::fromCents(self::cents($left) + self::cents($right));
    }

    private static function sub(string $left, string $right): string
    {
        return self::fromCents(self::cents($left) - self::cents($right));
    }
}