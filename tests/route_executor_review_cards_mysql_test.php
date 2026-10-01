<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Service/RouteExecutorReviewPackage.php';
require_once BASE_PATH.'/app/Service/RouteExecutorReviewCardService.php';

use App\Service\RouteExecutorReviewCardService;

$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV')!=='test' || $database==='' || !in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) {
    throw new RuntimeException('Guarded disposable test database required');
}
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$assert=static function(bool $value,string $message):void { if (!$value) throw new RuntimeException($message); };
$pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/151_route_executor_review_sends.sql'));
$pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/154_route_executor_review_cards.sql'));
$cards=new RouteExecutorReviewCardService($pdo,1);
$id='11111111-1111-4111-8111-111111111111';
$snapshot=['controls'=>[
    ['formId'=>'lab-contractor-form','name'=>'name','value'=>'ИП Тестовый','type'=>'text'],
    ['formId'=>'lab-contractor-form','name'=>'inn','value'=>'123456789012','type'=>'text'],
    ['formId'=>'lab-driver-form','name'=>'full_name','value'=>'Водитель Тестовый','type'=>'text'],
], 'logist'=>'12','trip_id'=>'123','trip_label'=>'#123 Тестовый рейс'];
$assert($cards->ready(),'Migration 154 creates shared cards table');
$assert($cards->save($id,101,0,$snapshot,[],[])===1,'First shared draft saved');
$list=$cards->listing();
$assert(count($list)===1 && $list[0]['contractor_name']==='ИП Тестовый','Draft visible in shared list');
$loaded=$cards->load($id);
$assert($loaded!==null && $loaded['snapshot']['trip_id']==='123' && (int)$loaded['revision']===1,'Shared card loads for another employee');
$snapshot['controls'][0]['value']='ИП Обновлённый';
$assert($cards->save($id,202,1,$snapshot,[],[])===2,'Another employee can update the card');
try { $cards->save($id,101,1,$snapshot,[],[]); throw new RuntimeException('Stale revision was accepted'); }
catch (DomainException $expected) { $assert(str_contains($expected->getMessage(),'другим сотрудником'),'Stale revision rejected'); }
$pdo->prepare("INSERT INTO route_executor_review_sends(card_id,actor_account_id,stage,partial_mail_status) VALUES(?,?,'partial','accepted')")->execute([$id,101]);
$cards->delete($id);
$assert($cards->listing()===[] && $cards->load($id)===null,'Deleted card disappears for all users');
$assert((int)$pdo->query('SELECT COUNT(*) FROM route_executor_review_sends')->fetchColumn()===1,'Sent email log survives deletion');
echo "route_executor_review_cards_mysql_test: PASS\n";
