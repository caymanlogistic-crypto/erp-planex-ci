<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Service/RouteExecutorContractMailService.php';

use App\Service\RouteExecutorContractMailService;

$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV')!=='test' || $database==='' || !in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) {
    throw new RuntimeException('Guarded disposable test database required');
}
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/155_route_executor_contract_mail.sql'));
$check=static function(bool $condition,string $message):void {if (!$condition) throw new RuntimeException($message);};
$mail=new RouteExecutorContractMailService($pdo);
$check(RouteExecutorContractMailService::destination('mg_logistic')===['login'=>'ashibanov','mail_entity'=>'mg_log'],'MG route goes to Shibanov');
$check(RouteExecutorContractMailService::destination('planex')===['login'=>'spugov','mail_entity'=>'planex'],'PLANEX route goes to Spugov');
try { RouteExecutorContractMailService::destination('');throw new RuntimeException('Unknown entity was accepted'); }
catch (DomainException) {}
$id='11111111-1111-4111-8111-111111111111';
$check($mail->reserve($id,3,285,'contract@example.test','<mail-1@example.test>'),'First contract mail reserved');
$check(!$mail->reserve($id,3,285,'contract@example.test','<mail-2@example.test>'),'Concurrent repeat blocked');
$mail->finish($id,'<mail-1@example.test>','accepted');
$check(!$mail->reserve($id,4,285,'other@example.test','<mail-3@example.test>'),'Accepted contract mail never automatically repeats');
$check($mail->status($id)==='accepted','Accepted result persisted');
$retryId='22222222-2222-4222-8222-222222222222';
$check($mail->reserve($retryId,3,286,'contract@example.test','<mail-4@example.test>'),'Second card reserved');
$mail->finish($retryId,'<mail-4@example.test>','failed');
$check($mail->reserve($retryId,3,286,'contract@example.test','<mail-5@example.test>'),'Confirmed failure can retry');
$mail->finish($retryId,'<mail-5@example.test>','unknown');
$check(!$mail->reserve($retryId,3,286,'contract@example.test','<mail-6@example.test>'),'Unknown SMTP result does not retry');
$check((int)$pdo->query('SELECT COUNT(*) FROM route_executor_contract_mails')->fetchColumn()===2,'One row per card');
echo "route_executor_contract_mail_mysql_test: PASS\n";
