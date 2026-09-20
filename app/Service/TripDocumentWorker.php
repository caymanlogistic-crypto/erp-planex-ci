<?php
declare(strict_types=1);
namespace App\Service;
use PDO;

require_once __DIR__.'/TripDocumentCollectionService.php';
require_once __DIR__.'/MaxMessagingService.php';
require_once __DIR__.'/MaxApiClient.php';

final class TripDocumentWorker
{
    public function __construct(private readonly TripDocumentCollectionService $service,private readonly DocumentMailTransport $mail=new DocumentMailTransport(),private readonly ?MaxMessagingService $max=null) {}

    public function run(): array
    {
        $pdo=$this->service->tenant;
        $lock='planex_documents_'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,32);
        $q=$pdo->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$lock]);
        if ((int)$q->fetchColumn()!==1) return ['busy'=>true];
        $result=['checked'=>0,'sent'=>0,'failed'=>0];
        try {
            // Stale sending claims are uncertain; never automatically resend them.
            $stale=$pdo->query("SELECT id,request_id FROM trip_document_mail WHERE status='sending' AND attempted_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($stale as $row) $this->finish((int)$row['id'],(int)$row['request_id'],'unknown','Не удалось подтвердить результат отправки. Проверьте переписку перед повтором.');
            foreach ($pdo->query('SELECT * FROM trip_document_mailboxes ORDER BY profile_id')->fetchAll(PDO::FETCH_ASSOC) as $box) {
                try {$this->poll($box);$result['checked']++;}
                catch (\Throwable) {
                    $pdo->beginTransaction();
                    try {
                        $error='Не удалось проверить входящую почту. Проверьте подключение рабочего ящика.';
                        $pdo->prepare('UPDATE trip_document_mailboxes SET error_message=?,last_checked_at=NOW() WHERE profile_id=?')->execute([$error,$box['profile_id']]);
                        if (!$box['error_message']) {
                            $q=$pdo->prepare("SELECT id FROM trip_document_requests WHERE legal_entity_id=? AND ? IN (assistant_account_id,responsible_account_id) AND package_stage<>'full'");
                            $q->execute([$box['legal_entity_id'],$box['account_id']]);
                            $outage=bin2hex(random_bytes(8));
                            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) $this->service->event((int)$id,'mailbox:'.$box['profile_id'].':'.$outage,'mail_error',$error,null,'mail_error');
                        }
                        $pdo->commit();
                    } catch (\Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
                }
            }
            foreach ($pdo->query("SELECT id FROM trip_document_mail WHERE status='pending' ORDER BY id LIMIT 25")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $status=$this->dispatch((int)$id); $result[$status==='accepted'?'sent':'failed']++;
            }
            $due=$pdo->query("SELECT id,version FROM trip_document_requests WHERE next_action IN ('wait','collect') AND next_check_at<=NOW() AND package_stage<>'full' ORDER BY id LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($due as $row) {
                $pdo->beginTransaction();
                try {
                    $q=$pdo->prepare("UPDATE trip_document_requests SET next_action='remind',version=version+1 WHERE id=? AND version=? AND next_action IN ('wait','collect')");
                    $q->execute([$row['id'],$row['version']]);
                    if ($q->rowCount()) $this->service->event((int)$row['id'],'due:'.$row['version'],'reminder','Проверьте документы и свяжитесь с перевозчиком.',null,'reminder');
                    $pdo->commit();
                } catch (\Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
            }
            $notifications=new MaxEventNotificationService($pdo,$this->max);
            $deliveries=$pdo->query("SELECT DISTINCT e.max_delivery_id FROM trip_document_events e JOIN max_event_deliveries d ON d.id=e.max_delivery_id WHERE d.status='pending' ORDER BY e.max_delivery_id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($deliveries as $id) {try {$notifications->dispatch((int)$id);}catch (\Throwable) { /* Existing MAX journal owns delivery errors. */ }}
            return $result;
        } finally {$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
    }

    private function poll(array $box): void
    {
        $profiles=new EmployeeMailProfileService($this->service->central,$this->service->companyId);
        $profile=$profiles->resolve((int)$box['account_id'],(int)$box['legal_entity_id']);
        if ($profile['profile_id']!==(int)$box['profile_id'] || $profile['email']!==$box['email']) throw new \DomainException('Mailbox identity changed');
        $credentials=$profiles->credentials((int)$box['account_id'],(int)$box['legal_entity_id']);
        $cursor=$box['uidvalidity']!==null?['uidvalidity'=>(int)$box['uidvalidity'],'uid'=>(int)$box['last_uid']]:null;
        $next=$this->mail->poll($credentials,$cursor,function(array $headers,callable $fetch,int $uid,int $validity) use($box):void {
            $ids=$this->candidateRequests($headers,(int)$box['profile_id']);
            if ($ids===[]) return;
            try {$message=$fetch();}
            catch (\RuntimeException $e) {
                if (!str_starts_with($e->getMessage(),'MAIL_')) throw $e;
                $message=['message_id'=>$headers['message-id']??('<unreadable.'.$box['profile_id'].'.'.$validity.'.'.$uid.'@planex.invalid>'),
                    'from'=>mb_convert_encoding($headers['from']??'','UTF-8','UTF-8'),'subject'=>'Не удалось разобрать ответ перевозчика',
                    'text'=>'Письмо превышает допустимый размер или имеет повреждённый формат. Откройте его в рабочей почте и запросите документы повторно.','attachments'=>[]];
            }
            $this->ingest($ids,$message);
        });
        $this->service->tenant->prepare('UPDATE trip_document_mailboxes SET uidvalidity=?,last_uid=?,last_checked_at=NOW(),error_message=NULL WHERE profile_id=?')->execute([$next['uidvalidity'],$next['uid'],$box['profile_id']]);
    }

    public function candidateRequests(array $headers,int $profileId): array
    {
        $own=$this->service->tenant->prepare('SELECT id FROM trip_document_mail WHERE message_id=?');
        $own->execute([trim((string)($headers['message-id']??''))]);
        if ($own->fetchColumn()) return [];
        $refs=($headers['in-reply-to']??'').' '.($headers['references']??'');
        preg_match_all('/<[^<>\s]{3,240}>/',$refs,$matches);
        $ids=[];
        foreach (array_slice(array_unique($matches[0]),0,40) as $ref) {
            $q=$this->service->tenant->prepare("SELECT r.id FROM trip_document_mail m JOIN trip_document_requests r ON r.id=m.request_id JOIN trip_document_mailboxes b ON b.profile_id=? AND b.legal_entity_id=r.legal_entity_id AND b.account_id IN (r.assistant_account_id,r.responsible_account_id) WHERE m.message_id=? AND m.status IN ('accepted','sending','unknown') AND r.company_id=?");
            $q->execute([$profileId,$ref,$this->service->companyId]); array_push($ids,...$q->fetchAll(PDO::FETCH_COLUMN));
        }
        // A cryptographically random request token is stronger than a human trip number.
        if (preg_match_all('/\[PX:([a-f0-9]{32})\]/',DocumentMailMime::decodeHeader($headers['subject']??''),$tokens)) {
            foreach (array_slice(array_unique($tokens[1]),0,10) as $token) {
                $q=$this->service->tenant->prepare("SELECT r.id FROM trip_document_requests r JOIN trip_document_mailboxes b ON b.profile_id=? AND b.legal_entity_id=r.legal_entity_id AND b.account_id IN (r.assistant_account_id,r.responsible_account_id) WHERE r.request_token=? AND r.company_id=? AND EXISTS(SELECT 1 FROM trip_document_mail m WHERE m.request_id=r.id AND m.status IN ('accepted','sending','unknown'))");
                $q->execute([$profileId,$token,$this->service->companyId]); array_push($ids,...$q->fetchAll(PDO::FETCH_COLUMN));
            }
        }
        return array_values(array_unique(array_map('intval',$ids)));
    }

    public function ingest(array $candidates,array $message): void
    {
        if (!$candidates) return;
        $pdo=$this->service->tenant;
        $messageId=trim((string)$message['message_id']);
        $identity=hash('sha256',$messageId!==''?$messageId:TripDocumentCollectionService::json(['from'=>$message['from'],'subject'=>$message['subject'],'text'=>$message['text'],'files'=>array_column($message['attachments'],'sha256')]));
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT id FROM trip_document_incoming WHERE identity_hash=? FOR UPDATE');$q->execute([$identity]);
            if ($q->fetchColumn()) {$pdo->commit();return;}
            $request=count($candidates)===1?(int)$candidates[0]:null;
            $q=$pdo->prepare('INSERT INTO trip_document_incoming(request_id,candidate_request_ids,identity_hash,message_id,sender,subject,body_text,attachments_json) VALUES(?,?,?,?,?,?,?,?)');
            $q->execute([$request,TripDocumentCollectionService::json($candidates),$identity,mb_substr($messageId,0,254),mb_substr($message['from'],0,500),mb_substr($message['subject'],0,1000),mb_substr($message['text'],0,100000),'[]']);
            $id=(int)$pdo->lastInsertId();
            $files=[];
            foreach ($message['attachments'] as $file) $files[]=$this->storeAttachment($id,$file);
            $pdo->prepare('UPDATE trip_document_incoming SET attachments_json=? WHERE id=?')->execute([TripDocumentCollectionService::json($files),$id]);
            foreach ($candidates as $candidate) {
                $pdo->prepare("UPDATE trip_document_requests SET response_received_at=NOW(),next_action='review',next_check_at=NULL,version=version+1 WHERE id=?")->execute([$candidate]);
                $this->service->event((int)$candidate,'incoming:'.$id,'reply',$request?'Получен ответ. Проверьте письмо и документы.':'Получено письмо с неоднозначной связью. Привяжите его к запросу.',null,'reply');
            }
            $pdo->commit();
        } catch (\Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }

    private function storeAttachment(int $messageId,array $file): array
    {
        $name=DocumentMailMime::filename($file['name']); $bytes=$file['bytes'];
        $meta=['name'=>$name,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
        $tmp=tempnam(sys_get_temp_dir(),'planex_doc_');
        if ($tmp===false) throw new \RuntimeException('Attachment temporary file failed');
        try {
            if (file_put_contents($tmp,$bytes)!==strlen($bytes)) throw new \RuntimeException('Attachment write failed');
            $error=DocumentService::validateUploadedFile(['error'=>UPLOAD_ERR_OK,'tmp_name'=>$tmp,'name'=>$name,'size'=>strlen($bytes)],false);
            if ($error) return $meta+['error'=>'Формат или размер вложения не поддерживается. Запросите подходящий файл.'];
            $relative='companies/'.$this->service->companyId.'/documents/incoming/'.$messageId;
            $dir=storage_path($relative);
            if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new \RuntimeException('Attachment directory failed');
            $relative.='/'.DocumentService::makeSafeFilename($name);
            if (!copy($tmp,storage_path($relative))) throw new \RuntimeException('Attachment copy failed');
            return $meta+['path'=>$relative,'mime'=>(new \finfo(FILEINFO_MIME_TYPE))->file($tmp)];
        } finally {unlink($tmp);}
    }

    public function dispatch(int $id): string
    {
        $pdo=$this->service->tenant;
        $wireStarted=false;
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT * FROM trip_document_mail WHERE id=? FOR UPDATE');$q->execute([$id]);$mail=$q->fetch(PDO::FETCH_ASSOC);
            if (!$mail) throw new \DomainException('Письмо не найдено.');
            if ($mail['status']!=='pending') {$pdo->commit();return $mail['status'];}
            $snapshot=json_decode($mail['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
            $profiles=new EmployeeMailProfileService($this->service->central,$this->service->companyId);
            $requestId=(int)$mail['request_id'];
            $q=$pdo->prepare('SELECT * FROM trip_document_requests WHERE id=? AND company_id=?');$q->execute([$requestId,$this->service->companyId]);$request=$q->fetch(PDO::FETCH_ASSOC);
            if (!$request) throw new \DomainException('Запрос недоступен.');
            $this->service->data->member((int)$mail['actor_membership_id']);
            $actual=[];
            foreach ([$request['assistant_account_id'],$request['responsible_account_id']] as $account) $actual[]=$profiles->publicProfile((int)$account,(int)$request['legal_entity_id']);
            if ($actual!==$snapshot['profiles'] || $profiles->settings()!==$snapshot['settings']) {
                $pdo->commit();$this->finish($id,$requestId,'stale','Почтовые настройки изменились. Проверьте письмо заново.');return 'stale';
            }
            foreach($snapshot['recipient_profiles']??[] as $recipient){
                $actualRecipient=($recipient['source']??'')==='account'?$this->service->data->packageRecipientByLogin($recipient['login']):$profiles->resolveRecipient((int)$recipient['account_id'],(int)$request['legal_entity_id']);
                if($actualRecipient!==$recipient){
                    $pdo->commit();$this->finish($id,$requestId,'stale','Адресат изменился. Проверьте пакет заново.');return 'stale';
                }
            }
            $message=$snapshot['message'];
            if(in_array($mail['kind'],['minimum','full'],true))$message['attachments']=TripDocumentPackage::wireAttachments($message['attachments'],$this->service->companyId);
            $q=$pdo->prepare('SELECT COUNT(*) FROM trip_document_mailboxes WHERE profile_id IN (?,?) AND (uidvalidity IS NULL OR error_message IS NOT NULL)');
            $q->execute([$request['sender_profile_id'],$snapshot['tracked']['profile_id']]);
            if ($q->fetchColumn()>0) {$pdo->commit();$this->finish($id,$requestId,'failed','Не удалось включить проверку входящих ящиков. Проверьте подключение почты.');return 'failed';}
            $credentials=$profiles->credentials((int)$request['assistant_account_id'],(int)$request['legal_entity_id']);
            if ($credentials['email']!==$request['sender_email'] || (int)$credentials['version']!==(int)$snapshot['profiles'][0]['version']) throw new \DomainException('Отправитель изменился.');
            $pdo->prepare("UPDATE trip_document_mail SET status='sending',attempts=attempts+1,attempted_at=NOW() WHERE id=?")->execute([$id]);
            $this->service->event($requestId,'attempt:'.$id.':'.((int)$mail['attempts']+1),'sending','Начата попытка отправки письма.',(int)$mail['actor_membership_id'],null,['mail_id'=>$id,'snapshot'=>$snapshot]);
            $pdo->commit();
            $wireStarted=true;
            $status=$this->mail->send($credentials,$message);
            $this->finish($id,$requestId,$status,$status==='accepted'?null:($status==='unknown'?'Не удалось подтвердить отправку. Проверьте переписку перед повтором.':'Письмо не отправлено. Проверьте почтовое подключение.'));
            return $status;
        } catch (\Throwable) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (isset($requestId)) {
                $failure=$wireStarted?'unknown':'failed';
                $this->finish($id,$requestId,$failure,$wireStarted?'Результат отправки не подтверждён. Проверьте переписку перед повтором.':'Не удалось отправить письмо. Проверьте данные запроса и почту.');
                return $failure;
            }
            throw new \RuntimeException('Document mail dispatch failed');
        }
    }

    public function resolveUncertain(int $requestId,int $actor,int $mailId,string $outcome,string $note): void
    {
        $note=trim($note);
        if(!in_array($outcome,['accepted','failed'],true) || mb_strlen($note)<10 || mb_strlen($note)>500)throw new \DomainException('Укажите проверенный результат и основание проверки (10–500 символов).');
        $this->finish($mailId,$requestId,$outcome,$outcome==='failed'?'ПЛ подтвердил отсутствие отправки. Доступен повтор после предпросмотра.':null,$actor,$note);
    }

    private function finish(int $id,int $requestId,string $status,?string $error,?int $confirmedBy=null,?string $note=null): void
    {
        $pdo=$this->service->tenant;$pdo->beginTransaction();
        try {
            if($confirmedBy!==null)$this->service->request($requestId,$confirmedBy,true,true);
            $q=$pdo->prepare('SELECT kind,status,snapshot_json,attempts FROM trip_document_mail WHERE id=? AND request_id=? FOR UPDATE');$q->execute([$id,$requestId]);$mail=$q->fetch(PDO::FETCH_ASSOC);
            if($confirmedBy!==null){
                if(!$mail || $mail['status']!=='unknown')throw new \DomainException('Результат уже обработан. Обновите страницу.');
                $this->service->event($requestId,'resolution:'.$id.':'.$mail['attempts'],'resolution','ПЛ проверил результат отправки: '.($status==='accepted'?'отправлено':'не отправлено').'. '.$note,$confirmedBy,null,['mail_id'=>$id,'outcome'=>$status]);
            }
            if (!$mail || $mail['status']==='accepted') {$pdo->commit();return;}
            $pdo->prepare("UPDATE trip_document_mail SET status=?,error_message=?,accepted_at=IF(?='accepted',NOW(),NULL) WHERE id=?")->execute([$status,$error,$status,$id]);
            if ($status==='accepted') {
                if(in_array($mail['kind'],['minimum','full'],true)){
                    $full=$mail['kind']==='full';
                    $q=$pdo->prepare("UPDATE trip_document_requests SET package_stage=?,next_action=?,next_check_at=IF(?,NULL,COALESCE(next_check_at,DATE_ADD(NOW(),INTERVAL 1 HOUR))),version=version+1 WHERE id=?");
                    $q->execute([$mail['kind'],$full?'complete':'collect',$full?1:0,$requestId]);
                    $this->service->event($requestId,'accepted:'.$id,'package_sent',($full?'Полные данные':'Минимальный пакет').' приняты почтовым сервером для отправки получателям.',null,$mail['kind']);
                } elseif ($mail['kind']==='reminder') {
                    $snapshot=json_decode($mail['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                    $q=$pdo->prepare('SELECT COUNT(*) FROM trip_document_incoming WHERE request_id=? AND reviewed_at IS NULL');$q->execute([$requestId]);$unread=(int)$q->fetchColumn()>0;
                    $pdo->prepare('UPDATE trip_document_requests SET next_action=?,next_check_at=?,version=version+1 WHERE id=?')->execute([$unread?'review':'wait',$unread?null:$snapshot['next_check_at'],$requestId]);
                    $this->service->event($requestId,'accepted:'.$id,'sent','Напоминание перевозчику принято почтовым сервером.',null);
                } else {
                    $pdo->prepare("UPDATE trip_document_requests SET next_action=IF(response_received_at IS NULL,'wait','review'),next_check_at=IF(response_received_at IS NULL,COALESCE(promised_at,DATE_ADD(NOW(),INTERVAL 1 HOUR)),NULL),version=version+1 WHERE id=?")->execute([$requestId]);
                    $this->service->event($requestId,'accepted:'.$id,'sent','Письмо принято почтовым сервером. Ожидаем документы.',null,'requested');
                }
            } else {
                $pdo->prepare("UPDATE trip_document_requests SET next_action='mail_error',version=version+1 WHERE id=?")->execute([$requestId]);
                $this->service->event($requestId,$status.':'.$id.':'.$mail['attempts'],'mail_error',$error??'Ошибка отправки письма.',null,'mail_error');
            }
            $pdo->commit();
        } catch (\Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }
}
