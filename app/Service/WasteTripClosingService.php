<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use PDO;
use Throwable;

/** Closing files belong to a started or completed waste trip; finance links only reference them. */
final class WasteTripClosingService
{
    public const REVIEW_EMAIL_ENABLED = false;

    public const SLOTS = [
        'contract' => 'Подписанный договор / заявка',
        'invoice' => 'Счёт',
        'act' => 'АКТ/УПД',
        'waybill' => 'Путевой лист',
    ];
    private const CODES = [
        'contract' => ['agreement', 'application'],
        'invoice' => ['invoice'],
        'act' => ['act', 'upd'],
        'waybill' => ['waybill'],
        'extra' => ['supporting'],
    ];
    private const RECIPIENTS = ['planex' => 'info@plan-ex.ru', 'mg_logistic' => 'shibanov@mg-log.ru'];

    public static function ready(PDO $pdo): bool
    {
        try {
            $pdo->query('SELECT id FROM waste_trip_closing_documents LIMIT 1');
            $pdo->query('SELECT waste_trip_id FROM waste_trip_closing_reviews LIMIT 1');
            $pdo->query('SELECT finance_link_id FROM waste_trip_closing_finance_links LIMIT 1');
            return true;
        } catch (Throwable) { return false; }
    }

    public static function recipient(string $legalEntityCode): string
    {
        return self::RECIPIENTS[$legalEntityCode] ?? throw new DomainException('Для исполнителя рейса не настроен получатель письма.');
    }

    private static function hasCompleteSet(array $trip): bool
    {
        $present=[];
        foreach ($trip['documents']??[] as $document) $present[(string)$document['slot_code']]=true;
        foreach (array_keys(self::SLOTS) as $slot) if (!isset($present[$slot])) return false;
        $requests=(int)($trip['request_count']??0);
        return $requests>0&&(int)($trip['processed_count']??0)>=$requests;
    }

    public static function section(array $trip): string
    {
        if (($trip['status']??'completed')==='in_progress') return 'incomplete';
        $complete=self::hasCompleteSet($trip);
        $paid=(int)round((float)($trip['carrier_paid_amount']??0)*100)>0;
        return $paid?($complete?'paid_complete':'paid_incomplete'):($complete?'ready':'incomplete');
    }

    public static function sectionLabel(string $section): string
    {
        return match($section){
            'incomplete'=>'Не хватает документов',
            'ready'=>'Готово к оплате',
            'paid_incomplete'=>'Оплачено, но не полный комплект',
            'paid_complete'=>'Оплаченные полные комплекты',
            default=>'Все рейсы',
        };
    }

    /** Count completed, unloaded trips whose missing closing files or TN processing still block payment. */
    public static function blockedUnloadedCount(PDO $pdo): int
    {
        $scope=self::closingVisibilityScope(true);
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM waste_trips wt
            WHERE wt.deleted_at IS NULL AND wt.status='completed'
              AND wt.actual_end_date IS NOT NULL
              AND {$scope['sql']}
              AND COALESCE((SELECT SUM(a.amount)
                  FROM finance_obligations o
                  JOIN finance_operation_allocations a ON a.obligation_id=o.id AND a.cancelled_at IS NULL
                  JOIN finance_operations fo ON fo.id=a.operation_id AND fo.status='POSTED'
                  LEFT JOIN finance_invoices fi ON fi.id=a.invoice_id
                  WHERE o.source_parent_type='WASTE_TRIP' AND o.source_parent_id=wt.id
                    AND o.party_role='carrier' AND o.cancelled_at IS NULL
                    AND (fi.id IS NULL OR (fi.cancelled_at IS NULL AND fi.status<>'cancelled'))),0)<=0
              AND (
                  (SELECT COUNT(DISTINCT cd.slot_code)
                     FROM waste_trip_closing_documents cd
                     JOIN documents d ON d.id=cd.document_id AND d.deleted_at IS NULL
                    WHERE cd.waste_trip_id=wt.id AND cd.deleted_at IS NULL
                      AND cd.slot_code IN ('contract','invoice','act','waybill'))<4
                  OR NOT EXISTS (SELECT 1 FROM waste_trip_requests wtr
                      JOIN feo_requests fr ON fr.id=wtr.feo_request_id
                      WHERE wtr.waste_trip_id=wt.id AND wtr.removed_at IS NULL)
                  OR EXISTS (SELECT 1 FROM waste_trip_requests wtr
                      JOIN feo_requests fr ON fr.id=wtr.feo_request_id
                      WHERE wtr.waste_trip_id=wt.id AND wtr.removed_at IS NULL
                        AND NOT EXISTS (SELECT 1 FROM waste_trip_completion_items i
                            WHERE i.waste_trip_request_id=wtr.id
                              AND (COALESCE(i.tn_absent,0)=1 OR
                                   (i.arrived_at IS NOT NULL AND i.departed_at IS NOT NULL))))
              )");
        $stmt->execute($scope['params']);
        return max(0,(int)$stmt->fetchColumn());
    }

