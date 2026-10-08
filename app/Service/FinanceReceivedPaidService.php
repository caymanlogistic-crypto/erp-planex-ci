<?php

namespace App\Service;

use PDO;

/** Read-only, trip-scoped projection of contractual rates, invoices and posted settlements. */
final class FinanceReceivedPaidService
{
    public static function fetch(PDO $pdo): array
    {
        $scope = RegulatedDataScopeService::condition('waste_trip', 'wt.id', 'wt.created_by_membership_id');
        $stmt = $pdo->prepare("SELECT wt.id, wt.business_id, wt.comment, wt.actual_start_date,
                wt.planned_start_date, wt.planned_start_date_from, wt.actual_end_date,
                wt.planned_finish_date, wt.client_id, c.name AS client_name,
                ct.id AS carrier_id, ct.name AS carrier_name,
                d.full_name AS driver_full_name
            FROM waste_trips wt
            JOIN clients c ON c.id=wt.client_id
            LEFT JOIN crews cr ON cr.id=wt.route_executor_id
            LEFT JOIN contractors ct ON ct.id=cr.contractor_id
            LEFT JOIN driver_vehicle_blocks dvb ON dvb.id=cr.driver_vehicle_block_id
            LEFT JOIN drivers d ON d.id=dvb.driver_id
            WHERE wt.deleted_at IS NULL AND wt.legal_entity_code='planex'
              AND wt.status='completed' AND {$scope['sql']}
            ORDER BY wt.business_id DESC");
        $stmt->execute($scope['params']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return [];
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $byId = [];
        foreach ($rows as $index => &$row) {
            $row['sides'] = [
                'customer' => ['rate' => null, 'invoices' => [], 'payments' => []],
                'carrier' => ['rate' => null, 'invoices' => [], 'payments' => []],
            ];
            $row['planned_receipt'] = null;
            $row['planned_carrier_payment'] = null;
            $byId[(int)$row['id']] = $index;
        }
        unset($row);

        // Customer rates may be preserved in obligations; carrier cost always comes
        // from the trip's "Себес · оплата подрядчику" payment rows.
        $rates = $pdo->prepare("SELECT source_parent_id AS trip_id, party_role, SUM(amount) AS amount
            FROM finance_obligations WHERE source_parent_type='WASTE_TRIP'
              AND party_role='customer' AND cancelled_at IS NULL
              AND status<>'cancelled' AND source_parent_id IN ($marks)
            GROUP BY source_parent_id,party_role");
        $rates->execute($ids);
        foreach ($rates->fetchAll(PDO::FETCH_ASSOC) as $rate) {
            $tripId = (int)$rate['trip_id'];
            $side = (string)$rate['party_role'];
            if (isset($byId[$tripId], $rows[$byId[$tripId]]['sides'][$side])) {
                $rows[$byId[$tripId]]['sides'][$side]['rate'] = (string)$rate['amount'];
            }
        }
        $sourceRates = $pdo->prepare("SELECT waste_trip_id AS trip_id,'customer' AS party_role,SUM(amount) AS amount
            FROM waste_trip_customer_payments WHERE waste_trip_id IN ($marks) GROUP BY waste_trip_id
            UNION ALL
            SELECT waste_trip_id AS trip_id,'carrier' AS party_role,SUM(amount) AS amount
            FROM waste_trip_carrier_payments WHERE waste_trip_id IN ($marks) GROUP BY waste_trip_id");
        $sourceRates->execute(array_merge($ids, $ids));
        foreach ($sourceRates->fetchAll(PDO::FETCH_ASSOC) as $rate) {
            $index = $byId[(int)$rate['trip_id']] ?? null;
            $side = (string)$rate['party_role'];
            if ($index !== null && ($side === 'carrier' || $rows[$index]['sides'][$side]['rate'] === null)) {
                $rows[$index]['sides'][$side]['rate'] = (string)$rate['amount'];
            }
        }

        $invoices = $pdo->prepare("SELECT o.source_parent_id AS trip_id,o.party_role,
                fi.id,fi.number,fi.invoice_date,SUM(l.amount) AS amount
            FROM finance_invoice_links l
            JOIN finance_obligations o ON o.id=l.obligation_id
            JOIN finance_invoices fi ON fi.id=l.invoice_id
            WHERE o.source_parent_type='WASTE_TRIP' AND o.source_parent_id IN ($marks)
              AND o.cancelled_at IS NULL AND fi.cancelled_at IS NULL AND fi.status<>'cancelled'
              AND ((o.party_role='customer' AND fi.direction='OUTGOING')
                OR (o.party_role='carrier' AND fi.direction='INCOMING'))
            GROUP BY o.source_parent_id,o.party_role,fi.id,fi.number,fi.invoice_date
            ORDER BY fi.invoice_date,fi.id");
        $invoices->execute($ids);
        foreach ($invoices->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
            $index = $byId[(int)$invoice['trip_id']] ?? null;
            $side = (string)$invoice['party_role'];
            if ($index !== null && isset($rows[$index]['sides'][$side])) {
                $rows[$index]['sides'][$side]['invoices'][] = $invoice;
            }
        }

        $payments = $pdo->prepare("SELECT o.source_parent_id AS trip_id,o.party_role,
                a.invoice_id,COALESCE(bt.operation_date,fo.operation_date,a.allocation_date) AS payment_date,
                SUM(a.amount) AS amount
            FROM finance_operation_allocations a
            JOIN finance_obligations o ON o.id=a.obligation_id
            JOIN finance_operations fo ON fo.id=a.operation_id AND fo.status='POSTED'
            LEFT JOIN bank_transactions bt ON bt.id=fo.bank_transaction_id
            LEFT JOIN finance_invoices fi ON fi.id=a.invoice_id
            WHERE o.source_parent_type='WASTE_TRIP' AND o.source_parent_id IN ($marks)
              AND o.cancelled_at IS NULL AND a.cancelled_at IS NULL
              AND (fi.id IS NULL OR (fi.cancelled_at IS NULL AND fi.status<>'cancelled'))
            GROUP BY o.source_parent_id,o.party_role,a.invoice_id,
                COALESCE(bt.operation_date,fo.operation_date,a.allocation_date),fo.id
            ORDER BY payment_date,fo.id");
        $payments->execute($ids);
        foreach ($payments->fetchAll(PDO::FETCH_ASSOC) as $payment) {
            $index = $byId[(int)$payment['trip_id']] ?? null;
            $side = (string)$payment['party_role'];
            if ($index !== null && isset($rows[$index]['sides'][$side])) {
                $rows[$index]['sides'][$side]['payments'][] = $payment;
            }
        }

        // Derive the deadline from the payment terms saved on the trip, not from
        // an obligation snapshot that may predate an edited unloading date.
        $planned = $pdo->prepare("SELECT p.id,p.waste_trip_id AS trip_id,p.amount,p.condition_type,
                p.days_count,p.days_kind,p.specific_due_date,
                COALESCE(SUM(CASE
                    WHEN a.cancelled_at IS NULL AND fo.status='POSTED'
                      AND (a.invoice_id IS NULL OR (fi.id IS NOT NULL AND fi.cancelled_at IS NULL AND fi.status<>'cancelled'))
                    THEN a.amount ELSE 0 END),0) AS paid_amount
            FROM waste_trip_customer_payments p
            LEFT JOIN finance_obligations o ON o.source_type='WASTE_TRIP_CUSTOMER_PAYMENT'
              AND o.source_id=p.id AND o.source_parent_type='WASTE_TRIP'
              AND o.source_parent_id=p.waste_trip_id AND o.party_role='customer'
              AND o.cancelled_at IS NULL AND o.status<>'cancelled'
            LEFT JOIN finance_operation_allocations a ON a.obligation_id=o.id
            LEFT JOIN finance_operations fo ON fo.id=a.operation_id
            LEFT JOIN finance_invoices fi ON fi.id=a.invoice_id
            WHERE p.waste_trip_id IN ($marks)
            GROUP BY p.id,p.waste_trip_id,p.amount,p.condition_type,
                p.days_count,p.days_kind,p.specific_due_date");
        $planned->execute($ids);
        $plansByTrip = [];
        foreach ($planned->fetchAll(PDO::FETCH_ASSOC) as $plan) {
            $tripId = (int)$plan['trip_id'];
            $trip = $rows[$byId[$tripId]];
            $actualEnd = self::validDate($trip['actual_end_date'] ?? null);
            $plannedEnd = self::validDate($trip['planned_finish_date'] ?? null);
            $actualStart = self::validDate($trip['actual_start_date'] ?? null);
            $plannedStart = self::validDate($trip['planned_start_date'] ?? null);
            $condition = (string)$plan['condition_type'];
            $days = $plan['days_count'] === null ? null : (int)$plan['days_count'];
            $daysKind = (string)($plan['days_kind'] ?: DateCalculationService::DAYS_KIND_CALENDAR);
            $specificDate = self::validDate($plan['specific_due_date'] ?? null);
            $dueDate = DateCalculationService::calculateFinalDueDate(
                $condition, $actualStart, $actualEnd, null, $days, $daysKind, $specificDate
            );
            $displayDate = $dueDate ?? DateCalculationService::calculateForecastDueDate(
                $condition, $plannedStart, $plannedEnd, $days, $daysKind, $specificDate
            );
            if ($displayDate === null) continue;
            $plansByTrip[$tripId][] = [
                'date' => $displayDate,
                'due_date' => $dueDate,
                'remaining' => (float)$plan['amount'] - (float)$plan['paid_amount'] > 0.005,
                'status' => $dueDate === null ? 'waiting_event' : 'ready',
                'id' => (int)$plan['id'],
            ];
        }
        foreach ($rows as &$row) {
            $plans = $plansByTrip[(int)$row['id']] ?? [];
            usort($plans, static fn(array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            foreach ($plans as $plan) {
                if ($plan['remaining']) {
                    $row['planned_receipt'] = $plan;
                    break;
                }
            }
            $row['planned_receipt'] ??= $plans[0] ?? null;
        }
        unset($row);
        // Carrier payment terms have the same trip date basis as customer terms.
        // Only an actual due_date can make a deadline overdue; forecast remains informational.
        $carrierPlans = $pdo->prepare("SELECT p.id,p.waste_trip_id AS trip_id,p.amount,p.condition_type,
                p.days_count,p.days_kind,p.specific_due_date,
                COALESCE(SUM(CASE
                    WHEN a.cancelled_at IS NULL AND fo.status='POSTED'
                      AND (a.invoice_id IS NULL OR (fi.id IS NOT NULL AND fi.cancelled_at IS NULL AND fi.status<>'cancelled'))
                    THEN a.amount ELSE 0 END),0) AS paid_amount
            FROM waste_trip_carrier_payments p
            LEFT JOIN finance_obligations o ON o.source_type='WASTE_TRIP_CARRIER_PAYMENT'
              AND o.source_id=p.id AND o.source_parent_type='WASTE_TRIP'
              AND o.source_parent_id=p.waste_trip_id AND o.party_role='carrier'
              AND o.cancelled_at IS NULL AND o.status<>'cancelled'
            LEFT JOIN finance_operation_allocations a ON a.obligation_id=o.id
            LEFT JOIN finance_operations fo ON fo.id=a.operation_id
            LEFT JOIN finance_invoices fi ON fi.id=a.invoice_id
            WHERE p.waste_trip_id IN ($marks)
            GROUP BY p.id,p.waste_trip_id,p.amount,p.condition_type,
                p.days_count,p.days_kind,p.specific_due_date");
        $carrierPlans->execute($ids);
        $carrierByTrip = [];
        foreach ($carrierPlans->fetchAll(PDO::FETCH_ASSOC) as $plan) {
            $tripId = (int)$plan['trip_id'];
            $trip = $rows[$byId[$tripId]];
            $condition = (string)$plan['condition_type'];
            $days = $plan['days_count'] === null ? null : (int)$plan['days_count'];
            $daysKind = (string)($plan['days_kind'] ?: DateCalculationService::DAYS_KIND_CALENDAR);
            $specificDate = self::validDate($plan['specific_due_date'] ?? null);
            $dueDate = DateCalculationService::calculateFinalDueDate(
                $condition, self::validDate($trip['actual_start_date'] ?? null),
                self::validDate($trip['actual_end_date'] ?? null), null, $days, $daysKind, $specificDate
            );
            $displayDate = $dueDate ?? DateCalculationService::calculateForecastDueDate(
                $condition, self::validDate($trip['planned_start_date'] ?? null),
                self::validDate($trip['planned_finish_date'] ?? null), $days, $daysKind, $specificDate
            );
            if ($displayDate === null) continue;
            $carrierByTrip[$tripId][] = [
                'date' => $displayDate,
                'due_date' => $dueDate,
                'remaining' => (float)$plan['amount'] - (float)$plan['paid_amount'] > 0.005,
                'status' => $dueDate === null ? 'waiting_event' : 'ready',
                'id' => (int)$plan['id'],
            ];
        }
        foreach ($rows as &$row) {
            $plans = $carrierByTrip[(int)$row['id']] ?? [];
            usort($plans, static fn(array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            foreach ($plans as $plan) {
                if ($plan['remaining']) { $row['planned_carrier_payment'] = $plan; break; }
            }
            $row['planned_carrier_payment'] ??= $plans[0] ?? null;
        }
        unset($row);
        return $rows;
    }

    private static function validDate(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
