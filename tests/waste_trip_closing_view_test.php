<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingService.php';
require_once BASE_PATH.'/app/Support/http_runtime.php';

$trip=static function(int $id,array $documents):array {
    return [
        'id'=>$id,'business_id'=>$id,'actual_end_date'=>'2026-09-30',
        'carrier_name'=>'Перевозчик','manager_name'=>'Менеджер','legal_entity_code'=>'planex',
        'recipient_email'=>'info@plan-ex.ru','review_state'=>'work','review_version'=>0,
        'documents'=>$documents,'paid_bank_transactions'=>[],
    ];
};
$trips=[
    $trip(1,[]),
    $trip(2,[['id'=>11,'slot_code'=>'contract','requirement_code'=>'agreement','original_name'=>'signed.pdf']]),
];
$success='Документ добавлен.';
$flashError=$error=null;
$schemaReady=true;
$_GET['trip_id']='1';
ob_start();
require BASE_PATH.'/app/View/pages/company_waste_trip_closing.php';
$html=(string)ob_get_clean();

$assert=static function(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);};
$assert(substr_count($html,'class="closing-missing"')===1,'empty trip must not show missing-documents warning');
$assert(!str_contains($html,'<select name="requirement_code"'),'document subtype selector must be absent');
$assert(str_contains($html,'name="requirement_code" value="application"'),'combined contract slot must keep finance code');
$assert(str_contains($html,'name="requirement_code" value="upd"'),'combined act slot must keep finance code');
$assert(str_contains($html,'class="closing-remove-form"'),'uploaded document must offer removal');
$assert(str_contains($html,'data-closing-action-result="success" hidden'),'successful action marker must remain available to inline update');
$assert(!str_contains($html,'class="notice success"'),'upload success banner must not be visible');
echo "waste_trip_closing_view: PASS\n";
