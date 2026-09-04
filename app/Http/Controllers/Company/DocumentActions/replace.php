<?php
$companyId=(int)(getSessionCompanyId()??0);$entityType=$_POST['entity_type']??'';$entityId=(int)($_POST['entity_id']??0);$replaceDocId=(int)($_POST['replace_doc_id']??0);
$allowedExt=['pdf','doc','docx','rtf','odt','xls','xlsx','csv','ods','jpg','jpeg','png','webp','gif','bmp','tif','tiff','heic','heif','txt'];$maxSize=20*1024*1024;
if($companyId<=0||$entityType===''||$entityId<=0||$replaceDocId<=0){header('Location: /company/documents?error=invalid_request');exit;}
// Validate entity_type whitelist
$entityWhitelist=['client','contractor','driver','vehicle_unit','vehicle_set','driver_vehicle_block','crew'];
if(!in_array($entityType,$entityWhitelist,true)){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=invalid_entity');exit;}
requireDocumentEntityPage($entityType);
try{$pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$company||$company['status']!=='active'){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=company_inactive');exit;}
$cfg=companyDatabaseConfig($config, $company);$ldb=new \App\Core\Database($cfg);$lpdo=$ldb->connection();applyLocalMigrations($lpdo);
// Load existing document (must exist and not be deleted)
$docStmt=$lpdo->prepare("SELECT * FROM documents WHERE id=? AND entity_type=? AND entity_id=? AND deleted_at IS NULL");$docStmt->execute([$replaceDocId,$entityType,$entityId]);$oldDoc=$docStmt->fetch(PDO::FETCH_ASSOC);
if(!$oldDoc){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=doc_not_found');exit;}
if(!canAccessDocumentEntity($lpdo,$entityType,$entityId)){denyEntityAccess();return;}
// Phase 1: Validate new file BEFORE touching old document
$uploadError=\App\Service\DocumentService::validateUploadedFile($_FILES['doc_file']??[]);if($uploadError!==null){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error='.urlencode($uploadError));exit;}
$ext=strtolower(pathinfo($_FILES['doc_file']['name'],PATHINFO_EXTENSION));
// Phase 2: Save new file to storage
$storedName=uniqid('doc_',true).'.'.$ext;$relativeDir='companies/'.$companyId.'/documents/'.$entityType.'/'.$entityId;$absoluteDir=storage_path($relativeDir);
if(!is_dir($absoluteDir))mkdir($absoluteDir,0755,true);
$destPath=$absoluteDir.DIRECTORY_SEPARATOR.$storedName;
if(!move_uploaded_file($_FILES['doc_file']['tmp_name'],$destPath)){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=save_failed');exit;}
// Verify file was actually written
if(!is_file($destPath)){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=save_failed');exit;}
// Phase 3: Only now soft-delete old document and insert new record
$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($destPath)?:'application/octet-stream';
$docType=trim($_POST['document_type']??$_POST['doc_type']??$oldDoc['document_type']??'');
$dtId=null;if($docType!==''){$dts=$lpdo->prepare("SELECT id FROM document_types WHERE name=? AND (entity_type=? OR entity_type IS NULL) LIMIT 1");$dts->execute([$docType,$entityType]);$dtId=$dts->fetchColumn()?:null;}
try{
    $lpdo->beginTransaction();
    $lpdo->prepare("UPDATE documents SET deleted_at=NOW(),deleted_by_user_id=?,delete_comment='Replaced' WHERE id=?")->execute([(int)$_SESSION['user_id'],$replaceDocId]);
    $ins=$lpdo->prepare('INSERT INTO documents(entity_type,entity_id,document_type,document_type_id,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role,created_by_user_id,created_by_role)VALUES(:et,:eid,:dtype,:dtid,:oname,:sname,:rpath,:mime,:fsize,:status,:uid,:role,:cuid,:crole)');
    $ins->execute([':et'=>$entityType,':eid'=>$entityId,':dtype'=>$docType?:null,':dtid'=>$dtId,':oname'=>$_FILES['doc_file']['name'],':sname'=>$storedName,':rpath'=>$relativeDir.'/'.$storedName,':mime'=>$mime,':fsize'=>$_FILES['doc_file']['size'],':status'=>'uploaded',':uid'=>(int)$_SESSION['user_id'],':role'=>$_SESSION['role_code'],':cuid'=>(int)$_SESSION['user_id'],':crole'=>$_SESSION['role_code']]);
    $lpdo->commit();
    $oldFile=\App\Service\DocumentService::resolveStoredPath($companyId,(string)($oldDoc['relative_path']??''));
    if($oldFile!==null&&!@unlink($oldFile)){error_log('Replaced document cleanup failed for resolved path, document id '.$replaceDocId);}
}catch(\Exception$e){
    $lpdo->rollBack();
    // New file was saved but DB insert failed — clean up the new file, old doc remains active
    if(is_file($destPath))@unlink($destPath);
    header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=server_error');exit;
}
header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId);exit;}
catch(\Exception$e){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId.'&error=server_error');exit;}