    public static function carrierDebt(array $trip): ?string
    {
        $rate=$trip['carrier_rate']??null;
        if($rate===null||(float)$rate<=0)return null;
        $debt=(int)round((float)$rate*100)-(int)round((float)($trip['carrier_paid_amount']??0)*100);
        if($debt<=0)return null;
        return 'Долг '.number_format($debt/100,$debt%100===0?0:2,',',' ').' ₽';
    }

    /** @return list<array<string,mixed>> */
    public static function trips(PDO $pdo): array
    {
        $scope = self::closingVisibilityScope();
        $stmt = $pdo->prepare("SELECT wt.id,wt.business_id,wt.comment,wt.status,wt.actual_start_date,wt.actual_end_date,wt.legal_entity_code,
                wt.route_executor_id,
                ct.id carrier_id,COALESCE(ct.name,'—') carrier_name,COALESCE(u.full_name,'—') manager_name,
                u.membership_id manager_membership_id,
                COALESCE(r.state,'work') review_state,COALESCE(r.version,0) review_version,
                r.mail_status,r.submitted_at
              FROM waste_trips wt
              LEFT JOIN crews c ON c.id=wt.route_executor_id
              LEFT JOIN contractors ct ON ct.id=c.contractor_id
              LEFT JOIN users u ON u.id=wt.responsible_user_id
              LEFT JOIN waste_trip_closing_reviews r ON r.waste_trip_id=wt.id
             WHERE wt.deleted_at IS NULL AND ".self::allowedStatusesSql()." AND {$scope['sql']}
             ORDER BY wt.business_id DESC LIMIT 500");
        $stmt->execute($scope['params']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $ids=array_map(static fn(array $row):int=>(int)$row['id'],$rows);
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $crewIds=array_values(array_unique(array_filter(array_map('intval',array_column($rows,'route_executor_id')))));
        $drivers=[];
        if($crewIds){
            $crewPlaceholders=implode(',',array_fill(0,count($crewIds),'?'));
            $driverQuery=$pdo->prepare("SELECT c.id crew_id,GROUP_CONCAT(d.full_name ORDER BY cd.position SEPARATOR ' / ') driver_names
                FROM crews c JOIN crew_drivers cd ON cd.crew_id=c.id JOIN drivers d ON d.id=cd.driver_id
                WHERE c.id IN ($crewPlaceholders) GROUP BY c.id");
            $driverQuery->execute($crewIds);
            foreach($driverQuery->fetchAll(PDO::FETCH_ASSOC) as $driver)$drivers[(int)$driver['crew_id']]=(string)$driver['driver_names'];
        }
        $documentQuery=$pdo->prepare("SELECT c.id,c.waste_trip_id,c.document_id,c.slot_code,c.requirement_code,c.custom_title,
                d.original_name,d.relative_path,d.mime_type,d.file_size,d.created_at
              FROM waste_trip_closing_documents c JOIN documents d ON d.id=c.document_id AND d.deleted_at IS NULL
             WHERE c.waste_trip_id IN ($placeholders) AND c.deleted_at IS NULL
             ORDER BY FIELD(c.slot_code,'contract','invoice','act','waybill','extra'),c.id");
        $documentQuery->execute($ids);
        $byTrip=[];
        foreach ($documentQuery->fetchAll(PDO::FETCH_ASSOC) as $document) $byTrip[(int)$document['waste_trip_id']][]=$document;
        $completion=self::processedCounts($pdo,$ids);
        $issueQuery=$pdo->prepare("SELECT wtr.waste_trip_id,fr.zayavka_id,
                COALESCE(i.tn_absent,0) tn_absent,i.arrived_at,i.departed_at,
                i.driver_signature_present,i.sender_signature_present,i.tn_datetime_status,i.recovery_source,
                COUNT(doc.id) scan_count
            FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id
            LEFT JOIN waste_trip_completion_items i ON i.waste_trip_request_id=wtr.id
            LEFT JOIN waste_trip_completion_documents doc ON doc.waste_trip_request_id=wtr.id AND doc.deleted_at IS NULL
            WHERE wtr.removed_at IS NULL AND wtr.waste_trip_id IN ($placeholders)
            GROUP BY wtr.id,wtr.waste_trip_id,fr.zayavka_id,i.tn_absent,i.arrived_at,i.departed_at,
                i.driver_signature_present,i.sender_signature_present,i.tn_datetime_status,i.recovery_source");
        $issueQuery->execute($ids);
        $tnIssues=[];$tnRequired=[];$tnScanned=[];
        foreach($issueQuery->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if((int)$item['tn_absent']===1) continue;
            $tripId=(int)$item['waste_trip_id'];
            $tnRequired[$tripId]=($tnRequired[$tripId]??0)+1;
            if((int)$item['scan_count']>0)$tnScanned[$tripId]=($tnScanned[$tripId]??0)+1;
            $issues=[];
            if((int)$item['scan_count']===0) $issues[]='нет скана ТН';
            if(!$item['arrived_at']||!$item['departed_at']) $issues[]='нет даты или времени';
            if(in_array($item['tn_datetime_status'],['partial','no'],true)&&!$item['recovery_source']) $issues[]='не указан источник восстановления';
            if((int)$item['driver_signature_present']!==1) $issues[]='нет подписи водителя';
            if((int)$item['sender_signature_present']!==1) $issues[]='нет подписи отправителя';
            if($issues) $tnIssues[(int)$item['waste_trip_id']][]='Заявка №'.$item['zayavka_id'].': '.implode(', ',$issues);
        }
        $invoiceSummaries=WasteTripClosingInvoiceService::summaries($pdo,$ids);
        $financeByTrip=[];
        foreach(FinanceReceivedPaidService::fetch($pdo,$ids,$scope) as $financeTrip)$financeByTrip[(int)$financeTrip['id']]=$financeTrip;
        $paid=[];
        $amountQuery=$pdo->prepare("SELECT source_parent_id,COUNT(*) obligations,SUM(amount) due,SUM(paid_amount) paid
            FROM finance_obligations WHERE source_parent_type='WASTE_TRIP' AND party_role='customer'
              AND cancelled_at IS NULL AND status<>'cancelled' AND source_parent_id IN ($placeholders)
            GROUP BY source_parent_id");
        $amountQuery->execute($ids);
        $eligible=[];
        foreach ($amountQuery->fetchAll(PDO::FETCH_ASSOC) as $amount) {
            if (self::moneyCents((string)$amount['due'])>0 && self::moneyCents((string)$amount['paid'])>=self::moneyCents((string)$amount['due'])) {
                $eligible[]=(int)$amount['source_parent_id'];
            }
        }
        if ($eligible) {
            $paymentPlaceholders=implode(',',array_fill(0,count($eligible),'?'));
            $paymentQuery=$pdo->prepare("SELECT DISTINCT o.source_parent_id,bt.id,bt.operation_date,bt.document_number
                FROM finance_operation_allocations a JOIN finance_obligations o ON o.id=a.obligation_id
                JOIN finance_operations fo ON fo.id=a.operation_id AND fo.status='POSTED'
                JOIN bank_transactions bt ON bt.id=fo.bank_transaction_id
                WHERE o.source_parent_type='WASTE_TRIP' AND o.source_parent_id IN ($paymentPlaceholders)
                  AND o.party_role='customer' AND o.cancelled_at IS NULL AND a.cancelled_at IS NULL AND a.amount>0
                  AND bt.credit_amount>0 AND COALESCE(bt.is_internal_transfer,0)=0
                  AND NOT EXISTS (SELECT 1 FROM finance_employee_movements fem WHERE fem.bank_transaction_id=bt.id)
                ORDER BY bt.operation_date,bt.id");
            $paymentQuery->execute($eligible);
            foreach ($paymentQuery->fetchAll(PDO::FETCH_ASSOC) as $payment) $paid[(int)$payment['source_parent_id']][]=$payment;
        }
        foreach ($rows as &$row) {
            $row['driver_names']=$drivers[(int)($row['route_executor_id']??0)]??'';
            $row['documents'] = $byTrip[(int)$row['id']] ?? [];
            $row['recipient_email'] = self::RECIPIENTS[(string)$row['legal_entity_code']] ?? '';
            $row['paid_bank_transactions'] = $paid[(int)$row['id']] ?? [];
            $row['request_count'] = (int)($completion[(int)$row['id']]['request_count'] ?? 0);
            $row['processed_count'] = (int)($completion[(int)$row['id']]['processed_count'] ?? 0);
            $row['tn_issues']=$tnIssues[(int)$row['id']]??[];
            $row['tn_required']=$tnRequired[(int)$row['id']]??0;
            $row['tn_scanned']=$tnScanned[(int)$row['id']]??0;
            $row['invoice_summary']=$invoiceSummaries[(int)$row['id']]??['status'=>'missing','detail'=>'—','invoice_id'=>0];
            $finance=$financeByTrip[(int)$row['id']]??null;
            $carrierSide=$finance['sides']['carrier']??['rate'=>null,'payments'=>[]];
            $customerSide=$finance['sides']['customer']??['rate'=>null,'payments'=>[]];
            $row['carrier_rate']=$carrierSide['rate'];
            $row['display_rate']=$row['legal_entity_code']==='mg_logistic'?($customerSide['source_rate']??$customerSide['rate']):$carrierSide['rate'];
            $row['customer_rate']=$customerSide['rate']??$customerSide['source_rate']??null;
            $row['customer_paid_amount']=array_sum(array_map(static fn(array $payment):float=>(float)$payment['amount'],$customerSide['payments']));
            $customerPaymentDates=array_filter(array_column($customerSide['payments'],'payment_date'));
            $row['customer_paid_date']=$customerPaymentDates?max($customerPaymentDates):null;
            $row['customer_settlement']=self::customerSettlementDisplay($row);
            $row['carrier_paid_amount']=array_sum(array_map(static fn(array $payment):float=>(float)$payment['amount'],$carrierSide['payments']));
            $paymentDates=array_column($carrierSide['payments'],'payment_date');
            $row['carrier_paid_date']=$paymentDates?max($paymentDates):null;
            $row['carrier_payment_plan']=$finance['planned_carrier_payment']??null;
            $row['carrier_payment_due']=$row['carrier_payment_plan']['date']??null;
            $row['carrier_payment_display']=self::carrierPaymentDisplay($row);
        }
        unset($row);
        return $rows;
    }

    /** @return array{status:string,text:string} */
    public static function customerSettlementDisplay(array $trip): array
    {
        $rate=$trip['customer_rate']??null;
        $paid=(int)round((float)($trip['customer_paid_amount']??0)*100);
        $money=static fn(int $cents):string=>number_format($cents/100,$cents%100===0?0:2,',',' ').' ₽';
        if($rate===null||(float)$rate<=0)return ['status'=>'unknown','text'=>$paid>0?'Оплачено '.$money($paid).' · сумма рейса не указана':'Сумма рейса не указана'];
        $due=max(0,(int)round((float)$rate*100)-$paid);
        if($due>0)return ['status'=>'debt','text'=>'Долг '.$money($due)];
        $date=(string)($trip['customer_paid_date']??'');
        return ['status'=>'paid','text'=>'Оплачено '.$money($paid).($date!==''?' · '.date('d.m.Y',strtotime($date)):'')];
    }

    /** @return array{status:string,text:string} */
    public static function carrierPaymentDisplay(array $trip, ?string $today = null): array
    {
        $today ??= (new \DateTimeImmutable('now',new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        $paid=(float)($trip['carrier_paid_amount']??0);
        $carrierRate=$trip['carrier_rate']??null;
        $paidDate=(string)($trip['carrier_paid_date']??'');
        $paidLabel=$paidDate!==''?date('d.m.Y',strtotime($paidDate)):'дату уточните';
        if($carrierRate!==null&&(float)$carrierRate>0&&$paid+0.005>=(float)$carrierRate)
            return ['status'=>'paid','text'=>'Оплачено: '.$paidLabel];
        $prefix=$paid>0?'Частично оплачено: '.$paidLabel.' · ':'';
        $plan=$trip['carrier_payment_plan']??null;
        $date=is_array($plan)?(string)($plan['date']??''):'';
        if($date==='')return ['status'=>$paid>0?'partial':'none','text'=>$paid>0?'Выплата: '.$paidLabel:'Срок оплаты не указан'];
        $label=date('d.m.Y',strtotime($date));
        if(empty($plan['due_date'])||($plan['status']??'')!=='ready')
            return ['status'=>'forecast','text'=>$prefix.'Ожидается событие · план '.$label];
        if($date<$today){
            $days=(new \DateTimeImmutable($date))->diff(new \DateTimeImmutable($today))->days;
            $word=($days%100>=11&&$days%100<=14)?'дней':($days%10===1?'день':($days%10>=2&&$days%10<=4?'дня':'дней'));
            return ['status'=>'overdue','text'=>$prefix.'Просрочено на '.$days.' '.$word.' · срок '.$label];
        }
        return ['status'=>$paid>0?'partial':'planned','text'=>$prefix.($date===$today?'Сегодня · срок '.$label:'Срок оплаты: '.$label)];
    }

    /** Matches the processed-request count shown by TripCompletionService's logistics assistant view. */
    public static function processedCounts(PDO $pdo, array $tripIds): array
    {
        if (!$tripIds) return [];
        $placeholders=implode(',',array_fill(0,count($tripIds),'?'));
        $query=$pdo->prepare("SELECT wtr.waste_trip_id,COUNT(DISTINCT wtr.id) request_count,
                COUNT(DISTINCT CASE WHEN COALESCE(i.tn_absent,0)=1 OR
                    (i.arrived_at IS NOT NULL AND i.departed_at IS NOT NULL) THEN wtr.id END) processed_count
            FROM waste_trip_requests wtr
            JOIN feo_requests fr ON fr.id=wtr.feo_request_id
            LEFT JOIN waste_trip_completion_items i ON i.waste_trip_request_id=wtr.id
            WHERE wtr.removed_at IS NULL AND wtr.waste_trip_id IN ($placeholders)
            GROUP BY wtr.waste_trip_id");
        $query->execute($tripIds);
        $result=[];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int)$row['waste_trip_id']]=$row;
        return $result;
    }

    /**
     * Closing-document readiness for trips already authorized by the finance register.
     * Uses the same four required slots and processed-request rule as section().
     *
     * @return array<int,array{status:string,missing:list<string>,processed:int,total:int,tn_issues:list<string>}>
     */
    public static function paymentReadinessForTrips(PDO $pdo, array $tripIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tripIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $documents = $pdo->prepare("SELECT c.waste_trip_id,c.slot_code
            FROM waste_trip_closing_documents c
            JOIN documents d ON d.id=c.document_id AND d.deleted_at IS NULL
            WHERE c.waste_trip_id IN ($marks) AND c.deleted_at IS NULL
              AND c.slot_code IN ('contract','invoice','act','waybill')");
        $documents->execute($ids);
        $present = [];
        foreach ($documents->fetchAll(PDO::FETCH_ASSOC) as $document) {
            $present[(int)$document['waste_trip_id']][(string)$document['slot_code']] = true;
        }
        $counts = self::processedCounts($pdo, $ids);
        $issuesQuery = $pdo->prepare("SELECT wtr.waste_trip_id,fr.zayavka_id,
                COALESCE(i.tn_absent,0) tn_absent,i.arrived_at,i.departed_at,
                i.driver_signature_present,i.sender_signature_present,i.tn_datetime_status,i.recovery_source,
                COUNT(doc.id) scan_count
            FROM waste_trip_requests wtr
            JOIN feo_requests fr ON fr.id=wtr.feo_request_id
            LEFT JOIN waste_trip_completion_items i ON i.waste_trip_request_id=wtr.id
            LEFT JOIN waste_trip_completion_documents doc
              ON doc.waste_trip_request_id=wtr.id AND doc.deleted_at IS NULL
            WHERE wtr.removed_at IS NULL AND wtr.waste_trip_id IN ($marks)
            GROUP BY wtr.id,wtr.waste_trip_id,fr.zayavka_id,i.tn_absent,i.arrived_at,i.departed_at,
                i.driver_signature_present,i.sender_signature_present,i.tn_datetime_status,i.recovery_source");
        $issuesQuery->execute($ids);
        $issues = [];
        foreach ($issuesQuery->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if ((int)$item['tn_absent'] === 1) continue;
            $reasons = [];
            if ((int)$item['scan_count'] === 0) $reasons[] = 'нет скана ТН';
            if (!$item['arrived_at'] || !$item['departed_at']) $reasons[] = 'нет даты или времени';
            if (in_array($item['tn_datetime_status'], ['partial', 'no'], true) && !$item['recovery_source']) $reasons[] = 'не указан источник восстановления';
            if ((int)$item['driver_signature_present'] !== 1) $reasons[] = 'нет подписи водителя';
            if ((int)$item['sender_signature_present'] !== 1) $reasons[] = 'нет подписи отправителя';
            if ($reasons) {
                $issues[(int)$item['waste_trip_id']][] = 'Заявка №' . $item['zayavka_id'] . ': ' . implode(', ', $reasons);
            }
        }
        $result = [];
        foreach ($ids as $id) {
            $missing = [];
            foreach (self::SLOTS as $slot => $label) {
                if (!isset($present[$id][$slot])) $missing[] = $label;
            }
            $total = (int)($counts[$id]['request_count'] ?? 0);
            $processed = (int)($counts[$id]['processed_count'] ?? 0);
            $result[$id] = [
                'status' => $missing === [] && $total > 0 && $processed >= $total ? 'ready' : 'incomplete',
                'missing' => $missing,
                'processed' => $processed,
                'total' => $total,
                'tn_issues' => $issues[$id] ?? [],
            ];
        }
        return $result;
    }

    public static function trip(PDO $pdo, int $tripId): array
    {
        $scope = self::closingVisibilityScope();
        $stmt = $pdo->prepare("SELECT wt.id,wt.business_id,wt.status,wt.legal_entity_code,wt.actual_end_date,
                COALESCE(r.state,'work') review_state,COALESCE(r.version,0) review_version
              FROM waste_trips wt LEFT JOIN waste_trip_closing_reviews r ON r.waste_trip_id=wt.id
             WHERE wt.id=? AND wt.deleted_at IS NULL AND ".self::allowedStatusesSql()." AND {$scope['sql']} LIMIT 1");
        $stmt->execute(array_merge([$tripId], $scope['params']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Рейс недоступен.');
        $row['documents'] = self::documents($pdo, $tripId);
        $row['recipient_email'] = self::recipient((string)$row['legal_entity_code']);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public static function documents(PDO $pdo, int $tripId): array
    {
        $stmt = $pdo->prepare("SELECT c.id,c.waste_trip_id,c.document_id,c.slot_code,c.requirement_code,c.custom_title,
                d.original_name,d.relative_path,d.mime_type,d.file_size,d.created_at
              FROM waste_trip_closing_documents c
              JOIN documents d ON d.id=c.document_id AND d.deleted_at IS NULL
             WHERE c.waste_trip_id=? AND c.deleted_at IS NULL ORDER BY FIELD(c.slot_code,'contract','invoice','act','waybill','extra'),c.id");
        $stmt->execute([$tripId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function addFiles(PDO $pdo, int $companyId, int $tripId, string $slot, string $code,
        string $title, array $files, int $userId, string $role): void
    {
        self::trip($pdo, $tripId);
        if ($tripId > 4294967295) throw new DomainException('Номер рейса превышает диапазон хранилища документов.');
        if (!isset(self::CODES[$slot]) || !in_array($code, self::CODES[$slot], true)) throw new DomainException('Выберите вид документа.');
        $title = trim($title);
        if ($slot === 'extra' && ($title === '' || mb_strlen($title) > 255)) throw new DomainException('Укажите название документа до 255 символов.');
        if ($files === [] || count($files) > 10) throw new DomainException('Выберите от одного до десяти файлов.');
        foreach ($files as $file) self::validateFile($file);
        $relativeDir = 'companies/'.$companyId.'/documents/waste_trip/'.$tripId;
        $absoluteDir = storage_path($relativeDir);
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0700, true) && !is_dir($absoluteDir)) throw new DomainException('Хранилище файлов недоступно.');
        $storedPaths=[];
        $own = !$pdo->inTransaction();
        try {
            if ($own) $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT id FROM waste_trips wt WHERE id=? AND deleted_at IS NULL AND ".self::allowedStatusesSql()." FOR UPDATE");
            $lock->execute([$tripId]);
            if (!$lock->fetchColumn()) throw new DomainException('Рейс изменился. Обновите страницу.');
            self::assertEditable($pdo, $tripId);
            foreach ($files as $file) {
                $name = basename((string)$file['name']);
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $stored = bin2hex(random_bytes(16)).'.'.$extension;
                $absolute = $absoluteDir.DIRECTORY_SEPARATOR.$stored;
                if (!move_uploaded_file((string)$file['tmp_name'], $absolute)) throw new DomainException('Не удалось сохранить файл.');
                $storedPaths[]=$absolute;
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($absolute) ?: 'application/octet-stream';
                $insert = $pdo->prepare('INSERT INTO documents (entity_type,entity_id,document_type,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role,created_by_user_id,created_by_role) VALUES (?,?,?,?,?,?,?,? ,?,?,?,?,?)');
                $insert->execute(['waste_trip',$tripId,self::SLOTS[$slot]??$title,$name,$stored,$relativeDir.'/'.$stored,$mime,(int)$file['size'],'uploaded',$userId?:null,$role,$userId?:null,$role]);
                $documentId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO waste_trip_closing_documents (waste_trip_id,document_id,slot_code,requirement_code,custom_title) VALUES (?,?,?,?,?)')
                    ->execute([$tripId,$documentId,$slot,$code,$slot==='extra'?$title:null]);
            }
            $pdo->prepare("INSERT INTO waste_trip_closing_reviews (waste_trip_id,state,version) VALUES (?,'work',1) ON DUPLICATE KEY UPDATE state='work',version=version+1")
                ->execute([$tripId]);
            self::syncFinance($pdo, $tripId);
            if ($own) $pdo->commit();
        } catch (Throwable $error) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            foreach ($storedPaths as $path) @unlink($path);
            throw $error;
        }
    }

    private static function validateFile(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('Не удалось загрузить файл.');
        $size=(int)($file['size']??0);
        if ($size<=0 || $size>20*1024*1024) throw new DomainException('Размер каждого файла должен быть не больше 20 МБ.');
        $extension=strtolower(pathinfo(basename((string)($file['name']??'')),PATHINFO_EXTENSION));
        if (!in_array($extension,['pdf','jpg','jpeg','png','webp','tif','tiff','doc','docx','xls','xlsx'],true)) throw new DomainException('Недопустимый формат файла.');
        if (!is_uploaded_file((string)($file['tmp_name']??''))) throw new DomainException('Файл не загружен.');
    }

    public static function removeFile(PDO $pdo, int $tripId, int $closingDocumentId): void
    {
        self::trip($pdo,$tripId);
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $lock=$pdo->prepare("SELECT id FROM waste_trips wt WHERE id=? AND deleted_at IS NULL AND ".self::allowedStatusesSql()." FOR UPDATE");
            $lock->execute([$tripId]);
            if (!$lock->fetchColumn()) throw new DomainException('Рейс изменился. Обновите страницу.');
            self::assertEditable($pdo, $tripId);
            $stmt = $pdo->prepare('SELECT document_id FROM waste_trip_closing_documents WHERE id=? AND waste_trip_id=? AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([$closingDocumentId,$tripId]);
            $documentId = (int)$stmt->fetchColumn();
            if ($documentId <= 0) throw new DomainException('Документ не найден.');
            $pdo->prepare('UPDATE waste_trip_closing_documents SET deleted_at=NOW() WHERE id=?')->execute([$closingDocumentId]);
            $pdo->prepare('UPDATE documents SET deleted_at=NOW() WHERE id=?')->execute([$documentId]);
            $pdo->prepare("INSERT INTO waste_trip_closing_reviews (waste_trip_id,state,version) VALUES (?,'work',1) ON DUPLICATE KEY UPDATE state='work',version=version+1")
                ->execute([$tripId]);
            self::syncFinance($pdo,$tripId);
            if ($own) $pdo->commit();
        } catch (Throwable $error) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function beginReview(PDO $pdo, int $tripId, int $expectedVersion, string $recipient, string $messageId): int
    {
        self::trip($pdo,$tripId);
        $own=!$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT IGNORE INTO waste_trip_closing_reviews (waste_trip_id,state,version) VALUES (?,'work',0)")
                ->execute([$tripId]);
            $stmt=$pdo->prepare('SELECT state,version FROM waste_trip_closing_reviews WHERE waste_trip_id=? FOR UPDATE');
            $stmt->execute([$tripId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['version']!==$expectedVersion || in_array($row['state'],['sending','unknown'],true)) {
                throw new DomainException('Данные рейса изменились. Обновите страницу.');
            }
            $version=$expectedVersion+1;
            $pdo->prepare("UPDATE waste_trip_closing_reviews SET state='sending',version=?,recipient_email=?,message_id=?,mail_status='pending',archive_token=NULL WHERE waste_trip_id=?")
                ->execute([$version,$recipient,$messageId,$tripId]);
            if ($own) $pdo->commit();
            return $version;
        } catch (Throwable $error) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public static function finishReview(PDO $pdo, int $tripId, string $messageId, string $mailStatus, ?string $archiveToken): void
    {
        $state=$mailStatus==='accepted'?'review':($mailStatus==='unknown'?'unknown':'failed');
        $stmt=$pdo->prepare("UPDATE waste_trip_closing_reviews SET state=?,mail_status=?,archive_token=?,submitted_at=IF(?='review',NOW(),submitted_at)
             WHERE waste_trip_id=? AND state='sending' AND message_id=?");
        $stmt->execute([$state,$mailStatus,$archiveToken,$state,$tripId,$messageId]);
    }

    public static function confirmNotSent(PDO $pdo, int $tripId, int $expectedVersion): void
    {
        self::trip($pdo,$tripId);
        $stmt=$pdo->prepare("UPDATE waste_trip_closing_reviews SET state='failed',mail_status='verified_not_sent',version=version+1
            WHERE waste_trip_id=? AND state='unknown' AND version=?");
        $stmt->execute([$tripId,$expectedVersion]);
        if ($stmt->rowCount()!==1) throw new DomainException('Статус отправки изменился. Обновите страницу.');
    }

    /** Posted bank payments allocated to either side of a trip qualify, including partial payments. */
    public static function paidBankTransactions(PDO $pdo, int $tripId): array
    {
        $payments = $pdo->prepare("SELECT DISTINCT bt.id,bt.operation_date,bt.document_number
              FROM finance_operation_allocations a
              JOIN finance_obligations o ON o.id=a.obligation_id
              JOIN finance_operations fo ON fo.id=a.operation_id AND fo.status='POSTED'
              JOIN bank_transactions bt ON bt.id=fo.bank_transaction_id
             WHERE o.source_parent_type='WASTE_TRIP' AND o.source_parent_id=?
               AND o.party_role IN ('customer','carrier')
               AND o.cancelled_at IS NULL AND a.cancelled_at IS NULL AND a.amount>0
               AND ((o.party_role='customer' AND bt.credit_amount>0)
                 OR (o.party_role='carrier' AND bt.debit_amount>0))
               AND COALESCE(bt.is_internal_transfer,0)=0
               AND NOT EXISTS (SELECT 1 FROM finance_employee_movements fem WHERE fem.bank_transaction_id=bt.id)
             ORDER BY bt.operation_date,bt.id");
        $payments->execute([$tripId]);
        return $payments->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Idempotently mirrors trip files to the financial bank-transaction document register. */
    public static function syncFinance(PDO $pdo, int $tripId): void
    {
        if (!self::ready($pdo)) return;
        $documents = self::documents($pdo,$tripId);
        $payments = self::paidBankTransactions($pdo,$tripId);
        $wanted = [];
        foreach ($payments as $payment) foreach ($documents as $document) {
            $wanted[(int)$document['id'].':'.(int)$payment['id']] = [$document,(int)$payment['id']];
        }
        $old = $pdo->prepare('SELECT p.finance_link_id,p.closing_document_id,p.bank_transaction_id FROM waste_trip_closing_finance_links p JOIN waste_trip_closing_documents c ON c.id=p.closing_document_id WHERE c.waste_trip_id=?');
        $old->execute([$tripId]);
        foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key=(int)$row['closing_document_id'].':'.(int)$row['bank_transaction_id'];
            if (isset($wanted[$key])) {unset($wanted[$key]);continue;}
            $pdo->prepare('DELETE FROM waste_trip_closing_finance_links WHERE finance_link_id=?')->execute([(int)$row['finance_link_id']]);
            $pdo->prepare('DELETE FROM finance_closing_document_links WHERE id=?')->execute([(int)$row['finance_link_id']]);
        }
        $existingPair=$pdo->prepare('SELECT 1 FROM finance_closing_document_links WHERE bank_transaction_id=? AND document_id=? LIMIT 1');
        foreach ($wanted as [$document,$bankId]) {
            $existingPair->execute([$bankId,(int)$document['document_id']]);
            if ($existingPair->fetchColumn()) continue;
            $insert=$pdo->prepare('INSERT IGNORE INTO finance_closing_document_links (bank_transaction_id,document_id,requirement_code,is_edo) VALUES (?,?,?,0)');
            $insert->execute([$bankId,(int)$document['document_id'],(string)$document['requirement_code']]);
            if ($insert->rowCount() > 0) $pdo->prepare('INSERT INTO waste_trip_closing_finance_links (finance_link_id,closing_document_id,bank_transaction_id) VALUES (?,?,?)')
                ->execute([(int)$pdo->lastInsertId(),(int)$document['id'],$bankId]);
        }
    }

    public static function assertEditable(PDO $pdo, int $tripId): void
    {
        $stmt=$pdo->prepare('SELECT state FROM waste_trip_closing_reviews WHERE waste_trip_id=? FOR UPDATE');
        $stmt->execute([$tripId]);
        $state=$stmt->fetchColumn();
        if (in_array($state, ['sending','unknown'], true)) {
            throw new DomainException('Результат отправки письма нужно проверить перед изменением комплекта.');
        }
    }

    /** The closing register belongs to the responsible manager, even when another user created the trip. */
    private static function closingVisibilityScope(bool $forRegistry=false): array
    {
        if (\App\Security\AccessRuntime::current()->isActive()) {
            $scope=AccessControlService::effectiveDataScope('waste_trip');
            if ($scope==='all') return ['sql'=>'1 = 1','params'=>[]];
            // The dedicated closing page grants a manager access to assigned documents,
            // without granting access to the general waste-trip editor.
            if ($scope!=='own' && !(($forRegistry||self::isRegistryRoute())
                && AccessControlService::canAccessPage('company.documents.trip_closing_registry'))) {
                return ['sql'=>'1 = 0','params'=>[]];
            }
            $membershipId=AccessControlService::effectiveMembershipId();
            if ($membershipId===null||$membershipId<=0) return ['sql'=>'1 = 0','params'=>[]];
            return ['sql'=>'EXISTS (SELECT 1 FROM users responsible WHERE responsible.id=wt.responsible_user_id AND responsible.membership_id=?)','params'=>[$membershipId]];
        }
        $role=(string)($_SESSION['role_code']??'');
        if (in_array($role,['superadmin','company_owner','senior_logist'],true)) return ['sql'=>'1 = 1','params'=>[]];
        $userId=(int)($_SESSION['user_id']??0);
        return $role==='logist'&&$userId>0
            ? ['sql'=>'wt.responsible_user_id=?','params'=>[$userId]]
            : ['sql'=>'1 = 0','params'=>[]];
    }

    public static function isRegistryRoute(): bool
    {
        return function_exists('current_app_path')
            && str_starts_with(current_app_path(),'/company/logistics/closing-documents');
    }

    public static function allowedStatusesSql(): string
    {
        return self::isRegistryRoute()
            ? "wt.status IN ('in_progress','completed')"
            : "wt.status='completed'";
    }

    private static function moneyCents(string $amount): int
    {
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $amount)) throw new DomainException('Некорректная сумма оплаты.');
        $negative=str_starts_with($amount,'-');
        [$rubles,$kopecks]=array_pad(explode('.',ltrim($amount,'-'),2),2,'0');
        return ($negative?-1:1)*((int)$rubles*100+(int)str_pad($kopecks,2,'0'));
    }
}
