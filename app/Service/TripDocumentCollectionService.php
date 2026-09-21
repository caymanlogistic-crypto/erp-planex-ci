<?php
declare(strict_types=1);
namespace App\Service;
use PDO;
use App\Security\AccessRuntime;

require_once __DIR__.'/TripDocumentData.php';
require_once __DIR__.'/EmployeeMailProfileService.php';
require_once __DIR__.'/DocumentMailTransport.php';
require_once __DIR__.'/DocumentService.php';
require_once __DIR__.'/RouteExecutorService.php';
require_once __DIR__.'/TripDocumentPackage.php';
require_once __DIR__.'/MaxEventCatalog.php';
require_once __DIR__.'/MaxEventNotificationService.php';

final class TripDocumentCollectionService
{
    public readonly TripDocumentData $data;
    private readonly EmployeeMailProfileService $profiles;
    public function __construct(public readonly PDO $central,public readonly PDO $tenant,public readonly int $companyId)
    {
        $this->data=new TripDocumentData($central,$tenant,$companyId);
        $this->profiles=new EmployeeMailProfileService($central,$companyId);
    }

    public function ready(): bool
    {
        return (int)$this->tenant->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('trip_document_requests','trip_document_mail','trip_document_incoming','trip_document_events','trip_document_mailboxes','trip_document_previews')")->fetchColumn()===6;
    }

    public function assistant(): array { return $this->data->memberByLogin('enshina'); }

