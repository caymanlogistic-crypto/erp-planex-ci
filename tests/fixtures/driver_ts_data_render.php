<?php
declare(strict_types=1);
$config=require dirname(__DIR__,2).'/bootstrap/app.php';
require BASE_PATH.'/app/Support/entrypoint_dependencies.php';
require_once BASE_PATH.'/app/Support/core_runtime.php';
require_once BASE_PATH.'/app/Service/RouteExecutorListPresentation.php';
$_SERVER['REQUEST_URI']='/company/driver-ts-data';$_SERVER['SCRIPT_NAME']='/index.php';
$_SESSION=['company_id'=>1,'role_code'=>'company_owner','account_id'=>3,'user_id'=>11,'membership_id'=>11];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(11,1,array_column(\App\Security\PageRegistry::companyPages(),'key')));
$company=['id'=>1,'name'=>'Тестовая компания','status'=>'active'];$error=null;
$rows=[
 ['crew_id'=>9,'created_at'=>'2026-10-27 12:00:00','driver_name'=>'Иванов Иван','driver_phone'=>'+7 900 000-00-05','driver_email'=>'ivanov@example.test','primary_brand'=>'КАМАЗ','primary_plate'=>'А123АА77','primary_capacity'=>20,'primary_volume'=>86,'secondary_brand'=>'ТОНАР','secondary_plate'=>'А1213334','secondary_capacity'=>24,'secondary_volume'=>92,'responsible_name'=>'Гладких Карина Артуровна','trips'=>[345,346,347,348]],
 ['crew_id'=>12,'created_at'=>'2026-10-25 12:00:00','driver_name'=>'Петрова Мария','driver_phone'=>'+7 900 000-00-16','driver_email'=>'petrova@example.test','primary_brand'=>'ГАЗОН NEXT','primary_plate'=>'М814КК16','primary_capacity'=>5,'primary_volume'=>22,'secondary_brand'=>null,'secondary_plate'=>null,'secondary_capacity'=>null,'secondary_volume'=>null,'responsible_name'=>'Владимир С.','trips'=>[351]],
];
for ($i=20;$i<=54;$i++) $rows[]=['crew_id'=>$i,'created_at'=>'2026-10-20 12:00:00','driver_name'=>'Тестовый водитель '.$i,'driver_phone'=>'+7 900 000-'.sprintf('%02d',$i),'driver_email'=>'driver'.$i.'@example.test','primary_brand'=>'КАМАЗ','primary_plate'=>'А'.str_pad((string)$i,3,'0',STR_PAD_LEFT).'ВС77','primary_capacity'=>20,'primary_volume'=>86,'secondary_brand'=>null,'secondary_plate'=>null,'secondary_capacity'=>null,'secondary_volume'=>null,'responsible_name'=>'Гладких Карина Артуровна','trips'=>[400+$i]];
if (($argv[1]??'')==='detail') {
 $detail=['crew_id'=>9,'trips'=>[345,346,347,348],'files'=>[['id'=>1,'document_type'=>'Паспорт водителя','original_name'=>'passport.pdf']]];
 $sections=['driver'=>[['ФИО','Иванов Иван'],['Телефон','+7 900 000-00-05'],['Email','ivanov@example.test']], 'vehicle'=>[['Марка','КАМАЗ'],['Госномер','А123АА77']]];
 require BASE_PATH.'/app/View/partials/driver_ts_data_detail.php';return;
}
$db=new class([]) extends \App\Core\Database { public function connection(): PDO { throw new RuntimeException('Synthetic visual fixture has no database'); } };
$pageTitle='Данные Вод и ТС';$pageContext='Данные Вод и ТС › Компания';
$pageStylesheets=['/assets/css/route-executor-list-v016.css','/assets/css/registry-components.css'];
$pageScripts=['/assets/js/driver-ts-data.js','/assets/js/table-reference-select.js','/assets/js/registry-components.js'];
ob_start();require BASE_PATH.'/app/View/pages/company_driver_ts_data.php';$content=(string)ob_get_clean();
require BASE_PATH.'/app/View/layouts/main.php';
