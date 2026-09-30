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
                ct.id AS carrier_id, ct.name AS carrier_name
            FROM waste_trips wt
            JOIN clients c ON c.id=wt.client_id
            LEFT JOIN crews cr ON cr.id=wt.route_executor_id
            LEFT JOIN contractors ct ON ct.id=cr.contractor_id
            WHERE wt.deleted_at IS NULL AND wt.legal_entity_code='planex' AND {$scope['sql']}
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
            $byId[(int)$row['id']] = $index;
        }
        unset($row);

        // Preserved v3 rates live in obligations; older trips use the source payment terms.
        $rates = $pdo->prepare("SELECT source_parent_id AS trip_id, party_role, SUM(amount) AS amount
            FROM finance_obligations WHERE source_parent_type='WASTE_TRIP'
              AND cancelled_at IS NULL AND status<>'cancelled' AND source_parent_id IN ($marks)
            GROUP BY source_parent_id,party_role");
        $rates->execute($ids);
        foreach ($rates->fetchAll(PDO::FETCH_ASSOC) as $rate) {
            $tripId = (int)$rate['trip_id'];
            $side = (string)$rate['party_role'];
            if (isset($byId[$tripId], $rows[$byId[$tripId]]['sides'][$side])) {
                $rows[$byId[$tripId]]['sides'][$side]['rate'] = (string)$rate['amount'];
            }
        }
        $fallback = $pdo->prepare("SELECT waste_trip_id AS trip_id,'customer' AS party_role,SUM(amount) AS amount
            FROM waste_trip_customer_payments WHERE waste_trip_id IN ($marks) GROUP BY waste_trip_id
            UNION ALL
            SELECT waste_trip_id AS trip_id,'carrier' AS party_role,SUM(amount) AS amount
            FROM waste_trip_carrier_payments WHERE waste_trip_id IN ($marks) GROUP BY waste_trip_id");
        $fallback->execute(array_merge($ids, $ids));
        foreach ($fallback->fetchAll(PDO::FETCH_ASSOC) as $rate) {
            $index = $byId[(int)$rate['trip_id']] ?? null;
            $side = (string)$rate['party_role'];
            if ($index !== null && $rows[$index]['sides'][$side]['rate'] === null) {
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
        return $rows;
    }
}
