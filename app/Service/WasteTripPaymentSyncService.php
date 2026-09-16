<?php

namespace App\Service;

use PDO;
use RuntimeException;

/** Synchronize source amounts while ERP owns the payment terms and paid facts. */
final class WasteTripPaymentSyncService
{
    public static function synchronize(PDO $pdo, int $tripId, string $side, ?string $amount, ?string $comment, array $actor): void
    {
        if (!$pdo->inTransaction()) throw new RuntimeException('Стоимость синхронизируется в транзакции рейса.');
        $table = match ($side) {
            'customer' => 'waste_trip_customer_payments',
            'general' => 'waste_trip_general_payments',
            default => throw new RuntimeException('Недопустимая сторона синхронизации.'),
        };
        $query = $pdo->prepare("SELECT * FROM {$table} WHERE waste_trip_id=? ORDER BY sort_order,id FOR UPDATE");
        $query->execute([$tripId]);
        $old = $query->fetchAll(PDO::FETCH_ASSOC);
        $cents = static fn(string $v): int => (int) str_replace('.', '', $v);
        $known = array_filter($old, static fn(array $r): bool => $r['amount'] !== null);
        if ($old !== [] && (($amount === null && $known === []) || ($amount !== null && count($known) === count($old) && array_sum(array_map(static fn(array $r): int => $cents($r['amount']), $old)) === $cents($amount)))) return;
        if ($side === 'customer') {
            $removed = $amount === null ? $old : array_slice($old, 1);
            self::assertUnlinked($pdo, $tripId, array_column($removed, 'id'));
        }
        if ($old === []) {
            $pdo->prepare("INSERT INTO {$table} (waste_trip_id,sort_order,amount,payment_type,payment_method,condition_type,days_count,days_kind,condition_comment,created_by_user_id,created_by_role,updated_by_user_id,updated_by_role) VALUES (?,1,?,'','','after_end',3,'calendar',?,?,?,?,?)")
                ->execute([$tripId,$amount,$comment,$actor['user_id'],$actor['role_code'],$actor['user_id'],$actor['role_code']]);
        } else {
            // The first installment keeps its identity and all manually edited terms.
            $pdo->prepare("UPDATE {$table} SET amount=?,updated_by_user_id=?,updated_by_role=? WHERE id=?")
                ->execute([$amount,$actor['user_id'],$actor['role_code'],$old[0]['id']]);
            $pdo->prepare("DELETE FROM {$table} WHERE waste_trip_id=? AND id<>?")->execute([$tripId,$old[0]['id']]);
        }
        $query->execute([$tripId]);
        LinearRouteService::logFinanceAudit($pdo,'waste_trip',$tripId,$side.'_payments_update',['payments'=>$old],['payments'=>$query->fetchAll(PDO::FETCH_ASSOC),'source'=>'legacy_sync'],(int)$actor['user_id'],(string)$actor['role_code']);
    }

    private static function assertUnlinked(PDO $pdo, int $tripId, array $ids): void
    {
        if ($ids === []) return;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $q = $pdo->prepare("SELECT COUNT(*) FROM finance_obligations o WHERE o.source_type='WASTE_TRIP_CUSTOMER_PAYMENT' AND o.source_parent_type='WASTE_TRIP' AND o.source_parent_id=? AND o.source_id IN ({$marks}) AND (
            EXISTS(SELECT 1 FROM finance_operation_allocations a JOIN finance_operations op ON op.id=a.operation_id WHERE a.obligation_id=o.id AND a.cancelled_at IS NULL AND op.status='POSTED')
            OR EXISTS(SELECT 1 FROM finance_invoice_links l WHERE l.obligation_id=o.id))");
        $q->execute(array_merge([$tripId], $ids));
        if ((int)$q->fetchColumn() > 0) throw new RuntimeException('Изменение стоимости затрагивает разбивку, связанную со счетами или проведёнными платежами. Согласуйте разбивку оплаты.');
    }
}
