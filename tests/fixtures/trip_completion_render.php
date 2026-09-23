<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Service/DocumentService.php';
if (!function_exists('csrfField')) { function csrfField(): string { return '<input type="hidden" name="_csrf" value="browser-test">'; } }
$company=['id'=>1,'name'=>'ПЛАНЭКС'];$error=null;$success=null;$formError=null;$query='';$hideComplete=true;
$metrics=['trips'=>2,'requests'=>22,'ready'=>8,'problems'=>14];
$trips=[
 ['id'=>1,'business_id'=>244,'actual_end_date'=>'2026-09-05','driver_name'=>'Пинчук Антон','contractor_name'=>'Перевозчик','complete_count'=>1,'request_count'=>3,'document_count'=>2,'missing_scan_count'=>1,'missing_time_count'=>1,'missing_driver_signature_count'=>1,'missing_sender_signature_count'=>0],
 ['id'=>2,'business_id'=>243,'actual_end_date'=>'2026-09-04','driver_name'=>'Иванов Иван','contractor_name'=>'Перевозчик 2','complete_count'=>7,'request_count'=>9,'document_count'=>8,'missing_scan_count'=>1,'missing_time_count'=>0,'missing_driver_signature_count'=>0,'missing_sender_signature_count'=>1],
];
$selectedTrip=['id'=>1,'business_id'=>244,'actual_start_date'=>'2026-09-01','actual_end_date'=>'2026-09-05','driver_name'=>'Пинчук Антон','contractor_name'=>'Перевозчик','complete_count'=>1,'request_count'=>3,'requests'=>[
 ['id'=>12,'zayavka_id'=>'275006','position_no'=>2,'snapshot_mass_kg'=>135,'shipper_name'=>'ООО Экопром','pickup_address'=>'Архангельская область, Приморский район','consignee_name'=>'Переработчик','delivery_address'=>'Московская область, Воскресенск, ул. Кирова, 3в','arrived_at'=>'','departed_at'=>'','driver_signature_present'=>0,'sender_signature_present'=>1,'tn_datetime_missing'=>1,'data_from_tracker'=>0,'data_from_driver'=>1,'data_invented'=>0,'completion_comment'=>'Уточнить время у водителя','documents'=>[],'issues'=>['Нет скана ТН','Нет даты или времени','Нет подписи водителя'],'is_complete'=>false],
 ['id'=>11,'zayavka_id'=>'274405','position_no'=>1,'snapshot_mass_kg'=>350,'shipper_name'=>'АО Важское','pickup_address'=>'Архангельская область, Вельский район, с. Благовещенское','consignee_name'=>'Переработчик','delivery_address'=>'Московская область, Воскресенск, ул. Кирова, 3в','arrived_at'=>'2026-09-01 09:40:00','departed_at'=>'2026-09-01 10:00:00','driver_signature_present'=>1,'sender_signature_present'=>1,'tn_datetime_missing'=>0,'data_from_tracker'=>1,'data_from_driver'=>0,'data_invented'=>0,'completion_comment'=>'','documents'=>[['id'=>101,'download_name'=>'Рейс_244_Заявка_274405_ТН_01.jpg','size_bytes'=>184512]],'issues'=>[],'is_complete'=>true],
 ['id'=>13,'zayavka_id'=>'271941','position_no'=>3,'snapshot_mass_kg'=>104,'shipper_name'=>'СПО Арктика','pickup_address'=>'Архангельская область, Северодвинск','consignee_name'=>'Переработчик','delivery_address'=>'Московская область, Воскресенск, ул. Кирова, 3в','arrived_at'=>'2026-09-02 12:30:00','departed_at'=>'2026-09-02 13:10:00','driver_signature_present'=>1,'sender_signature_present'=>0,'tn_datetime_missing'=>0,'data_from_tracker'=>0,'data_from_driver'=>0,'data_invented'=>1,'completion_comment'=>'','documents'=>[['id'=>102,'download_name'=>'Рейс_244_Заявка_271941_ТН_01.jpg','size_bytes'=>201120]],'issues'=>['Нет подписи отправителя'],'is_complete'=>false],
]];
$trips[0]=array_merge($trips[0],$selectedTrip);
$trips[1]=array_merge($trips[1],[
 'actual_start_date'=>'2026-09-03',
 'requests'=>[
  ['id'=>21,'zayavka_id'=>'270001','position_no'=>1,'snapshot_mass_kg'=>210,'shipper_name'=>'ООО Север','pickup_address'=>'Архангельск','consignee_name'=>'Переработчик','delivery_address'=>'Воскресенск','arrived_at'=>'2026-09-03 10:00:00','departed_at'=>'2026-09-03 10:30:00','driver_signature_present'=>1,'sender_signature_present'=>1,'tn_datetime_missing'=>0,'data_from_tracker'=>0,'data_from_driver'=>0,'data_invented'=>0,'completion_comment'=>'','documents'=>[['id'=>201,'download_name'=>'Рейс_243_Заявка_270001_ТН_01.jpg','size_bytes'=>120000]],'issues'=>[],'is_complete'=>true],
 ],
]);
$openTripId=0;
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/erp-ui.css"><title>Регистрация времени загрузки</title></head><body><main style="padding:20px;max-width:1800px;margin:auto"><?php require BASE_PATH.'/app/View/pages/company_logistics_assistant.php';?></main></body></html>
