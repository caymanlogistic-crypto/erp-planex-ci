<?php

require_once __DIR__.'/../app/Service/WarehouseStockService.php';

use App\Service\WarehouseStockService;

$warehouse = [['id'=>1,'name'=>'Склад']];
$flights = [
    ['id'=>200,'route_type'=>'generator_to_warehouse','destination_warehouse_id'=>1,'zayavki_ids'=>'310252'],
    ['id'=>349,'route_type'=>'generator_to_warehouse','destination_warehouse_id'=>1,'zayavki_ids'=>'310867'],
    ['id'=>353,'route_type'=>'mixed_to_utilizer','source_warehouse_id'=>1,
        'status'=>'completed','zayavki_ids'=>'312015,310867,322245,321828,321391',
        'direct_zayavki_ids'=>'312015'],
    ['id'=>356,'route_type'=>'generator_to_warehouse','destination_warehouse_id'=>1,
        'zayavki_ids'=>'322245,321828,321391'],
];
$movements = [
    ['id'=>1,'movement_type'=>'receipt','warehouse_id'=>1,'flight_id'=>200,'zayavka_id'=>310252,
        'mass_netto'=>'0.605','movement_date'=>'2026-10-01','created_at'=>'2026-10-01 10:00:00','status'=>'active'],
];
$masses = ['310867'=>'1.000','322245'=>'0.500','321828'=>'0.600','321391'=>'0.586'];
$erp = [
    ['business_id'=>349,'legacy_destination_warehouse_id'=>1,
        'zayavka_id'=>310867,'snapshot_mass_kg'=>1000],
    ['business_id'=>353,'status'=>'completed','legacy_source_warehouse_id'=>1,
        'zayavka_id'=>312015,'snapshot_mass_kg'=>400],
];
$id = 2;
foreach ($masses as $requestId => $mass) {
    $receiptFlightId = $requestId === '310867' ? 349 : 356;
    $movements[] = ['id'=>$id++,'movement_type'=>'receipt','warehouse_id'=>1,'flight_id'=>$receiptFlightId,
        'zayavka_id'=>$requestId,'mass_netto'=>$mass,
        'movement_date'=>$receiptFlightId === 349 ? '2026-10-14' : '2026-10-16',
        'created_at'=>$receiptFlightId === 349 ? '2026-10-14 12:00:00' : '2026-10-16 00:25:36','status'=>'active'];
    if ($receiptFlightId === 356) {
        $erp[] = ['business_id'=>356,'legacy_destination_warehouse_id'=>1,
            'zayavka_id'=>$requestId,'snapshot_mass_kg'=>(int)round((float)$mass * 1000)];
    }
}
foreach ($masses as $requestId => $mass) {
    $movements[] = ['id'=>$id++,'movement_type'=>'issue','warehouse_id'=>1,'flight_id'=>353,
        'zayavka_id'=>$requestId,'mass_netto'=>$mass,'movement_date'=>'2026-10-15',
        'created_at'=>'2026-10-16 00:25:55','status'=>'active'];
}
$result = WarehouseStockService::build($warehouse, $movements, $flights, $erp);
if ($result['current'][1] !== ['request_count'=>1,'weight_kg'=>605,'request_ids'=>[310252]]) {
    throw new RuntimeException('Складская группа смешанного рейса не списана после фактического прихода.');
}
$issueGroup = null;
foreach ($result['groups'][1] as $group) {
    if ($group['flight_id'] === 353) $issueGroup = $group;
}
if ($issueGroup === null || $issueGroup['request_count'] !== 4 || $issueGroup['mass_kg'] !== 2686
    || $issueGroup['stock_count'] !== 1 || $issueGroup['stock_kg'] !== 605
    || $issueGroup['warehouse_only_request_ids'] !== [] || $issueGroup['unmoved_request_ids'] !== []) {
    throw new RuntimeException('Рейс #353 неверно показывает списание, остаток или расхождение.');
}
if (isset($result['issues'][353])) {
    throw new RuntimeException('Складская группа смешанного рейса создаёт ложное расхождение.');
}
$withDirectStock = $movements;
$withDirectStock[] = ['id'=>$id,'movement_type'=>'receipt','warehouse_id'=>1,'flight_id'=>200,
    'zayavka_id'=>312015,'mass_netto'=>'0.400','movement_date'=>'2026-10-01',
    'created_at'=>'2026-10-01 10:00:01','status'=>'active'];
$directResult = WarehouseStockService::build($warehouse, $withDirectStock, $flights, $erp);
if ($directResult['current'][1]['request_ids'] !== [310252,312015]
    || $directResult['current'][1]['weight_kg'] !== 1005) {
    throw new RuntimeException('Прямая заявка смешанного рейса ошибочно списана со склада.');
}
$reactivated = $movements;
foreach ($reactivated as &$movement) {
    if ($movement['flight_id'] !== 353) continue;
    $movement['created_at'] = '2026-10-15 00:00:00';
    $movement['updated_at'] = '2026-10-16 00:25:55';
    $movement['comment'] = 'Исправление складского направления; повторно проведено после отмены';
}
unset($movement);
$reactivatedResult = WarehouseStockService::build($warehouse, $reactivated, $flights, $erp);
foreach ($reactivatedResult['groups'][1] as $group) {
    if ($group['flight_id'] === 353 && $group['stock_kg'] !== 605) {
        throw new RuntimeException('Повторно проведённое движение упорядочено по старому created_at.');
    }
}
echo "warehouse stock mixed order OK\n";
