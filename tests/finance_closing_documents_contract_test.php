<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Service/ClosingDocumentService.php';
use App\Service\ClosingDocumentService;
function ok(bool $v,string $m):void{if(!$v)throw new RuntimeException('ASSERT: '.$m);}
function same(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException("ASSERT: $m expected=".var_export($e,true).' actual='.var_export($a,true));}

same('legal_entity',ClosingDocumentService::profileCodeForBankName('ООО "АТИ"'),'ООО from bank name');
same('individual',ClosingDocumentService::profileCodeForBankName('ИП Иванов Иван Иванович'),'ИП from bank name');
same('self_employed',ClosingDocumentService::profileCodeForBankName('Петров Петр'),'no ООО/ИП means self-employed');
same('self_employed',ClosingDocumentService::profileCodeForBankName('АО Банк'),'OWNER fallback is self-employed until INN exception');
$profiles=ClosingDocumentService::profiles();
same(['agreement'],$profiles['legal_entity'][0]['accepted'],'ООО agreement');same(['act','upd'],$profiles['legal_entity'][1]['accepted'],'ООО act/upd');
same(['agreement'],$profiles['individual'][0]['accepted'],'ИП agreement');same(['act','upd'],$profiles['individual'][1]['accepted'],'ИП act/upd');
same(['agreement'],$profiles['self_employed'][0]['accepted'],'NPD agreement');same(['act'],$profiles['self_employed'][1]['accepted'],'NPD act');same(['npd_receipt'],$profiles['self_employed'][2]['accepted'],'NPD receipt');

