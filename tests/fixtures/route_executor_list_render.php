<?php
declare(strict_types=1);

$config=require dirname(__DIR__, 2).'/bootstrap/app.php';
require BASE_PATH.'/app/Support/entrypoint_dependencies.php';
spl_autoload_register(static function(string $class): void { if(str_starts_with($class,'App\\')){ $file=BASE_PATH.'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($file))require_once $file; } });
require_once BASE_PATH.'/app/Support/core_runtime.php';
require_once BASE_PATH.'/app/Service/RouteExecutorListPresentation.php';

$company=['status'=>'active'];
$companyId=1;
$localPdo=new class {
    public function prepare(): object { return new class { public function execute(): void {} public function fetchAll(): array { return []; } }; }
};
$responsibleLogists=[];
$responsibleLogistNames=[11=>'Гладких Карина Артуровна',12=>'Владимир С.'];
$reviewCards=[
    ['card_id'=>'synthetic-draft','stage'=>'new','updated_at'=>'2026-10-02 10:00:00','contractor_name'=>'ООО Черновик','contractor_phone'=>'+7 900 000-00-01','contractor_email'=>'draft@example.test','driver_name'=>'Водитель Черновик','driver_phone'=>'+7 900 000-00-02','driver_email'=>'driver@example.test','primary_brand'=>'SHACMAN','primary_plate'=>'А101ВС16','primary_capacity'=>'20','primary_volume'=>'86','secondary_brand'=>'ТОНАР','secondary_plate'=>'А121333','secondary_capacity'=>'24','secondary_volume'=>'92','responsible_membership_id'=>11],
    ['card_id'=>'synthetic-mail','stage'=>'partial','updated_at'=>'2026-10-02 09:00:00','contractor_name'=>'','driver_name'=>'Шапкин Владимир','driver_phone'=>'+7 900 000-00-03','primary_brand'=>'ГАЗ','primary_plate'=>'А123АА775','primary_capacity'=>'5','primary_volume'=>'20','responsible_membership_id'=>12],
];
if (($argv[1]??'')==='final-edit') {
    $reviewCards[0]['stage']='final_edit';
}
$executors=[['crew_id'=>9,'crew_status'=>'active','contractor_name'=>'ООО Действующий','contractor_phone'=>'+7 900 000-00-04','contractor_email'=>'active@example.test','driver_name'=>'Иванов Иван','driver_phone'=>'+7 900 000-00-05','driver_email'=>'ivanov@example.test','primary_brand'=>'КАМАЗ','primary_plate'=>'А123АА77','primary_capacity'=>'20','primary_volume'=>'86','secondary_brand'=>'ТОНАР','secondary_plate'=>'А1213334','secondary_capacity'=>'24','secondary_volume'=>'92','responsible_name'=>'GTP-Бухгалтерия']];
$_SERVER['REQUEST_URI']='/company/route-executors';
$_SERVER['SCRIPT_NAME']='/index.php';
$_SESSION=['company_id'=>1,'role_code'=>'company_owner','account_id'=>3,'user_id'=>11,'membership_id'=>11];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(11,1,array_column(\App\Security\PageRegistry::companyPages(),'key')));
$db=new class([]) extends \App\Core\Database { public function connection(): PDO { throw new RuntimeException('Synthetic visual fixture has no database'); } };
$pageTitle='Исполнители рейса';$pageContext='Исполнители рейса › Компания';
$pageStylesheets=['/assets/css/route-executor-review-workflow.css','/assets/css/route-executor-list-v016.css','/assets/css/registry-components.css'];
$pageScripts=['/assets/js/table-reference-select.js','/assets/js/registry-components.js'];
ob_start();require BASE_PATH.'/app/View/pages/company_route_executors.php';$content=(string)ob_get_clean();
require BASE_PATH.'/app/View/layouts/main.php';
