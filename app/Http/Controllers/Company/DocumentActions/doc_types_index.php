<?php
$companyId=(int)(getSessionCompanyId()??0);$pageTitle='Типы документов';$pageContext='Типы документов › Компания';
if($companyId<=0){$company=null;$docTypes=[];$dbError=null;ob_start();require base_path('app/View/pages/company_document_types.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
try{$pdo=$db->connection();$stmt=$pdo->prepare('SELECT * FROM companies WHERE id=?');$stmt->execute([$companyId]);$company=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$company){$company=null;$docTypes=[];$dbError=null;ob_start();require base_path('app/View/pages/company_document_types.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$pageContext='Типы документов › Компания: '.$company['name'];if($company['status']!=='active'){$docTypes=[];$dbError=null;ob_start();require base_path('app/View/pages/company_document_types.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');return;}
$cfg=companyDatabaseConfig($config, $company);$ldb=new \App\Core\Database($cfg);$lpdo=$ldb->connection();applyLocalMigrations($lpdo);
try{$lpdo->query("SELECT 1 FROM document_types LIMIT 1")->fetch();}catch(\Exception$e){$lpdo->exec(file_get_contents(base_path('database/migrations-local/024_create_document_types.sql')));$lpdo->exec(file_get_contents(base_path('database/migrations-local/025_add_document_type_id.sql')));}
$docTypes=$lpdo->query("SELECT * FROM document_types ORDER BY entity_type IS NULL DESC, entity_type, sort_order, name")->fetchAll(PDO::FETCH_ASSOC);$dbError=null;}
catch(\Exception$e){$company=$company??null;$docTypes=[];$dbError='Ошибка загрузки типов документов.';}
ob_start();require base_path('app/View/pages/company_document_types.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
