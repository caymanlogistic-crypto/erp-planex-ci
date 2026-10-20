<?php
declare(strict_types=1);
$root=dirname(__DIR__);
spl_autoload_register(static function(string $class) use($root): void { if(str_starts_with($class,'App\\')){ $file=$root.'/app/'.str_replace('\\','/',substr($class,4)).'.php'; if(is_file($file))require_once $file; } });
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
foreach(['Гладких Карина Артуровна'=>'Карина Г.','Гладких Карина'=>'Карина Г.',"\tЁлкина\u{00A0}Анна  Игоревна "=>'Анна Ё.','Салтыкова-Щедрина Анна-Мария'=>'Анна-Мария С.','Карина'=>'Карина',''=>'—'] as $full=>$expected) {
    $assert(Registry::manager($full)===$expected,'Manager label respects surname-first source and missing parts: '.$full);
}
$assert(Registry::manager('Гладких Карина Артуровна')!=='Гладких К.','Must not reverse surname and given name');
$assert(Registry::drivers('Иванов Иван Петрович / Петров Петр')==='Иванов И. П. / Петров П.','Driver initials');
$assert(Registry::profitability([['amount'=>'100.00']],[['amount'=>'120.00']])==='-20,0 %','Negative profitability');
$assert(Registry::profitability([['amount'=>'0.00']],[['amount'=>'0.00']])===null,'Zero sale cannot divide');
$assert(Registry::profitability([['amount'=>'100.00']],[])===null,'Missing cost must not imply 100 percent');
$assert(Registry::profitability([['amount'=>'100.00']],[['amount'=>'0.00']])==='100,0 %','Explicit zero is valid');
$assert(Registry::cents([['amount'=>'10.01'],['amount'=>'20.02']])===3003,'Integer cents');
$assert(count(Trips::STATUS_OPTIONS)===4 && Trips::displayStatus('searching_executor')==='planned_route','Four selectable statuses and old alias');
$assert(Trips::generalCustomerDoesNotPay(['destination_type'=>'warehouse']),'ERP warehouse destination blocks general customer cost');
$assert(Trips::generalCustomerDoesNotPay(['legacy_unload_type'=>'SKLAD']),'Legacy warehouse unloading blocks general customer cost');
$assert(!Trips::generalCustomerDoesNotPay(['destination_type'=>'utilizer','legacy_route_type'=>'warehouse_to_utilizer']),'Loading from warehouse still allows general customer cost');
$render=static function(bool $finance, int $selectedManager=0) use($legacy): string {
    $company=['status'=>'active'];$dbError=null;$formSuccess=null;$formError=null;$old=[];$clients=[];$responsibleUsers=[];$routeExecutors=[];$utilizers=[];$isFinanceRealm=$finance;
    $registryManagers=[['id'=>1,'full_name'=>'Сидоров Владимир Петрович'],['id'=>2,'full_name'=>'Гладких Карина Артуровна']];
    $base=$legacy+['id'=>1,'business_id'=>250,'comment'=>str_repeat('Длинное описание <script> ',20),'status'=>'searching_executor','actual_start_date'=>null,'planned_start_date'=>'2026-01-02','actual_end_date'=>null,'planned_finish_date'=>null,'responsible_name'=>'Сидоров Владимир Петрович','request_count'=>22,'snapshot_total_mass_kg'=>1500.25,'general_payments'=>[['amount'=>'200.00','condition_type'=>'after_end','days_count'=>3,'days_kind'=>'calendar']],'customer_payments'=>[['amount'=>'100.00','condition_type'=>'on_loading']],'carrier_payments'=>[['amount'=>'120.00','condition_type'=>'']]];
    $trips=[array_replace($base,['legal_entity_code'=>'planex','legacy_route_type'=>'generator_to_warehouse','legacy_destination_warehouse_name'=>'Склад Север']),array_replace($base,['id'=>2,'business_id'=>251,'legal_entity_code'=>'mg_logistic','legacy_route_type'=>'warehouse_to_warehouse','legacy_source_warehouse_name'=>'Склад Север','legacy_destination_warehouse_name'=>'Склад Юг','financial_model_version'=>3,'status'=>'executor_assigned','legacy_sync_error'=>'Исполнитель не найден по фамилии и госномеру.']),array_replace($base,['id'=>3,'business_id'=>252,'status'=>'in_progress','actual_start_date'=>'2026-01-03','route_executor_id'=>7,'executor_contractor_name'=>'ООО Перевозчик','executor_driver_names'=>'Иванов Иван Петрович','executor_vehicle_label'=>'MAN А123ВС77']),array_replace($base,['id'=>4,'business_id'=>253,'status'=>'completed','actual_start_date'=>'2026-01-03','actual_end_date'=>'2026-01-04','general_payments'=>[['amount'=>null,'condition_type'=>'after_end','days_count'=>3,'days_kind'=>'calendar']],'customer_payments'=>[['amount'=>'0.00','condition_type'=>'after_end','days_count'=>3,'days_kind'=>'calendar']],'carrier_payments'=>[['amount'=>'','condition_type'=>'after_end','days_count'=>7,'days_kind'=>'calendar']]] )];
    $trips[]=array_replace($base,['id'=>5,'business_id'=>254,'responsible_name'=>'Карина Г.','no_markup'=>1,'customer_payments'=>[['amount'=>'100.00']],'carrier_payments'=>[['amount'=>'100.00']]]);
    ob_start();require base_path('app/View/pages/company_waste_trips.php');return ob_get_clean();
};
$html=$render(true);
$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xp=new DOMXPath($dom);
$assert($xp->query('//table[contains(@class,"registry-ui-table--trips")]/thead/tr/th')->length===13,'Thirteen registry columns');
$sort=$xp->query('//select[@name="sort"]')->item(0);
$assert($sort!==null && $sort->getAttribute('aria-label')==='Сортировка рейсов','Registry sort control');
$assert($xp->query('//span[contains(@class,"toolbar-sort-label")]')->length===0,'No visible sort caption in waste registry');
$assert($xp->query('//select[@name="manager"]/option')->length===3,'Manager filter offers all and available managers');
$managerOptions=[];foreach($xp->query('//select[@name="manager"]/option') as $option)$managerOptions[]=[$option->getAttribute('value'),$option->textContent];
$assert($managerOptions===[['','Все менеджеры'],['1','Владимир С.'],['2','Карина Г.']],'Short labels keep original option IDs and order');
$selectedDom=new DOMDocument();@$selectedDom->loadHTML('<?xml encoding="utf-8" ?>'.$render(true,2));$selectedXp=new DOMXPath($selectedDom);
$assert($selectedXp->query('//select[@name="manager"]/option[@selected and @value="2"]')->item(0)?->textContent==='Карина Г.','Selected GET manager keeps its identity with short label');
$assert($xp->query('//tr[@data-waste-view="1"]/td[contains(@class,"waste-manager")]')->item(0)?->getAttribute('title')==='Сидоров Владимир Петрович','Filter formatting preserves full domain name');
$assert($xp->query('//tr[@data-waste-view="1"]/td[contains(@class,"waste-manager")]//span')->item(0)?->textContent==='Владимир С.','Manager table cell uses given name and surname initial');
$assert($xp->query('//tr[@data-waste-view="5"]/td[contains(@class,"waste-manager")]//span')->item(0)?->textContent==='Карина Г.','Already-short manager cell remains unchanged');
$assert($xp->query('//select[@name="sort"]/option[@value="status" and @selected]')->length===1,'Status sort is selected by default');
$assert($xp->query('//select[@name="sort"]/option[@value="status"]')->length===1 && $xp->query('//select[@name="sort"]/option[@value="manager"]')->length===1,'Status and manager sort choices');
$assert($xp->query('//tr[@data-waste-view="1"]/td')->length===13,'Thirteen data columns');
$assert($xp->query('//table[contains(@class,"registry-ui-table--trips")]/thead/tr/th[3]')->item(0)->textContent==='СТАТУС','Status follows description');
$assert(trim($xp->query('//tr[@data-waste-view="1"]/td[4]')->item(0)->textContent)==='Выгрузка:Склад Север' && $xp->query('//tr[@data-waste-view="1"]/td[4]//br')->length===1,'Warehouse destination has short caption and name on separate lines');
$assert(trim($xp->query('//tr[@data-waste-view="2"]/td[4]')->item(0)->textContent)==='Загрузка:Склад СеверВыгрузка:Склад Юг' && $xp->query('//tr[@data-waste-view="2"]/td[4]//br')->length===3,'Warehouse route shows both roles and names on separate lines');
$assert($xp->query('//tr[@data-waste-view="2"]/td[2]//span[contains(@class,"is-mg-logistic")]')->length===1,'MG company is second line');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[10]')->item(0)->textContent,'При выгрузке не оплачивается')&&$xp->query('//tr[@data-waste-view="1"]/td[10][contains(@class,"waste-general-disabled")]')->length===1,'Warehouse general payment explanation');
$assert(str_contains($xp->query('//tr[@data-waste-view="2"]/td[10]')->item(0)->textContent,'При выгрузке не оплачивается'),'Warehouse-to-warehouse general payment explanation');
$assert(str_contains($xp->query('//tr[@data-waste-view="2"]/td[12]')->item(0)->textContent,'Для МГ Логистик не заполняется')&&$xp->query('//tr[@data-waste-view="2"]/td[12][contains(@class,"waste-cost-disabled")]')->length===1,'MG cost explanation');
$assert(str_contains($xp->query('//tr[@data-waste-view="5"]/td[12]')->item(0)->textContent,'Без наценки')&&$xp->query('//tr[@data-waste-view="5"]/td[12][contains(@class,"waste-cost-disabled")]')->length===1,'No markup cost explanation');
$assert($xp->query('//tr[@data-waste-view="1"]//span[contains(@class,"registry-payment-line")]')->length===0,'Payment terms absent from registry');
$assert(str_contains($html,'ГЕН. ЗАКАЗЧИК') && !str_contains($html,'ДОХОДНОСТЬ'),'Three amounts replace profitability');
$assert(str_contains($xp->query('//tr[@data-waste-view="3"]/td[10]')->item(0)->textContent,'200 ₽'),'Utilizer trip retains general amount');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[11]')->item(0)->textContent,'100 ₽'),'Sale column');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[12]')->item(0)->textContent,'120 ₽'),'Manual cost column');
$assert($xp->query('//td[contains(@class,"waste-executor-unmatched")]')->length===0,'Missing executor has no warning highlight');
$assert(trim($xp->query('//tr[@data-waste-view="2"]/td[7]')->item(0)->textContent)==='—','Missing executor is shown as a dash');
$driverNode=$xp->query('//tr[@data-waste-view="3"]/td[7]//span[contains(@class,"registry-executor-secondary")]')->item(0);
$assert($driverNode!==null && $driverNode->textContent==='Иванов Иван Петрович' && $driverNode->getAttribute('title')==='Иванов Иван Петрович','Approved registry shows and exposes the full driver name');
$assert($xp->query('//select[@name="company"]/option')->length===3,'Company filter offers all, MG and Planex');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[8]')->item(0)->textContent,'22'),'Request count');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[9]')->item(0)->textContent,'1 500'),'Total mass rounded to kilograms');
$assert(str_contains($xp->query('//tr[@data-waste-view="4"]/td[10]')->item(0)->textContent,'—'),'Unknown amount hides default terms');
$assert(str_contains($xp->query('//tr[@data-waste-view="4"]/td[12]')->item(0)->textContent,'—'),'Empty amount hides default terms');
$assert(str_contains($xp->query('//tr[@data-waste-view="4"]/td[11]')->item(0)->textContent,'0 ₽'),'Explicit zero remains visible');
$assert(str_contains($xp->query('//tr[@data-waste-view="1"]/td[5]')->item(0)->textContent,'02.01.2026'),'Fallback planned date');
$assert(!str_contains($xp->query('//tr[@data-waste-view="3"]/td[5]')->item(0)->textContent,'плановая'),'Actual wins over plan');
$assert(str_contains($html,'Расчёты по прежним условиям'),'Historical settlement notice');
$assert(!str_contains($render(false),'Расчёты по прежним условиям'),'Historical finance mode hidden from non-finance roles');
$assert(!str_contains($html,'<script> Длинное'),'Escape legacy text');
$assert(!str_contains($render(false),'100 ₽')&&!str_contains($render(false),'-20,0 %'),'Finance stays hidden for non-finance roles');
if($out=getenv('WASTE_REGISTRY_RENDER_OUTPUT'))file_put_contents($out,$html);
$renderEdit=static function(bool $manual=false, ?string $legalEntity=null, bool $warehouse=false): string {
    $trip=['id'=>1,'business_id'=>250,'legacy_flight_id'=>250,'source_system'=>'legacy_sync','status'=>'planned_route','lock_version'=>1,'financial_model_version'=>3,'requests'=>[],
        'general_payments'=>[['amount'=>'1500.00','payment_type'=>'','payment_method'=>'','condition_type'=>'after_end','days_count'=>3,'days_kind'=>'calendar']],
        'customer_payments'=>[['amount'=>'1000.00','payment_type'=>'','payment_method'=>'','condition_type'=>'after_end','days_count'=>3,'days_kind'=>'calendar']], 'carrier_payments'=>[]];
    $trip['customer_amount_sync_disabled']=$manual;
    $trip['legal_entity_code']=$legalEntity;
    $trip['destination_type']=$warehouse?'warehouse':'utilizer';
    $trip['legacy_unload_type']=$warehouse?'SKLAD':'OO';
    $isFinanceRealm=true;$clients=[];$responsibleUsers=[];$routeExecutors=[];$utilizers=[];
    ob_start();require base_path('app/View/partials/company_waste_trip_modal_edit.php');return ob_get_clean();
};
$edit=$renderEdit();
$editDom=new DOMDocument();@$editDom->loadHTML('<?xml encoding="utf-8" ?>'.$edit);$editXp=new DOMXPath($editDom);
$saleInput=$editXp->query('//input[@name="customer_payments[0][amount]"]')->item(0);
$assert($saleInput!==null&&!$saleInput->hasAttribute('readonly')&&!$saleInput->hasAttribute('disabled'),'Synced sale amount is editable');
$assert($editXp->query('//input[@name="general_payments[0][amount]"]')->item(0)->hasAttribute('readonly'),'General amount remains source-owned');
$assert(str_contains($edit,'После изменения и сохранения сумма больше не будет обновляться'),'Explain effect of saving manual sale');
$assert(str_contains($renderEdit(true),'Синхронизация суммы отключена.'),'Show persisted manual ownership after reopening');
$mgEdit=$renderEdit(false,'mg_logistic');
$assert(str_contains($mgEdit,'себестоимость не заполняется')&&!str_contains($mgEdit,'name="carrier_payments[0][amount]"'),'MG modal prevents cost entry');
$warehouseEdit=$renderEdit(false,'planex',true);
$assert(str_contains($warehouseEdit,'data-waste-general-forced="1"')&&str_contains($warehouseEdit,'data-waste-general-disabled'),'Warehouse edit marks general payment controls for blocking');
if($out=getenv('WASTE_WAREHOUSE_EDIT_RENDER_OUTPUT'))file_put_contents($out,$warehouseEdit);
if($out=getenv('WASTE_EDIT_RENDER_OUTPUT'))file_put_contents($out,$edit);
echo "WASTE_TRIP_REGISTRY_RENDER=PASS\n";