$host=getenv('PLANEX_TEST_MYSQL_HOST')?:'127.0.0.1';$user=getenv('PLANEX_TEST_MYSQL_USER')?:'planex_ci';$pass=getenv('PLANEX_TEST_MYSQL_PASSWORD')?:'planex_ci';$port=(int)(getenv('PLANEX_TEST_MYSQL_PORT')?:3306);
$root=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$dbA='planex_closing_a_'.getmypid();$dbB='planex_closing_b_'.getmypid();$root->exec("CREATE DATABASE `$dbA` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$root->exec("CREATE DATABASE `$dbB` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
function pdoT(string $h,int $p,string $d,string $u,string $pw):PDO{return new PDO("mysql:host=$h;port=$p;dbname=$d;charset=utf8mb4",$u,$pw,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
function setup(PDO $p):void{
$p->exec("CREATE TABLE bank_transactions(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id INT UNSIGNED NOT NULL DEFAULT 1,operation_date DATE NOT NULL,document_number VARCHAR(100),operation_type VARCHAR(30),counterparty_name VARCHAR(255),counterparty_inn VARCHAR(20),debit_amount DECIMAL(15,2) NOT NULL DEFAULT 0,credit_amount DECIMAL(15,2) NOT NULL DEFAULT 0,purpose TEXT,classification_status VARCHAR(30) NOT NULL DEFAULT 'UNALLOCATED',is_internal_transfer TINYINT(1) NOT NULL DEFAULT 0) ENGINE=InnoDB");
$p->exec("CREATE TABLE finance_employee_movements(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bank_transaction_id INT UNSIGNED NULL,UNIQUE KEY uk_employee_bank_tx(bank_transaction_id),CONSTRAINT fk_employee_bank_tx FOREIGN KEY(bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL) ENGINE=InnoDB");
$p->exec("CREATE TABLE documents(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(20) NOT NULL,entity_id INT UNSIGNED NOT NULL,document_type VARCHAR(100),document_type_id INT UNSIGNED NULL,original_name VARCHAR(500) NOT NULL,stored_name VARCHAR(255) NOT NULL,relative_path VARCHAR(1000) NOT NULL,mime_type VARCHAR(100) NOT NULL,file_size BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'uploaded',deleted_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$p->exec("CREATE TABLE document_types(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(255),code VARCHAR(100)) ENGINE=InnoDB");
$m77=file_get_contents(__DIR__.'/../database/migrations-local/077_finance_closing_documents.sql');$m78=file_get_contents(__DIR__.'/../database/migrations-local/078_finance_closing_document_exceptions.sql');if($m77===false||$m78===false)throw new RuntimeException('migration missing');$p->exec($m77);$p->exec($m78);$p->exec($m78);ok(ClosingDocumentService::schemaReady($p),'078 schema ready');}
function tx(PDO $p,string $name,string $inn,bool $incoming=false,bool $internal=false):int{$s=$p->prepare("INSERT INTO bank_transactions(operation_date,document_number,operation_type,counterparty_name,counterparty_inn,debit_amount,credit_amount,purpose,is_internal_transfer) VALUES(CURDATE(),?,'PAYMENT',?,?,?,?, 'Тест',?)");$s->execute([uniqid('TX'),$name,$inn,$incoming?0:1000,$incoming?1000:0,$internal?1:0]);return(int)$p->lastInsertId();}
function doc(PDO $p,int $tx,string $code):int{$s=$p->prepare("INSERT INTO documents(entity_type,entity_id,document_type,original_name,stored_name,relative_path,mime_type,file_size,status) VALUES('bank_transaction',?,?,?, ?,?,'application/pdf',100,'uploaded')");$name=$code.'.pdf';$s->execute([$tx,$code,$name,uniqid('d').'.pdf','test/'.$name]);$id=(int)$p->lastInsertId();$l=$p->prepare('INSERT INTO finance_closing_document_links(bank_transaction_id,document_id,requirement_code) VALUES(?,?,?)');$l->execute([$tx,$id,$code]);return$id;}
function complete(PDO $p,int $tx,string $profile):void{doc($p,$tx,'agreement');doc($p,$tx,'act');if($profile==='self_employed')doc($p,$tx,'npd_receipt');}
$A=pdoT($host,$port,$dbA,$user,$pass);$B=pdoT($host,$port,$dbB,$user,$pass);setup($A);setup($B);
$tLegal=tx($A,'ООО Ромашка','7700000001');complete($A,$tLegal,'legal_entity');
$tIp=tx($A,'ИП Иванов','7700000002');complete($A,$tIp,'individual');
$tNpd=tx($A,'Петров Петр','770000000003');complete($A,$tNpd,'self_employed');
$tMissing=tx($A,'ООО Без Акта','7700000004');doc($A,$tMissing,'agreement');
$tMissingMany=tx($A,'Сидоров Сергей','770000000005');
$tExempt=tx($A,'АО Банк','7700000006');ClosingDocumentService::addException($A,'7700000006','Банковские комиссии',1);
$tIncoming=tx($A,'ООО Клиент','7700000007',true);$tInternal=tx($A,'ООО Свой счет','7700000008',false,true);$tEmployee=tx($A,'Петров сотрудник','770000000009');$A->prepare('INSERT INTO finance_employee_movements(bank_transaction_id) VALUES(?)')->execute([$tEmployee]);
$rows=ClosingDocumentService::reportRows($A,false);$by=[];foreach($rows as $r)$by[(int)$r['id']]=$r;
ok(!$by[$tLegal]['is_problem'],'ООО complete');ok(!$by[$tIp]['is_problem'],'ИП complete');ok(!$by[$tNpd]['is_problem'],'NPD complete');
ok($by[$tMissing]['is_problem'],'missing one problem');same(1,count($by[$tMissing]['missing_requirements']),'missing one count');ok($by[$tMissingMany]['is_problem'],'NPD missing problem');same(3,count($by[$tMissingMany]['missing_requirements']),'NPD missing all');
ok($by[$tExempt]['is_exempt']&&!$by[$tExempt]['is_problem'],'INN exception suppresses requirement');same('Банковские комиссии',$by[$tExempt]['exception_comment'],'exception comment');ok(!isset($by[$tIncoming]),'incoming excluded');ok(!isset($by[$tInternal]),'internal excluded');ok(!isset($by[$tEmployee]),'employee excluded');
$problems=ClosingDocumentService::reportRows($A,true);same(count($problems),ClosingDocumentService::problemCount($A),'badge same source');same(2,count($problems),'only two problems');
ClosingDocumentService::removeException($A,(int)ClosingDocumentService::exceptions($A)[0]['id']);same(3,ClosingDocumentService::problemCount($A),'removing exception restores problem');ClosingDocumentService::addException($A,'7700000006','Банковские комиссии',1);same(2,ClosingDocumentService::problemCount($A),'re-add exception suppresses problem');
$reuse1=tx($A,'ООО Ромашка','7700000001');$shared=doc($A,$reuse1,'agreement');$reuse2=tx($A,'ООО Ромашка','7700000001');ClosingDocumentService::linkExistingDocument($A,$reuse2,$shared,'agreement',1);$c=$A->prepare('SELECT COUNT(DISTINCT bank_transaction_id) FROM finance_closing_document_links WHERE document_id=?');$c->execute([$shared]);same(2,(int)$c->fetchColumn(),'document reusable for same INN');
$other=tx($A,'ООО Другой','7700000010');$thrown=false;try{ClosingDocumentService::linkExistingDocument($A,$other,$shared,'agreement',1);}catch(RuntimeException){$thrown=true;}ok($thrown,'cross-INN reuse rejected');
tx($B,'ООО Tenant B','7800000001');same(1,ClosingDocumentService::problemCount($B),'tenant B isolated');same(4,ClosingDocumentService::problemCount($A),'tenant A includes two new incomplete payments but is isolated');
$view=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_documents.php');ok(!str_contains($view,'Выберите контрагента'),'no manual counterparty select');ok(!str_contains($view,'Создать контрагента'),'no create counterparty');ok(str_contains($view,'Исключения по документам'),'exceptions navigation');
$routes=(string)file_get_contents(__DIR__.'/../app/Http/Routes/company_bank_finance.php');ok(str_contains($routes,"/company/finance/closing-documents/exceptions"),'exceptions route');
$nav=(string)file_get_contents(__DIR__.'/../app/Service/NavigationCounterService.php');ok(str_contains($nav,'ClosingDocumentService::problemCount($tenant)'),'menu badge same problem source');
try{$root->exec("DROP DATABASE `$dbA`");$root->exec("DROP DATABASE `$dbB`");}catch(Throwable){}
echo "finance_closing_documents_contract_test: PASS\nclassification_source=bank_counterparty_name\nexception_scope=inn_exact\nfixture_problem_count=4\n";
