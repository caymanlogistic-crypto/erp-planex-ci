<?php
declare(strict_types=1);
// Real application bootstrap/dependency order, deterministic synthetic view data; no DB writes.
require dirname(__DIR__, 2).'/bootstrap/app.php';
require BASE_PATH.'/app/Support/entrypoint_dependencies.php';
$_SERVER['REQUEST_URI']='/company/drivers';
$_SERVER['SCRIPT_NAME']='/index.php';
$_SESSION=['role_code'=>'company_owner','company_id'=>7,'user_id'=>11,'account_id'=>3,'user_name'=>'Анна Петрова'];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(11,7,array_column(\App\Security\PageRegistry::companyPages(),'key')));
$company=['name'=>'ООО «ПЛАНЭКС»','status'=>'active'];
$db=new class([]) extends \App\Core\Database { public function connection(): PDO { throw new RuntimeException('Synthetic fixture has no database'); } };
$pageTitle='Водители';$pageContext='Водители › Компания';
$pageStylesheets=['/assets/css/registry-components.css','/assets/css/drivers-registry.css'];
$pageScripts=['/assets/js/table-reference-select.js','/assets/js/registry-components.js'];
$drivers=[];
$names=['Яковлев Сергей Александрович','Александрова Мария Константиновна','Белов Иван Сергеевич'];
for($i=0;$i<35;$i++)$drivers[]=[
 'id'=>40+$i,'full_name'=>$names[$i%3],
 'main_phone'=>$i===1?null:'+7 900 123-45-67','extra_phones_count'=>$i%3,
 'email'=>$i===1?null:'driver.long.address@example.test',
 'passport_number'=>$i===1?null:'45 12 345678',
 'passport_issued_by'=>$i===1?null:'Отделом Министерства внутренних дел по Центральному району города Санкт-Петербурга УникальноеОкончание',
 'passport_issue_date'=>$i===1?null:'2019-02-03',
 'license_number'=>$i===1?null:'77 12 123456','license_issue_date'=>$i===1?null:'2020-05-06',
 'snils'=>$i===1?null:'123-456-789 01','files_count'=>$i%4,
];
if(($argv[1]??'')==='empty')$drivers=[];
if(($argv[1]??'')==='inactive')$company['status']='blocked';
if(($argv[1]??'')==='error')$dbError='Не удалось подключиться к базе данных компании.';
ob_start();require BASE_PATH.'/app/View/pages/company_drivers.php';$content=(string)ob_get_clean();
require BASE_PATH.'/app/View/layouts/main.php';

