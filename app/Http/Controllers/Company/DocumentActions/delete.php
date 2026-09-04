<?php
$companyId=(int)(getSessionCompanyId()??0);$docId=(int)($_POST['id']??0);$entityType=$_POST['entity_type']??'';$entityId=(int)($_POST['entity_id']??0);
if($companyId<=0||$docId<=0){header('Location: /company/documents');exit;}
try{$pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);if(!$company||$company['status']!=='active'){header('Location: /company/documents');exit;}
$cfg=companyDatabaseConfig($config, $company);$ldb=new \App\Core\Database($cfg);$lpdo=$ldb->connection();applyLocalMigrations($lpdo);
$docStmt=$lpdo->prepare("SELECT * FROM documents WHERE id=? AND deleted_at IS NULL");$docStmt->execute([$docId]);$doc=$docStmt->fetch(PDO::FETCH_ASSOC);
if(!$doc){denyEntityAccess();return;}
$entityType=(string)$doc['entity_type'];$entityId=(int)$doc['entity_id'];
requireDocumentEntityPage($entityType);
if(!canAccessDocumentEntity($lpdo,$entityType,$entityId)){denyEntityAccess();return;}
$uid=(int)($_SESSION['user_id']??0);$rl=(string)($_SESSION['role_code']??'');
$lpdo->prepare("UPDATE documents SET deleted_at=NOW(),deleted_by_user_id=?,deleted_by_role=? WHERE id=? AND deleted_at IS NULL")->execute([$uid,$rl,$docId]);
$oldFile=\App\Service\DocumentService::resolveStoredPath($companyId,(string)($doc['relative_path']??''));
if($oldFile!==null&&!@unlink($oldFile)){error_log('Document cleanup failed for synthetic-safe resolved path, document id '.$docId);}
if($doc){$snapshot=json_encode($doc,JSON_UNESCAPED_UNICODE);$un=$_SESSION['user_name']??'';try{$cp=$db->connection();\App\Service\AuditService::recordDeletion($cp,$company,'document',$docId,'documents',$doc['original_name']??'#'.$docId,$uid,$rl,$un,null,$snapshot);}catch(\Exception$ae){error_log('Audit failed: '.$ae->getMessage());}}
header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId);exit;}
catch(\Exception$e){header('Location: /company/documents?entity_type='.urlencode($entityType).'&entity_id='.$entityId);exit;}
