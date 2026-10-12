<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Service/RouteExecutorReviewCardService.php';

use App\Service\RouteExecutorReviewCardService;

$check=new ReflectionMethod(RouteExecutorReviewCardService::class,'assertFinalCorrection');
$before=['controls'=>[
    ['formId'=>'lab-contractor-form','name'=>'name','value'=>'ИП Тестовый','type'=>'text'],
    ['formId'=>'lab-contractor-form','name'=>'bank_corr_account','value'=>'30101810300000000601 ','type'=>'text'],
    ['formId'=>'lab-driver-form','name'=>'full_name','value'=>'Тестовый Водитель Тестович','type'=>'text'],
    ['formId'=>'lab-driver-form','name'=>'phone','value'=>'','type'=>'text'],
    ['formId'=>'lab-vehicle-form','name'=>'units[primary][plate_number]','value'=>'А123АА32','type'=>'text'],
], 'logist'=>'12','trip_id'=>'123','trip_label'=>'#123 Тестовый рейс','selected_entities'=>[],'removed_documents'=>[]];
$after=$before;
$after['controls'][1]['value']='30101810300000000601';
$after['controls'][3]['value']='+7 920 855-66-89';
$check->invoke(null,$before,$after);
$reject=static function (array $candidate,string $reason) use ($check,$before): void {
    try { $check->invoke(null,$before,$candidate); }
    catch (DomainException) { return; }
    throw new RuntimeException($reason);
};
$vehicle=$after;$vehicle['controls'][4]['value']='В456ВВ32';
$reject($vehicle,'Vehicle change was accepted');
$shortAccount=$after;$shortAccount['controls'][1]['value']='3010181030000000060';
$reject($shortAccount,'Invalid correspondent account was accepted');
$missingPhone=$after;$missingPhone['controls'][3]['value']='';
$reject($missingPhone,'Missing driver phone was accepted');
echo "route_executor_final_correction_contract_test: PASS\n";
