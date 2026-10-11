<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingService.php';
require_once BASE_PATH.'/app/Service/FinanceInvoiceService.php';
require_once BASE_PATH.'/app/Support/http_runtime.php';

$trip=static function(int $id,array $documents):array {
    return [
        'id'=>$id,'business_id'=>$id,'actual_end_date'=>'2026-09-30',
        'carrier_id'=>42,'carrier_name'=>'Перевозчик','manager_name'=>'Менеджер','legal_entity_code'=>'planex',
        'recipient_email'=>'info@plan-ex.ru','review_state'=>'work','review_version'=>0,
        'documents'=>$documents,'paid_bank_transactions'=>[],'request_count'=>3,'processed_count'=>2,
        'tn_issues'=>['Заявка №123: нет скана ТН'],
        'invoice_summary'=>['status'=>'missing','detail'=>'—','invoice_id'=>0],
    ];
};
$trips=[
    array_replace($trip(1,[]),['transport'=>['set_type'=>'single','set_label'=>'Одиночка','units'=>[
        ['id'=>8,'label'=>'Транспортная единица','brand'=>'ГАЗ','plate'=>'А111АА','number'=>'','date'=>'','documents'=>[]],
    ]]]),
    array_replace($trip(2,[
        ['id'=>11,'slot_code'=>'contract','requirement_code'=>'agreement','original_name'=>'signed.pdf'],
        ['id'=>15,'slot_code'=>'contract','requirement_code'=>'agreement','original_name'=>'signed-page-2.pdf'],
        ['id'=>12,'slot_code'=>'invoice','requirement_code'=>'invoice','original_name'=>'invoice.pdf'],
        ['id'=>13,'slot_code'=>'act','requirement_code'=>'upd','original_name'=>'act.pdf'],
        ['id'=>14,'slot_code'=>'waybill','requirement_code'=>'waybill','original_name'=>'waybill.pdf'],
        ['id'=>16,'slot_code'=>'extra','custom_title'=>'Реестр','original_name'=>'registry-1.pdf'],
        ['id'=>17,'slot_code'=>'extra','custom_title'=>'Реестр','original_name'=>'registry-2.pdf'],
    ]),['tn_issues'=>[],'request_count'=>3,'processed_count'=>3,'transport'=>['set_type'=>'coupling','set_label'=>'Сцепка','units'=>[
        ['id'=>9,'label'=>'Тягач','brand'=>'МАЗ','plate'=>'В222ВВ','number'=>'','date'=>'','documents'=>[]],
        ['id'=>10,'label'=>'Полуприцеп','brand'=>'ТОНАР','plate'=>'С333СС','number'=>'42','date'=>'2026-09-20','documents'=>[['id'=>88,'original_name'=>'card.pdf']]],
    ]]]),
];
$success='Документ добавлен.';
$flashError=$error=null;
$schemaReady=true;
$canCreateInvoice=true;
$contractors=[['id'=>42,'name'=>'Перевозчик','inn'=>'1234567890']];
$clients=[];
unset($_GET['trip_id']);
ob_start();
require BASE_PATH.'/app/View/pages/company_waste_trip_closing.php';
$html=(string)ob_get_clean();

$assert=static function(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);};
$assert(substr_count($html,'class="closing-shared-issues"')===2,'each trip must show common issues block');
$assert(str_contains($html,'data-closing-payment-ready')&&str_contains($html,'data-closing-manager'),'payment and manager filters must be rendered');
$assert(str_contains($html,'data-payment-ready="no"')&&str_contains($html,'data-payment-ready="yes"'),'payment readiness must match document and TN issues');
$assert(str_contains($html,'<option value="Менеджер">Менеджер</option>'),'manager must be selectable');
$assert(str_contains($html,'<b>Документы:</b> Не получены: Подписанный договор / заявка, Счёт, АКТ/УПД, Путевой лист'),'missing documents must be named');
$assert(str_contains($html,'<b>Проверка ТН:</b> Заявка №123: нет скана ТН'),'TN error must identify request');
$assert(str_contains($html,'Нет ошибок, счет можно оплачивать'),'clean trip must show payment readiness');
$assert(str_contains($html,'2 из 3 обработано'),'processed requests must follow document progress');
$assert(str_contains($html,'data-invoice-open data-carrier-id="42"'),'invoice action must use the trip carrier');
$assert(str_contains($html,'name="direction"')&&str_contains($html,'Полученный (от перевозчика)'),'invoice form must be reused');
$assert(str_contains($html,'data-document-preview target="_blank"'),'file names must use popup preview handler');
$assert(str_contains($html,'data-invoice-choose'),'existing invoice picker must be available');
$assert(str_contains($html,'<small>СЧЕТА</small><b>Не введён</b>'),'invoice summary must show missing status');
$assert(!preg_match('/<details class="closing-trip"[^>]*\sopen>/',$html),'trips must be collapsed initially');
$assert(!str_contains($html,'<select name="requirement_code"'),'document subtype selector must be absent');
$assert(str_contains($html,'name="requirement_code" value="application"'),'combined contract slot must keep finance code');
$assert(str_contains($html,'name="requirement_code" value="upd"'),'combined act slot must keep finance code');
$assert(str_contains($html,'class="closing-remove-form"'),'uploaded document must offer removal');
$assert(str_contains($html,'signed.pdf')&&str_contains($html,'signed-page-2.pdf'),'one document category must display every file');
$assert(str_contains($html,'4 из 5 получено')===false && str_contains($html,'5 из 5 получено'),'progress counts categories and grouped extras');
$assert(substr_count($html,'<strong>Реестр</strong>')===1,'extra files with the same title must form one document');
$assert(substr_count($html,'name="document_files[]"')>=5,'document uploads must accept multiple files');
$assert(str_contains($html,'Тягач · МАЗ В222ВВ')&&str_contains($html,'Полуприцеп · ТОНАР С333СС'),'coupling cards must target separate units');
$assert(str_contains($html,'name="vehicle_unit_id" value="9"')&&str_contains($html,'name="vehicle_unit_id" value="10"'),'each card form must bind its own vehicle unit');
$assert(str_contains($html,'Тип комплекта: Одиночка'),'single vehicle must be labelled');
$assert(str_contains($html,'data-closing-action-result="success" hidden'),'successful action marker must remain available to inline update');
$assert(!str_contains($html,'class="notice success"'),'upload success banner must not be visible');
$assert(str_contains($html,'Отправка комплекта на почту временно отключена.'),'email pause must be visible');
$assert(str_contains($html,'class="btn btn-primary closing-submit" disabled'),'review action must be disabled');
$assert(!str_contains($html,'data-review-form'),'email form must not be rendered');
echo "waste_trip_closing_view: PASS\n";
