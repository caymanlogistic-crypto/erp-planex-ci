<?php
declare(strict_types=1);

/** Task-scoped recovery. This file never boots application migrations or HTTP handlers. */
final class RegistryDocumentAttachmentRecovery
{
    private const AUTHORIZATION='RESTORE_37_RU_DOCUMENTS_20261020';
    private const DOCUMENT_KEYS=['id','entity_type','entity_id','document_type','document_type_id','original_name','stored_name','relative_path','mime_type','file_size','status','created_by_user_id','created_by_role','uploaded_by_user_id','uploaded_by_role','created_at','updated_at','deleted_at','delete_comment'];
    private const TYPE_KEYS=['id','name','code','entity_type','category','status','created_at','deleted_at'];
    private const TABLES=['driver'=>'drivers','vehicle_unit'=>'vehicle_units','contractor'=>'contractors'];
    private const ENTITY_KEYS=['full_name','name','inn','kpp','ogrn','phone','passport_number','license_number','snils','plate_number','vin','brand','model','unit_type','status','deleted_at','created_at','updated_at','created_by_user_id'];
    private readonly string $storage;
    private readonly array $counts;

    /** Options are for isolated library tests only; CLI always uses the fixed production counts. */
    public function __construct(private readonly PDO $pdo,string $storage,private readonly array $options=[])
    {
        $real=realpath($storage);
        self::need($real!==false && is_dir($real),'STORAGE_ROOT_INVALID');
        $this->storage=rtrim(str_replace('\\','/',$real),'/');
        $this->counts=array_replace(['files'=>37,'labels'=>74,'types'=>4,'documents'=>402,'catalog'=>33,'review'=>35,'owner'=>2],$options['counts']??[]);
        self::need(class_exists(\App\Service\TripDocumentPackage::class),'CANONICAL_METADATA_UNAVAILABLE');
        if (!defined('PLANEX_DOCUMENT_INVENTORY_LIBRARY_ONLY')) define('PLANEX_DOCUMENT_INVENTORY_LIBRARY_ONLY',true);
        require_once __DIR__.'/registry_document_inventory_readonly_probe.php';
    }

    public static function payloadDigest(array $payload): string { return hash('sha256',self::json(self::canonical($payload))); }

    /** Install the same sanitized I/O policy in CLI and isolated tests. */
    public static function installIoGuard(): void
    {
        set_error_handler(static function(int $severity): bool {
            // Inspector probes intentionally suppress transient /proc/self/fd stat
            // failures. Respect that mask while sanitizing reportable I/O errors.
            if (!(error_reporting() & $severity)) return false;
            throw new RuntimeException('RECOVERY_IO_ERROR');
        });
    }

    public function check(array $payload): array
    {
        self::need(!$this->pdo->inTransaction(),'TRANSACTION_ALREADY_OPEN');
        $this->pdo->beginTransaction();
        try { $inspection=$this->inspect($payload,false);$this->pdo->rollBack();return $this->receipt($inspection); }
        catch (Throwable $error) { if ($this->pdo->inTransaction()) $this->pdo->rollBack();throw $error; }
    }

