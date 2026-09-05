<?php
$companyId=(int)(getSessionCompanyId()??0);if($companyId<=0){header('Location: /company/document-types');exit;}
try{$pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);if(!$company||$company['status']!=='active'){header('Location: /company/document-types');exit;}
$cfg=companyDatabaseConfig($config, $company);$ldb=new \App\Core\Database($cfg);$lpdo=$ldb->connection();applyLocalMigrations($lpdo);
$name=trim($_POST['name']??'');$code=trim($_POST['code']??'');$entityType=trim($_POST['entity_type']??'');$sortOrder=(int)($_POST['sort_order']??0);
if($name===''){header('Location: /company/document-types/create?error=name_required');exit;}
$ins=$lpdo->prepare("INSERT INTO document_types (name,code,entity_type,sort_order,created_by_user_id,created_by_role) VALUES (:name,:code,:entity_type,:sort_order,:uid,:role)");
$ins->execute([':name'=>$name,':code'=>$code?:null,':entity_type'=>$entityType?:null,':sort_order'=>$sortOrder,':uid'=>(int)$_SESSION['user_id'],':role'=>$_SESSION['role_code']??null]);
header('Location: /company/document-types');exit;}
catch(\Exception$e){header('Location: /company/document-types?error=create_failed');exit;}
