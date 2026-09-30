<?php

require_once __DIR__.'/../app/Service/WarehouseStockService.php';

use App\Service\WarehouseStockService;

$warehouses = [['id'=>1,'name'=>'Краснодар'], ['id'=>2,'name'=>'Иркутск']];
$flights = [
    ['id'=>10,'comment'=>'Прибытие','source_warehouse_id'=>null,'destination_warehouse_id'=>1,'zayavki_ids'=>'123, 456','driver_name'=>'Иванов','vehicle'=>'А 123 АА'],
    ['id'=>11,'comment'=>'Вывоз','source_warehouse_id'=>1,'destination_warehouse_id'=>null,'zayavki_ids'=>'123','driver_name'=>'Петров','vehicle'=>'В 456 ВВ'],
];
$movements = [
    ['id'=>1,'movement_type'=>'receipt','warehouse_id'=>1,'flight_id'=>10,'zayavka_id'=>123,'mass_netto'=>'1.500','movement_date'=>'2026-09-01','status'=>'active'],
    ['id'=>2,'movement_type'=>'receipt','warehouse_id'=>1,'flight_id'=>10,'zayavka_id'=>456,'mass_netto'=>'0.500','movement_date'=>'2026-09-01','status'=>'active'],
    ['id'=>3,'movement_type'=>'issue','warehouse_id'=>1,'flight_id'=>11,'zayavka_id'=>123,'mass_netto'=>'1.500','movement_date'=>'2026-09-02','status'=>'active'],
];
$erp = [
    ['business_id'=>10,'legacy_source_warehouse_id'=>null,'legacy_destination_warehouse_id'=>1,'zayavka_id'=>123,'snapshot_mass_kg'=>1500],
    ['business_id'=>10,'legacy_source_warehouse_id'=>null,'legacy_destination_warehouse_id'=>1,'zayavka_id'=>456,'snapshot_mass_kg'=>500],
    ['business_id'=>11,'legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>123,'snapshot_mass_kg'=>1500],
];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['current'][1] !== ['request_count'=>1,'weight_kg'=>500]) throw new RuntimeException('Неверный текущий остаток.');
if ($data['groups'][1][0]['stock_count'] !== 1 || $data['groups'][1][0]['stock_kg'] !== 500) throw new RuntimeException('Неверный остаток после вывоза.');
if ($data['groups'][1][1]['stock_count'] !== 2 || $data['groups'][1][1]['stock_kg'] !== 2000) throw new RuntimeException('Неверный остаток после прихода.');
if ($data['issue_count'] !== 0) throw new RuntimeException('Ложное расхождение.');
$flights[1]['zayavki_ids'] = '123,456';
$erp[] = ['business_id'=>11,'legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>456,'snapshot_mass_kg'=>500];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['groups'][1][0]['display_request_ids'] !== ['123'] || $data['groups'][1][0]['skipped_request_ids'] !== []) throw new RuntimeException('Показана заявка вне складского движения.');
if ($data['groups'][1][0]['request_count'] !== 1 || $data['groups'][1][0]['mass_kg'] !== 1500 || $data['issue_count'] !== 0) throw new RuntimeException('Несписанная заявка изменила складской итог или сверку.');
$erp[0]['legacy_destination_warehouse_id'] = 2;
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['issue_count'] !== 1 || !isset($data['issues'][10])) throw new RuntimeException('Не обнаружено расхождение складов.');
$erp[] = ['business_id'=>12,'legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>999,'snapshot_mass_kg'=>100];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['issue_count'] !== 2 || !isset($data['issues'][12])) throw new RuntimeException('Не обнаружен лишний складской рейс ERP.');

$flights[] = ['id'=>13,'comment'=>'Начался','status'=>'started','source_warehouse_id'=>1,'destination_warehouse_id'=>null,'actual_start_date'=>'2026-09-03','zayavki_ids'=>'456,999','driver_name'=>'Петров','vehicle'=>'В 456 ВВ'];
$erp[] = ['business_id'=>13,'status'=>'in_progress','actual_start_date'=>'2026-09-03','legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>456,'snapshot_mass_kg'=>500];
$erp[] = ['business_id'=>13,'status'=>'in_progress','actual_start_date'=>'2026-09-03','legacy_source_warehouse_id'=>1,'legacy_destination_warehouse_id'=>null,'zayavka_id'=>999,'snapshot_mass_kg'=>100];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['current'][1] !== ['request_count'=>0,'weight_kg'=>0]) throw new RuntimeException('Начатый рейс не списал складской остаток.');
if ($data['groups'][1][0]['request_ids'] !== ['456'] || !$data['groups'][1][0]['is_derived']) throw new RuntimeException('Рейс должен показывать только списанную складскую заявку.');
$flights[2]['status'] = 'completed';
$erp[5]['status'] = 'completed';
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['current'][1]['weight_kg'] !== 0) throw new RuntimeException('Завершённый рейс повторно списал груз.');
$movements[] = ['id'=>4,'movement_type'=>'issue','warehouse_id'=>1,'flight_id'=>13,'zayavka_id'=>456,'mass_netto'=>'0.500','movement_date'=>'2026-09-03','status'=>'active'];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['current'][1]['weight_kg'] !== 0 || $data['groups'][1][0]['is_derived']) throw new RuntimeException('Дублируется реальное списание складского журнала.');
echo "warehouse stock service OK\n";
