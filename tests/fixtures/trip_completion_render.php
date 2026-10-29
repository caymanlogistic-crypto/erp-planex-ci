<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Service/DocumentService.php';
if (!function_exists('csrfField')) { function csrfField(): string { return '<input type="hidden" name="_csrf" value="browser-test">'; } }
$company=['id'=>1,'name'=>'ПЛАНЭКС'];$error=null;$success=null;$formError=null;$query='';$hideComplete=true;
$metrics=['trips'=>2,'requests'=>22,'ready'=>8,'problems'=>14];
$trips=[
 ['id'=>1,'business_id'=>244,'status'=>'completed','actual_end_date'=>'2026-09-05','driver_name'=>'Пинчук Антон','complete_count'=>1,'request_count'=>3,'document_count'=>2,'missing_scan_count'=>1,'missing_time_count'=>1,'missing_driver_signature_count'=>1,'missing_sender_signature_count'=>0],
 ['id'=>2,'business_id'=>243,'status'=>'in_progress','actual_end_date'=>null,'driver_name'=>'Иванов Иван','complete_count'=>7,'request_count'=>9,'document_count'=>8,'missing_scan_count'=>1,'missing_time_count'=>0,'missing_driver_signature_count'=>0,'missing_sender_signature_count'=>1],
];
$selectedTrip=['id'=>1,'business_id'=>244,'actual_start_date'=>'2026-09-01','actual_end_date'=>'2026-09-05','driver_name'=>'Пинчук Антон','contractor_name'=>'Перевозчик','complete_count'=>1,'request_count'=>3,'requests'=>[
 ['id'=>12,'zayavka_id'=>'275006','position_no'=>2,'snapshot_mass_kg'=>135,'shipper_name'=>'ООО Экопром','pickup_address'=>'Архангельская область, Приморский район','consignee_name'=>'Переработчик','delivery_address'=>'Московская область, Воскресенск, ул. Кирова, 3в','arrived_at'=>'','departed_at'=>'','driver_signature_present'=>0,'sender_signature_present'=>1,'tn_datetime_missing'=>1,'data_from_tracker'=>0,'data_from_driver'=>1,'data_invented'=>0,'completion_comment'=>'Уточнить время у водителя','documents'=>[],'issues'=>['Нет скана ТН','Нет даты или времени','Нет подписи водителя'],'is_complete'=>false],
 ['id'=>11,'zayavka_id'=>'274405','position_no'=>1,'snapshot_mass_kg'=>350,'shipper_name'=>'АО Важское','pickup_address'=>'Архангельская область, Вельский район, с. Благовещенское','consignee_name'=>'Переработчик','delivery_address'=>'Московская область, Воскресенск, ул. Кирова, 3в','arrived_at'=>'2026-09-01 09:40:00','departed_at'=>'2026-09-01 10:00:00','driver_signature_present'=>1,'sender_signature_present'=>1,'tn_datetime_missing'=>0,'data_from_tracker'=>1,'data_from_driver'=>0,'data_invented'=>0,'completion_comment'=>'','documents'=>[['id'=>101,'download_name'=>'Рейс_244_Заявка_274405_ТН_01.jpg','size_bytes'=>184512]],'issues'=>[],'is_complete'=>true],
 ['id'=>13,'zayavka_id'=>'271941','position_no'=>3,'snapshot_mass_kg'=>104,'shipper_name'=>'СПО Арктика','pickup_address'=>'Архангельская область, Северодвинск','consignee_name'=>'Переработчик','delivery_address'=>'Московская область, Воскресенск, ул. Кирова, 3в','arrived_at'=>'2026-09-02 12:30:00','departed_at'=>'2026-09-02 13:10:00','driver_signature_present'=>1,'sender_signature_present'=>0,'tn_datetime_missing'=>0,'tn_absent'=>1,'data_from_tracker'=>0,'data_from_driver'=>0,'data_invented'=>1,'completion_comment'=>'','documents'=>[['id'=>102,'download_name'=>'Рейс_244_Заявка_271941_ТН_01.jpg','size_bytes'=>201120]],'issues'=>['Нет ТН','Нет подписи отправителя'],'is_complete'=>false],
]];
$trips[0]=array_merge($trips[0],$selectedTrip);
$trips[1]=array_merge($trips[1],[
 'actual_start_date'=>'2026-09-03',
 'requests'=>[
  ['id'=>21,'zayavka_id'=>'270001','position_no'=>1,'snapshot_mass_kg'=>210,'shipper_name'=>'ООО Север','pickup_address'=>'Архангельск','consignee_name'=>'Переработчик','delivery_address'=>'Воскресенск','arrived_at'=>'2026-09-03 10:00:00','departed_at'=>'2026-09-03 10:30:00','driver_signature_present'=>1,'sender_signature_present'=>1,'tn_datetime_missing'=>0,'data_from_tracker'=>0,'data_from_driver'=>0,'data_invented'=>0,'completion_comment'=>'','documents'=>[['id'=>201,'download_name'=>'Рейс_243_Заявка_270001_ТН_01.jpg','size_bytes'=>120000]],'issues'=>[],'is_complete'=>true],
 ],
]);
foreach($trips as &$trip){
 $trip['manager_name']='Карина Г';
 $trip['manager_phone']='+7 908 221-12-12';
 $trip['manager_email']='';
 $trip['review_status']='work';
 $trip['review_version']=0;
 $trip['driver_phone']='+7 900 000-20-01';
 foreach($trip['requests'] as &$request){
  $request['driver_signature_status']=(int)$request['driver_signature_present']===1?'yes':null;
  $request['sender_signature_status']=(int)$request['sender_signature_present']===1?'yes':'no';
  $request['tn_datetime_status']=(int)$request['tn_datetime_missing']===1?'no':'full';
  $request['tn_absent']=(int)($request['tn_absent']??0);
  $request['recovery_source']=(int)$request['data_from_driver']===1?'driver':null;
 }
 unset($request);
}
unset($trip);
$fixtureMode=$argv[1]??'registration';
if($fixtureMode==='time-only') $trips[0]['requests'][2]['tn_absent']=0;
$reviewerMode=in_array($fixtureMode,['reviewer','reviewer-empty'],true);
if(in_array($fixtureMode,['reviewer','registration-readonly'],true)){
 $trips[0]['review_status']='review';$trips[0]['review_version']=1;
 $trips[1]['review_status']='archive';$trips[1]['review_version']=2;
}
if($fixtureMode==='reviewer'){
 $workTrip=$trips[1];
 $workTrip['id']=3;$workTrip['business_id']=245;
 $workTrip['review_status']='work';$workTrip['review_version']=0;
 $workTrip['requests'][0]['id']=31;
 $trips[]=$workTrip;
 usort($trips,static fn(array $a,array $b):int=>(int)$b['business_id']<=>(int)$a['business_id']);
}
if($fixtureMode==='reviewer-empty'){$trips=[];$metrics=['trips'=>0,'requests'=>0,'ready'=>0,'problems'=>0];}
$openTripId=0;
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/erp-ui.css"><link rel="stylesheet" href="/assets/css/workspace-benchmark.css"><title>Регистрация времени загрузки</title></head><body><main class="<?=$reviewerMode?'content content--wide-workspace':''?>" style="padding:20px;max-width:1800px;margin:auto"><?php if($reviewerMode):?><div class="workspace-shell-1690"><?php endif;?><?php require BASE_PATH.'/app/View/pages/company_logistics_assistant.php';?><?php if($reviewerMode):?></div><?php endif;?></main></body></html>
