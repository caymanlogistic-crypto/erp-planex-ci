<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DomainException;
use PDO;

final class WasteTripClosingInvoiceService
{
    /** @param list<int> $tripIds @return array<int,array{status:string,detail:string,invoice_id:int}> */
    public static function summaries(PDO $pdo, array $tripIds): array
    {
        if (!$tripIds) return [];
        $marks=implode(',',array_fill(0,count($tripIds),'?'));
        $query=$pdo->prepare("SELECT o.source_parent_id trip_id,o.id obligation_id,o.due_date,o.forecast_due_date,o.status obligation_status,
                i.id invoice_id,i.amount invoice_amount,l.amount link_amount
            FROM finance_obligations o
            LEFT JOIN finance_invoice_links l ON l.obligation_id=o.id
            LEFT JOIN finance_invoices i ON i.id=l.invoice_id AND i.status<>'cancelled'
            WHERE o.source_parent_type='WASTE_TRIP' AND o.party_role='carrier' AND o.cancelled_at IS NULL
              AND o.status<>'cancelled' AND o.source_parent_id IN ($marks)
            ORDER BY o.source_parent_id,o.id,i.id");
        $query->execute($tripIds);
        $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        $invoiceIds=array_values(array_unique(array_filter(array_map(static fn(array $r):int=>(int)($r['invoice_id']??0),$rows))));
        $timelines=FinanceInvoicePaymentTimelineService::buildForInvoices($pdo,$invoiceIds);
        $group=[];
        foreach($rows as $row) $group[(int)$row['trip_id']][]=$row;
        $today=new DateTimeImmutable('today');
        $result=[];
        foreach($tripIds as $tripId) {
            $items=$group[$tripId]??[];$linked=[];$dates=[];$tripObligations=[];
            foreach($items as $item) {
                $tripObligations[(int)$item['obligation_id']]=true;
                $id=(int)($item['invoice_id']??0);
                if($id<=0) continue;
                $linked[$id]=(int)round((float)$item['invoice_amount']*100);
            }
            if(!$linked) {$result[$tripId]=['status'=>'missing','detail'=>'—','invoice_id'=>0];continue;}
            $paid=true;$paymentDate=null;
            foreach($linked as $id=>$amount) {
                $timeline=$timelines[$id]??[];
                if((int)round((float)($timeline['paid_total']??0)*100)<$amount) $paid=false;
                foreach($timeline['plans']??[] as $plan) {
                    if(!isset($tripObligations[(int)($plan['obligation_id']??0)])) continue;
                    if((int)round((float)($plan['remaining_amount']??0)*100)<=0) continue;
                    $deadline=FinanceInvoicePaymentTimelineService::deadlineState($plan,$today);
                    if(!$deadline['waiting_event'] && $deadline['due_date']) $dates[]=$deadline['due_date'];
                }
                foreach($timeline['facts']??[] as $fact) {
                    $date=$fact['actual_date']??null;
                    if($date && ($paymentDate===null || $date>$paymentDate)) $paymentDate=$date;
                }
            }
            if($paid) {
                $detail=$paymentDate?'Оплата: '.date('d.m.Y',strtotime($paymentDate)):'Дата оплаты не указана';
                $result[$tripId]=['status'=>'paid','detail'=>$detail,'invoice_id'=>(int)array_key_first($linked)];
                continue;
            }
            sort($dates);
            $due=$dates[0]??null;
            if(!$due) $detail='Срок по рейсу ожидает события';
            else {
                $dueDate=new DateTimeImmutable($due);
                $days=$dueDate<$today?(int)$dueDate->diff($today)->format('%a'):0;
                $suffix=$days%10===1&&$days%100!==11?'день':(in_array($days%10,[2,3,4],true)&&!in_array($days%100,[12,13,14],true)?'дня':'дней');
                $detail=$days>0?'Просрочен '.$days.' '.$suffix:($dueDate==$today?'Срок оплаты сегодня':'Срок оплаты '.date('d.m.Y',strtotime($due)));
            }
            $result[$tripId]=['status'=>'waiting','detail'=>$detail,'invoice_id'=>(int)array_key_first($linked)];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public static function candidates(PDO $pdo,int $tripId): array
    {
        $trip=WasteTripClosingService::trip($pdo,$tripId);
        $carrier=self::carrierId($pdo,$tripId);
        if($carrier<=0) return [];
        $stmt=$pdo->prepare("SELECT i.id,i.number,i.invoice_date,i.amount,i.paid_amount
            FROM finance_invoices i
            WHERE i.direction='INCOMING' AND i.counterparty_entity_type='contractor' AND i.counterparty_entity_id=?
              AND i.status<>'cancelled' AND i.paid_amount=0
              AND NOT EXISTS(SELECT 1 FROM finance_invoice_links l WHERE l.invoice_id=i.id)
              AND NOT EXISTS(SELECT 1 FROM finance_operation_allocations a JOIN finance_operations op ON op.id=a.operation_id
                  WHERE a.invoice_id=i.id AND a.cancelled_at IS NULL AND op.status='POSTED')
            ORDER BY i.invoice_date DESC,i.id DESC LIMIT 100");
        $stmt->execute([$carrier]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function linkExisting(PDO $pdo,int $tripId,int $invoiceId,array $actor): void
    {
        WasteTripClosingService::trip($pdo,$tripId);
        $carrier=self::carrierId($pdo,$tripId);
        $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare("SELECT id,amount FROM finance_invoices WHERE id=? AND direction='INCOMING'
                AND counterparty_entity_type='contractor' AND counterparty_entity_id=? AND status<>'cancelled' AND paid_amount=0 FOR UPDATE");
            $stmt->execute([$invoiceId,$carrier]);$invoice=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$invoice) throw new DomainException('Счёт перевозчика недоступен для привязки.');
            $check=$pdo->prepare('SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=?');
            $check->execute([$invoiceId]);
            if((int)$check->fetchColumn()>0) throw new DomainException('Этот счёт уже связан с обязательством.');
            $obligations=$pdo->prepare("SELECT o.id,o.amount,COALESCE(SUM(CASE WHEN i.status<>'cancelled' THEN l.amount ELSE 0 END),0) linked
                FROM finance_obligations o LEFT JOIN finance_invoice_links l ON l.obligation_id=o.id
                LEFT JOIN finance_invoices i ON i.id=l.invoice_id
                WHERE o.source_parent_type='WASTE_TRIP' AND o.source_parent_id=? AND o.party_role='carrier'
                  AND o.counterparty_entity_type='contractor' AND o.counterparty_entity_id=?
                  AND o.cancelled_at IS NULL AND o.status<>'cancelled' GROUP BY o.id,o.amount ORDER BY o.id");
            $obligations->execute([$tripId,$carrier]);$remaining=(int)round((float)$invoice['amount']*100);$links=[];
            foreach($obligations->fetchAll(PDO::FETCH_ASSOC) as $ob) {
                $free=max(0,(int)round(((float)$ob['amount']-(float)$ob['linked'])*100));
                $take=min($remaining,$free);
                if($take>0) {$links[]=['obligation_id'=>(int)$ob['id'],'amount'=>sprintf('%d.%02d',intdiv($take,100),$take%100)];$remaining-=$take;}
                if($remaining===0) break;
            }
            if($remaining>0) throw new DomainException('Сумма счёта превышает свободный остаток обязательств рейса.');
            FinanceObligationService::replaceInvoiceLinks($pdo,$invoiceId,$links,$actor);
            $pdo->commit();
        } catch(\Throwable $error) {
            if($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    private static function carrierId(PDO $pdo,int $tripId): int
    {
        $stmt=$pdo->prepare('SELECT c.contractor_id FROM waste_trips wt LEFT JOIN crews c ON c.id=wt.route_executor_id WHERE wt.id=?');
        $stmt->execute([$tripId]);
        return (int)$stmt->fetchColumn();
    }
}
