<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class TripCompletionService
{
    public function __construct(
        public readonly PDO $tenant,
        private readonly int $companyId,
        private readonly string $editableReviewStatus = 'work',
    ) {}

    public function ready(): bool
    {
        try {
            foreach (['waste_trip_completion_items','waste_trip_completion_documents','waste_trip_registration_journal','waste_trip_completion_reviews'] as $table) {
                $stmt=$this->tenant->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
                $stmt->execute([$table]);
                if ((int)$stmt->fetchColumn()!==1) return false;
            }
            $stmt=$this->tenant->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='waste_trip_completion_items' AND column_name IN ('tn_datetime_missing','data_from_tracker','data_from_driver','data_invented')");
            $stmt->execute();
            if ((int)$stmt->fetchColumn()!==4) return false;
            $stmt=$this->tenant->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='waste_trip_completion_items' AND column_name IN ('sender_signature_status','driver_signature_status','tn_datetime_status','recovery_source')");
            $stmt->execute();
            if ((int)$stmt->fetchColumn()!==4) return false;
            $stmt=$this->tenant->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='waste_trip_completion_items' AND column_name='tn_absent'");
            $stmt->execute();
            if ((int)$stmt->fetchColumn()!==1) return false;
            return true;
        } catch (Throwable) { return false; }
    }

    /** @return list<array<string,mixed>> */
    public function trips(string $query='', bool $hideComplete=true): array
    {
        $where="wt.deleted_at IS NULL AND wt.status IN ('in_progress','completed')";
        $params=[];
        $query=trim($query);
        if ($query!=='') {
            $where.=" AND (CAST(wt.business_id AS CHAR) LIKE ? OR fr.zayavka_id LIKE ? OR wt.comment LIKE ? OR u.full_name LIKE ? OR d.full_name LIKE ?)";
            $like='%'.$query.'%'; $params=[$like,$like,$like,$like,$like];
        }
        $having=$hideComplete?'HAVING complete_count < request_count':'';
        $sql="SELECT wt.id,wt.business_id,wt.status,wt.actual_start_date,wt.actual_end_date,wt.comment,wt.updated_at,
                     COALESCE(u.full_name,'') manager_name,COALESCE(u.phone,'') manager_phone,COALESCE(u.email,'') manager_email,
                     COALESCE(d.full_name,'') driver_name,COALESCE(d.phone,'') driver_phone,
                     COALESCE(cr.review_status,'work') review_status,COALESCE(cr.status_version,0) review_version,
                     COUNT(DISTINCT wtr.id) request_count,
                     COUNT(DISTINCT CASE WHEN i.arrived_at IS NOT NULL AND i.departed_at IS NOT NULL
                         AND i.driver_signature_present=1 AND i.sender_signature_present=1
                         AND COALESCE(i.tn_absent,0)=0
                         AND (i.tn_datetime_status='full' OR (i.tn_datetime_status IN ('partial','no') AND i.recovery_source IS NOT NULL) OR i.tn_datetime_status IS NULL)
                         AND doc.waste_trip_request_id IS NOT NULL THEN wtr.id END) complete_count,
                     COUNT(DISTINCT doc.id) document_count,
                     COUNT(DISTINCT CASE WHEN doc.waste_trip_request_id IS NULL THEN wtr.id END) missing_scan_count,
                     COUNT(DISTINCT CASE WHEN i.arrived_at IS NULL OR i.departed_at IS NULL THEN wtr.id END) missing_time_count,
                     COUNT(DISTINCT CASE WHEN COALESCE(i.driver_signature_present,0)=0 THEN wtr.id END) missing_driver_signature_count,
                     COUNT(DISTINCT CASE WHEN COALESCE(i.sender_signature_present,0)=0 THEN wtr.id END) missing_sender_signature_count
                FROM waste_trips wt
                LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id AND wtr.removed_at IS NULL
                LEFT JOIN feo_requests fr ON fr.id=wtr.feo_request_id
                LEFT JOIN waste_trip_completion_items i ON i.waste_trip_request_id=wtr.id
                LEFT JOIN waste_trip_completion_documents doc ON doc.waste_trip_request_id=wtr.id AND doc.deleted_at IS NULL
                LEFT JOIN waste_trip_completion_reviews cr ON cr.waste_trip_id=wt.id
                LEFT JOIN users u ON u.id=wt.responsible_user_id
                LEFT JOIN crews c ON c.id=wt.route_executor_id
                LEFT JOIN drivers d ON d.id=c.driver_id
               WHERE {$where}
               GROUP BY wt.id,wt.business_id,wt.status,wt.actual_start_date,wt.actual_end_date,wt.comment,wt.updated_at,u.full_name,u.phone,u.email,d.full_name,d.phone,cr.review_status,cr.status_version
               {$having}
               ORDER BY wt.business_id DESC,wt.id DESC";
        $stmt=$this->tenant->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Lightweight request data for list summaries and client-side search; full forms load on demand. */
    public function tripListRequests(array $tripIds): array
    {
        if ($tripIds===[]) return [];
        $marks=implode(',',array_fill(0,count($tripIds),'?'));
        $stmt=$this->tenant->prepare("SELECT wtr.id,wtr.waste_trip_id,wtr.snapshot_mass_kg,fr.zayavka_id,
                fr.naim_oo_gruzootpravitel shipper_name,fr.mno_adres_pogruzki pickup_address,
                fr.gruzopoluchatel consignee_name,COALESCE(NULLIF(fr.adres_dostavki_otkhodov,''),fr.adres_vygruzki_otkhodov) delivery_address,
                i.arrived_at,i.departed_at,i.driver_signature_status,i.sender_signature_status,
                i.tn_datetime_status,i.recovery_source,COALESCE(i.tn_absent,0) tn_absent,
                COALESCE(i.driver_signature_present,0) driver_signature_present,
                COALESCE(i.sender_signature_present,0) sender_signature_present,
                COALESCE(i.tn_datetime_missing,0) tn_datetime_missing,
                COALESCE(i.data_from_tracker,0) data_from_tracker,
                COALESCE(i.data_from_driver,0) data_from_driver,
                COALESCE(i.data_invented,0) data_invented,COALESCE(i.comment,'') completion_comment
            FROM waste_trip_requests wtr
            JOIN feo_requests fr ON fr.id=wtr.feo_request_id
            LEFT JOIN waste_trip_completion_items i ON i.waste_trip_request_id=wtr.id
            WHERE wtr.waste_trip_id IN ($marks) AND wtr.removed_at IS NULL
            ORDER BY CAST(fr.zayavka_id AS UNSIGNED) DESC,fr.zayavka_id DESC,wtr.id DESC");
        $stmt->execute($tripIds);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $requestIds=array_map('intval',array_column($rows,'id'));
        $documents=[];
        if ($requestIds!==[]) {
            $docMarks=implode(',',array_fill(0,count($requestIds),'?'));
            $docs=$this->tenant->prepare("SELECT waste_trip_request_id,download_name FROM waste_trip_completion_documents WHERE deleted_at IS NULL AND waste_trip_request_id IN ($docMarks)");
            $docs->execute($requestIds);
            foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $doc) $documents[(int)$doc['waste_trip_request_id']][]=(string)$doc['download_name'];
        }
        $byTrip=[];
        foreach ($rows as $row) {
            $row['driver_signature_status']=$row['driver_signature_status']?:((int)$row['driver_signature_present']===1?'yes':null);
            $row['sender_signature_status']=$row['sender_signature_status']?:((int)$row['sender_signature_present']===1?'yes':null);
            $row['tn_datetime_status']=$row['tn_datetime_status']?:((int)$row['tn_datetime_missing']===1?'no':(!empty($row['arrived_at'])&&!empty($row['departed_at'])?'full':null));
            $row['recovery_source']=$row['recovery_source']==='invented'?'driver':($row['recovery_source']?:((int)$row['data_from_tracker']===1?'tracker':(((int)$row['data_from_driver']===1||(int)$row['data_invented']===1)?'driver':null)));
            $row['document_names']=$documents[(int)$row['id']]??[];
            $row['document_count']=count($row['document_names']);
            $byTrip[(int)$row['waste_trip_id']][]=$row;
        }
        return $byTrip;
    }

    /** @return array<string,mixed> */
    public function trip(int $tripId): array
    {
        $stmt=$this->tenant->prepare("SELECT wt.id,wt.business_id,wt.status,wt.actual_start_date,wt.actual_end_date,wt.comment,
                COALESCE(u.full_name,'') manager_name,COALESCE(u.phone,'') manager_phone,COALESCE(u.email,'') manager_email,
                COALESCE(d.full_name,'') driver_name,COALESCE(d.phone,'') driver_phone,
                COALESCE(cr.review_status,'work') review_status,COALESCE(cr.status_version,0) review_version
              FROM waste_trips wt
              LEFT JOIN users u ON u.id=wt.responsible_user_id
              LEFT JOIN crews c ON c.id=wt.route_executor_id
              LEFT JOIN drivers d ON d.id=c.driver_id
              LEFT JOIN waste_trip_completion_reviews cr ON cr.waste_trip_id=wt.id
             WHERE wt.id=? AND wt.deleted_at IS NULL AND wt.status IN ('in_progress','completed') LIMIT 1");
        $stmt->execute([$tripId]); $trip=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$trip) throw new DomainException('Рейс в процессе вывоза или со сданным грузом не найден.');

        $stmt=$this->tenant->prepare("SELECT wtr.id,wtr.position_no,wtr.snapshot_mass_kg,fr.zayavka_id,
                fr.naim_oo_gruzootpravitel shipper_name,fr.mno_adres_pogruzki pickup_address,
                fr.gruzopoluchatel consignee_name,COALESCE(NULLIF(fr.adres_dostavki_otkhodov,''),fr.adres_vygruzki_otkhodov) delivery_address,
                i.arrived_at,i.departed_at,COALESCE(i.driver_signature_present,0) driver_signature_present,
                COALESCE(i.sender_signature_present,0) sender_signature_present,
                i.driver_signature_status,i.sender_signature_status,i.tn_datetime_status,i.recovery_source,
                COALESCE(i.tn_absent,0) tn_absent,
                COALESCE(i.tn_datetime_missing,0) tn_datetime_missing,
                COALESCE(i.data_from_tracker,0) data_from_tracker,
                COALESCE(i.data_from_driver,0) data_from_driver,
                COALESCE(i.data_invented,0) data_invented,
                COALESCE(i.comment,'') completion_comment,
                COALESCE(i.lock_version,0) lock_version
              FROM waste_trip_requests wtr
              JOIN feo_requests fr ON fr.id=wtr.feo_request_id
              LEFT JOIN waste_trip_completion_items i ON i.waste_trip_request_id=wtr.id
             WHERE wtr.waste_trip_id=? AND wtr.removed_at IS NULL
             ORDER BY CAST(fr.zayavka_id AS UNSIGNED) DESC,fr.zayavka_id DESC,wtr.id DESC");
        $stmt->execute([$tripId]); $requests=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids=array_map('intval',array_column($requests,'id')); $documents=[];
        if ($ids!==[]) {
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $stmt=$this->tenant->prepare("SELECT * FROM waste_trip_completion_documents WHERE deleted_at IS NULL AND waste_trip_request_id IN ({$marks}) ORDER BY id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $doc) $documents[(int)$doc['waste_trip_request_id']][]=$doc;
        }
        foreach ($requests as &$request) {
            $request['driver_signature_status']=$request['driver_signature_status']?:((int)$request['driver_signature_present']===1?'yes':null);
            $request['sender_signature_status']=$request['sender_signature_status']?:((int)$request['sender_signature_present']===1?'yes':null);
            $request['tn_datetime_status']=$request['tn_datetime_status']?:((int)$request['tn_datetime_missing']===1?'no':(!empty($request['arrived_at'])&&!empty($request['departed_at'])?'full':null));
            $request['recovery_source']=$request['recovery_source']==='invented'?'driver':($request['recovery_source']?:((int)$request['data_from_tracker']===1?'tracker':(((int)$request['data_from_driver']===1||(int)$request['data_invented']===1)?'driver':null)));
            $request['documents']=$documents[(int)$request['id']]??[];
            $request['issues']=$this->issues($request);
            $request['is_complete']=$request['issues']===[];
        }
        unset($request);
        $trip['requests']=$requests;
        $trip['complete_count']=count(array_filter($requests,static fn(array $r):bool=>(bool)$r['is_complete']));
        $trip['request_count']=count($requests);
        return $trip;
    }

    /** @return list<string> */
    public function issues(array $request): array
    {
        $issues=[];
        if ((int)($request['tn_absent']??0)===1) $issues[]='Нет ТН';
        else if (empty($request['documents'])) $issues[]='Нет скана ТН';
        if (empty($request['arrived_at']) || empty($request['departed_at'])) $issues[]='Нет даты или времени';
        if (in_array($request['tn_datetime_status']??null,['partial','no'],true) && empty($request['recovery_source'])) $issues[]='Не указан источник восстановления';
        if ((int)($request['driver_signature_present']??0)!==1) $issues[]='Нет подписи водителя';
        if ((int)($request['sender_signature_present']??0)!==1) $issues[]='Нет подписи отправителя';
        return $issues;
    }

    /** @return list<array{label:string,ids:list<string>}> */
    public function reviewProblems(array $trip): array
    {
        $groups=[];
        foreach ($trip['requests'] as $request) {
            foreach ($request['issues'] as $issue) {
                $groups[$issue][]=(string)$request['zayavka_id'];
            }
        }
        $result=[];
        foreach ($groups as $label=>$ids) $result[]=['label'=>$label,'ids'=>array_values(array_unique($ids))];
        return $result;
    }

    /** @return array{status:string,version:int,problems:list<array{label:string,ids:list<string>}> >} */
    public function transitionReview(int $tripId,int $expectedVersion,string $action,int $actorMembershipId,string $actorLogin=''): array
    {
        if (!in_array($action,['submit','archive','restore','return_work'],true)) throw new DomainException('Неизвестное действие с рейсом.');
        if (($action==='submit')!==($this->editableReviewStatus==='work')) throw new DomainException('Это действие недоступно в вашем разделе.');
        $this->tenant->beginTransaction();
        try {
            $lock=$this->tenant->prepare("SELECT id FROM waste_trips WHERE id=? AND deleted_at IS NULL AND status IN ('in_progress','completed') FOR UPDATE");
            $lock->execute([$tripId]);
            if (!$lock->fetchColumn()) throw new DomainException('Рейс недоступен.');
            $this->tenant->prepare("INSERT IGNORE INTO waste_trip_completion_reviews (waste_trip_id) VALUES (?)")->execute([$tripId]);
            $current=$this->tenant->prepare('SELECT review_status,status_version FROM waste_trip_completion_reviews WHERE waste_trip_id=? FOR UPDATE');
            $current->execute([$tripId]);$row=$current->fetch(PDO::FETCH_ASSOC);
            if ((int)$row['status_version']!==$expectedVersion) throw new DomainException('Статус рейса изменился. Обновите страницу.');
            $expectedStatus=match($action){'submit'=>'work','archive','return_work'=>'review','restore'=>'archive'};
            if ($row['review_status']!==$expectedStatus) throw new DomainException('Статус рейса изменился. Обновите страницу.');
            $trip=$this->trip($tripId);
            $problems=$this->reviewProblems($trip);
            $next=match($action){'archive'=>'archive','return_work'=>'work',default=>'review'};
            $update=$this->tenant->prepare('UPDATE waste_trip_completion_reviews SET review_status=?,status_version=status_version+1,updated_by_membership_id=?,submitted_at=IF(?=\'submit\',NOW(6),submitted_at),mail_status=IF(?=\'submit\',\'pending\',mail_status),mail_error=IF(?=\'submit\',NULL,mail_error) WHERE waste_trip_id=?');
            $update->execute([$next,$actorMembershipId,$action,$action,$action,$tripId]);
            $this->writeJournal($tripId,null,null,'review',match($action){'archive'=>'Рейс проверен и перенесён в архив','restore'=>'Рейс возвращён из архива на проверку','return_work'=>'Рейс возвращён на доработку',default=>'Рейс передан на проверку'},$actorMembershipId,$actorLogin,
                ['review_status'=>$row['review_status']],['review_status'=>$next]);
            $this->tenant->commit();
            return ['status'=>$next,'version'=>$expectedVersion+1,'problems'=>$problems];
        } catch (Throwable $error) {
            if ($this->tenant->inTransaction()) $this->tenant->rollBack();
            throw $error;
        }
    }

    public function beginReviewMail(int $tripId,int $version,string $messageId): void
    {
        $stmt=$this->tenant->prepare("UPDATE waste_trip_completion_reviews SET mail_status='sending',mail_message_id=? WHERE waste_trip_id=? AND status_version=? AND mail_status='pending'");
        $stmt->execute([$messageId,$tripId,$version]);
        if ($stmt->rowCount()!==1) throw new DomainException('Отправка уже начата или статус рейса изменился.');
    }

    public function finishReviewMail(int $tripId,string $messageId,string $state): void
    {
        if (!in_array($state,['accepted','failed','unknown'],true)) throw new DomainException('Некорректный результат отправки.');
        $stmt=$this->tenant->prepare('UPDATE waste_trip_completion_reviews SET mail_status=?,mail_error=? WHERE waste_trip_id=? AND mail_message_id=?');
        $stmt->execute([$state,$state==='accepted'?null:($state==='unknown'?'Отправка не подтверждена. Проверьте почту перед повторной передачей.':'Не удалось отправить письмо.'),$tripId,$messageId]);
    }

    public function save(int $tripId,int $requestId,array $input,int $actorMembershipId,string $actorLogin=''): void
    {
        $this->assertRequest($tripId,$requestId);
        [$arrived,$departed]=$this->pickupInterval($input['pickup_interval']??null);
        $senderStatus=(string)($input['sender_signature_status']??(!empty($input['sender_signature_present'])?'yes':''));
        $driverStatus=(string)($input['driver_signature_status']??(!empty($input['driver_signature_present'])?'yes':''));
        $dateStatus=(string)($input['tn_datetime_status']??(!empty($input['tn_datetime_missing'])?'no':($arrived&&$departed?'full':'')));
        $recoverySource=(string)($input['recovery_source']??(!empty($input['data_from_tracker'])?'tracker':((!empty($input['data_from_driver'])||!empty($input['data_invented']))?'driver':'')));
        $tnAbsent=$input['tn_absent']??'0';
        if (!in_array($senderStatus,['','yes','no'],true) || !in_array($driverStatus,['','yes','no'],true)
            || !in_array($dateStatus,['','full','partial','no'],true) || !in_array($recoverySource,['','tracker','driver'],true)
            || !in_array((string)$tnAbsent,['0','1'],true)) {
            throw new DomainException('Некорректный статус заявки.');
        }
        if (!in_array($dateStatus,['partial','no'],true)) $recoverySource='';
        $comment=trim((string)($input['comment']??''));
        if (mb_strlen($comment)>500) throw new DomainException('Комментарий не должен превышать 500 символов.');
        $next=[
            'arrived_at'=>$arrived,'departed_at'=>$departed,
            'driver_signature_present'=>$driverStatus==='yes'?1:0,'driver_signature_status'=>$driverStatus?:null,
            'sender_signature_present'=>$senderStatus==='yes'?1:0,'sender_signature_status'=>$senderStatus?:null,
            'tn_datetime_missing'=>in_array($dateStatus,['partial','no'],true)?1:0,'tn_datetime_status'=>$dateStatus?:null,
            'tn_absent'=>(int)$tnAbsent,'data_from_tracker'=>$recoverySource==='tracker'?1:0,
            'data_from_driver'=>$recoverySource==='driver'?1:0,'data_invented'=>0,
            'recovery_source'=>$recoverySource?:null,'comment'=>$comment!==''?$comment:null,
        ];
        $this->tenant->beginTransaction();
        try {
        $this->lockEditableTrip($tripId,$requestId);
        $lock=$this->tenant->prepare('SELECT id FROM waste_trip_requests WHERE id=? AND removed_at IS NULL FOR UPDATE');
        $lock->execute([$requestId]);
        $oldStmt=$this->tenant->prepare('SELECT * FROM waste_trip_completion_items WHERE waste_trip_request_id=? FOR UPDATE');
        $oldStmt->execute([$requestId]);
        $old=$oldStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $before=[];$after=[];
        foreach ($next as $field=>$value) {
            $previous=$old[$field]??null;
            if (in_array($field,['driver_signature_present','sender_signature_present','tn_datetime_missing','tn_absent','data_from_tracker','data_from_driver','data_invented'],true)) $previous=(int)$previous;
            if ($previous!==$value) { $before[$field]=$previous;$after[$field]=$value; }
        }
        if ($after===[]) { $this->tenant->commit();return; }
        $stmt=$this->tenant->prepare("INSERT INTO waste_trip_completion_items
            (waste_trip_request_id,arrived_at,departed_at,driver_signature_present,driver_signature_status,sender_signature_present,sender_signature_status,tn_datetime_missing,tn_datetime_status,tn_absent,data_from_tracker,data_from_driver,data_invented,recovery_source,comment,updated_by_membership_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE arrived_at=VALUES(arrived_at),departed_at=VALUES(departed_at),
            driver_signature_present=VALUES(driver_signature_present),sender_signature_present=VALUES(sender_signature_present),
            driver_signature_status=VALUES(driver_signature_status),sender_signature_status=VALUES(sender_signature_status),tn_datetime_status=VALUES(tn_datetime_status),tn_absent=VALUES(tn_absent),recovery_source=VALUES(recovery_source),
            tn_datetime_missing=VALUES(tn_datetime_missing),data_from_tracker=VALUES(data_from_tracker),
            data_from_driver=VALUES(data_from_driver),data_invented=VALUES(data_invented),
            comment=VALUES(comment),updated_by_membership_id=VALUES(updated_by_membership_id),lock_version=lock_version+1");
        $stmt->execute([$requestId,$next['arrived_at'],$next['departed_at'],
            $next['driver_signature_present'],$next['driver_signature_status'],$next['sender_signature_present'],$next['sender_signature_status'],
            $next['tn_datetime_missing'],$next['tn_datetime_status'],$next['tn_absent'],$next['data_from_tracker'],
            $next['data_from_driver'],$next['data_invented'],$next['recovery_source'],$next['comment'],$actorMembershipId]);
        $keys=array_keys($after);
        $kind=count(array_diff($keys,['arrived_at','departed_at']))===0?'time':
            (count($keys)===1 && $keys[0]==='comment'?'comment':'marks');
        $this->writeJournal($tripId,$requestId,null,$kind,'Изменены данные заявки',$actorMembershipId,$actorLogin,$before,$after);
        $this->tenant->commit();
        } catch (Throwable $error) {
            if ($this->tenant->inTransaction()) $this->tenant->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $file */
    public function upload(int $tripId,int $requestId,array $file,int $actorMembershipId,bool $requireHttpUpload=true,string $actorLogin=''): int
    {
        $identity=$this->assertRequest($tripId,$requestId);
        $validation=DocumentService::validateUploadedFile($file,$requireHttpUpload);
        if ($validation!==null) throw new DomainException(match($validation){'file_too_large'=>'Файл больше 20 МБ.','invalid_extension','invalid_mime'=>'Разрешены PDF и изображения.',default=>'Не удалось принять файл.'});
        $ext=DocumentService::extractExtension((string)$file['name']);
        if (!in_array($ext,['pdf','jpg','jpeg','png','webp','tif','tiff'],true)) throw new DomainException('Разрешены PDF и изображения.');
        $absolute=null;
        $this->tenant->beginTransaction();
        try {
            $identity=$this->lockEditableTrip($tripId,$requestId);
            $lock=$this->tenant->prepare('SELECT id FROM waste_trip_requests WHERE id=? AND removed_at IS NULL FOR UPDATE');$lock->execute([$requestId]);
            $stmt=$this->tenant->prepare('SELECT COUNT(*) FROM waste_trip_completion_documents WHERE waste_trip_request_id=? AND deleted_at IS NULL');$stmt->execute([$requestId]);
            $activeCount=(int)$stmt->fetchColumn();
            if ($activeCount>=2) throw new DomainException('К заявке можно добавить не более двух сканов ТН.');
            $sequence=$activeCount+1;
            $tripNumber=$this->safeSegment((string)$identity['business_id']); $requestNumber=$this->safeSegment((string)$identity['zayavka_id']);
            $storedName="trip_{$tripNumber}_request_{$requestNumber}_tn_".str_pad((string)$sequence,2,'0',STR_PAD_LEFT).'_'.bin2hex(random_bytes(4)).'.'.$ext;
            $downloadName="Рейс_{$tripNumber}_Заявка_{$requestNumber}_ТН_".str_pad((string)$sequence,2,'0',STR_PAD_LEFT).'.'.$ext;
            $relativeDir="companies/{$this->companyId}/documents/waste_trip_completion/{$requestId}";
            $absoluteDir=storage_path($relativeDir);
            if (!is_dir($absoluteDir) && !mkdir($absoluteDir,0750,true) && !is_dir($absoluteDir)) throw new RuntimeException('Не удалось подготовить хранилище документов.');
            $absolute=$absoluteDir.DIRECTORY_SEPARATOR.$storedName;
            $moved=$requireHttpUpload?move_uploaded_file((string)$file['tmp_name'],$absolute):rename((string)$file['tmp_name'],$absolute);
            if (!$moved) throw new RuntimeException('Не удалось сохранить файл.');
            $mime=(string)((new \finfo(FILEINFO_MIME_TYPE))->file($absolute)?:'application/octet-stream');
            $stmt=$this->tenant->prepare('INSERT INTO waste_trip_completion_documents (waste_trip_request_id,original_name,download_name,stored_path,mime_type,size_bytes,uploaded_by_membership_id) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([$requestId,(string)$file['name'],$downloadName,$relativeDir.'/'.$storedName,$mime,(int)filesize($absolute),$actorMembershipId]);
            $id=(int)$this->tenant->lastInsertId();
            $this->writeJournal($tripId,$requestId,$id,'scan','Добавлен скан ТН',$actorMembershipId,$actorLogin,[],['file'=>(string)$file['name']]);
            $this->tenant->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->tenant->inTransaction()) $this->tenant->rollBack();
            if ($absolute!==null && is_file($absolute)) @unlink($absolute);
            throw $error;
        }
    }

    public function activeDocumentCount(int $tripId,int $requestId): int
    {
        $this->assertRequest($tripId,$requestId);
        $stmt=$this->tenant->prepare('SELECT COUNT(*) FROM waste_trip_completion_documents WHERE waste_trip_request_id=? AND deleted_at IS NULL');
        $stmt->execute([$requestId]);
        return (int)$stmt->fetchColumn();
    }

    public function softDelete(int $tripId,int $requestId,int $documentId,int $actorMembershipId,string $actorLogin=''): void
    {
        $this->assertRequest($tripId,$requestId);
        $this->tenant->beginTransaction();
        try {
            $this->lockEditableTrip($tripId,$requestId);
            $lock=$this->tenant->prepare('SELECT original_name FROM waste_trip_completion_documents WHERE id=? AND waste_trip_request_id=? AND deleted_at IS NULL FOR UPDATE');
            $lock->execute([$documentId,$requestId]);
            $name=$lock->fetchColumn();
            if ($name===false) throw new DomainException('Файл не найден.');
            $stmt=$this->tenant->prepare('UPDATE waste_trip_completion_documents SET deleted_at=NOW(),deleted_by_membership_id=? WHERE id=? AND waste_trip_request_id=? AND deleted_at IS NULL');
            $stmt->execute([$actorMembershipId,$documentId,$requestId]);
            if ($stmt->rowCount()!==1) throw new DomainException('Файл не найден.');
            $this->writeJournal($tripId,$requestId,$documentId,'scan','Удалён скан ТН',$actorMembershipId,$actorLogin,['file'=>(string)$name],[]);
            $this->tenant->commit();
        } catch (Throwable $error) {
            if ($this->tenant->inTransaction()) $this->tenant->rollBack();
            throw $error;
        }
    }

    /** @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int,users:list<string>} */
    public function journal(array $filters): array
    {
        $where=[];$params=[];
        foreach (['from'=>'>=','to'=>'<='] as $key=>$operator) {
            $date=trim((string)($filters[$key]??''));
            if ($date!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) {
                $where[]="DATE(j.occurred_at) {$operator} ?";$params[]=$date;
            }
        }
        $user=trim((string)($filters['user']??''));
        if ($user!=='') { $where[]='j.actor_login=?';$params[]=$user; }
        $kind=trim((string)($filters['kind']??''));
        if (in_array($kind,['initial','time','marks','scan','comment','review'],true)) { $where[]='j.event_kind=?';$params[]=$kind; }
        $query=trim((string)($filters['q']??''));
        if ($query!=='') {
            $where[]='(CAST(wt.business_id AS CHAR) LIKE ? OR fr.zayavka_id LIKE ?)';
            $like='%'.str_replace(['%','_'],['\\%','\\_'],$query).'%';
            $params[]=$like;$params[]=$like;
        }
        $condition=$where?' WHERE '.implode(' AND ',$where):'';
        $from=' FROM waste_trip_registration_journal j JOIN waste_trips wt ON wt.id=j.waste_trip_id LEFT JOIN waste_trip_requests wtr ON wtr.id=j.waste_trip_request_id LEFT JOIN feo_requests fr ON fr.id=wtr.feo_request_id';
        $count=$this->tenant->prepare('SELECT COUNT(*)'.$from.$condition);
        $count->execute($params);$total=(int)$count->fetchColumn();
        $pages=max(1,(int)ceil($total/50));$page=min($pages,max(1,(int)($filters['page']??1)));
        $sql='SELECT j.id,j.occurred_at,j.actor_login,j.event_kind,j.action_text,j.before_json,j.after_json,wt.business_id trip_number,fr.zayavka_id request_number'
            .$from.$condition.' ORDER BY j.occurred_at DESC,j.id DESC LIMIT 50 OFFSET '.(($page-1)*50);
        $stmt=$this->tenant->prepare($sql);$stmt->execute($params);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['before']=json_decode((string)($row['before_json']??'{}'),true)?:[];
            $row['after']=json_decode((string)($row['after_json']??'{}'),true)?:[];
            unset($row['before_json'],$row['after_json']);
        }
        unset($row);
        $users=$this->tenant->query('SELECT DISTINCT actor_login FROM waste_trip_registration_journal ORDER BY actor_login')->fetchAll(PDO::FETCH_COLUMN);
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pages'=>$pages,'users'=>$users];
    }

    private function writeJournal(int $tripId,?int $requestId,?int $documentId,string $kind,string $action,int $actorMembershipId,string $actorLogin,array $before,array $after): void
    {
        $stmt=$this->tenant->prepare('INSERT INTO waste_trip_registration_journal
            (waste_trip_id,waste_trip_request_id,document_id,event_kind,action_text,actor_membership_id,actor_login,before_json,after_json)
            VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$tripId,$requestId,$documentId,$kind,$action,$actorMembershipId,
            $actorLogin!==''?$actorLogin:'membership:'.$actorMembershipId,
            json_encode($before,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            json_encode($after,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }

    /** @return array<string,mixed> */
    public function document(int $tripId,int $documentId): array
    {
        $stmt=$this->tenant->prepare("SELECT doc.* FROM waste_trip_completion_documents doc JOIN waste_trip_requests wtr ON wtr.id=doc.waste_trip_request_id JOIN waste_trips wt ON wt.id=wtr.waste_trip_id WHERE doc.id=? AND wt.id=? AND doc.deleted_at IS NULL LIMIT 1");
        $stmt->execute([$documentId,$tripId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Файл не найден.');
        return $row;
    }

    public function absoluteDocumentPath(array $document): string
    {
        $path=realpath(storage_path((string)$document['stored_path']));
        $root=realpath(storage_path("companies/{$this->companyId}/documents/waste_trip_completion"));
        if ($path===false || $root===false || !str_starts_with($path,$root.DIRECTORY_SEPARATOR)) throw new DomainException('Файл недоступен.');
        return $path;
    }

    public function xlsx(array $trip): string
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('На сервере недоступно создание Excel.');
        $path=tempnam(sys_get_temp_dir(),'trip_report_');if($path===false)throw new RuntimeException('Не удалось подготовить Excel.');
        $zip=new ZipArchive();if($zip->open($path,ZipArchive::OVERWRITE)!==true){@unlink($path);throw new RuntimeException('Не удалось создать Excel.');}
        $headers=['Заявка','Грузоотправитель','Адрес забора','Грузополучатель','Адрес выгрузки','Вес, кг','Приезд на забор','Уезд с забора','Подпись водителя','Подпись отправителя','Даты и времени нет в ТН','Восстановлены по трекеру','Внесены со слов вод./мен','Файлов ТН','Статус','Проблемы','Комментарий'];
        $rows=[];
        foreach($trip['requests']as$request)$rows[]=[
            (string)$request['zayavka_id'],(string)$request['shipper_name'],(string)$request['pickup_address'],(string)$request['consignee_name'],(string)$request['delivery_address'],
            (float)$request['snapshot_mass_kg'],$this->excelSerial($request['arrived_at']??null),$this->excelSerial($request['departed_at']??null),
            (int)$request['driver_signature_present']===1?'Да':'Нет',(int)$request['sender_signature_present']===1?'Да':'Нет',
            (int)$request['tn_datetime_missing']===1?'Да':'Нет',(int)$request['data_from_tracker']===1?'Да':'Нет',
            ($request['recovery_source']==='driver'?'Да':'Нет'),count($request['documents']),
            $request['is_complete']?'Собрано':'Требует дополнения',implode(', ',$request['issues']),(string)$request['completion_comment'],
        ];
        $last=max(3,3+count($rows));$sheetRows=[];
        $sheetRows[]='<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="inlineStr"><is><t>'.$this->xml('Рейс №'.$trip['business_id'].' — комплектность документов').'</t></is></c></row>';
        $summary='Статус: '.(($trip['status']??'')==='completed'?'Груз сдан':'Идёт вывоз').' · Выезд: '.$this->reportDate((string)($trip['actual_start_date']??'')).' · Сдача: '.$this->reportDate((string)($trip['actual_end_date']??'')).' · Заявок: '.count($rows).' · Полностью собрано: '.(int)$trip['complete_count'];
        $sheetRows[]='<row r="2" ht="21" customHeight="1"><c r="A2" s="5" t="inlineStr"><is><t>'.$this->xml($summary).'</t></is></c></row>';
        $cells=[];foreach($headers as$i=>$value)$cells[]=$this->xlsxCell($i+1,3,$value,2);$sheetRows[]='<row r="3" ht="34" customHeight="1">'.implode('',$cells).'</row>';
        foreach($rows as$rowIndex=>$values){$excelRow=$rowIndex+4;$style=$values[14]==='Собрано'?4:3;$cells=[];foreach($values as$columnIndex=>$value){$cellStyle=$columnIndex===5||$columnIndex===13?6:(in_array($columnIndex,[6,7],true)?($style===4?8:7):$style);$cells[]=$this->xlsxCell($columnIndex+1,$excelRow,$value,$cellStyle);}$sheetRows[]='<row r="'.$excelRow.'" ht="42" customHeight="1">'.implode('',$cells).'</row>';}
        $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:Q'.$last.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols>';
        foreach([14,27,36,27,36,12,20,20,18,20,22,22,24,12,20,34,28]as$i=>$width)$sheet.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';
        $sheet.='</cols><sheetData>'.implode('',$sheetRows).'</sheetData><autoFilter ref="A3:Q'.$last.'"/><mergeCells count="2"><mergeCell ref="A1:Q1"/><mergeCell ref="A2:Q2"/></mergeCells><pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Рейс '.$this->xml((string)$trip['business_id']).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml',$this->xlsxStyles());$zip->addFromString('xl/worksheets/sheet1.xml',$sheet);$zip->close();return$path;
    }

    public function package(array $trip): string
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('На сервере недоступно создание ZIP.');
        $path=tempnam(sys_get_temp_dir(),'trip_completion_'); if ($path===false) throw new RuntimeException('Не удалось подготовить архив.');
        $zip=new ZipArchive(); if ($zip->open($path,ZipArchive::OVERWRITE)!==true) { @unlink($path); throw new RuntimeException('Не удалось создать архив.'); }
        $report=$this->xlsx($trip);
        $zip->addFile($report,'Рейс_'.$trip['business_id'].'_сводный_отчет.xlsx');
        foreach ($trip['requests'] as $request) foreach ($request['documents'] as $document) {
            try { $zip->addFile($this->absoluteDocumentPath($document),(string)$document['download_name']); } catch (Throwable) {}
        }
        $zip->close();@unlink($report);return $path;
    }

    /** @return array{business_id:int|string,zayavka_id:string} */
    private function lockEditableTrip(int $tripId,int $requestId): array
    {
        $lock=$this->tenant->prepare('SELECT id FROM waste_trips WHERE id=? FOR UPDATE');
        $lock->execute([$tripId]);
        return $this->assertRequest($tripId,$requestId);
    }

    /** @return array{business_id:int|string,zayavka_id:string} */
    private function assertRequest(int $tripId,int $requestId): array
    {
        $stmt=$this->tenant->prepare("SELECT wt.business_id,fr.zayavka_id,COALESCE(cr.review_status,'work') review_status FROM waste_trip_requests wtr JOIN waste_trips wt ON wt.id=wtr.waste_trip_id JOIN feo_requests fr ON fr.id=wtr.feo_request_id LEFT JOIN waste_trip_completion_reviews cr ON cr.waste_trip_id=wt.id WHERE wtr.id=? AND wtr.removed_at IS NULL AND wt.id=? AND wt.status IN ('in_progress','completed') AND wt.deleted_at IS NULL LIMIT 1");
        $stmt->execute([$requestId,$tripId]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Заявка рейса не найдена.');
        if ($row['review_status']!==$this->editableReviewStatus) throw new DomainException('Изменять заявки можно только в доступном для вашей роли состоянии рейса.');
        return $row;
    }

    /** @return array{0:?string,1:?string} */
    private function pickupInterval(mixed $value): array
    {
        $value=trim((string)$value);
        if ($value==='') return [null,null];
        if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{2}) (\d{2}):(\d{2}) - (\d{2}):(\d{2})$/D',$value,$parts)) {
            throw new DomainException('Укажите интервал в формате ДД.ММ.ГГ ЧЧ:ММ - ЧЧ:ММ.');
        }
        $date=$parts[1].'.'.$parts[2].'.'.$parts[3];
        $arrived=DateTimeImmutable::createFromFormat('!d.m.y H:i',$date.' '.$parts[4].':'.$parts[5]);
        $arrivedErrors=DateTimeImmutable::getLastErrors();
        $departed=DateTimeImmutable::createFromFormat('!d.m.y H:i',$date.' '.$parts[6].':'.$parts[7]);
        $departedErrors=DateTimeImmutable::getLastErrors();
        $invalid=static fn(mixed $dateTime,mixed $errors):bool=>!$dateTime
            || (is_array($errors) && (($errors['warning_count']??0)>0 || ($errors['error_count']??0)>0));
        if ($invalid($arrived,$arrivedErrors) || $invalid($departed,$departedErrors)) {
            throw new DomainException('Укажите корректные дату и время.');
        }
        if ($arrived>$departed) throw new DomainException('Время уезда не может быть раньше времени приезда.');
        return [$arrived->format('Y-m-d H:i:00'),$departed->format('Y-m-d H:i:00')];
    }

    private function safeSegment(string $value): string
    {
        $value=preg_replace('/[^0-9A-Za-zА-Яа-я_-]+/u','_',$value)??'';
        return trim($value,'_')?:'unknown';
    }

    private function xlsxCell(int $column,int $row,mixed $value,int $style): string
    {
        $ref=$this->xlsxColumn($column).$row;
        if(is_int($value)||is_float($value))return'<c r="'.$ref.'" s="'.$style.'"><v>'.(string)$value.'</v></c>';
        return'<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml((string)$value).'</t></is></c>';
    }

    private function xlsxColumn(int $number): string
    { $label='';while($number>0){$number--; $label=chr(65+($number%26)).$label;$number=intdiv($number,26);}return$label; }

    private function xml(string $value): string
    { return htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8'); }

    private function excelSerial(mixed $value): float|string
    {
        $text=trim((string)$value);if($text==='')return'';$date=new DateTimeImmutable($text);
        $day=$date->setTime(0,0);$origin=new DateTimeImmutable('1899-12-30 00:00:00',$date->getTimezone());
        $days=(int)$origin->diff($day)->format('%r%a');$seconds=((int)$date->format('H'))*3600+((int)$date->format('i'))*60+(int)$date->format('s');
        return$days+($seconds/86400);
    }

    private function reportDate(string $value): string
    { return trim($value)!==''?date('d.m.Y',strtotime($value)):'не указан'; }

    private function xlsxStyles(): string
    {
        return'<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd.mm.yy hh:mm"/></numFmts><fonts count="3"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="16"/><color rgb="FFFFFFFF"/><name val="Arial"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFFFF1F0"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFECFDF3"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD0D5DD"/></left><right style="thin"><color rgb="FFD0D5DD"/></right><top style="thin"><color rgb="FFD0D5DD"/></top><bottom style="thin"><color rgb="FFD0D5DD"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="9"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf><xf numFmtId="164" fontId="0" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf><xf numFmtId="164" fontId="0" fillId="4" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
