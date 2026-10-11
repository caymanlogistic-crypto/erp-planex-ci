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
              AND i.status<>'cancelled'
              AND NOT EXISTS(SELECT 1 FROM finance_invoice_links l WHERE l.invoice_id=i.id)
              AND NOT EXISTS(SELECT 1 FROM finance_operation_allocations a
                  WHERE a.invoice_id=i.id AND a.cancelled_at IS NULL
                    AND (a.obligation_id IS NOT NULL OR a.linear_route_id IS NOT NULL OR a.linear_route_payment_id IS NOT NULL))
              AND (i.paid_amount=0 AND i.status<>'paid' OR EXISTS(SELECT 1 FROM finance_operation_allocations a
                  JOIN finance_operations op ON op.id=a.operation_id
                  WHERE a.invoice_id=i.id AND a.cancelled_at IS NULL AND op.status='POSTED'))
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
            $stmt=$pdo->prepare("SELECT id,amount,paid_amount,status FROM finance_invoices WHERE id=? AND direction='INCOMING'
                AND counterparty_entity_type='contractor' AND counterparty_entity_id=? AND status<>'cancelled' FOR UPDATE");
            $stmt->execute([$invoiceId,$carrier]);$invoice=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$invoice) throw new DomainException('Счёт перевозчика недоступен для привязки.');
            $check=$pdo->prepare('SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=?');
            $check->execute([$invoiceId]);
            if((int)$check->fetchColumn()>0) throw new DomainException('Этот счёт уже связан с обязательством.');
            $allocations=$pdo->prepare("SELECT a.*,op.status operation_status,op.cancelled_at operation_cancelled_at,
                op.operation_type,op.counterparty_entity_type operation_counterparty_type,
                op.counterparty_entity_id operation_counterparty_id FROM finance_operation_allocations a
                JOIN finance_operations op ON op.id=a.operation_id
                WHERE a.invoice_id=? AND a.cancelled_at IS NULL ORDER BY a.id FOR UPDATE");
            $allocations->execute([$invoiceId]);
            $paidAllocations=$allocations->fetchAll(PDO::FETCH_ASSOC);
            foreach($paidAllocations as $allocation) {
                if($allocation['obligation_id']!==null || $allocation['linear_route_id']!==null || $allocation['linear_route_payment_id']!==null
                    || $allocation['operation_status']!=='POSTED' || $allocation['operation_cancelled_at']!==null
                    || $allocation['operation_type']!=='EXPENSE'
                    || ($allocation['operation_counterparty_type']!==null && $allocation['operation_counterparty_type']!=='contractor')
                    || ($allocation['operation_counterparty_id']!==null && (int)$allocation['operation_counterparty_id']!==$carrier)) {
                    throw new DomainException('У счёта есть распределение, которое нельзя перенести на этот рейс.');
                }
            }
            if(!$paidAllocations && ((float)$invoice['paid_amount']>0 || $invoice['status']==='paid')) {
                throw new DomainException('У счёта указана оплата без проведённого распределения. Сначала уточните оплату в финансах.');
            }
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
            $paymentCapacity=$paidAllocations?self::moveStandaloneAllocations($pdo,$invoiceId,$paidAllocations,$links,$actor):[];
            FinanceObligationService::replaceInvoiceLinks($pdo,$invoiceId,$links,$actor);
            if($paidAllocations) self::restoreAllocationsOnTrip($pdo,$tripId,$invoiceId,$paidAllocations,$paymentCapacity,$actor);
            $pdo->commit();
        } catch(\Throwable $error) {
            if($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    /** Cancel old standalone rows inside the enclosing transaction so the canonical link guard sees no paid fact. */
    private static function moveStandaloneAllocations(PDO $pdo,int $invoiceId,array $allocations,array $links,array $actor): array
    {
        $paid=array_sum(array_map(static fn(array $a):int=>(int)round((float)$a['amount']*100),$allocations));
        $capacity=0;$byObligation=[];
        $paidStmt=$pdo->prepare("SELECT COALESCE(SUM(a.amount),0) FROM finance_operation_allocations a
            JOIN finance_operations op ON op.id=a.operation_id
            WHERE a.obligation_id=? AND a.cancelled_at IS NULL AND op.status='POSTED'");
        foreach($links as $link) {
            $ob=$pdo->prepare('SELECT amount FROM finance_obligations WHERE id=? FOR UPDATE');
            $ob->execute([$link['obligation_id']]);
            $amount=(int)round((float)$ob->fetchColumn()*100);
            $paidStmt->execute([$link['obligation_id']]);
            $already=(int)round((float)$paidStmt->fetchColumn()*100);
            $free=min((int)round((float)$link['amount']*100),max(0,$amount-$already));
            $byObligation[(int)$link['obligation_id']]=$free;
            $capacity+=$free;
        }
        if($paid>$capacity) throw new DomainException('Оплата счёта превышает неоплаченный остаток обязательств рейса.');
        $cancel=$pdo->prepare("UPDATE finance_operation_allocations SET cancelled_at=NOW(),cancelled_by_user_id=?,cancelled_by_role=?,
            cancel_reason='Перенос оплаты счёта на обязательства рейса' WHERE id=? AND cancelled_at IS NULL");
        foreach($allocations as $allocation) {
            $cancel->execute([(int)$actor['user_id'],(string)$actor['role_code'],(int)$allocation['id']]);
            FinanceAuditLogService::log($pdo,'finance_allocation',(int)$allocation['id'],'allocation_reclassify',
                ['invoice_id'=>$invoiceId,'obligation_id'=>null,'amount'=>$allocation['amount']],
                ['trip_obligation_links'=>$links],(int)$actor['user_id'],(string)$actor['role_code']);
        }
        return $byObligation;
    }

    /** Recreate each factual payment against the new links without changing its operation, amount or date. */
    private static function restoreAllocationsOnTrip(PDO $pdo,int $tripId,int $invoiceId,array $allocations,array $remaining,array $actor): void
    {
        $insert=$pdo->prepare("INSERT INTO finance_operation_allocations
            (operation_id,invoice_id,obligation_id,linear_route_id,linear_route_payment_id,cash_flow_category_id,
             amount,allocation_date,method,rule_code,comment,created_by_user_id,created_by_role)
            VALUES (?,?,?,NULL,NULL,?,?,?,?,?,?,?,?)");
        foreach($allocations as $allocation) {
            $left=(int)round((float)$allocation['amount']*100);
            foreach($remaining as $obligationId=>&$free) {
                if($left===0) break;
                $take=min($left,$free);
                if($take===0) continue;
                $insert->execute([(int)$allocation['operation_id'],$invoiceId,$obligationId,
                    $allocation['cash_flow_category_id'],sprintf('%d.%02d',intdiv($take,100),$take%100),$allocation['allocation_date'],$allocation['method'],
                    $allocation['rule_code'],$allocation['comment'],(int)$actor['user_id'],(string)$actor['role_code']]);
                $newId=(int)$pdo->lastInsertId();
                FinanceAuditLogService::log($pdo,'finance_allocation',$newId,'allocation_reclassify_create',null,
                    ['previous_allocation_id'=>(int)$allocation['id'],'invoice_id'=>$invoiceId,'obligation_id'=>$obligationId,'amount'=>sprintf('%d.%02d',intdiv($take,100),$take%100)],
                    (int)$actor['user_id'],(string)$actor['role_code']);
                $free-=$take;$left-=$take;
            }
            unset($free);
            if($left!==0) throw new DomainException('Не удалось распределить оплату счёта по обязательствам рейса.');
        }
        FinanceSettlementCascadeService::recalculateInvoice($pdo,$invoiceId,(int)$actor['user_id'],(string)$actor['role_code']);
        FinanceObligationService::syncWasteTrip($pdo,$tripId);
    }

    private static function carrierId(PDO $pdo,int $tripId): int
    {
        $stmt=$pdo->prepare('SELECT c.contractor_id FROM waste_trips wt LEFT JOIN crews c ON c.id=wt.route_executor_id WHERE wt.id=?');
        $stmt->execute([$tripId]);
        return (int)$stmt->fetchColumn();
    }
}
