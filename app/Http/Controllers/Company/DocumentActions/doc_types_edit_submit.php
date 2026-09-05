<?php
$companyId=(int)(getSessionCompanyId()??0);if($companyId<=0){header('Location: /company/document-types');exit;}
try{$pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);if(!$company||$company['status']!=='active'){header('Location: /company/document-types');exit;}
$cfg=companyDatabaseConfig($config, $company);$ldb=new \App\Core\Database($cfg);$lpdo=$ldb->connection();applyLocalMigrations($lpdo);
$name=trim($_POST['name']??'');$code=trim($_POST['code']??'');$entityType=trim($_POST['entity_type']??'');$sortOrder=(int)($_POST['sort_order']??0);$typeId=(int)$id;
if($name===''){header('Location: /company/document-types/'.$typeId.'/edit?error=name_required');exit;}
$upd=$lpdo->prepare("UPDATE document_types SET name=:name,code=:code,entity_type=:entity_type,sort_order=:sort_order WHERE id=:id");
$upd->execute([':name'=>$name,':code'=>$code?:null,':entity_type'=>$entityType?:null,':sort_order'=>$sortOrder,':id'=>$typeId]);
header('Location: /company/document-types');exit;}
catch(\Exception$e){header('Location: /company/document-types?error=edit_failed');exit;}