    public function apply(array $payload,string $checkDigest): array
    {
        self::need((bool)preg_match('/^[a-f0-9]{64}$/D',$checkDigest),'CHECK_RECEIPT_REQUIRED');
        self::need(!$this->pdo->inTransaction(),'TRANSACTION_ALREADY_OPEN');
        $lock='attachment-recovery:25';
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lock]);
        self::need((int)$q->fetchColumn()===1,'RECOVERY_BUSY');
        $created=[];$directories=[];$commitAttempted=false;
        try {
            $this->pdo->beginTransaction();
            $before=$this->inspect($payload,true);
            self::need(hash_equals($before['check_digest'],$checkDigest),'CHECK_RECEIPT_DRIFT');
            // All baselines, source metadata, entities, reference files and content hashes
            // have passed before even creating a directory or opening a destination file.
            $labelCount=0;$restored=0;
            foreach ($before['label_pending'] as $change) {
                $q=$this->pdo->prepare('UPDATE documents SET document_type=?,document_type_id=?,updated_at=updated_at WHERE id=? AND document_type=? AND document_type_id=?');
                $q->execute([$change['new_type'],$change['new_document_type_id'],$change['id'],$change['old_type'],$change['document_type_id']]);
                self::need($q->rowCount()===1,'LABEL_UPDATE_RACE');$labelCount++;
            }
            foreach ($before['pending'] as $item) {
                $relativeDir='companies/25/documents/'.$item['entity_type'].'/'.$item['entity_id'];
                $directory=$this->directory($relativeDir,$directories);
                $storedName='recovery_'.$payload['task_id'].'_'.bin2hex(random_bytes(16)).'.'.strtolower(pathinfo($item['original_name'],PATHINFO_EXTENSION));
                $relative=$relativeDir.'/'.$storedName;$destination=$directory.'/'.$storedName;
                $handle=fopen($destination,'xb');self::need(is_resource($handle),'DESTINATION_CREATE_FAILED');
                $created[]=$destination;
                try {
                    if ($item['source_kind']==='owner') {
                        self::need(fwrite($handle,$item['_bytes'])===strlen($item['_bytes']),'DESTINATION_WRITE_FAILED');
                    } else {
                        $source=fopen($item['_absolute_source'],'rb');self::need(is_resource($source),'SOURCE_OPEN_FAILED');
                        try { self::need(stream_copy_to_stream($source,$handle)===(int)$item['size'],'DESTINATION_WRITE_FAILED'); }
                        finally { fclose($source); }
                    }
                    self::need(fflush($handle),'DESTINATION_FLUSH_FAILED');
                } finally { fclose($handle); }
                self::need(filesize($destination)===(int)$item['size'] && hash_file('sha256',$destination)===$item['sha256'],'SOURCE_CHANGED_DURING_COPY');
                $q=$this->pdo->prepare("INSERT INTO documents(entity_type,entity_id,document_type,document_type_id,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role,created_by_user_id,created_by_role,comments) VALUES(?,?,?,?,?,?,?,?,?,'uploaded',0,'system',0,'system',?)");
                $q->execute([$item['entity_type'],$item['entity_id'],$item['document_type'],$item['document_type_id'],$item['original_name'],$storedName,$relative,$item['mime'],$item['size'],$before['marker']]);
                $restored++;
            }
            $after=$this->inspect($payload,true);
            self::need(hash_equals($before['check_digest'],$after['check_digest']) && !$after['pending'] && !$after['label_pending'],'POSTCONDITION_FAILED');
            if (isset($this->options['before_commit'])) ($this->options['before_commit'])($this->pdo);
            $commitAttempted=true;
            $committed=isset($this->options['commit']) ? ($this->options['commit'])($this->pdo) : $this->pdo->commit();
            self::need($committed===true,'COMMIT_OUTCOME_UNKNOWN');
            $receipt=$this->receipt($after);$receipt['after_image_sha256']=$receipt['before_image_sha256'];
            $receipt['before_image']=$before['before_image'];
            $receipt['before_image_sha256']=hash('sha256',self::json(self::canonical($before['before_image'])));
            return $receipt+['restored'=>$restored,'labels_updated'=>$labelCount,'types_updated'=>0,'deduplicated'=>$this->counts['files']-$restored];
        } catch (Throwable $error) {
            if (!$commitAttempted) {
                $rolledBack=false;
                try { if ($this->pdo->inTransaction()) $rolledBack=$this->pdo->rollBack(); } catch (Throwable) {}
                // Unlink only our exclusive new files after a positively confirmed rollback.
                if ($rolledBack) {
                    foreach (array_reverse($created) as $file) if (is_file($file)) unlink($file);
                    foreach (array_reverse($directories) as $directory) if (is_dir($directory) && count(scandir($directory))===2) rmdir($directory);
                }
            } else {
                // A failed commit acknowledgement can mean COMMIT succeeded. Preserve files
                // for a fresh check; deleting them could destroy successfully inserted rows.
                throw new RuntimeException('COMMIT_OUTCOME_UNKNOWN_FILES_PRESERVED',0,$error);
            }
            throw $error;
        } finally {
            try { $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); } catch (Throwable) {}
        }
    }

    private function inspect(array $payload,bool $lock): array
    {
        $this->validatePayload($payload);
        $digest=self::payloadDigest($payload);$marker='PLANEX_ATTACHMENT_RECOVERY:'.$payload['task_id'].':'.$digest;
        $suffix=$lock?' FOR UPDATE':'';
        $documents=$this->pdo->query("SELECT * FROM documents WHERE entity_type IN ('contractor','driver','vehicle_unit') ORDER BY id".$suffix)->fetchAll(PDO::FETCH_ASSOC);
        $types=$this->pdo->query('SELECT * FROM document_types ORDER BY id'.$suffix)->fetchAll(PDO::FETCH_ASSOC);
        $beforeDocs=self::indexed($payload['documents_before']);$beforeTypes=self::indexed($payload['types_before']);
        $actualDocs=self::indexed($documents);$actualTypes=self::indexed($types);
        self::need(count($types)===$this->counts['catalog'],'TYPE_CATALOG_DRIFT');
        $labelChanges=self::indexed($payload['label_updates']);$typeMappings=self::indexed($payload['type_mappings']);
        $labelPending=[];$canonicalDocs=[];$canonicalTypes=[];$filesBefore=[];
        foreach ($beforeTypes as $id=>$before) {
            self::need(isset($actualTypes[$id]),'BASELINE_TYPE_MISSING');$actual=$actualTypes[$id];
            self::projection($actual,$before,'TYPE_BASELINE_DRIFT');$canonicalTypes[]=$actual;
        }
        foreach ($typeMappings as $id=>$mapping) {
            $old=$actualTypes[$id]??null;$canonicalType=$actualTypes[(int)$mapping['canonical_id']]??null;
            self::need(is_array($old) && is_array($canonicalType) && isset($beforeTypes[$id],$beforeTypes[(int)$mapping['canonical_id']]),'TYPE_MAPPING_TARGET_MISSING');
            self::need((string)$old['name']===$mapping['old_name'] && (string)$old['code']===$mapping['code'] && (string)$old['entity_type']===$mapping['entity_type'] && self::equivalentType($old,$canonicalType) && (string)$canonicalType['name']===$mapping['new_name'] && ($canonicalType['deleted_at']??null)===null,'TYPE_MAPPING_PROOF_INVALID');
        }
        foreach ($beforeDocs as $id=>$before) {
            self::need(isset($actualDocs[$id]),'BASELINE_DOCUMENT_MISSING');$actual=$actualDocs[$id];$expected=$before;
            if (isset($labelChanges[$id])) {
                $change=$labelChanges[$id];$mapping=$typeMappings[(int)$change['document_type_id']]??null;
                self::need(is_array($mapping) && (string)$before['document_type']===$change['old_type'] && (int)$before['document_type_id']===(int)$change['document_type_id'] && (int)$mapping['canonical_id']===(int)$change['new_document_type_id'] && $mapping['new_name']===$change['new_type'],'LABEL_CHANGE_PROOF_INVALID');
                $old=(string)$actual['document_type']===$change['old_type'] && (int)$actual['document_type_id']===(int)$change['document_type_id'];
                $new=(string)$actual['document_type']===$change['new_type'] && (int)$actual['document_type_id']===(int)$change['new_document_type_id'];
                self::need($old || $new,'DOCUMENT_LABEL_DRIFT');
                $expected['document_type']=$actual['document_type'];$expected['document_type_id']=$actual['document_type_id'];
                if ($old) $labelPending[]=$change;
            }
            self::projection($actual,$expected,'DOCUMENT_BASELINE_DRIFT');
            $filesBefore[$id]=$this->fingerprint((string)$actual['relative_path'],false);
            $actual['document_type']=$before['document_type'];$actual['document_type_id']=$before['document_type_id'];$canonicalDocs[]=$actual;
        }
        foreach ($labelChanges as $id=>$change) self::need(isset($beforeDocs[$id]),'LABEL_TARGET_NOT_IN_BASELINE');
        $entities=[];$entityRows=[];
        foreach ($payload['entities_before'] as $expected) {
            $kind=$expected['entity_type'];$id=(int)$expected['id'];$key=$kind.':'.$id;
            self::need(!isset($entities[$key]),'DUPLICATE_ENTITY_PROOF');
            $q=$this->pdo->prepare('SELECT * FROM '.self::TABLES[$kind].' WHERE id=?'.$suffix);$q->execute([$id]);$actual=$q->fetch(PDO::FETCH_ASSOC);
            self::need(is_array($actual),'TARGET_ENTITY_MISSING');self::projection($actual,$expected['values'],'TARGET_ENTITY_DRIFT');
            self::need(($actual['status']??'')==='active' && ($actual['deleted_at']??null)===null,'TARGET_ENTITY_NOT_ACTIVE');
            $entities[$key]=$actual;$entityRows[$key]=$actual;
        }
        $pending=[];$sources=[];$reviewRows=[];$matches=[];$restoreKeys=[];
        foreach ($payload['restores'] as $position=>$restore) {
            $key=$restore['entity_type'].':'.$restore['entity_id'];
            self::need(isset($entities[$key]),'TARGET_ENTITY_PROOF_REQUIRED');
            $type=$actualTypes[(int)$restore['document_type_id']]??null;
            self::need(is_array($type) && ($type['deleted_at']??null)===null && (string)$type['entity_type']===$restore['entity_type'] && $restore['document_type']===self::label((string)$type['code']),'RESTORE_TYPE_PROOF_INVALID');
            $reference=$actualDocs[(int)$restore['reference_document_id']]??null;
            self::need(is_array($reference) && isset($beforeDocs[(int)$restore['reference_document_id']]) && $reference['entity_type']===$restore['entity_type'] && (int)$reference['entity_id']===(int)$restore['entity_id'] && ($reference['deleted_at']??null)===null,'REFERENCE_TARGET_PROOF_INVALID');
            self::need(self::equivalentType($actualTypes[(int)$reference['document_type_id']]??null,$type),'REFERENCE_DOCUMENT_TYPE_MISMATCH');
            $referenceFile=$filesBefore[(int)$restore['reference_document_id']];
            self::need(($referenceFile['sha256']??'')===$restore['reference_sha256'],'REFERENCE_HASH_MISMATCH');
            if ($restore['source_kind']==='review') {
                $cardId=$restore['source_card_id'];
                if (!isset($reviewRows[$cardId])) {
                    $q=$this->pdo->prepare('SELECT * FROM route_executor_review_cards WHERE card_id=?'.$suffix);$q->execute([$cardId]);$card=$q->fetch(PDO::FETCH_ASSOC);
                    self::need(is_array($card),'SOURCE_CARD_MISSING');$reviewRows[$cardId]=$card;
                }
                $card=$reviewRows[$cardId];$retained=json_decode((string)$card['files_json'],true,512,JSON_THROW_ON_ERROR);
                $source=$retained[$restore['source_position']]??null;
                self::need(is_array($source) && (string)$source['path']===$restore['source_path'] && (string)$source['original_name']===$restore['original_name'] && (int)$source['size']===(int)$restore['size'] && (string)$source['mime']===$restore['mime'],'REVIEW_SOURCE_METADATA_MISMATCH');
                $prefix='companies/25/route-executor-review/'.$cardId.'/';
                self::need(str_starts_with($restore['source_path'],$prefix),'REVIEW_SOURCE_PATH_INVALID');
                $this->sourceIdentity($restore,$source,$card,$entities[$key],(string)$type['code']);
                $fingerprint=$this->fingerprint($restore['source_path'],true);
                self::need($fingerprint['sha256']===$restore['sha256'] && $fingerprint['size']===(int)$restore['size'] && $fingerprint['mime']===$restore['mime'],'REVIEW_SOURCE_CONTENT_MISMATCH');
                $restore['_absolute_source']=$this->existingPath($restore['source_path']);$sources[$position]=$fingerprint;
            } else {
                $bytes=base64_decode($restore['inline_base64'],true);
                self::need(is_string($bytes) && str_starts_with($bytes,"\x89PNG\r\n\x1a\n") && strlen($bytes)===(int)$restore['size'] && hash('sha256',$bytes)===$restore['sha256'] && (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)==='image/png','OWNER_SOURCE_CONTENT_MISMATCH');
                $restore['_bytes']=$bytes;$sources[$position]=['sha256'=>$restore['sha256'],'size'=>(int)$restore['size'],'mime'=>'image/png'];
            }
            $restoreKey=$key.':'.$restore['sha256'];self::need(!isset($restoreKeys[$restoreKey]),'DUPLICATE_RESTORE_CONTENT');$restoreKeys[$restoreKey]=true;
            $found=[];
            foreach ($actualDocs as $id=>$doc) {
                if ($doc['entity_type']!==$restore['entity_type'] || (int)$doc['entity_id']!==(int)$restore['entity_id'] || ($doc['deleted_at']??null)!==null) continue;
                $fingerprint=isset($filesBefore[$id])?$filesBefore[$id]:$this->fingerprint((string)$doc['relative_path'],true);
                if (($fingerprint['sha256']??'')===$restore['sha256']) {
                    self::need(self::equivalentType($actualTypes[(int)$doc['document_type_id']]??null,$type),'DEDUP_TYPE_CONFLICT');$found[]=$id;
                    if (!isset($beforeDocs[$id])) $this->assertOwnDocument($doc,$restore,$marker,$payload['task_id']);
                }
            }
            self::need(count($found)<=1,'AMBIGUOUS_EXISTING_CONTENT');
            if ($found) $matches[$found[0]]=$position;else $pending[]=$restore;
        }
        foreach ($actualDocs as $id=>$doc) self::need(isset($beforeDocs[$id]) || isset($matches[$id]),'UNEXPECTED_DOCUMENT_DRIFT');
        $beforeImage=['documents'=>array_values(array_intersect_key($actualDocs,$beforeDocs)),'document_types'=>$types,'entities'=>$entityRows,'review_cards'=>$reviewRows];
        $canonical=['payload_digest'=>$digest,'documents'=>$canonicalDocs,'document_types'=>$canonicalTypes,'entities'=>$entityRows,'old_files'=>$filesBefore,'source_files'=>$sources,'review_cards'=>$reviewRows];
        $restoreDocuments=[];
        foreach ($matches as $id=>$position) {
            $restore=$payload['restores'][$position];
            $restoreDocuments[]=['position'=>$position,'id'=>$id,'entity_type'=>$restore['entity_type'],'entity_id'=>$restore['entity_id'],'sha256'=>$restore['sha256'],'document_type'=>$restore['document_type'],'original_name'=>$restore['original_name']];
        }
        usort($restoreDocuments,static fn(array $a,array $b):int=>$a['position']<=>$b['position']);
        return ['payload_digest'=>$digest,'check_digest'=>hash('sha256',self::json(self::canonical($canonical))),'marker'=>$marker,'pending'=>$pending,'label_pending'=>$labelPending,'before_image'=>$beforeImage,'matched_document_ids'=>array_keys($matches),'restore_documents'=>$restoreDocuments];
    }

    private function validatePayload(array $payload): void
    {
        self::keys($payload,['task_id','company_id','authorization','expected_file_count','expected_label_count','documents_before','types_before','entities_before','label_updates','type_mappings','restores']);
        self::need((bool)preg_match('/^[a-z0-9][a-z0-9-]{4,100}$/D',(string)($payload['task_id']??'')) && ($payload['company_id']??null)===25 && ($payload['authorization']??'')===self::AUTHORIZATION,'RECOVERY_AUTHORIZATION_INVALID');
        self::need(($payload['expected_file_count']??null)===$this->counts['files'] && ($payload['expected_label_count']??null)===$this->counts['labels'],'RECOVERY_COUNTS_INVALID');
        foreach (['documents_before'=>'documents','types_before'=>'catalog','label_updates'=>'labels','type_mappings'=>'types','restores'=>'files'] as $name=>$count) self::need(is_array($payload[$name]??null) && array_is_list($payload[$name]) && count($payload[$name])===$this->counts[$count],'PAYLOAD_ARRAY_COUNT_INVALID');
        foreach ($payload['documents_before'] as $row) {
            self::keys($row,self::DOCUMENT_KEYS);self::need(isset($row['id'],$row['entity_type'],$row['entity_id'],$row['relative_path']) && array_key_exists('document_type_id',$row) && array_key_exists('document_type',$row),'DOCUMENT_PROJECTION_INCOMPLETE');
        }
        foreach ($payload['types_before'] as $row) { self::keys($row,self::TYPE_KEYS);self::need(isset($row['id'],$row['name']) && array_key_exists('code',$row) && array_key_exists('entity_type',$row),'TYPE_PROJECTION_INCOMPLETE'); }
        foreach ($payload['label_updates'] as $change) { self::keys($change,['id','old_type','new_type','document_type_id','new_document_type_id']);self::need(isset($change['id'],$change['old_type'],$change['new_type'],$change['document_type_id'],$change['new_document_type_id']) && $change['old_type']!==$change['new_type'] && (int)$change['document_type_id']!==(int)$change['new_document_type_id'],'LABEL_CHANGE_INVALID'); }
        foreach ($payload['type_mappings'] as $change) {
            self::keys($change,['id','old_name','canonical_id','new_name','code','entity_type']);
            self::need(isset($change['id'],$change['old_name'],$change['canonical_id'],$change['new_name'],$change['code'],$change['entity_type']) && $change['old_name']!==$change['new_name'] && (int)$change['id']!==(int)$change['canonical_id'] && $change['new_name']===self::label((string)$change['code']),'TYPE_MAPPING_INVALID');
        }
        self::need(is_array($payload['entities_before']??null) && array_is_list($payload['entities_before']) && count($payload['entities_before'])>0,'ENTITY_PROOFS_REQUIRED');
        foreach ($payload['entities_before'] as $entity) {
            self::keys($entity,['entity_type','id','values']);self::need(isset(self::TABLES[$entity['entity_type']??'']) && (int)($entity['id']??0)>0 && is_array($entity['values']??null),'ENTITY_PROOF_INVALID');self::keys($entity['values'],self::ENTITY_KEYS);
        }
        $kinds=['review'=>0,'owner'=>0];
        foreach ($payload['restores'] as $restore) {
            self::keys($restore,['entity_type','entity_id','document_type','document_type_id','original_name','mime','size','sha256','source_kind','source_card_id','source_position','source_path','inline_base64','reference_document_id','reference_sha256']);
            self::need(isset(self::TABLES[$restore['entity_type']??'']) && (int)($restore['entity_id']??0)>0 && (int)($restore['document_type_id']??0)>0 && (int)($restore['reference_document_id']??0)>0 && isset($restore['document_type']),'RESTORE_TARGET_INVALID');
            foreach (['sha256','reference_sha256'] as $field) self::need(is_string($restore[$field]??null) && (bool)preg_match('/^[a-f0-9]{64}$/D',$restore[$field]),'CONTENT_HASH_INVALID');
            $name=$restore['original_name']??'';
            self::need(is_string($name) && $name!=='' && strlen($name)<=500 && !preg_match('~[\\\\/\x00-\x1f]~',$name) && in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),['pdf','jpg','jpeg','png','webp','doc','docx','rtf','odt','xls','xlsx','csv','ods','gif','bmp','tif','tiff','heic','heif','txt'],true),'ORIGINAL_NAME_INVALID');
            self::need(is_int($restore['size']??null) && $restore['size']>0 && $restore['size']<=20*1024*1024 && is_string($restore['mime']??null),'RESTORE_SIZE_MIME_INVALID');
            $kind=$restore['source_kind']??'';self::need(isset($kinds[$kind]),'SOURCE_KIND_INVALID');$kinds[$kind]++;
            if ($kind==='review') self::need(is_string($restore['source_card_id']??null) && (bool)preg_match('/^[a-f0-9-]{36}$/D',$restore['source_card_id']) && is_int($restore['source_position']??null) && $restore['source_position']>=0 && is_string($restore['source_path']??null) && !isset($restore['inline_base64']),'REVIEW_SOURCE_INVALID');
            else self::need(($restore['source_card_id']??null)===null && ($restore['source_position']??null)===null && ($restore['source_path']??null)===null && is_string($restore['inline_base64']??null) && $restore['mime']==='image/png','OWNER_SOURCE_INVALID');
        }
        self::need($kinds['review']===$this->counts['review'] && $kinds['owner']===$this->counts['owner'],'SOURCE_COUNTS_INVALID');
    }

    private function sourceIdentity(array $restore,array $file,array $card,array $entity,string $code): void
    {
        $form=$restore['entity_type']==='vehicle_unit'?'vehicle':($restore['entity_type']==='driver'?'driver':'contractor');
        self::need(($file['formId']??'')==='lab-'.$form.'-form','SOURCE_FORM_MISMATCH');
        $field=(string)($file['name']??'');
        if ($form==='vehicle') {
            self::need((bool)preg_match('/^predef_doc\[(primary|secondary)\]\[([a-z_]+)\](?:\[\])?$/D',$field,$m) && $m[2]===$code,'SOURCE_DOCUMENT_TYPE_MISMATCH');
            $identityField='units['.$m[1].'][plate_number]';$targetIdentity=(string)$entity['plate_number'];
        } else {
            self::need($field==='predef_doc['.$code.'][]' || $field==='predef_doc['.$code.']','SOURCE_DOCUMENT_TYPE_MISMATCH');
            $identityField=$form==='driver'?'full_name':'name';$targetIdentity=(string)$entity[$identityField];
        }
        $snapshot=json_decode((string)$card['snapshot_json'],true,512,JSON_THROW_ON_ERROR);$identities=[];
        foreach ($snapshot['controls']??[] as $control) if (($control['formId']??'')==='lab-'.$form.'-form' && ($control['name']??'')===$identityField) $identities[]=(string)($control['value']??'');
        $normalize=static fn(string $value):string=>mb_strtolower((string)preg_replace('/\s+/u','',trim($value)));
        self::need(count($identities)===1 && $normalize($identities[0])===$normalize($targetIdentity),'SOURCE_TARGET_IDENTITY_MISMATCH');
    }

    private function assertOwnDocument(array $document,array $restore,string $marker,string $taskId): void
    {
        $expected=['entity_type'=>$restore['entity_type'],'entity_id'=>$restore['entity_id'],'document_type'=>$restore['document_type'],'document_type_id'=>$restore['document_type_id'],'original_name'=>$restore['original_name'],'mime_type'=>$restore['mime'],'file_size'=>$restore['size'],'status'=>'uploaded','uploaded_by_user_id'=>0,'uploaded_by_role'=>'system','created_by_user_id'=>0,'created_by_role'=>'system','comments'=>$marker,'deleted_at'=>null];
        self::projection($document,$expected,'OWN_DOCUMENT_DRIFT');
        self::need(str_starts_with((string)$document['stored_name'],'recovery_'.$taskId.'_') && $document['relative_path']==='companies/25/documents/'.$restore['entity_type'].'/'.$restore['entity_id'].'/'.$document['stored_name'],'OWN_DOCUMENT_PATH_INVALID');
        foreach (['document_name','document_number','document_date','document_expire','deleted_by_user_id','deleted_by_role','delete_comment','updated_by_user_id','updated_by_role'] as $field) if (array_key_exists($field,$document)) self::need($document[$field]===null,'OWN_DOCUMENT_METADATA_DRIFT');
    }

    private function fingerprint(string $relative,bool $required): array
    {
        $this->safePath($relative);
        $prefix=str_starts_with($relative,'companies/25/documents/')?'documents':(str_starts_with($relative,'companies/25/route-executor-review/')?'route-executor-review':'');
        self::need($prefix!=='','PROOF_FILE_PREFIX_INVALID');
        $inspection=RegistryDocumentInventoryReadOnly::inspect($this->storage,25,$relative,$prefix,microtime(true)+120);
        // Every approved old file must remain present as well; historical missing
        // files are not silently treated as successful invariance evidence.
        self::need(($inspection['status']??'')==='present_stable','PROOF_FILE_NOT_PRESENT_STABLE');
        $absolute=$this->existingPath($relative);
        return ['path'=>$relative,'sha256'=>$inspection['sha256'],'size'=>$inspection['size'],'mime'=>(new finfo(FILEINFO_MIME_TYPE))->file($absolute)];
    }

    private function safePath(string $relative): string
    {
        self::need($relative!=='' && !str_contains($relative,'\\') && !str_contains($relative,"\0") && !str_starts_with($relative,'/') && !preg_match('~(?:^|/)\.\.?(?:/|$)|:~',$relative),'STORAGE_PATH_INVALID');
        self::need(str_starts_with($relative,'companies/25/'),'COMPANY_STORAGE_BOUNDARY');
        return $this->storage.'/'.$relative;
    }

    private function existingPath(string $relative): string
    {
        $path=realpath($this->safePath($relative));
        self::need($path!==false && is_file($path) && str_starts_with(str_replace('\\','/',$path),$this->storage.'/companies/25/'),'STORAGE_REALPATH_BOUNDARY');
        return str_replace('\\','/',$path);
    }

    private function directory(string $relative,array &$created): string
    {
        $this->safePath($relative);$path=$this->storage;
        foreach (explode('/',$relative) as $segment) {
            $path.='/'.$segment;self::need(!is_link($path),'DESTINATION_SYMLINK_REJECTED');
            if (!is_dir($path)) { self::need(mkdir($path,0750),'DESTINATION_DIRECTORY_FAILED');$created[]=$path; }
            $real=realpath($path);self::need($real!==false && str_starts_with(str_replace('\\','/',$real),$this->storage.'/'),'DESTINATION_BOUNDARY_INVALID');
        }
        return $path;
    }

    private function receipt(array $inspection): array
    {
        return ['ok'=>true,'payload_digest'=>$inspection['payload_digest'],'check_digest'=>$inspection['check_digest'],'pending_restores'=>count($inspection['pending']),'pending_labels'=>count($inspection['label_pending']),'pending_types'=>0,'type_mappings'=>$this->counts['types'],'restore_documents'=>$inspection['restore_documents'],'matched_document_ids'=>$inspection['matched_document_ids'],'before_image'=>$inspection['before_image'],'before_image_sha256'=>hash('sha256',self::json(self::canonical($inspection['before_image'])))];
    }

    private static function label(string $code): string
    { self::need(isset(\App\Service\TripDocumentPackage::LABELS[$code]),'UNKNOWN_CANONICAL_DOCUMENT_CODE');return \App\Service\TripDocumentPackage::LABELS[$code]; }
    private static function equivalentType(?array $left,array $right): bool
    { return $left!==null && (string)$left['code']===(string)$right['code'] && (string)$left['entity_type']===(string)$right['entity_type']; }
    private static function need(bool $condition,string $code): void { if (!$condition) throw new RuntimeException($code); }
    private static function keys(array $row,array $allowed): void { self::need(!array_diff(array_keys($row),$allowed),'UNKNOWN_PAYLOAD_KEY'); }
    private static function indexed(array $rows): array
    {
        $indexed=[];foreach ($rows as $row) { $id=(int)($row['id']??0);self::need($id>0 && !isset($indexed[$id]),'INVALID_OR_DUPLICATE_ROW_ID');$indexed[$id]=$row; }return $indexed;
    }
    private static function projection(array $actual,array $expected,string $code): void
    { foreach ($expected as $key=>$value) self::need(array_key_exists($key,$actual) && ($value===null ? $actual[$key]===null : $actual[$key]!==null && (string)$actual[$key]===(string)$value),$code); }
    private static function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value,SORT_STRING);
        foreach ($value as &$item) $item=self::canonical($item);unset($item);return $value;
    }
}

