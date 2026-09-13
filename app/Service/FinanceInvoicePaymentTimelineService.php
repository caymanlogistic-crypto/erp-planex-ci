<?php

namespace App\Service;

use DateTimeImmutable;
use PDO;
use Throwable;

final class FinanceInvoicePaymentTimelineService
{
    /**
     * Build read-only plan/fact payment timelines for invoice register rows.
     * Plan comes from invoice links to route obligations; fact comes only from
     * posted, non-cancelled operation allocations. No invoice status fields are trusted here.
     *
     * @param int[] $invoiceIds
     * @return array<int,array<string,mixed>>
     */
    public static function buildForInvoices(PDO $pdo, array $invoiceIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $invoiceIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        try {
            $plans = self::fetchPlans($pdo, $ids);
            $facts = self::fetchFacts($pdo, $ids);
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($ids as $invoiceId) {
            $result[$invoiceId] = self::emptyTimeline();
        }

        foreach ($plans as $row) {
            $invoiceId = (int)$row['invoice_id'];
            if (!isset($result[$invoiceId])) {
                continue;
            }
            $obligationId = (int)($row['obligation_id'] ?? 0);
            $key = $obligationId > 0 ? 'o:' . $obligationId : 'l:' . (int)$row['link_id'];
            if (!isset($result[$invoiceId]['plan_map'][$key])) {
                $expectedDate = self::validDate($row['due_date'] ?? null) ?? self::validDate($row['forecast_due_date'] ?? null);
                $result[$invoiceId]['plan_map'][$key] = [
                    'obligation_id' => $obligationId > 0 ? $obligationId : null,
                    'route_id' => (int)($row['linear_route_id'] ?? $row['source_parent_id'] ?? 0) ?: null,
                    'expected_date' => $expectedDate,
                    'due_date' => self::validDate($row['due_date'] ?? null),
                    'forecast_due_date' => self::validDate($row['forecast_due_date'] ?? null),
                    'amount' => '0.00',
                    'paid_amount' => '0.00',
                    'remaining_amount' => '0.00',
                    'status' => (string)($row['obligation_status'] ?? ''),
                    'fact_parts' => [],
                ];
            }
            $result[$invoiceId]['plan_map'][$key]['amount'] = self::addMoney(
                (string)$result[$invoiceId]['plan_map'][$key]['amount'],
                (string)($row['planned_amount'] ?? '0.00')
            );
            $result[$invoiceId]['planned_total'] = self::addMoney(
                (string)$result[$invoiceId]['planned_total'],
                (string)($row['planned_amount'] ?? '0.00')
            );
        }

        foreach ($facts as $row) {
            $invoiceId = (int)$row['invoice_id'];
            if (!isset($result[$invoiceId])) {
                continue;
            }
            $amount = self::money((string)($row['amount'] ?? '0.00'));
            $actualDate = self::validDate($row['actual_date'] ?? null) ?? self::validDate($row['allocation_date'] ?? null);
            $operationId = (int)($row['operation_id'] ?? 0);
            $factKey = ($operationId > 0 ? 'op:' . $operationId : 'a:' . (int)$row['allocation_id']) . ':' . ($actualDate ?? '');
            if (!isset($result[$invoiceId]['fact_map'][$factKey])) {
                $result[$invoiceId]['fact_map'][$factKey] = [
                    'operation_id' => $operationId > 0 ? $operationId : null,
                    'actual_date' => $actualDate,
                    'amount' => '0.00',
                    'method' => (string)($row['method'] ?? ''),
                ];
            }
            $result[$invoiceId]['fact_map'][$factKey]['amount'] = self::addMoney(
                (string)$result[$invoiceId]['fact_map'][$factKey]['amount'],
                $amount
            );
            $result[$invoiceId]['paid_total'] = self::addMoney((string)$result[$invoiceId]['paid_total'], $amount);

            $obligationId = (int)($row['obligation_id'] ?? 0);
            $planKey = $obligationId > 0 ? 'o:' . $obligationId : null;
            $expectedDate = null;
            if ($planKey !== null && isset($result[$invoiceId]['plan_map'][$planKey])) {
                $plan =& $result[$invoiceId]['plan_map'][$planKey];
                $plan['paid_amount'] = self::addMoney((string)$plan['paid_amount'], $amount);
                $expectedDate = $plan['expected_date'];
                $delay = self::dateDeltaDays($expectedDate, $actualDate);
                $part = [
                    'actual_date' => $actualDate,
                    'amount' => $amount,
                    'delay_days' => $delay,
                ];
                $plan['fact_parts'][] = $part;
                unset($plan);
            } else {
                $delay = null;
            }

            $result[$invoiceId]['settlement_parts'][] = [
                'amount' => $amount,
                'actual_date' => $actualDate,
                'expected_date' => $expectedDate,
                'delay_days' => $delay,
            ];
            if ($delay !== null) {
                if ($delay > 0) {
                    $result[$invoiceId]['late_paid_total'] = self::addMoney((string)$result[$invoiceId]['late_paid_total'], $amount);
                    $result[$invoiceId]['max_delay_days'] = max((int)$result[$invoiceId]['max_delay_days'], $delay);
                } else {
                    $result[$invoiceId]['on_time_paid_total'] = self::addMoney((string)$result[$invoiceId]['on_time_paid_total'], $amount);
                }
            }
        }

        foreach ($result as &$timeline) {
            foreach ($timeline['plan_map'] as &$plan) {
                $plan['remaining_amount'] = self::maxZero(self::subMoney((string)$plan['amount'], (string)$plan['paid_amount']));
            }
            unset($plan);

            $timeline['plans'] = array_values($timeline['plan_map']);
            usort($timeline['plans'], static function (array $a, array $b): int {
                $ad = $a['expected_date'] ?? '9999-12-31';
                $bd = $b['expected_date'] ?? '9999-12-31';
                return [$ad, (int)($a['obligation_id'] ?? 0)] <=> [$bd, (int)($b['obligation_id'] ?? 0)];
            });
            $timeline['facts'] = array_values($timeline['fact_map']);
            usort($timeline['facts'], static function (array $a, array $b): int {
                return [($a['actual_date'] ?? '9999-12-31'), (int)($a['operation_id'] ?? 0)] <=> [($b['actual_date'] ?? '9999-12-31'), (int)($b['operation_id'] ?? 0)];
            });
            $timeline['planned_count'] = count($timeline['plans']);
            $timeline['fact_count'] = count($timeline['facts']);
            $timeline['remaining_total'] = self::maxZero(self::subMoney((string)$timeline['planned_total'], (string)$timeline['paid_total']));
            unset($timeline['plan_map'], $timeline['fact_map']);
        }
        unset($timeline);

        return $result;
    }

    /**
     * Deadline semantics for an invoice payment stage.
     * Forecast dates are display/planning data only. Overdue starts only after
     * a factual due_date exists and that date is before today.
     *
     * @return array{display_date:?string,due_date:?string,forecast_due_date:?string,waiting_event:bool,overdue_days:int,due_today:bool}
     */
    public static function deadlineState(array $plan, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        $due = self::validDate($plan['due_date'] ?? null);
        $forecast = self::validDate($plan['forecast_due_date'] ?? null);
        $display = $due ?? $forecast;
        $obligationStatus = (string)($plan['status'] ?? '');
        $waitingEvent = $due === null || in_array($obligationStatus, ['waiting_event', 'calendar_missing'], true);
        $overdueDays = 0;
        $dueToday = false;

        if (!$waitingEvent && $due !== null) {
            $dueDate = new DateTimeImmutable($due);
            if ($today > $dueDate) {
                $overdueDays = (int)$dueDate->diff($today)->format('%a');
            } elseif ($today == $dueDate) {
                $dueToday = true;
            }
        }

        return [
            'display_date' => $display,
            'due_date' => $due,
            'forecast_due_date' => $forecast,
            'waiting_event' => $waitingEvent,
            'overdue_days' => $overdueDays,
            'due_today' => $dueToday,
        ];
    }

    /** @param int[] $ids */
    private static function fetchPlans(PDO $pdo, array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT l.id AS link_id,l.invoice_id,l.obligation_id,l.linear_route_id,l.amount AS planned_amount,
                    o.source_parent_id,o.due_date,o.forecast_due_date,o.status AS obligation_status
               FROM finance_invoice_links l
          LEFT JOIN finance_obligations o ON o.id=l.obligation_id
              WHERE l.invoice_id IN ($ph)
              ORDER BY l.invoice_id,CASE WHEN COALESCE(o.due_date,o.forecast_due_date) IS NULL THEN 1 ELSE 0 END,
                       COALESCE(o.due_date,o.forecast_due_date),l.id"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param int[] $ids */
    private static function fetchFacts(PDO $pdo, array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT a.id AS allocation_id,a.invoice_id,a.obligation_id,a.amount,a.allocation_date,a.method,
                    fo.id AS operation_id,
                    COALESCE(bt.operation_date,fo.operation_date,a.allocation_date) AS actual_date
               FROM finance_operation_allocations a
               JOIN finance_operations fo ON fo.id=a.operation_id
          LEFT JOIN bank_transactions bt ON bt.id=fo.bank_transaction_id
              WHERE a.invoice_id IN ($ph)
                AND a.cancelled_at IS NULL
                AND fo.status='POSTED'
              ORDER BY a.invoice_id,COALESCE(bt.operation_date,fo.operation_date,a.allocation_date),fo.id,a.id"
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function emptyTimeline(): array
    {
        return [
            'planned_count' => 0,
            'fact_count' => 0,
            'planned_total' => '0.00',
            'paid_total' => '0.00',
            'remaining_total' => '0.00',
            'late_paid_total' => '0.00',
            'on_time_paid_total' => '0.00',
            'max_delay_days' => 0,
            'plans' => [],
            'facts' => [],
            'settlement_parts' => [],
            'plan_map' => [],
            'fact_map' => [],
        ];
    }

    /** Positive means late, negative means paid early, zero means on due date. */
    private static function dateDeltaDays(?string $expected, ?string $actual): ?int
    {
        if ($expected === null || $actual === null) {
            return null;
        }
        $due = new DateTimeImmutable($expected);
        $paid = new DateTimeImmutable($actual);
        $days = (int)$due->diff($paid)->format('%a');
        if ($paid < $due) {
            return -$days;
        }
        return $days;
    }

    private static function validDate(mixed $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function maxZero(string $value): string
    {
        return self::cents($value) < 0 ? '0.00' : self::money($value);
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

    private static function addMoney(string $a, string $b): string
    {
        return self::fromCents(self::cents($a) + self::cents($b));
    }

    private static function subMoney(string $a, string $b): string
    {
        return self::fromCents(self::cents($a) - self::cents($b));
    }
}
