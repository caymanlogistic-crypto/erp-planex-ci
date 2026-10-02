<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/core_runtime.php';
require_once BASE_PATH.'/app/Service/RouteExecutorListPresentation.php';

$company=['status'=>'active'];
$companyId=1;
$localPdo=new class {
    public function prepare(): object { return new class { public function execute(): void {} public function fetchAll(): array { return []; } }; }
};
$responsibleLogists=[];
$responsibleLogistNames=[11=>'Карина Гладких'];
$reviewCards=[
    ['card_id'=>'synthetic-draft','stage'=>'new','updated_at'=>'2026-10-02 10:00:00','contractor_name'=>'ООО Черновик','contractor_phone'=>'+7 900 000-00-01','contractor_email'=>'draft@example.test','driver_name'=>'Водитель Черновик','driver_phone'=>'+7 900 000-00-02','driver_email'=>'driver@example.test','primary_brand'=>'SHACMAN','primary_plate'=>'А101ВС16','primary_capacity'=>'20','primary_volume'=>'86','secondary_brand'=>'ТОНАР','secondary_plate'=>'А121333','secondary_capacity'=>'24','secondary_volume'=>'92','responsible_membership_id'=>11],
    ['card_id'=>'synthetic-mail','stage'=>'partial','updated_at'=>'2026-10-02 09:00:00','contractor_name'=>'','driver_name'=>'Шапкин Владимир','driver_phone'=>'+7 900 000-00-03','primary_brand'=>'ГАЗ','primary_plate'=>'А123АА775','primary_capacity'=>'5','primary_volume'=>'20','responsible_membership_id'=>11],
];
$executors=[['crew_id'=>9,'crew_status'=>'active','contractor_name'=>'ООО Действующий','contractor_phone'=>'+7 900 000-00-04','contractor_email'=>'active@example.test','driver_name'=>'Иванов Иван','driver_phone'=>'+7 900 000-00-05','driver_email'=>'ivanov@example.test','primary_brand'=>'КАМАЗ','primary_plate'=>'А123АА77','primary_capacity'=>'20','primary_volume'=>'86','secondary_brand'=>'ТОНАР','secondary_plate'=>'А1213334','secondary_capacity'=>'24','secondary_volume'=>'92','responsible_name'=>'Карина Гладких']];
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/css/erp-ui.css"><link rel="stylesheet" href="/assets/css/app-base.css"><link rel="stylesheet" href="/assets/css/route-executor-review-workflow.css"><link rel="stylesheet" href="/assets/css/route-executor-header.css">
<style>html,body{margin:0}.test-workspace{box-sizing:border-box;width:calc(100% - 220px);margin-left:220px;padding:12px 16px;min-width:0}.modal-overlay{display:none!important}</style></head><body><main class="test-workspace">
<?php require BASE_PATH.'/app/View/pages/company_route_executors.php'; ?>
</main></body></html>
