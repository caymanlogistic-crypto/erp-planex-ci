<?php

require_once __DIR__.'/../app/Service/WarehouseStockService.php';

use App\Service\WarehouseStockService;

$warehouses = [['id'=>2,'name'=>'НОВОСИБИРСК']];
$flights = [
    ['id'=>273,'comment'=>'Первый приход','destination_warehouse_id'=>2,'zayavki_ids'=>'310252'],
    ['id'=>298,'comment'=>'Повторный приход','destination_warehouse_id'=>2,'zayavki_ids'=>'310252'],
    ['id'=>351,'comment'=>'Списание','source_warehouse_id'=>2,'zayavki_ids'=>'310252'],
];
$movements = [
    ['id'=>3,'movement_type'=>'receipt','warehouse_id'=>2,'flight_id'=>298,'zayavka_id'=>310252,'mass_netto'=>'0.605','movement_date'=>'2026-10-02','created_at'=>'2026-10-02 12:00:00','status'=>'active'],
    ['id'=>1,'movement_type'=>'receipt','warehouse_id'=>2,'flight_id'=>273,'zayavka_id'=>310252,'mass_netto'=>'0.605','movement_date'=>'2026-10-01','created_at'=>'2026-10-01 12:00:00','status'=>'active'],
    ['id'=>4,'movement_type'=>'issue','warehouse_id'=>2,'flight_id'=>351,'zayavka_id'=>310252,'mass_netto'=>'0.605','movement_date'=>'2026-10-03','created_at'=>'2026-10-03 12:00:00','status'=>'active'],
];
$erp = [
    ['business_id'=>273,'legacy_destination_warehouse_id'=>2,'zayavka_id'=>310252,'snapshot_mass_kg'=>605],
    ['business_id'=>298,'legacy_destination_warehouse_id'=>2,'zayavka_id'=>310252,'snapshot_mass_kg'=>605],
    ['business_id'=>351,'legacy_source_warehouse_id'=>2,'zayavka_id'=>310252,'snapshot_mass_kg'=>605],
];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if ($data['current'][2] !== ['request_count'=>1,'weight_kg'=>605,'request_ids'=>[310252]]) {
    throw new RuntimeException('Проверка повтора изменила складской остаток.');
}
if ($data['issue_count'] !== 1 || !isset($data['issues'][298])
    || !str_contains(implode(' ', $data['issues'][298]['messages']), 'Ранее она уже поступила в рейсе #273; повторно проведена в рейсе #298')) {
    throw new RuntimeException('Повторный приход не попал в расхождения с правильным порядком рейсов.');
}
foreach ($data['groups'][2] as $group) {
    $expected = in_array($group['flight_id'], [273,298], true) ? ['310252'] : [];
    if ($group['duplicate_request_ids'] !== $expected) {
        throw new RuntimeException('Метка повтора должна быть на обоих приходах и отсутствовать на списании.');
    }
}
$returned = $movements;
$returned[2]['movement_date'] = '2026-10-01';
$returned[2]['created_at'] = '2026-10-01 18:00:00';
$normalReturn = WarehouseStockService::build($warehouses, $returned, $flights, $erp);
if ($normalReturn['duplicate_receipts'] !== [] || isset($normalReturn['issues'][298])) {
    throw new RuntimeException('Повторный приход после полного списания ошибочно помечен как дубль.');
}
echo "warehouse stock duplicate receipt OK\n";
