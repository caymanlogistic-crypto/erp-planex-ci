<?php
$companyId=(int)(getSessionCompanyId()??0);$entityType=$_POST['entity_type']??'';$entityId=(int)($_POST['entity_id']??0);
if($companyId<=0||$entityType===''||$entityId<=0){header('Location: /company/documents?error=invalid_request');exit;}
$whitelist=['client','contractor','driver','vehicle_unit','vehicle_set','driver_vehicle_block','crew'];
if(!in_array($entityType,$whitelist,true)){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=invalid_entity');exit;}
requireDocumentEntityPage($entityType);
$allowedExt=['pdf','doc','docx','rtf','odt','xls','xlsx','csv','ods','jpg','jpeg','png','webp','gif','bmp','tif','tiff','heic','heif','txt'];$maxSize=20*1024*1024;
// Read doc_type from both document_type (form standard) and doc_type (legacy fallback)
$docType=trim($_POST['document_type']??$_POST['doc_type']??'');
$uploadError=\App\Service\DocumentService::validateUploadedFile($_FILES['doc_file']??[]);if($uploadError!==null){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error='.urlencode($uploadError));exit;}
$ext=strtolower(pathinfo($_FILES['doc_file']['name'],PATHINFO_EXTENSION));
try{$pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$company||$company['status']!=='active'){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=company_inactive');exit;}
$cfg=companyDatabaseConfig($config, $company);$ldb=new \App\Core\Database($cfg);$lpdo=$ldb->connection();applyLocalMigrations($lpdo);
if(!canAccessDocumentEntity($lpdo,$entityType,$entityId)){denyEntityAccess();return;}
try{$lpdo->query("SELECT 1 FROM documents LIMIT 1")->fetch();}catch(\Exception$e){$lpdo->exec(file_get_contents(base_path('database/migrations-local/007_create_company_documents.sql')));}
try{$lpdo->query("SELECT 1 FROM document_types LIMIT 1")->fetch();}catch(\Exception$e){$lpdo->exec(file_get_contents(base_path('database/migrations-local/024_create_document_types.sql')));$lpdo->exec(file_get_contents(base_path('database/migrations-local/025_add_document_type_id.sql')));}
$storedName=uniqid('doc_',true).'.'.$ext;$relativeDir='companies/'.$companyId.'/documents/'.$entityType.'/'.$entityId;$absoluteDir=storage_path($relativeDir);
if(!is_dir($absoluteDir))mkdir($absoluteDir,0755,true);
if(!move_uploaded_file($_FILES['doc_file']['tmp_name'],$absoluteDir.DIRECTORY_SEPARATOR.$storedName)){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=save_failed');exit;}
$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($absoluteDir.DIRECTORY_SEPARATOR.$storedName)?:'application/octet-stream';
$dtId=null;if($docType!==''){$dts=$lpdo->prepare("SELECT id FROM document_types WHERE name=? AND (entity_type=? OR entity_type IS NULL) LIMIT 1");$dts->execute([$docType,$entityType]);$dtId=$dts->fetchColumn()?:null;}
$ins=$lpdo->prepare('INSERT INTO documents(entity_type,entity_id,document_type,document_type_id,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role,created_by_user_id,created_by_role)VALUES(:et,:eid,:dtype,:dtid,:oname,:sname,:rpath,:mime,:fsize,:status,:uid,:role,:cuid,:crole)');
$ins->execute([':et'=>$entityType,':eid'=>$entityId,':dtype'=>$docType?:null,':dtid'=>$dtId,':oname'=>$_FILES['doc_file']['name'],':sname'=>$storedName,':rpath'=>$relativeDir.'/'.$storedName,':mime'=>$mime,':fsize'=>$_FILES['doc_file']['size'],':status'=>'uploaded',':uid'=>(int)$_SESSION['user_id'],':role'=>$_SESSION['role_code'],':cuid'=>(int)$_SESSION['user_id'],':crole'=>$_SESSION['role_code']]);
header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&upload=ok');exit;}
catch(\Exception$e){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=server_error');exit;}
