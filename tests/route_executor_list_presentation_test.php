<?php
declare(strict_types=1);

require_once __DIR__.'/../app/Service/RouteExecutorListPresentation.php';

use App\Service\RouteExecutorListPresentation as ListView;

$snapshot=json_encode(['controls'=>[
    ['formId'=>'lab-contractor-form','name'=>'contacts[0][phone]','value'=>'+7 900 000-00-00'],
    ['formId'=>'lab-contractor-form','name'=>'contacts[0][email]','value'=>'contact@example.test'],
    ['formId'=>'lab-driver-form','name'=>'email','value'=>'driver@example.test'],
    ['formId'=>'lab-vehicle-form','name'=>'units[primary][brand]','value'=>'SHACMAN'],
    ['formId'=>'lab-vehicle-form','name'=>'units[primary][plate_number]','value'=>'А101ВС16'],
    ['formId'=>'lab-vehicle-form','name'=>'units[primary][capacity_tons]','value'=>'20.00'],
    ['formId'=>'lab-vehicle-form','name'=>'units[secondary][brand]','value'=>'ТОНАР'],
    ['formId'=>'lab-vehicle-form','name'=>'units[secondary][plate_number]','value'=>'А121333'],
    ['formId'=>'lab-vehicle-form','name'=>'units[secondary][volume_m3]','value'=>'92.50'],
]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$data=ListView::reviewFields($snapshot);
foreach (['contractor_phone'=>'+7 900 000-00-00','contractor_email'=>'contact@example.test','driver_email'=>'driver@example.test','primary_brand'=>'SHACMAN','secondary_brand'=>'ТОНАР'] as $key=>$expected) {
    if ($data[$key]!==$expected) throw new RuntimeException("Missing review list field $key");
}
if (ListView::plate($data['primary_plate'])!=='А 101 ВС 16') throw new RuntimeException('Truck plate display');
if (ListView::plate($data['secondary_plate'],true)!=='А1213 33') throw new RuntimeException('Trailer plate display');
if (ListView::plate('X-123-XX-777')!=='X 123 XX 777') throw new RuntimeException('Three-digit region display');
if (ListView::metric($data['primary_capacity'])!=='20' || ListView::metric($data['secondary_volume'])!=='92,5') throw new RuntimeException('Vehicle metric display');
if (ListView::plate('НЕФОРМАТ')!=='НЕФОРМАТ' || ListView::metric(null)!=='') throw new RuntimeException('Nonstandard or missing values');
echo "route_executor_list_presentation_test: PASS\n";
