<?php
$companyId=(int)(getSessionCompanyId()??0);$entityType=$_GET['entity_type']??'';$entityId=(int)($_GET['entity_id']??0);$replaceDocId=(int)($_GET['replace']??0);
$missingEntityContext=($entityType===''||$entityId<=0);
$entityInfo=['client'=>['label'=>'Клиент','table'=>'clients','df'=>'name'],'contractor'=>['label'=>'Перевозчик','table'=>'contractors','df'=>'name'],'driver'=>['label'=>'Водитель','table'=>'drivers','df'=>'full_name'],'vehicle_unit'=>['label'=>'Транспортная единица','table'=>'vehicle_units','df'=>'plate_number'],'vehicle_set'=>['label'=>'Транспорт','table'=>'vehicle_sets','df'=>'id'],'driver_vehicle_block'=>['label'=>'Водители+ТС','table'=>'driver_vehicle_blocks','df'=>'id'],'crew'=>['label'=>'Экипаж','table'=>'crews','df'=>'id']];
$entityTypeError=!$missingEntityContext&&!isset($entityInfo[$entityType]);
$entityLabel=$entityInfo[$entityType]['label']??'';$entityName='';$errors=[];$old=$_GET;$formError=null;$success=false;$createdDoc=null;$docTypes=[];$replacedDoc=null;$company=null;$dbError=null;$entityNotFound=false;
if($missingEntityContext||$entityTypeError){ob_start();require base_path('app/View/pages/company_documents_upload.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
requireDocumentEntityPage($entityType);
try{$pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$company){ob_start();require base_path('app/View/pages/company_documents_upload.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
if($company['status']!=='active'){ob_start();require base_path('app/View/pages/company_documents_upload.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$cfg=companyDatabaseConfig($config, $company);$ldb=new \App\Core\Database($cfg);$lpdo=$ldb->connection();applyLocalMigrations($lpdo);
if(!canAccessDocumentEntity($lpdo,$entityType,$entityId)){denyEntityAccess();return;}
try{$lpdo->query("SELECT 1 FROM documents LIMIT 1")->fetch();}catch(\Exception$e){$lpdo->exec(file_get_contents(base_path('database/migrations-local/007_create_company_documents.sql')));}
try{$lpdo->query("SELECT 1 FROM document_types LIMIT 1")->fetch();}catch(\Exception$e){$lpdo->exec(file_get_contents(base_path('database/migrations-local/024_create_document_types.sql')));$lpdo->exec(file_get_contents(base_path('database/migrations-local/025_add_document_type_id.sql')));}
$dtStmt=$lpdo->query("SELECT * FROM document_types WHERE entity_type IS NULL OR entity_type='' OR entity_type='$entityType' ORDER BY sort_order,name ASC");$docTypes=$dtStmt->fetchAll(PDO::FETCH_ASSOC);
$meta=$entityInfo[$entityType];$entityTable=$meta['table'];$df=$meta['df'];
$eStmt=$lpdo->prepare("SELECT `$df` FROM `$entityTable` WHERE id=?");$eStmt->execute([$entityId]);$val=$eStmt->fetchColumn();
if($val!==false){$entityName=(string)$val;}else{$entityNotFound=true;}
if($replaceDocId>0){$rs=$lpdo->prepare("SELECT * FROM documents WHERE id=? AND deleted_at IS NULL");$rs->execute([$replaceDocId]);$replacedDoc=$rs->fetch(PDO::FETCH_ASSOC);}
$dbError=null;}catch(\Exception$e){$company=$company??null;$dbError='Ошибка загрузки: '.$e->getMessage();}
ob_start();require base_path('app/View/pages/company_documents_upload.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
