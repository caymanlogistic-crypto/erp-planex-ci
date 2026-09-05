<?php
$companyId=(int)(getSessionCompanyId()??0);$entityType=$_GET['entity_type']??'';$entityId=(int)($_GET['entity_id']??0);
$missingEntityContext=($entityType===''&&$entityId<=0);
$whitelist=[
    'client'=>['label'=>'Клиент','labelDative'=>'клиентам','table'=>'clients','backRoute'=>'/company/clients','displayField'=>'name'],
    'contractor'=>['label'=>'Перевозчик','labelDative'=>'перевозчикам','table'=>'contractors','backRoute'=>'/company/contractors','displayField'=>'name'],
    'driver'=>['label'=>'Водитель','labelDative'=>'водителям','table'=>'drivers','backRoute'=>'/company/drivers','displayField'=>'full_name'],
    'vehicle_unit'=>['label'=>'Транспортная единица','labelDative'=>'транспортным единицам','table'=>'vehicle_units','backRoute'=>'/company/vehicles','displayField'=>'plate_number'],
    'vehicle_set'=>['label'=>'Транспорт','labelDative'=>'транспорту','table'=>'vehicle_sets','backRoute'=>'/company/vehicle-sets','displayField'=>'id'],
    'driver_vehicle_block'=>['label'=>'Водители+ТС','labelDative'=>'связкам','table'=>'driver_vehicle_blocks','backRoute'=>'/company/driver-vehicle-blocks','displayField'=>'id'],
    'crew'=>['label'=>'Экипаж','labelDative'=>'экипажам','table'=>'crews','backRoute'=>'/company/crews','displayField'=>'id'],
];
if(!isset($whitelist[$entityType])){$entityTypeError=!$missingEntityContext;$company=null;$documents=[];$entityLabel='';$entityLabelDative='';$entityName='';$backRoute='';$dbError=null;$entityNotFound=false;$pageTitle='Документы';$pageContext='Документы › Компания';ob_start();require base_path('app/View/pages/company_documents.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
requireDocumentEntityPage($entityType);
$entityLabel=$whitelist[$entityType]['label'];$entityLabelDative=$whitelist[$entityType]['labelDative'];$entityTable=$whitelist[$entityType]['table'];$backRoute=$whitelist[$entityType]['backRoute'];$displayField=$whitelist[$entityType]['displayField'];
if($companyId<=0){$company=null;$documents=[];$entityName='';$dbError=null;$entityNotFound=false;$pageTitle='Документы';$pageContext='Документы › Компания';ob_start();require base_path('app/View/pages/company_documents.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
try{$pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$company){$company=null;$documents=[];$entityName='';$dbError=null;$entityNotFound=false;$pageTitle='Документы';$pageContext='Документы › Компания';ob_start();require base_path('app/View/pages/company_documents.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$pageContext='Документы › Компания: '.$company['name'];
if($company['status']!=='active'){$documents=[];$entityName='';$dbError=null;$entityNotFound=false;$pageTitle='Документы';$pageContext='Документы › Компания: '.$company['name'];ob_start();require base_path('app/View/pages/company_documents.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$localDbConfig=companyDatabaseConfig($config, $company);$localDb=new \App\Core\Database($localDbConfig);$localPdo=$localDb->connection();applyLocalMigrations($localPdo);
if($entityId>0&&!canAccessDocumentEntity($localPdo,$entityType,$entityId)){denyEntityAccess();return;}
try{$localPdo->query("SELECT 1 FROM documents LIMIT 1")->fetch();}catch(\Exception$e){$localPdo->exec(file_get_contents(base_path('database/migrations-local/007_create_company_documents.sql')));}
try{$localPdo->query("SELECT 1 FROM document_types LIMIT 1")->fetch();}catch(\Exception$e){$localPdo->exec(file_get_contents(base_path('database/migrations-local/024_create_document_types.sql')));$localPdo->exec(file_get_contents(base_path('database/migrations-local/025_add_document_type_id.sql')));}
try{$localPdo->query("SELECT created_by_user_id FROM documents LIMIT 1")->fetch();}catch(\Exception$e){$localPdo->exec("ALTER TABLE documents ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL");}
$entityName='';$entityNotFound=false;
if($entityId>0){
    $eStmt=$localPdo->prepare("SELECT `$displayField` FROM `$entityTable` WHERE id=?");
    $eStmt->execute([$entityId]);
    $val=$eStmt->fetchColumn();
    if($val!==false){$entityName=(string)$val;}else{$entityNotFound=true;}
}
$docStmt=$localPdo->prepare("SELECT d.*, dt.name AS type_name, dt.code AS type_code FROM documents d LEFT JOIN document_types dt ON d.document_type_id=dt.id WHERE d.entity_type=? AND d.entity_id=? AND d.deleted_at IS NULL ORDER BY d.id DESC");
$dummyEid=$entityId>0?$entityId:0;$docStmt->execute([$entityType,$dummyEid]);$documents=$docStmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Документы'.($entityName!==''?': '.$entityName:'');$dbError=null;}
catch(\Exception$e){$company=$company??null;$documents=[];$entityName='';$dbError='Не удалось подключиться к базе данных компании.';if(strpos($e->getMessage(),'Base table')!==false||strpos($e->getMessage(),'not found')!==false)$dbError='Модуль документов ещё не установлен.';}
$entityTypeError=!$missingEntityContext&&!isset($whitelist[$entityType]);
//$topbarCrumbs=[['label'=>mb_strtoupper($company['name']??''),'url'=>'/company/dashboard'],['label'=>'Документы','url'=>null]];
ob_start();require base_path('app/View/pages/company_documents.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
