<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 2).'/bootstrap/app.php';
require BASE_PATH.'/app/Support/entrypoint_dependencies.php';
$role = $argv[1] ?? 'owner';
if (!in_array($role, ['owner', 'logist'], true)) throw new InvalidArgumentException('Unknown role');
$_SERVER['REQUEST_URI'] = '/company/warehouses/addresses';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SESSION = ['role_code'=>$role === 'owner' ? 'company_owner' : 'logist', 'company_id'=>7,
    'user_id'=>11, 'account_id'=>3, 'user_name'=>'Тестовый пользователь', 'membership_id'=>$role === 'owner' ? 11 : 12];
App\Security\AccessRuntime::setCurrent($role === 'owner'
    ? App\Security\EffectiveAccess::systemOwner(11, 7, array_column(App\Security\PageRegistry::companyPages(), 'key'))
    : App\Security\EffectiveAccess::member(12, 7, ['company.warehouses.addresses'=>true], [], 'logist'));
$company = ['id'=>7, 'name'=>'ООО «ПЛАНЭКС»', 'status'=>'active'];
$db = new class([]) extends App\Core\Database { public function connection(): PDO { throw new RuntimeException('Fixture has no database'); } };
$pageTitle = 'Адреса складов';
$pageContext = 'Склады › Адреса складов';
$dbError = $successFlash = $errorFlash = null;
$isOwner = $role === 'owner';
$warehouses = [
    ['id'=>7, 'name'=>'Склад Север', 'address'=>'Москва, Складская улица, 1, корпус 2', 'contacts'=>[
        ['name'=>'Иванов Иван','position'=>'Заведующий','phone'=>'+7 900 000-00-01','email'=>'ivanov@example.test'],
        ['name'=>'Петров Пётр','position'=>'Приёмщик','phone'=>'+7 900 000-00-02','email'=>'petrov@example.test']]],
    ['id'=>8, 'name'=>'Склад Юг', 'address'=>'Тула, Южная улица, 2', 'contacts'=>[]],
];
for ($id=9; $id<58; $id++) $warehouses[] = ['id'=>$id, 'name'=>'Склад '.$id,
    'address'=>'Регион, Промышленная улица, '.$id, 'contacts'=>[]];
$warehouses[] = ['id'=>58, 'name'=>'Склад без адреса', 'address'=>'', 'contacts'=>[]];
foreach ($warehouses as &$warehouse) {
    $warehouse += ['latitude'=>null, 'longitude'=>null, 'request_count'=>0, 'weight_kg'=>0];
}
unset($warehouse);
$warehouses[0]['latitude'] = '55.75';
$warehouses[0]['longitude'] = '37.62';
$warehouses[0]['request_count'] = 2;
$warehouses[0]['weight_kg'] = 3750;
ob_start();
require BASE_PATH.'/app/View/pages/company_warehouse_directory.php';
$content = (string)ob_get_clean();
require BASE_PATH.'/app/View/layouts/main.php';
