<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/app/Service/WasteTripService.php';
require_once $root.'/app/Service/LinearRouteService.php';
require_once $root.'/app/Service/DateCalculationService.php';
require_once $root.'/app/Service/WasteTripCustomerPaymentService.php';
require_once $root.'/app/Service/WasteTripRegistryPresenter.php';
use App\Service\WasteTripRegistryPresenter as Registry;
use App\Service\WasteTripService as Trips;
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function base_path(string $v): string { return dirname(__DIR__).'/'.$v; }
function app_url(string $v): string { return $v; }
function ui_date(string $v): string { return date('d.m.Y',strtotime($v)); }
function csrfField(): string { return '<input type="hidden" name="csrf_token" value="fixture">'; }
$assert=static function(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); };
$legacy=['source_system'=>'legacy_sync','legacy_flight_id'=>250,'route_executor_id'=>null];
$assert(!Registry::executorUnmatched($legacy),'Absent source executor is not an error');
$assert(!Registry::executorUnmatched($legacy+['legacy_sync_error'=>'Утилизатор не определён однозначно по заявкам ФЭО.']),'Unrelated warning is not an executor mismatch');
$assert(Registry::executorUnmatched($legacy+['legacy_sync_error'=>'Исполнитель не найден по фамилии и госномеру.']),'Known unmatched source executor');
$assert(Registry::manager('Сидоров Владимир Петрович')==='Владимир С.','Manager name order');
$assert(Registry::drivers('Иванов Иван Петрович / Петров Петр')==='Иванов И. П. / Петров П.','Driver initials');
$assert(Registry::profitability([['amount'=>'100.00']],[['amount'=>'120.00']])==='-20,0 %','Negative profitability');
$assert(Registry::profitability([['amount'=>'0.00']],[['amount'=>'0.00']])===null,'Zero sale cannot divide');
$assert(Registry::profitability([['amount'=>'100.00']],[])===null,'Missing cost must not imply 100 percent');
$assert(Registry::profitability([['amount'=>'100.00']],[['amount'=>'0.00']])==='100,0 %','Explicit zero is valid');
$assert(Registry::cents([['amount'=>'10.01'],['amount'=>'20.02']])===3003,'Integer cents');
$assert(count(Trips::STATUS_OPTIONS)===4 && Trips::displayStatus('searching_executor')==='planned_route','Four selectable statuses and old alias');
$render=static function(bool $finance) use($legacy): string {
    $company=['status'=>'active'];$dbError=null;$formSuccess=null;$formError=null;$old=[];$clients=[];$responsibleUsers=[];$routeExecutors=[];$utilizers=[];$isFinanceRealm=$finance;
    $base=$legacy+['id'=>1,'business_id'=>250,'comment'=>str_repeat('Длинное описание <script> ',20),'status'=>'searching_executor','actual_start_date'=>null,'planned_start_date'=>'2026-01-02','actual_end_date'=>null,'planned_finish_date'=>null,'responsible_name'=>'Сидоров Владимир Петрович','request_count'=>22,'snapshot_total_mass_kg'=>1500.25,'general_payments'=>[['amount'=>'200.00','condition_type'=>'after_end','days_count'=>3,'days_kind'=>'calendar']],'customer_payments'=>[['amount'=>'100.00','condition_type'=>'on_loading']],'carrier_payments'=>[['amount'=>'120.00','condition_type'=>'']]];
    $trips=[$base,array_replace($base,['id'=>2,'business_id'=>251,'status'=>'executor_assigned','legacy_sync_error'=>'Исполнитель не найден по фамилии и госномеру.']),array_replace($base,['id'=>3,'business_id'=>252,'status'=>'in_progress','actual_start_date'=>'2026-01-03','route_executor_id'=>7,'executor_contractor_name'=>'ООО Перевозчик','executor_driver_names'=>'Иванов Иван Петрович','executor_vehicle_label'=>'MAN А123ВС77']),array_replace($base,['id'=>4,'business_id'=>253,'status'=>'completed','actual_start_date'=>'2026-01-03','actual_end_date'=>'2026-01-04'])];
    ob_start();require base_path('app/View/pages/company_waste_trips.php');return ob_get_clean();
};
$html=$render(true);
$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xp=new DOMXPath($dom);
$assert($xp->query('//table[contains(@class,"waste-registry")]/thead/tr/th')->length===12,'Twelve registry columns');
$assert($xp->query('//tr[@data-waste-view="1"]/td')->length===12,'Twelve data columns');
$assert(str_contains($html,'ГЕН. ЗАКАЗЧИК') && !str_contains($html,'ДОХОДНОСТЬ'),'Three amounts replace profitability');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[10]')->item(0)->textContent,'200 ₽'),'General amount separate from sale');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[11]')->item(0)->textContent,'100 ₽'),'Sale column');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[12]')->item(0)->textContent,'120 ₽'),'Manual cost column');
$assert($xp->query('//td[contains(@class,"waste-executor-unmatched")]')->length===1,'Only unmatched row yellow');
$assert($xp->query('//tr[@data-waste-view="1"]/td[7]')->item(0)->textContent==='22','Request count');
$assert(trim($xp->query('//tr[@data-waste-view="1"]/td[8]')->item(0)->textContent)==='1 500,250','Total mass');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[3]')->item(0)->textContent,'02.01.2026'),'Fallback planned date');
$assert(!str_contains($xp->query('//tr[@data-waste-view="3"]/td[3]')->item(0)->textContent,'плановая'),'Actual wins over plan');
$assert(!str_contains($html,'<script> Длинное'),'Escape legacy text');
$assert(!str_contains($render(false),'100 ₽')&&!str_contains($render(false),'-20,0 %'),'Finance stays hidden for non-finance roles');
if($out=getenv('WASTE_REGISTRY_RENDER_OUTPUT'))file_put_contents($out,$html);
if($out=getenv('WASTE_EDIT_RENDER_OUTPUT')) {
    $trip=['id'=>1,'business_id'=>250,'legacy_flight_id'=>250,'source_system'=>'legacy_sync','status'=>'planned_route','lock_version'=>1,'requests'=>[],
        'general_payments'=>[['amount'=>'1500.00','payment_type'=>'','payment_method'=>'','condition_type'=>'after_end','days_count'=>3,'days_kind'=>'calendar']],
        'customer_payments'=>[['amount'=>'1000.00','payment_type'=>'','payment_method'=>'','condition_type'=>'after_end','days_count'=>3,'days_kind'=>'calendar']], 'carrier_payments'=>[]];
    $isFinanceRealm=true;$clients=[];$responsibleUsers=[];$routeExecutors=[];$utilizers=[];
    ob_start();require base_path('app/View/partials/company_waste_trip_modal_edit.php');file_put_contents($out,ob_get_clean());
}
echo "WASTE_TRIP_REGISTRY_RENDER=PASS\n";
