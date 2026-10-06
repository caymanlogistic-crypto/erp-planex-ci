<?php

require_once __DIR__.'/../app/Service/WarehouseStockService.php';

use App\Service\WarehouseStockService;

$warehouses = [['id'=>9,'name'=>'КРАСНОЯРСК']];
$flights = [
    ['id'=>225,'destination_warehouse_id'=>9,'zayavki_ids'=>'1'],
    ['id'=>226,'destination_warehouse_id'=>9,'zayavki_ids'=>'2'],
    ['id'=>286,'destination_warehouse_id'=>9,'zayavki_ids'=>'311706,295036,296555'],
    ['id'=>290,'destination_warehouse_id'=>9,'zayavki_ids'=>'311706'],
    ['id'=>309,'source_warehouse_id'=>9,'status'=>'started','actual_start_date'=>'2026-09-29','zayavki_ids'=>'311706'],
];
$movements = [
    ['id'=>1,'movement_type'=>'receipt','warehouse_id'=>9,'flight_id'=>225,'zayavka_id'=>1,'mass_netto'=>'0.100','movement_date'=>'2026-09-01','status'=>'active'],
    ['id'=>2,'movement_type'=>'receipt','warehouse_id'=>9,'flight_id'=>226,'zayavka_id'=>2,'mass_netto'=>'0.100','movement_date'=>'2026-09-02','status'=>'active'],
    ['id'=>3,'movement_type'=>'receipt','warehouse_id'=>9,'flight_id'=>286,'zayavka_id'=>311706,'mass_netto'=>'2.129','movement_date'=>'2026-09-18','status'=>'active'],
    ['id'=>4,'movement_type'=>'receipt','warehouse_id'=>9,'flight_id'=>286,'zayavka_id'=>295036,'mass_netto'=>'1.389','movement_date'=>'2026-09-18','status'=>'active'],
    ['id'=>5,'movement_type'=>'receipt','warehouse_id'=>9,'flight_id'=>286,'zayavka_id'=>296555,'mass_netto'=>'3.280','movement_date'=>'2026-09-18','status'=>'active'],
    ['id'=>6,'movement_type'=>'receipt','warehouse_id'=>9,'flight_id'=>290,'zayavka_id'=>311706,'mass_netto'=>'2.129','movement_date'=>'2026-09-21','status'=>'active'],
];
$erp = [
    ['business_id'=>286,'legacy_destination_warehouse_id'=>9,'zayavka_id'=>311706,'snapshot_mass_kg'=>0],
    ['business_id'=>286,'legacy_destination_warehouse_id'=>9,'zayavka_id'=>295036,'snapshot_mass_kg'=>1389],
    ['business_id'=>286,'legacy_destination_warehouse_id'=>9,'zayavka_id'=>296555,'snapshot_mass_kg'=>3280],
    ['business_id'=>290,'legacy_destination_warehouse_id'=>9,'zayavka_id'=>311706,'snapshot_mass_kg'=>2129],
    ['business_id'=>309,'legacy_source_warehouse_id'=>9,'status'=>'started','actual_start_date'=>'2026-09-29','zayavka_id'=>311706,'snapshot_mass_kg'=>2129],
];
$data = WarehouseStockService::build($warehouses, $movements, $flights, $erp);
if (isset($data['issues'][225]) || isset($data['issues'][290])) throw new RuntimeException('Исторический рейс или корректный приход попал в расхождения.');
if ($data['issue_count'] !== 3 || !isset($data['issues'][226], $data['issues'][286], $data['issues'][309])) {
    throw new RuntimeException('Неверный состав расхождений после границы переноса.');
}
$in = implode(' ', $data['issues'][286]['messages']);
$out = implode(' ', $data['issues'][309]['messages']);
foreach (['6 798 кг', '4 669 кг', '#311706', '2 129 кг', '#286 (2 129 кг)', '#290 (2 129 кг)'] as $text) {
    if (!str_contains($in, $text)) throw new RuntimeException('В приходе не раскрыто: '.$text);
}
foreach (['4 258 кг', '2 129 кг', '#311706', '#286 (2 129 кг)', '#290 (2 129 кг)'] as $text) {
    if (!str_contains($out, $text)) throw new RuntimeException('В вывозе не раскрыто: '.$text);
}
if ($data['issues'][286]['movement_label'] !== 'Складской журнал: 3 заявки · ERP: 3 заявки'
    || $data['issues'][309]['movement_label'] !== 'Расчётное движение'
    || $data['issues'][309]['has_movements']) {
    throw new RuntimeException('Расчётное списание показано как фактическая запись журнала.');
}
echo "warehouse stock discrepancy details OK\n";
