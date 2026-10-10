<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use PDO;
use Throwable;

/** Closing files belong to a completed waste trip; finance links only reference them. */
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

    /** @return list<array<string,mixed>> */
    public static function trips(PDO $pdo): array
    {
        $scope = RegulatedDataScopeService::condition('waste_trip', 'wt.id', 'wt.created_by_membership_id');
        $stmt = $pdo->prepare("SELECT wt.id,wt.business_id,wt.actual_end_date,wt.legal_entity_code,
                ct.id carrier_id,COALESCE(ct.name,'—') carrier_name,COALESCE(u.full_name,'—') manager_name,
                COALESCE(r.state,'work') review_state,COALESCE(r.version,0) review_version,
                r.mail_status,r.submitted_at
              FROM waste_trips wt
              LEFT JOIN crews c ON c.id=wt.route_executor_id
              LEFT JOIN contractors ct ON ct.id=c.contractor_id
              LEFT JOIN users u ON u.id=wt.responsible_user_id
              LEFT JOIN waste_trip_closing_reviews r ON r.waste_trip_id=wt.id
             WHERE wt.deleted_at IS NULL AND wt.status='completed' AND {$scope['sql']}
             ORDER BY wt.business_id DESC LIMIT 500");
        $stmt->execute($scope['params']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];
        $ids=array_map(static fn(array $row):int=>(int)$row['id'],$rows);
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $documentQuery=$pdo->prepare("SELECT c.id,c.waste_trip_id,c.document_id,c.slot_code,c.requirement_code,c.custom_title,
                d.original_name,d.relative_path,d.mime_type,d.file_size,d.created_at
              FROM waste_trip_closing_documents c JOIN documents d ON d.id=c.document_id AND d.deleted_at IS NULL
             WHERE c.waste_trip_id IN ($placeholders) AND c.deleted_at IS NULL
             ORDER BY FIELD(c.slot_code,'contract','invoice','act','waybill','extra'),c.id");
        $documentQuery->execute($ids);
        $byTrip=[];
        foreach ($documentQuery->fetchAll(PDO::FETCH_ASSOC) as $document) $byTrip[(int)$document['waste_trip_id']][]=$document;
        $completionQuery=$pdo->prepare("SELECT wtr.waste_trip_id,
                COUNT(DISTINCT wtr.id) request_count,
                COUNT(DISTINCT CASE WHEN COALESCE(i.tn_absent,0)=1 OR (
                    i.arrived_at IS NOT NULL AND i.departed_at IS NOT NULL
                    AND i.driver_signature_present=1 AND i.sender_signature_present=1
                    AND (i.tn_datetime_status='full' OR (i.tn_datetime_status IN ('partial','no') AND i.recovery_source IS NOT NULL) OR i.tn_datetime_status IS NULL)
                    AND doc.waste_trip_request_id IS NOT NULL
                ) THEN wtr.id END) processed_count
            FROM waste_trip_requests wtr
            LEFT JOIN waste_trip_completion_items i ON i.waste_trip_request_id=wtr.id
            LEFT JOIN waste_trip_completion_documents doc ON doc.waste_trip_request_id=wtr.id AND doc.deleted_at IS NULL
            WHERE wtr.removed_at IS NULL AND wtr.waste_trip_id IN ($placeholders)
            GROUP BY wtr.waste_trip_id");
        $completionQuery->execute($ids);
        $completion=[];
        foreach ($completionQuery->fetchAll(PDO::FETCH_ASSOC) as $progress) $completion[(int)$progress['waste_trip_id']]=$progress;
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
        $tnIssues=[];
        foreach($issueQuery->fetchAll(PDO::FETCH_ASSOC) as $item) {
            if((int)$item['tn_absent']===1) continue;
            $issues=[];
            if((int)$item['scan_count']===0) $issues[]='нет скана ТН';
            if(!$item['arrived_at']||!$item['departed_at']) $issues[]='нет даты или времени';
            if(in_array($item['tn_datetime_status'],['partial','no'],true)&&!$item['recovery_source']) $issues[]='не указан источник восстановления';
            if((int)$item['driver_signature_present']!==1) $issues[]='нет подписи водителя';
            if((int)$item['sender_signature_present']!==1) $issues[]='нет подписи отправителя';
            if($issues) $tnIssues[(int)$item['waste_trip_id']][]='Заявка №'.$item['zayavka_id'].': '.implode(', ',$issues);
        }
        $invoiceSummaries=WasteTripClosingInvoiceService::summaries($pdo,$ids);
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
            $row['documents'] = $byTrip[(int)$row['id']] ?? [];
            $row['recipient_email'] = self::RECIPIENTS[(string)$row['legal_entity_code']] ?? '';
            $row['paid_bank_transactions'] = $paid[(int)$row['id']] ?? [];
            $row['request_count'] = (int)($completion[(int)$row['id']]['request_count'] ?? 0);
            $row['processed_count'] = (int)($completion[(int)$row['id']]['processed_count'] ?? 0);
            $row['tn_issues']=$tnIssues[(int)$row['id']]??[];
            $row['invoice_summary']=$invoiceSummaries[(int)$row['id']]??['status'=>'missing','detail'=>'—','invoice_id'=>0];
        }
        unset($row);
        return $rows;
    }

    public static function trip(PDO $pdo, int $tripId): array
    {
        $scope = RegulatedDataScopeService::condition('waste_trip', 'wt.id', 'wt.created_by_membership_id');
        $stmt = $pdo->prepare("SELECT wt.id,wt.business_id,wt.legal_entity_code,wt.actual_end_date,
                COALESCE(r.state,'work') review_state,COALESCE(r.version,0) review_version
              FROM waste_trips wt LEFT JOIN waste_trip_closing_reviews r ON r.waste_trip_id=wt.id
             WHERE wt.id=? AND wt.deleted_at IS NULL AND wt.status='completed' AND {$scope['sql']} LIMIT 1");
        $stmt->execute(array_merge([$tripId], $scope['params']));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Завершённый рейс недоступен.');
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
            $lock = $pdo->prepare("SELECT id FROM waste_trips WHERE id=? AND deleted_at IS NULL AND status='completed' FOR UPDATE");
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
            $lock=$pdo->prepare("SELECT id FROM waste_trips WHERE id=? AND deleted_at IS NULL AND status='completed' FOR UPDATE");
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

    /** Only fully settled customer obligations with posted, allocated bank receipts qualify. */
    public static function paidBankTransactions(PDO $pdo, int $tripId): array
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) AS obligations,SUM(o.amount) AS due,SUM(o.paid_amount) AS paid
              FROM finance_obligations o WHERE o.source_parent_type='WASTE_TRIP' AND o.source_parent_id=?
                AND o.party_role='customer' AND o.cancelled_at IS NULL AND o.status<>'cancelled'");
        $stmt->execute([$tripId]);
        $amounts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($amounts['obligations']??0) === 0 || self::moneyCents((string)($amounts['due']??'0')) <= 0
            || self::moneyCents((string)($amounts['paid']??'0')) < self::moneyCents((string)$amounts['due'])) return [];
        $payments = $pdo->prepare("SELECT DISTINCT bt.id,bt.operation_date,bt.document_number
              FROM finance_operation_allocations a
              JOIN finance_obligations o ON o.id=a.obligation_id
              JOIN finance_operations fo ON fo.id=a.operation_id AND fo.status='POSTED'
              JOIN bank_transactions bt ON bt.id=fo.bank_transaction_id
             WHERE o.source_parent_type='WASTE_TRIP' AND o.source_parent_id=? AND o.party_role='customer'
               AND o.cancelled_at IS NULL AND a.cancelled_at IS NULL AND a.amount>0
               AND bt.credit_amount>0 AND COALESCE(bt.is_internal_transfer,0)=0
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
        foreach ($wanted as [$document,$bankId]) {
            $insert=$pdo->prepare('INSERT IGNORE INTO finance_closing_document_links (bank_transaction_id,document_id,requirement_code,is_edo) VALUES (?,?,?,0)');
            $insert->execute([$bankId,(int)$document['document_id'],(string)$document['requirement_code']]);
            if ($insert->rowCount() > 0) $pdo->prepare('INSERT INTO waste_trip_closing_finance_links (finance_link_id,closing_document_id,bank_transaction_id) VALUES (?,?,?)')
                ->execute([(int)$pdo->lastInsertId(),(int)$document['id'],$bankId]);
        }
    }

    private static function assertEditable(PDO $pdo, int $tripId): void
    {
        $stmt=$pdo->prepare('SELECT state FROM waste_trip_closing_reviews WHERE waste_trip_id=? FOR UPDATE');
        $stmt->execute([$tripId]);
        if (in_array($stmt->fetchColumn(), ['sending','unknown'], true)) {
            throw new DomainException('Результат отправки письма нужно проверить перед изменением комплекта.');
        }
    }

    private static function moneyCents(string $amount): int
    {
        if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/D', $amount)) throw new DomainException('Некорректная сумма оплаты.');
        $negative=str_starts_with($amount,'-');
        [$rubles,$kopecks]=array_pad(explode('.',ltrim($amount,'-'),2),2,'0');
        return ($negative?-1:1)*((int)$rubles*100+(int)str_pad($kopecks,2,'0'));
    }
}
