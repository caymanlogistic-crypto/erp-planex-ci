<?php
declare(strict_types=1);
$config = require dirname(__DIR__, 2) . '/bootstrap/app.php';
require BASE_PATH . '/app/Support/entrypoint_dependencies.php';
$kind = $argv[1] ?? 'transfers';
$state = $argv[2] ?? 'populated';
if (!in_array($kind, ['transfers', 'weights'], true)) throw new InvalidArgumentException('Unknown history fixture');
$_SERVER['REQUEST_URI'] = '/company/feo/requests/' . $kind;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SESSION = ['role_code'=>'company_owner','company_id'=>7,'user_id'=>11,'account_id'=>3,'user_name'=>'Тестовый пользователь','membership_id'=>11];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(11,7,array_column(\App\Security\PageRegistry::companyPages(),'key')));
$company = ['id'=>7,'name'=>'ООО «ПЛАНЭКС»','status'=>'active'];
$db = new class([]) extends \App\Core\Database { public function connection(): PDO { throw new RuntimeException('Fixture has no database'); } };
$page = 1; $dbError = null; $isOwner = true; $keyConfigured = true; $coordinatesOnly = false;
$pageTitle = 'Перемещение заявок';
$pageContext = 'Заявки ФЭО › ' . ($kind === 'transfers' ? 'Перемещение заявок' : 'Изменение веса заявок');
$pageStylesheets = ['/assets/css/registry-components.css'];
$pageScripts = ['/assets/js/registry-components.js'];
$batches = [
 ['id'=>18,'name'=>'Перемещение на склад','destination_json'=>json_encode(['address'=>'Москва, улица Примерная, дом 12, корпус 1','quality'=>'Только улица, дом не определён']),'created_at'=>'2026-10-18 15:30:00','request_count'=>12,'created_by_name'=>'Владимир С.','status'=>'applied'],
 ['id'=>17,'name'=>'Корректировка адреса для клиента','destination_json'=>json_encode(['address'=>'Санкт-Петербург, Невский проспект, 10','quality'=>'Точный адрес']),'created_at'=>'2026-10-17 13:20:00','request_count'=>2,'created_by_name'=>'Антон Ш.','status'=>'reverted'],
];
for ($i=0;$i<70;$i++) $batches[] = ['id'=>100+$i,'name'=>($i===0?'Очень длинное название блока изменения заявок, проверяющее сохранение полного текста и обрезку в таблице':('Проверочный блок '.$i)),'destination_json'=>json_encode(['address'=>'Складская улица, '.$i,'quality'=>'Точный адрес']),'created_at'=>'2026-10-16 11:30:00','request_count'=>1+$i%9,'created_by_name'=>'Иван К.','status'=>$i%8===0?'reverted':'applied'];
if ($state==='empty') $batches=[];
ob_start();
require BASE_PATH . '/app/View/pages/' . ($kind==='transfers'?'company_feo_transfers.php':'company_feo_weight_batches.php');
$content = (string)ob_get_clean();
require BASE_PATH . '/app/View/layouts/main.php';
