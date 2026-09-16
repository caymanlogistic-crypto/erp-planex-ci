<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

/** Explicit, separately authorized cutover. Never called by a page or synchronization. */
final class WasteTripFinancialTransitionService
{
    public static function transition(PDO $pdo, array $actor): int
    {
        if ($pdo->inTransaction()) throw new RuntimeException('Переход требует отдельной транзакции.');
        $pdo->beginTransaction();
        try {
            $trips = $pdo->query('SELECT id FROM waste_trips WHERE financial_model_version=1 ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $preserved = [];
            foreach ($trips as $tripId) {
                // Invoice creation/settlement also locks the obligation: keep the decision
                // and conversion in the same transaction as those financial writers.
                $lock = $pdo->prepare("SELECT id FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND source_parent_id=? FOR UPDATE");
                $lock->execute([$tripId]);
                $lock->fetchAll(PDO::FETCH_COLUMN);
                $q = $pdo->prepare("SELECT o.id FROM finance_obligations o WHERE o.source_parent_type='WASTE_TRIP' AND o.source_parent_id=? AND (
                    EXISTS(SELECT 1 FROM finance_invoice_links l WHERE l.obligation_id=o.id)
                    OR EXISTS(SELECT 1 FROM finance_operation_allocations a JOIN finance_operations op ON op.id=a.operation_id WHERE a.obligation_id=o.id AND a.cancelled_at IS NULL AND op.status='POSTED')) LIMIT 1 FOR UPDATE");
                $q->execute([$tripId]);
                $preserved[(int)$tripId] = $q->fetchColumn() !== false;
                if (WasteTripGeneralPaymentService::fetchForTrip($pdo,(int)$tripId) !== []) throw new RuntimeException('Обнаружен незавершённый переход финансов рейса '.$tripId.'.');
            }
            foreach ($trips as $tripId) {
                $tripId = (int)$tripId;
                $customer = WasteTripCustomerPaymentService::fetchForTrip($pdo,$tripId);
                $carrier = WasteTripCarrierPaymentService::fetchForTrip($pdo,$tripId);
                $defaults = static function (array $rows): array {
                    foreach ($rows as &$row) if (trim((string)$row['condition_type']) === '') {
                        $row['condition_type']='after_end';$row['days_count']=3;$row['days_kind']='calendar';
                    }
                    unset($row);
                    return $rows;
                };
                WasteTripGeneralPaymentService::store($pdo,$tripId,$defaults($customer),$actor);
                WasteTripCustomerPaymentService::store($pdo,$tripId,$defaults($carrier),$actor);
                WasteTripCarrierPaymentService::store($pdo,$tripId,[],$actor);
                // v3 displays the new amounts but retains the original settlements.
                // Original payment rows, including IDs, are preserved in the audit below.
                $version = $preserved[$tripId] ? 3 : 2;
                $pdo->prepare('UPDATE waste_trips SET financial_model_version=?,lock_version=lock_version+1 WHERE id=?')->execute([$version,$tripId]);
                if ($version === 2) FinanceObligationService::syncWasteTrip($pdo,$tripId);
                $pdo->prepare("INSERT INTO waste_trip_audit_log(waste_trip_id,action,before_json,after_json,actor_user_id,actor_role) VALUES (?,'update',?,?,?,?)")->execute([
                    $tripId,json_encode(['financial_model_version'=>1,'customer_payments'=>$customer,'carrier_payments'=>$carrier],JSON_THROW_ON_ERROR),
                    json_encode(['financial_model_version'=>$version,'settlements_preserved'=>$preserved[$tripId],'general_payments'=>$defaults($customer),'customer_payments'=>$defaults($carrier),'carrier_payments'=>[]],JSON_THROW_ON_ERROR),
                    (int)$actor['user_id']?:null,$actor['role_code'],
                ]);
            }
            $pdo->commit();
            return count($trips);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
