<?php
$companyId=(int)(getSessionCompanyId()??0);$typeId=(int)$id;if($companyId<=0||$typeId<=0){header('Location: /company/document-types');exit;}
try{$pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);if(!$company||$company['status']!=='active'){header('Location: /company/document-types');exit;}
$cfg=companyDatabaseConfig($config, $company);$ldb=new \App\Core\Database($cfg);$lpdo=$ldb->connection();applyLocalMigrations($lpdo);
$dtStmt=$lpdo->prepare("SELECT * FROM document_types WHERE id=?");$dtStmt->execute([$typeId]);$dt=$dtStmt->fetch(PDO::FETCH_ASSOC);
$uid=(int)($_SESSION['user_id']??0);$rl=(string)($_SESSION['role_code']??'');
$lpdo->prepare("UPDATE document_types SET deleted_at=NOW(),deleted_by_user_id=?,deleted_by_role=? WHERE id=?")->execute([$uid,$rl,$typeId]);
if($dt){$snapshot=json_encode($dt,JSON_UNESCAPED_UNICODE);$un=$_SESSION['user_name']??'';try{$cp=$db->connection();\App\Service\AuditService::recordDeletion($cp,$company,'document_type',$typeId,'document_types',$dt['name']??'#'.$typeId,$uid,$rl,$un,null,$snapshot);}catch(\Exception$ae){error_log('Audit failed: '.$ae->getMessage());}}
header('Location: /company/document-types');exit;}
catch(\Exception$e){header('Location: /company/document-types?error=delete_failed');exit;}
