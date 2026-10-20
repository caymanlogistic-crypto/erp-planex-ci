<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/TripDocumentPackage.php';
define('PLANEX_ATTACHMENT_RECOVERY_LIBRARY',true);
require_once BASE_PATH.'/scripts/registry_document_attachment_recovery.php';

RegistryDocumentAttachmentRecovery::installIoGuard();
try {
$schema=(string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV')!=='test' || !preg_match('/^planex_t_[a-f0-9]{24}$/D',$schema) || getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')!==$schema) throw new RuntimeException('Unique disposable test schema required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$schema.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
cleanCompanyPoolDatabase($pdo);
$pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/007_create_company_documents.sql'));
$pdo->exec('ALTER TABLE documents ADD document_type_id INT NULL,ADD created_by_user_id INT NULL,ADD created_by_role VARCHAR(20) NULL,ADD deleted_at DATETIME NULL,ADD delete_comment TEXT NULL,ADD updated_by_user_id INT NULL,ADD updated_by_role VARCHAR(20) NULL');
$pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/024_create_document_types.sql'));
$pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/025_add_document_type_id.sql'));
$pdo->exec('DELETE FROM document_types');
$pdo->exec('ALTER TABLE document_types ADD deleted_at DATETIME NULL');
$pdo->exec("CREATE TABLE drivers(id INT PRIMARY KEY,full_name VARCHAR(100),phone VARCHAR(30),status VARCHAR(20),deleted_at DATETIME NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,created_by_user_id INT)");
$pdo->exec("INSERT INTO drivers(id,full_name,phone,status,created_by_user_id) VALUES(1,'Тестов Тест Тестович','+79000000000','active',77),(2,'Другой Тест Тестович','+79000000001','active',88)");
$pdo->exec('CREATE TABLE route_executor_review_cards(card_id CHAR(36) PRIMARY KEY,snapshot_json LONGTEXT,files_json LONGTEXT,deleted_at DATETIME NULL)');
$pdo->exec('CREATE TABLE entity_assignments(id INT PRIMARY KEY,entity_type VARCHAR(30),entity_id INT,membership_id INT)');
$pdo->exec("INSERT INTO entity_assignments VALUES(1,'driver',1,77)");
$pdo->exec('CREATE TABLE business_data(id INT PRIMARY KEY,amount DECIMAL(10,2),note VARCHAR(50))');
$pdo->exec("INSERT INTO business_data VALUES(1,123.45,'Unrelated business state')");
$root=sys_get_temp_dir().'/planex_attachment_recovery_'.bin2hex(random_bytes(8));
if (!mkdir($root,0700,true)) throw new RuntimeException('Fixture storage unavailable');
$put=static function(string $relative,string $bytes)use($root):void { $path=$root.'/'.$relative;if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);file_put_contents($path,$bytes); };
$assert=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$codes=['passport','driver_license','snils','company_card'];
$insertType=$pdo->prepare('INSERT INTO document_types(id,name,code,entity_type,category) VALUES(?,?,?,?,?)');
for ($i=1;$i<=33;$i++) {
    if ($i<=4) { $code=$codes[$i-1];$name=$code;$kind=$code==='company_card'?'contractor':'driver';$category='predefined'; }
    elseif ($i<=8) { $code=$codes[$i-5];$name=App\Service\TripDocumentPackage::LABELS[$code];$kind=$code==='company_card'?'contractor':'driver';$category='predefined'; }
    else { $code=null;$name='Дополнительный тип '.$i;$kind='driver';$category='custom'; }
    $insertType->execute([$i,$name,$code,$kind,$category]);
}
$insertDocument=$pdo->prepare("INSERT INTO documents(id,entity_type,entity_id,document_type,document_type_id,original_name,stored_name,relative_path,mime_type,file_size,status,created_by_user_id,created_by_role,uploaded_by_user_id,uploaded_by_role,comments) VALUES(?,?,?,?,?,?,?,?,?,?,'uploaded',77,'logist',77,'logist','Existing metadata is immutable')");
$labelUpdates=[];
for ($i=1;$i<=402;$i++) {
    $typeId=$i<=74?(($i-1)%4)+1:5;$code=$i<=74?$codes[$typeId-1]:'passport';
    $kind=$code==='company_card'?'contractor':'driver';$name=$i<=74 || $i>396?$code:App\Service\TripDocumentPackage::LABELS[$code];
    $relative='companies/25/documents/existing-'.$i.'.pdf';$bytes='%PDF-1.4 old immutable '.$i;$put($relative,$bytes);
    $insertDocument->execute([$i,$kind,1,$name,$typeId,'старый '.$i.'.pdf','existing-'.$i.'.pdf',$relative,'application/pdf',strlen($bytes)]);
    if ($i<=74) $labelUpdates[]=['id'=>$i,'old_type'=>$code,'new_type'=>App\Service\TripDocumentPackage::LABELS[$code],'document_type_id'=>$typeId,'new_document_type_id'=>$typeId+4];
}
// Unrelated categories must neither block the finite baseline nor be updated.
$insertDocument->execute([403,'finance',9,'Счёт',null,'unrelated.pdf','unrelated.pdf','unrelated/no-file-needed.pdf','application/pdf',10]);
$docProjection=['id','entity_type','entity_id','document_type','document_type_id','original_name','stored_name','relative_path','mime_type','file_size','status','created_by_user_id','created_by_role','uploaded_by_user_id','uploaded_by_role','created_at','updated_at','deleted_at','delete_comment'];
$typeProjection=['id','name','code','entity_type','category','created_at','deleted_at'];
$selectProjection=static fn(string $table,array $keys,string $where=''):array=>$pdo->query('SELECT '.implode(',',$keys).' FROM '.$table.$where.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$card='12345678-1234-4123-8123-123456789012';$retained=[];$restores=[];
for ($i=0;$i<35;$i++) {
    $name='Паспорт утраченная страница '.$i.'.pdf';$bytes='%PDF-1.4 retained synthetic page '.$i;
    $relative='companies/25/route-executor-review/'.$card.'/page-'.$i.'.pdf';$put($relative,$bytes);
    $retained[]=['id'=>str_pad((string)$i,32,'0'),'formId'=>'lab-driver-form','name'=>'predef_doc[passport][]','index'=>0,'original_name'=>$name,'mime'=>'application/pdf','size'=>strlen($bytes),'path'=>$relative];
    $restores[]=['entity_type'=>'driver','entity_id'=>1,'document_type'=>'Паспорт','document_type_id'=>5,'original_name'=>$name,'mime'=>'application/pdf','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'source_kind'=>'review','source_card_id'=>$card,'source_position'=>$i,'source_path'=>$relative,'reference_document_id'=>1,'reference_sha256'=>hash_file('sha256',$root.'/companies/25/documents/existing-1.pdf')];
}
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2beioAAAAASUVORK5CYII=',true);
for ($i=0;$i<2;$i++) {
    $bytes=$png.'owner-'.$i;
    $restores[]=['entity_type'=>'driver','entity_id'=>1,'document_type'=>'Паспорт','document_type_id'=>5,'original_name'=>'Предоставленная владельцем страница '.$i.'.png','mime'=>'image/png','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'source_kind'=>'owner','source_card_id'=>null,'source_position'=>null,'source_path'=>null,'inline_base64'=>base64_encode($bytes),'reference_document_id'=>1,'reference_sha256'=>hash_file('sha256',$root.'/companies/25/documents/existing-1.pdf')];
}
$snapshot=['controls'=>[['formId'=>'lab-driver-form','name'=>'full_name','value'=>'Тестов Тест Тестович']]];
$pdo->prepare('INSERT INTO route_executor_review_cards VALUES(?,?,?,NOW())')->execute([$card,json_encode($snapshot,JSON_UNESCAPED_UNICODE),json_encode($retained,JSON_UNESCAPED_UNICODE)]);
$typeMappings=[];foreach ($codes as $i=>$code) $typeMappings[]=['id'=>$i+1,'old_name'=>$code,'canonical_id'=>$i+5,'new_name'=>App\Service\TripDocumentPackage::LABELS[$code],'code'=>$code,'entity_type'=>$code==='company_card'?'contractor':'driver'];
$entity=$pdo->query('SELECT full_name,status,deleted_at,created_at,updated_at,created_by_user_id FROM drivers WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$payload=['task_id'=>'document-attachments-restore-20261020','company_id'=>25,'authorization'=>'RESTORE_37_RU_DOCUMENTS_20261020','expected_file_count'=>37,'expected_label_count'=>74,'documents_before'=>$selectProjection('documents',$docProjection," WHERE entity_type IN ('contractor','driver','vehicle_unit')"),'types_before'=>$selectProjection('document_types',$typeProjection),'entities_before'=>[['entity_type'=>'driver','id'=>1,'values'=>$entity]],'label_updates'=>$labelUpdates,'type_mappings'=>$typeMappings,'restores'=>$restores];
$allFiles=static function()use($root):array {
    $out=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) if ($file->isFile()) $out[substr(str_replace('\\','/',$file->getPathname()),strlen(str_replace('\\','/',$root))+1)]=hash_file('sha256',$file->getPathname());ksort($out);return $out;
};
$state=static function()use($pdo,$allFiles):array {
    $out=[];foreach (['documents','document_types','drivers','route_executor_review_cards','entity_assignments','business_data'] as $table) $out[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.($table==='route_executor_review_cards'?'card_id':'id'))->fetchAll(PDO::FETCH_ASSOC);$out['files']=$allFiles();return $out;
};
$reject=static function(callable $action,string $message)use($assert):void { try { $action(); } catch (RuntimeException $expected) { return; }throw new RuntimeException($message); };
$recovery=new RegistryDocumentAttachmentRecovery($pdo,$root);
try {
    $before=$state();$checked=$recovery->check($payload);
    $assert($checked['pending_restores']===37 && $checked['pending_labels']===74 && $checked['pending_types']===0 && $checked['type_mappings']===4,'Exact approved recovery plan must be returned without writes');
    $assert(count($checked['before_image']['documents'])===402 && count($checked['before_image']['document_types'])===33 && isset($checked['before_image']['documents'][0]['comments']),'Receipt must retain full before images beyond projections');
    $assert($before===$state(),'Check must not change data, ACL, old files or sources');
    $mutations=[
        static function(array &$p):void {$p['restores'][0]['sha256']=str_repeat('0',64);},
        static function(array &$p):void {$p['restores'][0]['entity_id']=2;},
        static function(array &$p):void {$p['restores'][0]['source_position']=34;},
        static function(array &$p):void {$p['restores'][0]['reference_sha256']=str_repeat('1',64);},
        static function(array &$p):void {$p['restores'][0]['document_type_id']=2;},
        static function(array &$p):void {$p['restores'][35]['inline_base64']=base64_encode('not a png');},
        static function(array &$p):void {$p['documents_before'][0]['unexpected_sql_column']='rejected';},
    ];
    foreach ($mutations as $mutate) {
        $invalid=$payload;$mutate($invalid);$reject(fn()=>$recovery->apply($invalid,$checked['check_digest']),'Invalid proof was accepted');$assert($before===$state(),'Invalid proof wrote data or a file before validation');
    }
    $reject(fn()=>$recovery->apply($payload,''),'Apply without check receipt was accepted');
    $pdo->exec("UPDATE documents SET original_name='Changed baseline' WHERE id=200");
    $drifted=$state();$reject(fn()=>$recovery->apply($payload,$checked['check_digest']),'Baseline drift was accepted');$assert($drifted===$state(),'Baseline failure wrote additional data');
    $pdo->prepare('UPDATE documents SET original_name=?,updated_at=? WHERE id=200')->execute([$before['documents'][199]['original_name'],$before['documents'][199]['updated_at']]);
    $assert($before===$state(),'Baseline fixture restored exactly');
    // An omitted projection field is still bound by the complete check receipt.
    $pdo->exec("UPDATE drivers SET phone='+79999999999' WHERE id=1");
    $drifted=$state();$reject(fn()=>$recovery->apply($payload,$checked['check_digest']),'Full receipt failed to detect non-projected entity drift');$assert($drifted===$state(),'Receipt drift wrote data');
    $pdo->exec("UPDATE drivers SET phone='+79000000000' WHERE id=1");
    $rollback=new RegistryDocumentAttachmentRecovery($pdo,$root,['before_commit'=>static function():void {throw new RuntimeException('INJECTED_BEFORE_COMMIT');}]);
    $reject(fn()=>$rollback->apply($payload,$checked['check_digest']),'Injected transaction failure was ignored');
    $assert($before===$state(),'Confirmed rollback must remove only task-created files and preserve all prior rows and files');
    $applied=$recovery->apply($payload,$checked['check_digest']);
    $assert($applied['restored']===37 && $applied['labels_updated']===74 && $applied['types_updated']===0 && $applied['type_mappings']===4,'Exact 37 file and 74 label/FK recovery must succeed without catalog mutation');
    $assert($applied['before_image']===$checked['before_image'],'Successful apply receipt must retain the actual pre-write before image');
    $assert(count($applied['restore_documents'])===37 && array_column($applied['restore_documents'],'position')===range(0,36),'Receipt must expose all restored document IDs in payload order for independent GET verification');
    $after=$state();$assert(count($after['documents'])===440,'37 new rows must be added beside 402 baseline and one unrelated row');
    foreach ($before['documents'] as $row) {
        $current=$pdo->query('SELECT * FROM documents WHERE id='.(int)$row['id'])->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['id']<=74) { $row['document_type']=App\Service\TripDocumentPackage::LABELS[$codes[((int)$row['id']-1)%4]];$row['document_type_id']=(int)$row['document_type_id']+4; }
        $assert($row===$current,'Only approved existing label and type FK may change; all other document metadata stays exact');
    }
    foreach ($before['files'] as $path=>$sha) $assert(($after['files'][$path]??null)===$sha,'An existing or retained source file changed');
    foreach (['document_types','drivers','route_executor_review_cards','entity_assignments','business_data'] as $table) $assert($before[$table]===$after[$table],'Catalog, entity, ACL, card or business data changed');
    $new=$pdo->query('SELECT * FROM documents WHERE id>403 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($new as $i=>$document) {
        $expected=$restores[$i];$assert($document['original_name']===$expected['original_name'] && $document['document_type']===$expected['document_type'] && (int)$document['document_type_id']===5,'New rows must preserve names and canonical type IDs');
        $assert((int)$document['created_by_user_id']===0 && $document['created_by_role']==='system' && (int)$document['uploaded_by_user_id']===0 && $document['uploaded_by_role']==='system','Recovery must never impersonate the old uploader');
        $assert(hash_file('sha256',$root.'/'.$document['relative_path'])===$expected['sha256'] && (int)$document['file_size']===$expected['size'] && $document['mime_type']===$expected['mime'],'New storage content, MIME and metadata must match its proof');
    }
    $repeat=$recovery->apply($payload,$checked['check_digest']);
    $assert($repeat['restored']===0 && $repeat['labels_updated']===0 && $repeat['types_updated']===0 && $repeat['deduplicated']===37 && $after===$state(),'Repeated apply must be a complete no-op using the original check receipt');
    $assert($recovery->check($payload)['check_digest']===$checked['check_digest'],'Receipt must remain stable after exactly approved changes');
    // Simulate lost COMMIT acknowledgement with genuinely new rows/files, then retry.
    foreach ($new as $document) unlink($root.'/'.$document['relative_path']);$pdo->exec('DELETE FROM documents WHERE id>403');
    foreach ($labelUpdates as $change) $pdo->prepare('UPDATE documents SET document_type=?,document_type_id=?,updated_at=updated_at WHERE id=?')->execute([$change['old_type'],$change['document_type_id'],$change['id']]);
    $unknown=new RegistryDocumentAttachmentRecovery($pdo,$root,['commit'=>static function(PDO $database):bool {$database->commit();throw new RuntimeException('LOST_COMMIT_ACK');}]);
    $reject(fn()=>$unknown->apply($payload,$checked['check_digest']),'Lost commit acknowledgement must be surfaced');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM documents WHERE id>403')->fetchColumn()===37,'Committed rows survived an unknown acknowledgement');
    foreach ($pdo->query('SELECT relative_path FROM documents WHERE id>403')->fetchAll(PDO::FETCH_COLUMN) as $path) $assert(is_file($root.'/'.$path),'Unknown commit outcome deleted a committed file');
    $assert($recovery->apply($payload,$checked['check_digest'])['restored']===0,'Retry after unknown commit must discover committed content without duplicates');
    echo "DOCUMENT_ATTACHMENT_RECOVERY_MYSQL=PASS restored=37 labels=74 type_mappings=4 catalog_unchanged=PASS repeat=0 rollback=PASS unknown_commit=PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $resolved=realpath($root);$temporary=realpath(sys_get_temp_dir());
    $assert($resolved!==false && $temporary!==false && str_starts_with(str_replace('\\','/',$resolved),rtrim(str_replace('\\','/',$temporary),'/').'/planex_attachment_recovery_'),'Fixture cleanup boundary');
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());else unlink($file->getPathname()); }rmdir($root);
}
} finally { restore_error_handler(); }