    public function request(int $id,int $actor,bool $assistantOnly=false,bool $lock=false): array
    {
        $this->data->member($actor);
        $q=$this->tenant->prepare('SELECT * FROM trip_document_requests WHERE id=? AND company_id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$id,$this->companyId]); $request=$q->fetch(PDO::FETCH_ASSOC);
        if (!$request) throw new \DomainException('Запрос не найден.');
        $isAssistant=$actor===(int)$request['assistant_membership_id'] && $actor===(int)$this->assistant()['membership_id'];
        $owner=AccessRuntime::current()->isActive() && AccessRuntime::current()->companyId()===$this->companyId && AccessRuntime::current()->membershipId()===$actor && AccessRuntime::current()->isSystemOwner();
        if ($assistantOnly?!$isAssistant:(!$isAssistant && !$owner && $actor!==(int)$request['responsible_membership_id'] && $actor!==(int)$request['initiator_membership_id'])) throw new \DomainException('Нет доступа к этому запросу.');
        return $request;
    }

    public function listing(int $actor): array
    {
        $this->data->member($actor);
        $all=$actor===(int)$this->assistant()['membership_id'] || (AccessRuntime::current()->companyId()===$this->companyId && AccessRuntime::current()->membershipId()===$actor && AccessRuntime::current()->isSystemOwner());
        $q=$this->tenant->prepare('SELECT * FROM trip_document_requests WHERE company_id=?'.($all?'':' AND (responsible_membership_id=? OR initiator_membership_id=?)')." ORDER BY next_action='complete',COALESCE(next_check_at,created_at),id DESC LIMIT 200");
        $q->execute($all?[$this->companyId]:[$this->companyId,$actor,$actor]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            try {$trip=$this->data->trip($row['trip_type'],(int)$row['trip_id']); $row['trip_number']=$trip['number'];$row['trip_label']=$trip['label'];$row['responsible_name']=$trip['responsible']['full_name'];}
            catch (\DomainException) {$row['trip_number']=$row['trip_id'];$row['trip_label']='#'.$row['trip_id'];$row['responsible_name']='Сотрудник недоступен';}
        }
        return $rows;
    }

    public function previewRequest(int $actor,array $input): array
    {
        $input['request_token']=bin2hex(random_bytes(16));
        $snapshot=$this->requestSnapshot($actor,$input);
        return $this->savePreview($actor,null,'request',$input,$snapshot);
    }

    public function previewReminder(int $id,int $actor,array $input): array
    {
        $input['request_id']=$id;
        $input['operation_token']=bin2hex(random_bytes(16));
        return $this->savePreview($actor,$id,'reminder',$input,$this->reminderSnapshot($actor,$input));
    }

    public function previewRetry(int $id,int $actor,int $mailId): array
    {
        $input=['request_id'=>$id,'retry_mail_id'=>$mailId];
        $snapshot=$this->retrySnapshot($actor,$input);
        return $this->savePreview($actor,$id,$snapshot['retry_kind'],$input,$snapshot);
    }

    public function completeness(array $request): array
    {
        $trip=$this->data->trip($request['trip_type'],(int)$request['trip_id']);
        return TripDocumentPackage::inspect($trip,$this->data->cards($trip),$this->companyId);
    }

    public function previewPackage(int $id,int $actor,string $stage): array
    {
        $input=['request_id'=>$id,'stage'=>$stage,'operation_token'=>bin2hex(random_bytes(16))];
        return $this->savePreview($actor,$id,$stage,$input,$this->packageSnapshot($actor,$input));
    }

    private function packageSnapshot(int $actor,array $input): array
    {
        $request=$this->request((int)$input['request_id'],$actor,true);$stage=$input['stage'];
        if(!in_array($stage,['minimum','full'],true) || $request['package_stage']==='full' || ($stage==='minimum' && $request['package_stage']!=='none'))throw new \DomainException('Этот этап пакета уже отправлен.');
        $state=$this->completeness($request);
        if($state[$stage.'_missing'])throw new \DomainException('Пакет ещё не готов. Заполните недостающее в карточках.');
        if($stage==='minimum' && !$state['full_missing'])throw new \DomainException('Уже готовы полные данные. Отправьте полный пакет одним письмом.');
        $snapshot=$this->currentEnvelope($request);$recipients=[$snapshot['responsible']];
        // OWNER: only L/PL use two legal-entity mailboxes; internal recipients use their account email.
        foreach(['spugov','gluhova','ashibanov'] as $login)$recipients[]=$this->data->packageRecipientByLogin($login);
        $previous=null;
        if($request['package_stage']==='minimum'){
            $q=$this->tenant->prepare("SELECT snapshot_json FROM trip_document_mail WHERE request_id=? AND kind='minimum' AND status='accepted'");$q->execute([$request['id']]);$json=$q->fetchColumn();
            if(!$json)throw new \DomainException('Не найден подтверждённо отправленный минимальный пакет.');
            $previous=json_decode($json,true,512,JSON_THROW_ON_ERROR)['package_state'];
        }
        $files=$state['files'];
        if($previous)$files=array_values(array_filter($files,static function(array $file)use($previous):bool{
            foreach($previous['files'] as $old)if($file['object']===$old['object'] && $file['code']===$old['code'] && $file['sha256']===$old['sha256'])return false;
            return true;
        }));
        if(count($files)>DocumentMailMime::MAX_PARTS-1 || array_sum(array_column($files,'size'))>DocumentMailMime::MAX_BYTES*0.7)throw new \DomainException('Вложения слишком велики для одного письма. Уменьшите размер файлов в карточках и проверьте пакет ещё раз.');
        $snapshot['recipient_profiles']=$recipients;$snapshot['package_state']=$state;$snapshot['package_input']=$input;$snapshot['request_version']=(int)$request['version'];
        $snapshot['message']=['from'=>$snapshot['sender']['email'],'to'=>array_values(array_unique(array_column($recipients,'email'))),'cc'=>[],
            'message_id'=>'<planex.'.$input['operation_token'].'@'.substr(strrchr($snapshot['sender']['email'],'@'),1).'>',
            'subject'=>'Данные Рейс #'.$snapshot['trip']['number'].($stage==='full'?' — полные данные':' — минимальный пакет'),
            'text'=>TripDocumentPackage::body($snapshot['trip'],$state,$stage,$previous),'attachments'=>$files];
        return $snapshot;
    }

    private function retrySnapshot(int $actor,array $input): array
    {
        $request=$this->request((int)$input['request_id'],$actor,true);
        $q=$this->tenant->prepare("SELECT * FROM trip_document_mail WHERE id=? AND request_id=? AND status IN ('failed','stale')");$q->execute([$input['retry_mail_id'],$request['id']]);$mail=$q->fetch(PDO::FETCH_ASSOC);
        if (!$mail) throw new \DomainException('Повтор доступен только для подтверждённо неотправленного письма.');
        $old=json_decode($mail['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        if(in_array($mail['kind'],['minimum','full'],true)){
            $snapshot=$this->packageSnapshot($actor,$old['package_input']);$snapshot['retry_kind']=$mail['kind'];return $snapshot;
        }
        $snapshot=$this->currentEnvelope($request);
        $snapshot['message']=$old['message'];$snapshot['request_version']=(int)$request['version'];$snapshot['retry_kind']=$mail['kind'];
        if (isset($old['next_check_at'])) $snapshot['next_check_at']=$old['next_check_at'];
        return $snapshot;
    }

    private function reminderSnapshot(int $actor,array $input): array
    {
        $request=$this->request((int)$input['request_id'],$actor,true);
        if ($request['package_stage']==='full') throw new \DomainException('Полный пакет уже отправлен.');
        $next=self::dateTime((string)($input['next_check_at']??''));
        if (strtotime($next)<=time()) throw new \DomainException('Укажите следующую проверку в будущем.');
        $q=$this->tenant->prepare("SELECT snapshot_json FROM trip_document_mail WHERE request_id=? AND kind='request' AND status='accepted' LIMIT 1");$q->execute([$request['id']]);$json=$q->fetchColumn();
        if (!$json) throw new \DomainException('Первоначальный запрос ещё не отправлен.');
        $initial=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        $snapshot=$this->currentEnvelope($request);
        $snapshot['request_version']=(int)$request['version'];$snapshot['next_check_at']=$next;
        $state=$this->completeness($request);$missing=[];
        foreach($state['full_missing'] as $row)$missing[]='— '.$row['group'].': '.$row['label'];
        $snapshot['message']=['from'=>$request['sender_email'],'to'=>[$request['contact_email']],'cc'=>[$request['responsible_email']],
            'message_id'=>'<planex.'.$input['operation_token'].'@'.substr(strrchr($request['sender_email'],'@'),1).'>',
            'in_reply_to'=>$initial['message']['message_id'],'subject'=>'Re: '.$initial['message']['subject'],
            'text'=>$request['contact_name'].", добрый день!\n\nПо рейсу №".$snapshot['trip']['number']." ещё нужны данные и документы:\n".implode("\n",$missing)."\n\nПожалуйста, пришлите недостающее как можно быстрее или сообщите, когда сможете предоставить.\n\nПожалуйста, отвечайте кнопкой «Ответить всем», чтобы ответ получили помощник и ответственный логист.",
            'attachments'=>[]];
        return $snapshot;
    }

    public function currentEnvelope(array $request): array
    {
        $legal=(int)$request['legal_entity_id'];$assistant=$this->data->member((int)$request['assistant_membership_id']);
        $responsibleMember=$this->data->member((int)$request['responsible_membership_id']);
        $sender=$this->profiles->resolve((int)$assistant['account_id'],$legal);
        $responsible=$this->profiles->resolveRecipient((int)$responsibleMember['account_id'],$legal);
        $tracked=$this->profiles->resolve((int)$responsibleMember['account_id'],$legal);
        $trip=$this->data->trip($request['trip_type'],(int)$request['trip_id']);
        if((int)$trip['responsible']['membership_id']!==(int)$request['responsible_membership_id'])throw new \DomainException('Ответственный рейса изменился. Переписка закреплена за прежним логистом; перед продолжением проверьте назначение в рейсе.');
        if ($sender['email']!==$request['sender_email'] || $responsible['email']!==$request['responsible_email'] || $sender['profile_id']!==(int)$request['sender_profile_id']) throw new \DomainException('Адреса закреплённой переписки изменились. Восстановите исходные рабочие адреса перед продолжением.');
        return ['trip'=>$trip,'assistant'=>$assistant,'sender'=>$sender,'responsible'=>$responsible,'tracked'=>$tracked,
            'profiles'=>[$this->profiles->publicProfile((int)$assistant['account_id'],$legal),$this->profiles->publicProfile((int)$responsibleMember['account_id'],$legal)],'settings'=>$this->profiles->settings()];
    }

    public function scheduleReview(int $id,int $actor,string $date): void
    {
        $this->tenant->beginTransaction();
        try {
            $request=$this->request($id,$actor,true,true);
            $complete=$request['package_stage']==='full';
            $next=$complete?null:self::dateTime($date);
            if (!$complete && strtotime($next)<=time()) throw new \DomainException('Следующая проверка должна быть в будущем.');
            $this->tenant->prepare('UPDATE trip_document_requests SET next_check_at=?,next_action=?,version=version+1 WHERE id=?')->execute([$next,$complete?'complete':'collect',$id]);
            $this->tenant->prepare('UPDATE trip_document_incoming SET reviewed_at=NOW() WHERE request_id=? AND reviewed_at IS NULL')->execute([$id]);
            $this->event($id,'review:'.$request['version'],'review',$complete?'Ответ после отправки полного пакета проверен.':'Ответ проверен. Следующая проверка: '.$next,$actor);
            $this->tenant->commit();
        } catch (\Throwable $e) {if($this->tenant->inTransaction())$this->tenant->rollBack();throw $e;}
    }

    public function executorChoices(int $id,int $actor): array
    {
        $this->request($id,$actor,true);
        if (!AccessRuntime::current()->canAccessPage('company.route_executors')) return [];
        $q=$this->tenant->query("SELECT c.id,ct.name contractor_name,d.full_name,vu.plate_number FROM crews c JOIN contractors ct ON ct.id=c.contractor_id JOIN driver_vehicle_blocks b ON b.id=c.driver_vehicle_block_id JOIN drivers d ON d.id=b.driver_id JOIN vehicle_sets s ON s.id=b.vehicle_set_id JOIN vehicle_units vu ON vu.id=s.primary_vehicle_unit_id WHERE c.deleted_at IS NULL AND c.status='active' AND ct.deleted_at IS NULL AND d.deleted_at IS NULL AND s.deleted_at IS NULL AND vu.deleted_at IS NULL ORDER BY ct.name,d.full_name,c.id");
        return RouteExecutorService::filterVisibleCrews($this->tenant,$q->fetchAll(PDO::FETCH_ASSOC));
    }

    public function saveTax(int $id,int $actor,array $input): void
    {
        $tax=trim((string)($input['tax_system']??''));$vat=trim((string)($input['vat_mode']??''));$date=(string)($input['effective_from']??'');
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if($tax==='' || $vat==='' || mb_strlen($tax)>100 || mb_strlen($vat)>100 || !$parsed || $parsed->format('Y-m-d')!==$date)throw new \DomainException('Заполните систему налогообложения, НДС и корректную дату начала действия.');
        $this->tenant->beginTransaction();
        try{
            $request=$this->request($id,$actor,true,true);
            if((int)$request['version']!==(int)($input['version']??0))throw new \DomainException('Запрос изменился. Обновите страницу.');
            $cards=$this->data->cards($this->data->trip($request['trip_type'],(int)$request['trip_id']));$contractor=(int)($cards['contractor']['id']??0);
            if(!$contractor || !AccessRuntime::current()->canAccessPage('company.contractors') || !RegulatedDataScopeService::canAccess($this->tenant,'contractor','contractors',$contractor))throw new \DomainException('Карточка подрядчика недоступна.');
            $user=$this->tenant->prepare('SELECT id FROM users WHERE membership_id=? AND deleted_at IS NULL');$user->execute([$actor]);
            $this->tenant->prepare("INSERT INTO contractor_tax_history(contractor_id,tax_system,vat_mode,effective_from,created_by_user_id,created_by_role) VALUES(?,?,?,?,?,'logist')")->execute([$contractor,$tax,$vat,$date,(int)$user->fetchColumn()?:null]);
            $this->tenant->prepare('UPDATE trip_document_requests SET version=version+1 WHERE id=?')->execute([$id]);
            $this->event($id,'tax:'.$request['version'],'card','Налогообложение сохранено в историю карточки подрядчика.',$actor);
            $this->tenant->commit();
        }catch(\Throwable $e){if($this->tenant->inTransaction())$this->tenant->rollBack();throw $e;}
    }

    public function assignExecutor(int $id,int $actor,int $crewId,int $version): void
    {
        $this->tenant->beginTransaction();
        try {
            $request=$this->request($id,$actor,true,true);
            if ($request['package_stage']==='full' || (int)$request['version']!==$version) throw new \DomainException('Запрос изменился. Обновите страницу.');
            if (!in_array($crewId,array_map('intval',array_column($this->executorChoices($id,$actor),'id')),true)) throw new \DomainException('Исполнитель рейса недоступен.');
            $table=TripDocumentData::tripTable($request['trip_type']);
            $q=$this->tenant->prepare('SELECT * FROM waste_trips WHERE id=? AND deleted_at IS NULL FOR UPDATE');$q->execute([$request['trip_id']]);$trip=$q->fetch(PDO::FETCH_ASSOC);
            if (!$trip || in_array($trip['status'],['completed','cancelled','archived'],true)) throw new \DomainException('В завершённом рейсе нельзя менять исполнителя.');
            $q=$this->tenant->prepare('SELECT contractor_id FROM crews WHERE id=?');$q->execute([$crewId]);$contractor=(int)$q->fetchColumn();
            $oldContractor=(int)($trip['carrier_contractor_id']??0);
            if (!$oldContractor && !empty($trip['route_executor_id'])) {$q->execute([$trip['route_executor_id']]);$oldContractor=(int)$q->fetchColumn();}
            if ($oldContractor && $contractor!==$oldContractor) throw new \DomainException('У исполнителя другой подрядчик. Изменение перевозчика должен проверить ответственный логист в рейсе.');
            $q=$this->tenant->prepare('SELECT id FROM users WHERE membership_id=? AND deleted_at IS NULL');$q->execute([$actor]);$user=(int)$q->fetchColumn();
            $q=$this->tenant->prepare("UPDATE waste_trips SET route_executor_id=?,updated_by_user_id=?,updated_by_role='logist',lock_version=lock_version+1 WHERE id=?");$q->execute([$crewId,$user?:null,$request['trip_id']]);
            $this->tenant->prepare('INSERT INTO waste_trip_audit_log(waste_trip_id,action,before_json,after_json,actor_user_id,actor_role) VALUES(?,?,?,?,?,?)')->execute([$request['trip_id'],'document_executor',self::json(['route_executor_id'=>$trip['route_executor_id']]),self::json(['route_executor_id'=>$crewId]),$user?:null,'logist']);
            $this->tenant->prepare('UPDATE trip_document_requests SET version=version+1 WHERE id=?')->execute([$id]);
            $this->event($id,'executor:'.$version,'executor','Исполнитель #'.$crewId.' связан с рейсом.',$actor);
            $this->tenant->commit();
        }catch(\Throwable $e){if($this->tenant->inTransaction())$this->tenant->rollBack();throw $e;}
    }

    public function bindIncoming(int $id,int $actor,int $incomingId): void
    {
        $this->tenant->beginTransaction();
        try {
            $this->request($id,$actor,true,true);
            $q=$this->tenant->prepare('SELECT * FROM trip_document_incoming WHERE id=? FOR UPDATE');$q->execute([$incomingId]);$mail=$q->fetch(PDO::FETCH_ASSOC);
            if (!$mail || ($mail['request_id']!==null && (int)$mail['request_id']!==$id) || !in_array($id,json_decode($mail['candidate_request_ids'],true),true)) throw new \DomainException('Письмо недоступно для этого запроса.');
            if ($mail['request_id']===null) {
                $this->tenant->prepare('UPDATE trip_document_incoming SET request_id=? WHERE id=?')->execute([$id,$incomingId]);
                $this->event($id,'bound:'.$incomingId,'bound','Письмо привязано к запросу помощником.',$actor);
                foreach(json_decode($mail['candidate_request_ids'],true) as $other){
                    if((int)$other===$id)continue;
                    $q=$this->tenant->prepare("SELECT COUNT(*) FROM trip_document_incoming WHERE reviewed_at IS NULL AND (request_id=? OR (request_id IS NULL AND JSON_CONTAINS(candidate_request_ids,?)))");$q->execute([$other,(string)$other]);
                    if(!(int)$q->fetchColumn())$this->tenant->prepare("UPDATE trip_document_requests SET next_action=IF(package_stage='full','complete','collect'),next_check_at=IF(package_stage='full',NULL,DATE_ADD(NOW(),INTERVAL 1 HOUR)),version=version+1 WHERE id=? AND next_action='review'")->execute([$other]);
                }
            }
            $this->tenant->commit();
        } catch (\Throwable $e) {if($this->tenant->inTransaction())$this->tenant->rollBack();throw $e;}
    }

    public function importAttachment(int $id,int $actor,int $incomingId,int $index,string $entityType,int $entityId,string $code): int
    {
        $this->tenant->beginTransaction();
        try {
            $request=$this->request($id,$actor,true,true);
            $q=$this->tenant->prepare('SELECT * FROM trip_document_incoming WHERE id=? AND request_id=? FOR UPDATE');$q->execute([$incomingId,$id]);$mail=$q->fetch(PDO::FETCH_ASSOC);
            if (!$mail) throw new \DomainException('Сначала привяжите письмо к этому запросу.');
            $files=json_decode($mail['attachments_json'],true,512,JSON_THROW_ON_ERROR);$file=$files[$index]??null;
            if (!$file || isset($file['error'])) throw new \DomainException('Вложение недоступно.');
            $cards=$this->data->cards($this->data->trip($request['trip_type'],(int)$request['trip_id']));
            $allowed=match($entityType) {'contractor'=>[$cards['contractor']['id']??0],'driver'=>array_column($cards['drivers'],'id'),'vehicle_unit'=>array_column($cards['vehicles'],'id'),default=>[]};
            if (!in_array($entityId,array_map('intval',$allowed),true)) throw new \DomainException('Выберите карточку, связанную с этим рейсом.');
            $page=['contractor'=>'company.contractors','driver'=>'company.drivers','vehicle_unit'=>'company.vehicle_sets'][$entityType];
            $table=['contractor'=>'contractors','driver'=>'drivers','vehicle_unit'=>'vehicle_units'][$entityType];
            if (!AccessRuntime::current()->canAccessPage($page) || !RegulatedDataScopeService::canAccess($this->tenant,$entityType,$table,$entityId)) throw new \DomainException('Нет доступа к карточке для сохранения документа.');
            $codes=['contractor'=>['company_card','inn_cert','ogrn_cert','contract'],'driver'=>['passport','driver_license','snils'],'vehicle_unit'=>['sts','photo','diagnostic_card']];
            if (!in_array($code,$codes[$entityType]??[],true)) throw new \DomainException('Выберите согласованный тип документа.');
            if (!empty($file['document_id'])) {
                if (($file['entity_type']??'')!==$entityType || (int)($file['entity_id']??0)!==$entityId || ($file['type_code']??'')!==$code) throw new \DomainException('Этот файл уже сохранён в другой карточке или с другим типом.');
                $this->tenant->commit();return (int)$file['document_id'];
            }
            $path=DocumentService::resolveStoredPath($this->companyId,$file['path']);
            if (!$path || !hash_equals($file['sha256'],hash_file('sha256',$path))) throw new \DomainException('Вложение изменилось или недоступно.');
            $q=$this->tenant->prepare('SELECT id,name FROM document_types WHERE code=? AND entity_type IN (?,?) ORDER BY entity_type=? DESC,id LIMIT 1');
            $q->execute([$code,$entityType,$entityType==='vehicle_unit'?'vehicle_set':$entityType,$entityType]);$type=$q->fetch(PDO::FETCH_ASSOC);
            if (!$type) throw new \DomainException('Тип документа отсутствует в справочнике.');
            $q=$this->tenant->prepare("INSERT INTO documents(entity_type,entity_id,document_type,document_type_id,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role) VALUES(?,?,?,?,?,?,?,?,?,'uploaded',?,'logist')");
            $user=$this->tenant->prepare('SELECT id FROM users WHERE membership_id=? AND deleted_at IS NULL');$user->execute([$actor]);$userId=(int)$user->fetchColumn();
            $q->execute([$entityType,$entityId,$type['name'],$type['id'],$file['name'],basename($file['path']),$file['path'],$file['mime'],$file['size'],$userId?:null]);
            $documentId=(int)$this->tenant->lastInsertId();
            $files[$index]+=['document_id'=>$documentId,'entity_type'=>$entityType,'entity_id'=>$entityId,'type_code'=>$code];
            $this->tenant->prepare('UPDATE trip_document_incoming SET attachments_json=? WHERE id=?')->execute([self::json($files),$incomingId]);
            $this->tenant->prepare('UPDATE trip_document_requests SET version=version+1 WHERE id=?')->execute([$id]);
            $this->event($id,'attachment:'.$incomingId.':'.$index,'document','Файл «'.$file['name'].'» сохранён: '.$type['name'].'.',$actor);
            $this->tenant->commit();return $documentId;
        } catch (\Throwable $e) {if($this->tenant->inTransaction())$this->tenant->rollBack();throw $e;}
    }

    public function incomingFile(int $id,int $actor,int $incomingId,int $index): array
    {
        $this->request($id,$actor);
        $q=$this->tenant->prepare('SELECT attachments_json FROM trip_document_incoming WHERE id=? AND request_id=?');$q->execute([$incomingId,$id]);$json=$q->fetchColumn();
        $file=$json?(json_decode($json,true)[$index]??null):null;
        $path=$file && !isset($file['error'])?DocumentService::resolveStoredPath($this->companyId,$file['path']):null;
        if (!$path || !hash_equals($file['sha256'],hash_file('sha256',$path))) throw new \DomainException('Вложение недоступно.');
        return ['path'=>$path,'name'=>$file['name']];
    }

    public function outgoingFile(int $id,int $actor,int $mailId,int $index): array
    {
        $this->request($id,$actor);
        $q=$this->tenant->prepare('SELECT snapshot_json FROM trip_document_mail WHERE id=? AND request_id=?');$q->execute([$mailId,$id]);$json=$q->fetchColumn();
        $file=$json?(json_decode($json,true)['message']['attachments'][$index]??null):null;
        $path=$file?DocumentService::resolveStoredPath($this->companyId,$file['path']):null;
        if(!$path || !hash_equals($file['sha256'],hash_file('sha256',$path)))throw new \DomainException('Сохранённое вложение недоступно.');
        return ['path'=>$path,'name'=>$file['name']];
    }

    private function requestSnapshot(int $actor,array $input): array
    {
        $this->data->member($actor);
        $type=(string)($input['trip_type']??''); $id=(int)($input['trip_id']??0);
        if (!RegulatedDataScopeService::canAccess($this->tenant,'waste_trip',TripDocumentData::tripTable($type),$id)) throw new \DomainException('Рейс недоступен.');
        $trip=$this->data->trip($type,$id);
        $assistant=$this->assistant();
        $legal=(int)($input['legal_entity_id']??0);
        $sender=$this->profiles->resolve((int)$assistant['account_id'],$legal);
        $responsible=$this->profiles->resolveRecipient((int)$trip['responsible']['account_id'],$legal);
        // Both tracked mailboxes must be configured before a request can be launched.
        $tracked=$this->profiles->resolve((int)$trip['responsible']['account_id'],$legal);
        $name=trim((string)($input['contact_name']??'')); $phone=trim((string)($input['contact_phone']??''));
        if ($name==='' || mb_strlen($name)>255 || $phone==='' || mb_strlen($phone)>64) throw new \DomainException('Укажите имя и телефон контакта перевозчика.');
        $email=DocumentMailMime::address(trim((string)($input['contact_email']??'')));
        $promised=!empty($input['has_promise'])?self::dateTime((string)($input['promised_at']??'')):null;
        $token=(string)$input['request_token'];
        if (!preg_match('/^[a-f0-9]{32}$/D',$token)) throw new \DomainException('Обновите предпросмотр письма.');
        return ['trip'=>$trip,'assistant'=>$assistant,'sender'=>$sender,'responsible'=>$responsible,'tracked'=>$tracked,
            'profiles'=>[$this->profiles->publicProfile((int)$assistant['account_id'],$legal),$this->profiles->publicProfile((int)$trip['responsible']['account_id'],$legal)],
            'settings'=>$this->profiles->settings(),'contact_name'=>$name,'contact_email'=>$email,'contact_phone'=>$phone,'promised_at'=>$promised,
            'message'=>['from'=>$sender['email'],'to'=>[$email],'cc'=>array_values(array_diff([$responsible['email']],[$email])),
                'message_id'=>'<planex.'.$token.'@'.substr(strrchr($sender['email'],'@'),1).'>',
                'subject'=>'Запрос документов · Рейс #'.$trip['number'].' [PX:'.$token.']',
                'text'=>self::requestText($trip['number'],$name),'attachments'=>[]]];
    }

    public static function requestText(string $number,string $name): string
    {
        return $name.", добрый день!\n\nВаша компания номинирована на перевозку по рейсу №".$number.". Просим как можно быстрее предоставить следующие данные и документы:\n\n".
            "Подрядчик: наименование и тип (юрлицо / ИП / самозанятый / физлицо), ИНН, КПП, ОГРН; ФИО и должность руководителя; юридический и фактический адреса; расчётный счёт, банк, БИК, корреспондентский счёт, ИНН банка; система налогообложения и НДС; контактные лица — имя, телефон, email, почта для документов.\n\n".
            "ТС: тип комплекта (одиночка / сцепка / автопоезд); марка, модель, госномер, VIN машины и прицепа/полуприцепа; грузоподъёмность и объём соответствующей части комплекта; СТС и фотографии каждой части.\n\n".
            "Водитель: ФИО, основной телефон, дополнительные телефоны при наличии, email; паспорт — серия, номер, код подразделения, кем и когда выдан; ВУ — номер и дата выдачи; СНИЛС. Приложите паспорт и водительское удостоверение. Неприменимые реквизиты подрядчика заполнять не нужно.\n\n".
            "Можно предоставить после рейса (ПР): карточку предприятия, свидетельства ИНН и ОГРН, договор; номер, дату и скан диагностической карты машины/прицепа; скан СНИЛС.\n\n".
            'Пожалуйста, отвечайте кнопкой «Ответить всем», чтобы ответ получили помощник и ответственный логист.';
    }

    private function savePreview(int $actor,?int $request,string $kind,array $input,array $snapshot): array
    {
        $token=bin2hex(random_bytes(24)); $hash=self::hash($snapshot);
        $q=$this->tenant->prepare('INSERT INTO trip_document_previews(token,membership_id,request_id,kind,input_json,snapshot_json,snapshot_hash,expires_at) VALUES(?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 20 MINUTE))');
        $q->execute([$token,$actor,$request,$kind,self::json($input),self::json($snapshot),$hash]);
        return ['token'=>$token,'snapshot'=>$snapshot];
    }

    public function sendPreview(int $actor,string $token): int
    {
        $this->tenant->beginTransaction();
        try {
            $q=$this->tenant->prepare('SELECT *,(expires_at>NOW()) valid FROM trip_document_previews WHERE token=? AND membership_id=? FOR UPDATE');
            $q->execute([$token,$actor]); $preview=$q->fetch(PDO::FETCH_ASSOC);
            if (!$preview) throw new \DomainException('Предпросмотр не найден.');
            if ($preview['consumed_mail_id']) {$this->tenant->commit();return (int)$preview['consumed_mail_id'];}
            if (!$preview['valid']) throw new \DomainException('Предпросмотр устарел. Проверьте письмо ещё раз.');
            $input=json_decode($preview['input_json'],true,512,JSON_THROW_ON_ERROR);
            if (isset($input['retry_mail_id'])) {
                $this->request((int)$preview['request_id'],$actor,true,true);
                $current=$this->retrySnapshot($actor,$input);
                if (!hash_equals($preview['snapshot_hash'],self::hash($current))) throw new \DomainException('Данные изменились. Проверьте письмо ещё раз.');
                if(isset($current['package_input']))$current['message']['attachments']=TripDocumentPackage::freeze($current['message']['attachments'],$this->companyId,(int)$preview['request_id'],$current['package_input']['operation_token']);
                $q=$this->tenant->prepare("UPDATE trip_document_mail SET status='pending',error_message=NULL,attempted_at=NULL,snapshot_json=?,snapshot_hash=?,actor_membership_id=? WHERE id=? AND status IN ('failed','stale')");
                $q->execute([self::json($current),self::hash($current),$actor,$input['retry_mail_id']]);
                if (!$q->rowCount()) throw new \DomainException('Отправка уже возобновлена.');
                $mail=(int)$input['retry_mail_id'];
                $this->tenant->prepare("UPDATE trip_document_requests SET next_action='send',version=version+1 WHERE id=?")->execute([$preview['request_id']]);
                $this->event((int)$preview['request_id'],'retry:'.$token,'retry','ПЛ проверил письмо и повторно поставил его на отправку.',$actor);
                $this->tenant->prepare('UPDATE trip_document_previews SET consumed_mail_id=? WHERE token=?')->execute([$mail,$token]);
                $this->tenant->commit();return $mail;
            }
            if(in_array($preview['kind'],['minimum','full'],true)){
                $request=$this->request((int)$preview['request_id'],$actor,true,true);
                $current=$this->packageSnapshot($actor,$input);
                if(!hash_equals($preview['snapshot_hash'],self::hash($current)))throw new \DomainException('Данные, документы или адресаты изменились. Проверьте пакет заново.');
                $q=$this->tenant->prepare("SELECT id FROM trip_document_mail WHERE request_id=? AND (status IN ('pending','sending','unknown') OR kind=?)");$q->execute([$request['id'],$preview['kind']]);
                if($q->fetchColumn())throw new \DomainException('У запроса уже есть отправка этого этапа или незавершённое письмо. Проверьте переписку.');
                $current['message']['attachments']=TripDocumentPackage::freeze($current['message']['attachments'],$this->companyId,(int)$request['id'],$input['operation_token']);
                $mail=$this->enqueueMail((int)$request['id'],'package:'.$preview['kind'],$preview['kind'],$actor,$current);
                $this->event((int)$request['id'],'queued:'.$mail,'queued',($preview['kind']==='full'?'Полные данные':'Минимальный пакет').' ожидают отправки.',$actor);
                $this->tenant->prepare("UPDATE trip_document_requests SET next_action='send',version=version+1 WHERE id=?")->execute([$request['id']]);
                $this->tenant->prepare('UPDATE trip_document_previews SET consumed_mail_id=? WHERE token=?')->execute([$mail,$token]);$this->tenant->commit();return $mail;
            }
            if ($preview['kind']==='reminder') {
                $this->request((int)$preview['request_id'],$actor,true,true);
                $current=$this->reminderSnapshot($actor,$input);
                if (!hash_equals($preview['snapshot_hash'],self::hash($current))) throw new \DomainException('Запрос или почтовые настройки изменились. Проверьте письмо ещё раз.');
                $q=$this->tenant->prepare("SELECT id FROM trip_document_mail WHERE request_id=? AND status IN ('pending','sending','unknown')");$q->execute([$preview['request_id']]);
                if ($q->fetchColumn()) throw new \DomainException('У запроса есть незавершённая отправка. Сначала проверьте её результат.');
                $mail=$this->enqueueMail((int)$preview['request_id'],'reminder:'.$input['operation_token'],'reminder',$actor,$current);
                $this->event((int)$preview['request_id'],'queued:'.$mail,'queued','Напоминание перевозчику ожидает отправки.',$actor);
                $this->tenant->prepare('UPDATE trip_document_previews SET consumed_mail_id=? WHERE token=?')->execute([$mail,$token]);
                $this->tenant->commit();return $mail;
            }
            if ($preview['kind']!=='request') throw new \DomainException('Неизвестный этап отправки.');
            $current=$this->requestSnapshot($actor,$input);
            if (!hash_equals($preview['snapshot_hash'],self::hash($current))) throw new \DomainException('Данные рейса или почты изменились. Проверьте новое письмо перед отправкой.');
            $sender=$current['sender']; $responsible=$current['responsible']; $assistant=$current['assistant'];
            $q=$this->tenant->prepare('SELECT id FROM trip_document_requests WHERE trip_type=? AND trip_id=?');
            $q->execute([$input['trip_type'],$input['trip_id']]);
            if ($q->fetchColumn()) throw new \DomainException('Для рейса уже есть запрос. Откройте его, чтобы продолжить переписку.');
            $q=$this->tenant->prepare('INSERT INTO trip_document_requests(company_id,request_token,trip_type,trip_id,legal_entity_id,initiator_membership_id,responsible_membership_id,assistant_membership_id,assistant_account_id,responsible_account_id,sender_profile_id,sender_email,responsible_email,contact_name,contact_email,contact_phone,promised_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$this->companyId,$input['request_token'],$input['trip_type'],$input['trip_id'],$sender['legal_entity_id'],$actor,$current['trip']['responsible']['membership_id'],$assistant['membership_id'],$assistant['account_id'],$responsible['account_id'],$sender['profile_id'],$sender['email'],$responsible['email'],$current['contact_name'],$current['contact_email'],$current['contact_phone'],$current['promised_at']]);
            $request=(int)$this->tenant->lastInsertId();
            $mail=$this->enqueueMail($request,'initial','request',$actor,$current);
            foreach ([$sender,$current['tracked']] as $profile) {
                $q=$this->tenant->prepare('INSERT IGNORE INTO trip_document_mailboxes(profile_id,account_id,legal_entity_id,email) VALUES(?,?,?,?)');
                $q->execute([$profile['profile_id'],$profile['account_id'],$profile['legal_entity_id'],$profile['email']]);
            }
            $this->event($request,'created','created','Запрос сохранён. Письмо ожидает отправки.',$actor);
            $this->tenant->prepare('UPDATE trip_document_previews SET consumed_mail_id=? WHERE token=?')->execute([$mail,$token]);
            $this->tenant->commit(); return $mail;
        } catch (\Throwable $e) {if ($this->tenant->inTransaction()) $this->tenant->rollBack();throw $e;}
    }

    private function enqueueMail(int $request,string $key,string $kind,int $actor,array $snapshot): int
    {
        $q=$this->tenant->prepare('INSERT INTO trip_document_mail(request_id,operation_key,kind,message_id,actor_membership_id,sender_profile_id,snapshot_json,snapshot_hash) VALUES(?,?,?,?,?,?,?,?)');
        $q->execute([$request,$key,$kind,$snapshot['message']['message_id'],$actor,$snapshot['sender']['profile_id'],self::json($snapshot),self::hash($snapshot)]);
        return (int)$this->tenant->lastInsertId();
    }

    public function event(int $requestId,string $key,string $action,string $description,?int $actor,?string $maxEvent=null,array $context=[]): void
    {
        if (!$this->tenant->inTransaction()) throw new \LogicException('History must share the business transaction.');
        $q=$this->tenant->prepare('INSERT IGNORE INTO trip_document_events(request_id,event_key,action,description,actor_membership_id,context_json) VALUES(?,?,?,?,?,?)');
        $q->execute([$requestId,$key,$action,$description,$actor,$context?self::json($context):null]);
        if (!$q->rowCount() || !$maxEvent) return;
        $eventId=(int)$this->tenant->lastInsertId();
        try {
            $q=$this->tenant->prepare('SELECT trip_type,trip_id FROM trip_document_requests WHERE id=?'); $q->execute([$requestId]);$request=$q->fetch(PDO::FETCH_ASSOC);
            $trip=$this->data->trip($request['trip_type'],(int)$request['trip_id']);
            $max=new MaxEventNotificationService($this->tenant);
            $delivery=$max->enqueue('documents.'.$maxEvent,(string)$eventId,['trip'=>$trip['number'],'loading_dates'=>$trip['loading_dates']?:'Не указаны','responsible'=>$trip['responsible']['full_name'],'action'=>$description,'url'=>self::publicRequestUrl($requestId)],null);
            if (!$delivery) throw new \DomainException('Выберите группу для события документов в настройках MAX.');
            $this->tenant->prepare('UPDATE trip_document_events SET max_delivery_id=? WHERE id=?')->execute([$delivery,$eventId]);
        } catch (\Throwable) {
            $this->tenant->prepare('UPDATE trip_document_events SET max_error=? WHERE id=?')->execute(['Уведомление MAX не создано. Проверьте назначение события в настройках MAX.',$eventId]);
        }
    }

    public static function publicRequestUrl(int $id): string
    {
        $base=rtrim(trim((string)env('APP_PUBLIC_URL','')),'/');
        if (!filter_var($base,FILTER_VALIDATE_URL) || !in_array(parse_url($base,PHP_URL_SCHEME),['http','https'],true)) throw new \DomainException('Не настроен публичный адрес ERP.');
        $path='/company/documents/collection/'.$id;
        $appBase=app_base_path();$configured=rtrim((string)parse_url($base,PHP_URL_PATH),'/');
        return $base.($appBase!=='' && !str_ends_with($configured,$appBase)?$appBase:'').$path;
    }

    public static function dateTime(string $value): string
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
        if (!$date || $date->format('Y-m-d\TH:i')!==$value) throw new \DomainException('Укажите корректные дату и время.');
        return $date->format('Y-m-d H:i:s');
    }
    public static function json(array $value): string {return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function hash(array $value): string {return hash('sha256',self::json($value));}
}