if (!defined('PLANEX_ATTACHMENT_RECOVERY_LIBRARY')) {
    ini_set('display_errors','0');
    RegistryDocumentAttachmentRecovery::installIoGuard();
    try {
        if (PHP_SAPI!=='cli') throw new RuntimeException('CLI_REQUIRED');
        $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
        if ($root!=='/home/s/spugovxsim/planexp/public_html/erpv2') throw new RuntimeException('PRODUCTION_ROOT_INVALID');
        $mode=(string)getenv('PLANEX_ATTACHMENT_RECOVERY_MODE');
        if (!in_array($mode,['check','apply'],true)) throw new RuntimeException('RECOVERY_MODE_INVALID');
        $input=stream_get_contents(STDIN,16*1024*1024+1);
        if (!is_string($input) || strlen($input)>16*1024*1024) throw new RuntimeException('PAYLOAD_SIZE_INVALID');
        $payload=json_decode($input,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('PAYLOAD_INVALID');
        $config=require $root.'/bootstrap/app.php';
        require_once $root.'/app/Support/crypto_helper.php';
        require_once $root.'/app/Support/company_database.php';
        require_once $root.'/app/Service/TripDocumentPackage.php';
        $connect=static fn(array $cfg):PDO=>new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $central=$connect($config['database']);
        $q=$central->prepare("SELECT id,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE id=25 AND status='active'");$q->execute();$company=$q->fetch(PDO::FETCH_ASSOC);
        if (!$company || empty($company['db_identifier'])) throw new RuntimeException('COMPANY_DATABASE_UNAVAILABLE');
        $recovery=new RegistryDocumentAttachmentRecovery($connect(companyDatabaseConfig($config,$company)),$root.'/storage');
        $receipt=$mode==='check'?$recovery->check($payload):$recovery->apply($payload,(string)getenv('PLANEX_ATTACHMENT_RECOVERY_CHECK_DIGEST'));
        echo json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    } catch (Throwable $error) {
        $code=$error instanceof RuntimeException && preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D',$error->getMessage())?$error->getMessage():'RECOVERY_UNEXPECTED_FAILURE';
        fwrite(STDERR,$code."\n");exit(1);
    }
}
