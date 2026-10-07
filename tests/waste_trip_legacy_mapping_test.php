<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Service/WasteTripService.php';
require_once dirname(__DIR__).'/app/Service/WasteTripLegacySourceService.php';
require_once dirname(__DIR__).'/app/Service/WasteTripLegacyMappingService.php';
require_once dirname(__DIR__).'/app/Service/FeoUtilizerService.php';

use App\Service\WasteTripLegacyMappingService as Mapping;

$check = static function(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$reject = static function(callable $fn) use ($check): void {
    try { $fn(); } catch (RuntimeException) { return; }
    $check(false, 'Expected validation failure');
};
$check(Mapping::plates('АФ-47432В (Hyundai HD120)  С894НО193 ') === ['C894HO193'], 'Separate model and plate');
$check(Mapping::plates('SCANIA О361ЕО29') === ['O361EO29'], 'Plate inside a label');
$check(Mapping::plates('с 894 нО 193') === ['C894HO193'], 'Whitespace/case normalization');
$flight = ['driver_id'=>50,'driver_name'=>'Семёнов Алексей Иванович','vehicle_make_plate'=>'MAN С894НО193'];
$crew = ['crew_id'=>12,'full_name'=>'Семенов Алексей Иванович','primary_plate'=>'C894HO193'];
$check(Mapping::executor($flight, [$crew])['id'] === 12, 'Unambiguous surname and plate');
$check(Mapping::executor($flight, [$crew, $crew])['id'] === 12, 'Duplicate driver rows in one crew');
$check(Mapping::executor($flight, [$crew, array_replace($crew,['crew_id'=>13])])['id'] === null, 'Ambiguous crew');
$check(Mapping::executor($flight, [array_replace($crew,['full_name'=>'Иванов Алексей'])])['id'] === null, 'Plate alone cannot match');
$check(Mapping::executor($flight, [array_replace($crew,['primary_plate'=>'C894HO19'])])['id'] === null, 'Region must match');
$check(Mapping::executor(array_replace($flight,['vehicle_make_plate'=>'С894НО193 О361ЕО29']), [$crew])['id'] === null, 'Ambiguous legacy plate');
$base = ['id'=>226,'zayavki_ids'=>'100,101','status'=>'planned_route','cost'=>1];
$state = Mapping::state($base);
$check($state['status'] === 'planned_route' && $state['carrier_cost'] === '1.00', 'Planned and literal 1 ruble');
$check(Mapping::state(array_replace($base,['cost'=>null]))['carrier_cost'] === null, 'Missing is not zero');
$check(Mapping::state(array_replace($base,['cost'=>0]))['carrier_cost'] === '0.00', 'Zero preserved');
$reject(static fn()=>Mapping::state(array_replace($base,['id'=>225])));
$reject(static fn()=>Mapping::state(array_replace($base,['status'=>'unknown'])));
$reject(static fn()=>Mapping::state(array_replace($base,['cost'=>-1])));
$reject(static fn()=>Mapping::state(array_replace($base,['planned_start_date'=>'2026-02-30'])));
$openRange=Mapping::state(array_replace($base,['planned_start_date_from'=>'2026-09-01']));
$check($openRange['planned_start_mode']==='range' && $openRange['planned_start_date_from']==='2026-09-01' && $openRange['planned_start_date_to']===null, 'Open legacy range must not invent an end or exact date');
$check(Mapping::state(array_replace($base,['status'=>'completed']))['status']==='completed','Completed source status is authoritative without factual dates');
$reject(static fn()=>Mapping::state(array_replace($base,['zayavki_ids'=>'100,100'])));
$check(Mapping::status('found') === 'executor_assigned', 'Formed mapping');
$check(Mapping::status('started') === 'in_progress', 'Started mapping');
$check(Mapping::manager('Семёнов', 'Алексей', [['membership_id'=>90,'full_name'=>'Семенов Иван']]) === 90, 'First-word surname controls mapping');
$check(Mapping::manager('Семенов', '', [['membership_id'=>90,'full_name'=>'Алексей Семенов']]) === null, 'Only first word is surname');
$check(Mapping::manager('Семенов', 'Алексей', [['membership_id'=>90,'full_name'=>'Семенов Алексей'],['membership_id'=>91,'full_name'=>'Семенов Иван']]) === null, 'Ambiguous surname');
$check(Mapping::manager('Гладских', 'Карина', [['membership_id'=>2,'full_name'=>'Гладких Карина Артуровна']]) === 2, 'Owner-confirmed surname alias');
$check(Mapping::customer([['id'=>13,'name'=>'ООО "МГ ЛОГИСТИК"'],['id'=>4,'name'=>'МГ Логистик Восток']]) === 13, 'Customer exact normalized name');
$check(Mapping::customer([['id'=>13,'name'=>'ООО «МГ ЛОГИСТИК»'],['id'=>14,'name'=>'МГ логистик']]) === null, 'Ambiguous customer');
echo "WASTE_TRIP_LEGACY_MAPPING=PASS\n";

foreach(['planned_route'=>'planned_route','search'=>'planned_route','attention'=>'planned_route','found'=>'executor_assigned','started'=>'in_progress','completed'=>'completed'] as $source=>$target)$check(Mapping::status($source)===$target,'Approved status mapping '.$source);
$check(Mapping::executor(['driver_id'=>null],[])===['id'=>null,'warning'=>null],'No source executor means no warning');
