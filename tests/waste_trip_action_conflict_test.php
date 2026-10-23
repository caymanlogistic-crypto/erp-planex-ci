<?php
declare(strict_types=1);

require_once __DIR__.'/../app/Service/WarehouseStockService.php';
require_once __DIR__.'/../app/Service/WasteTripActionConflictService.php';

use App\Service\WarehouseStockService;
use App\Service\WasteTripActionConflictService;

$flights = [
    ['id'=>501,'status'=>'completed','route_type'=>'generator_to_warehouse','source_warehouse_id'=>null,'destination_warehouse_id'=>2,'zayavki_ids'=>'1001','direct_zayavki_ids'=>''],
    ['id'=>502,'status'=>'started','route_type'=>'warehouse_to_utilizer','source_warehouse_id'=>2,'destination_warehouse_id'=>null,'zayavki_ids'=>'1001','direct_zayavki_ids'=>''],
    ['id'=>503,'status'=>'started','route_type'=>'generator_to_utilizer','source_warehouse_id'=>null,'destination_warehouse_id'=>null,'zayavki_ids'=>'1002','direct_zayavki_ids'=>''],
    ['id'=>504,'status'=>'completed','route_type'=>'generator_to_utilizer','source_warehouse_id'=>null,'destination_warehouse_id'=>null,'zayavki_ids'=>'1002','direct_zayavki_ids'=>''],
    ['id'=>505,'status'=>'started','route_type'=>'warehouse_to_utilizer','source_warehouse_id'=>2,'destination_warehouse_id'=>null,'zayavki_ids'=>'1001','direct_zayavki_ids'=>''],
    ['id'=>506,'status'=>'completed','route_type'=>'generator_to_warehouse','source_warehouse_id'=>null,'destination_warehouse_id'=>2,'zayavki_ids'=>'1003','direct_zayavki_ids'=>''],
    ['id'=>507,'status'=>'completed','route_type'=>'generator_to_warehouse','source_warehouse_id'=>null,'destination_warehouse_id'=>2,'zayavki_ids'=>'1003','direct_zayavki_ids'=>''],
    ['id'=>508,'status'=>'planned_route','route_type'=>'generator_to_utilizer','source_warehouse_id'=>null,'destination_warehouse_id'=>null,'zayavki_ids'=>'1002','direct_zayavki_ids'=>''],
    ['id'=>509,'status'=>'started','route_type'=>'generator_to_warehouse','source_warehouse_id'=>null,'destination_warehouse_id'=>2,'zayavki_ids'=>'1004','direct_zayavki_ids'=>''],
    ['id'=>510,'status'=>'started','route_type'=>'generator_to_warehouse','source_warehouse_id'=>null,'destination_warehouse_id'=>2,'zayavki_ids'=>'1004','direct_zayavki_ids'=>''],
    ['id'=>511,'status'=>'started','route_type'=>'mixed_to_utilizer','source_warehouse_id'=>2,'destination_warehouse_id'=>null,'zayavki_ids'=>'1001,1005','direct_zayavki_ids'=>'1005'],
];
$movements = [
    ['id'=>1,'movement_type'=>'receipt','warehouse_id'=>2,'flight_id'=>501,'zayavka_id'=>'1001','mass_netto'=>'1.000','movement_date'=>'2026-10-20','created_at'=>'2026-10-20 10:00:00','status'=>'active'],
    ['id'=>2,'movement_type'=>'issue','warehouse_id'=>2,'flight_id'=>502,'zayavka_id'=>'1001','mass_netto'=>'1.000','movement_date'=>'2026-10-21','created_at'=>'2026-10-21 10:00:00','status'=>'active'],
    ['id'=>3,'movement_type'=>'receipt','warehouse_id'=>2,'flight_id'=>506,'zayavka_id'=>'1003','mass_netto'=>'1.000','movement_date'=>'2026-10-20','created_at'=>'2026-10-20 11:00:00','status'=>'active'],
    ['id'=>4,'movement_type'=>'receipt','warehouse_id'=>2,'flight_id'=>507,'zayavka_id'=>'1003','mass_netto'=>'1.000','movement_date'=>'2026-10-21','created_at'=>'2026-10-21 11:00:00','status'=>'active'],
];
$erp = [];
foreach ($flights as $flight) {
    foreach (preg_split('/[\s,;]+/', $flight['zayavki_ids'], -1, PREG_SPLIT_NO_EMPTY) as $requestId) {
        $erp[] = ['business_id'=>$flight['id'],'status'=>match ($flight['status']) {
            'completed'=>'completed','started'=>'in_progress',default=>'planned',
        },'legacy_source_warehouse_id'=>$flight['source_warehouse_id'],
            'legacy_destination_warehouse_id'=>$flight['destination_warehouse_id'],
            'zayavka_id'=>$requestId,'snapshot_mass_kg'=>1000];
    }
}
$stock = WarehouseStockService::build([['id'=>2,'name'=>'Склад']],$movements,$flights,$erp);
$issues = WasteTripActionConflictService::analyze($flights,$stock);
$has = static fn(int $id,string $needle): bool => str_contains(implode(' ', $issues[$id] ?? []), $needle);
if (isset($issues[501]) || isset($issues[506]) || !$has(502,'Возможная повторная сдача утилизатору заявки 1001')
    || !$has(505,'не подтверждено списание со склада #2')
    || !$has(503,'Возможный повторный забор заявки 1002')
    || !$has(504,'Возможная повторная сдача утилизатору заявки 1002')
    || !$has(509,'Возможная повторная сдача на склад #2 заявки 1004')
    || !$has(511,'Заявка 1001: не подтверждено списание со склада #2')
    || $has(511,'Заявка 1005: не подтверждено списание')
    || !$has(507,'повторное поступление на склад #2') || isset($issues[508])) {
    throw new RuntimeException('Trip action diagnostics differ from warehouse stock and active legs. '.json_encode($issues,JSON_UNESCAPED_UNICODE));
}

$stock['issues'][507]['messages'] = [];
$excepted = WasteTripActionConflictService::analyze($flights,$stock);
if (isset($excepted[507])) {
    throw new RuntimeException('Approved warehouse issue appeared again in trip diagnostics.');
}
echo "WASTE_TRIP_ACTION_CONFLICT=PASS\n";
