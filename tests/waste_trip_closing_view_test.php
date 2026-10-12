<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingService.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingReportService.php';
require_once BASE_PATH.'/app/Service/FinanceInvoiceService.php';
require_once BASE_PATH.'/app/Support/http_runtime.php';

$trip=static function(int $id,array $documents):array {
    return [
        'id'=>$id,'business_id'=>$id,'actual_start_date'=>'2026-09-29','actual_end_date'=>'2026-09-30',
        'carrier_id'=>42,'carrier_name'=>'Перевозчик','manager_name'=>'Менеджер','manager_membership_id'=>71,'legal_entity_code'=>'planex',
        'recipient_email'=>'info@plan-ex.ru','review_state'=>'work','review_version'=>0,
        'carrier_rate'=>'25000.00','display_rate'=>'25000.00','carrier_paid_amount'=>'0.00','carrier_payment_due'=>'2026-10-15','carrier_paid_date'=>null,
        'customer_rate'=>'250000.00','customer_paid_amount'=>'0.00','customer_paid_date'=>null,
        'carrier_payment_plan'=>['date'=>'2026-10-15','due_date'=>'2026-10-15','status'=>'ready'],
        'comment'=>'Вывоз отходов с площадки А','driver_names'=>'Иванов Иван','tn_required'=>2,'tn_scanned'=>0,
        'documents'=>$documents,'paid_bank_transactions'=>[],'request_count'=>3,'processed_count'=>2,
        'tn_issues'=>['Заявка №123: нет скана ТН','Заявка №124: нет скана ТН','Заявка №125: нет подписи водителя'],
        'invoice_summary'=>['status'=>'missing','detail'=>'—','invoice_id'=>0],
    ];
};
$trips=[
    array_replace($trip(1,[]),['transport'=>['set_type'=>'single','set_label'=>'Одиночка','units'=>[
        ['id'=>8,'label'=>'Транспортная единица','brand'=>'ГАЗ','plate'=>'А111АА','can_upload'=>true,'documents'=>[]],
    ]]]),
    array_replace($trip(2,[
        ['id'=>11,'slot_code'=>'contract','requirement_code'=>'agreement','original_name'=>'signed.pdf'],
        ['id'=>15,'slot_code'=>'contract','requirement_code'=>'agreement','original_name'=>'signed-page-2.pdf'],
        ['id'=>12,'slot_code'=>'invoice','requirement_code'=>'invoice','original_name'=>'invoice.pdf'],
        ['id'=>13,'slot_code'=>'act','requirement_code'=>'upd','original_name'=>'act.pdf'],
        ['id'=>14,'slot_code'=>'waybill','requirement_code'=>'waybill','original_name'=>'waybill.pdf'],
        ['id'=>16,'slot_code'=>'extra','custom_title'=>'Реестр','original_name'=>'registry-1.pdf'],
        ['id'=>17,'slot_code'=>'extra','custom_title'=>'Реестр','original_name'=>'registry-2.pdf'],
    ]),['legal_entity_code'=>'mg_logistic','tn_issues'=>[],'request_count'=>3,'processed_count'=>3,'transport'=>['set_type'=>'coupling','set_label'=>'Сцепка','units'=>[
        ['id'=>9,'label'=>'Тягач','brand'=>'МАЗ','plate'=>'В222ВВ','has_card'=>true,'can_upload'=>true,'documents'=>[['id'=>89,'original_name'=>'tractor-card.pdf']]],
        ['id'=>10,'label'=>'Полуприцеп','brand'=>'ТОНАР','plate'=>'С333СС','has_card'=>true,'can_upload'=>false,'documents'=>[]],
    ]],'display_rate'=>'35000.00','carrier_paid_amount'=>'25000.00','carrier_paid_date'=>'2026-10-10','customer_paid_amount'=>'250000.00','customer_paid_date'=>'2026-10-12','tn_scanned'=>2]),
];
$success='Документ добавлен.';
$flashError=$error=null;
$schemaReady=true;
$paymentQueueReady=true;
$canCreateInvoice=true;
$contractors=[['id'=>42,'name'=>'Перевозчик','inn'=>'1234567890']];
$clients=[];
$responsibleUsers=[['membership_id'=>71,'full_name'=>'Менеджер','login'=>'manager'],['membership_id'=>72,'full_name'=>'Без рейсов','login'=>'idle']];
$viewStateKey='planex:trip-closing:v1:1:42';
$reportRows=\App\Service\WasteTripClosingReportService::rows($trips,'2026-10-12');
$xlsx=\App\Service\WasteTripClosingReportService::xlsx($reportRows);
$archive=new ZipArchive();
if($archive->open($xlsx)!==true)throw new RuntimeException('Excel report is not a valid XLSX archive');
$sheet=(string)$archive->getFromName('xl/worksheets/sheet1.xml');
$styles=(string)$archive->getFromName('xl/styles.xml');
$archive->close();@unlink($xlsx);
unset($_GET['trip_id']);
ob_start();
require BASE_PATH.'/app/View/pages/company_waste_trip_closing.php';
$html=(string)ob_get_clean();

$assert=static function(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);};
$assert(substr_count($html,'class="closing-shared-issues"')===2,'each trip must show common issues block');
$assert(substr_count($html,'Начало: 29.09.2026')===2 && substr_count($html,'Завершён 30.09.2026')===2,'summary shows actual start and end dates');
$assert(substr_count($html,'Водитель: Иванов Иван')===2,'summary shows driver beside carrier');
$assert(str_contains($html,'closing-customer-settlement is-debt">Долг 250 000 ₽') && str_contains($html,'closing-customer-settlement is-paid">Оплачено 250 000 ₽ · 12.10.2026'),'executor shows customer debt or posted receipt');
$assert(\App\Service\WasteTripClosingService::customerSettlementDisplay(['customer_rate'=>'250000.00','customer_paid_amount'=>'50000.00'])===['status'=>'debt','text'=>'Долг 200 000 ₽'],'partial customer receipt leaves remaining debt');
$assert(\App\Service\WasteTripClosingService::customerSettlementDisplay(['customer_rate'=>'250000.25','customer_paid_amount'=>'250000.00'])===['status'=>'debt','text'=>'Долг 0,25 ₽'],'customer debt keeps kopecks');
$assert(\App\Service\WasteTripClosingService::customerSettlementDisplay(['customer_rate'=>null,'customer_paid_amount'=>'0.00'])===['status'=>'unknown','text'=>'Сумма рейса не указана'],'missing customer amount must not invent a debt');
$assert(str_contains($html,'data-closing-state-key="planex:trip-closing:v1:1:42"'),'saved view settings must use the current account scope');
$assert(str_contains($html,'data-closing-report-open')&&str_contains($html,'data-closing-report-dialog')&&str_contains($html,'data-closing-report-download'),'summary report must open in a popup with Excel download');
$assert(substr_count($html,'data-report-trip-id=')===2,'summary report must include both accessible trips');
$assert(str_contains($sheet,'Рейс №')&&str_contains($sheet,'Название рейса')&&str_contains($sheet,'Вывоз отходов с площадки А')&&str_contains($sheet,'ПЛАНЭКС')&&str_contains($sheet,'МГ Логистика')&&str_contains($sheet,'autoFilter ref="A1:O3"'),'Excel report must contain trip title, both executors and filter');
$assert(str_contains($sheet,'<t xml:space="preserve">Статус</t>')&&str_contains($sheet,'<t xml:space="preserve">Дата</t>')&&!str_contains($sheet,'Оплачено, документы не все'),'Excel must have separate payment status and date columns without removed flag');
$assert(str_contains($html,'Название рейса')&&str_contains($html,'Вывоз отходов с площадки А')&&!str_contains($html,'Направление</th>'),'popup must display the saved trip title');
$assert(str_contains($html,'<th scope="col">Статус</th><th scope="col">Дата</th>')&&!str_contains($html,'Оплачено, документы не все'),'popup must have separate payment status and date columns without removed flag');
$assert(str_contains($html,'closing-report-status is-present')&&str_contains($html,'closing-report-status is-missing'),'popup must color available and missing documents');
$assert(str_contains($styles,'FFE0F3E7')&&str_contains($styles,'FFFCE5E3')&&str_contains($styles,'FFFFF0C2')&&str_contains($sheet,'r="E2" s="2"')&&str_contains($sheet,'r="E3" s="1"'),'Excel must color document statuses');
$assert($reportRows[1]['diagnostic_card_state']==='present'&&str_contains($reportRows[1]['diagnostic_card'],'Тягач В222ВВ: Есть')&&str_contains($reportRows[1]['diagnostic_card'],'Полуприцеп С333СС: Есть')&&$reportRows[0]['diagnostic_card']==='Нет','report must reuse vehicle card availability for every unit');
$assert($reportRows[0]['payment_status']==='План'&&$reportRows[0]['payment_date']==='15.10.2026'&&$reportRows[1]['payment_status']==='Оплачено'&&$reportRows[1]['payment_date']==='10.10.2026','report must separate payment status from planned or actual date');
$assert($reportRows[0]['tn']==='Нет'&&$reportRows[1]['tn']==='Есть','report TN status must follow scans');
$partialTrip=$trips[1];$partialTrip['transport']['units'][1]['has_card']=false;
$partialRows=\App\Service\WasteTripClosingReportService::rows([$partialTrip],'2026-10-12');
$assert($partialRows[0]['diagnostic_card_state']==='partial'&&str_contains($partialRows[0]['diagnostic_card'],'Тягач В222ВВ: Есть')&&str_contains($partialRows[0]['diagnostic_card'],'Полуприцеп С333СС: Нет'),'both diagnostic cards must be shown separately');
$partialXlsx=\App\Service\WasteTripClosingReportService::xlsx($partialRows);
$partialArchive=new ZipArchive();$partialArchive->open($partialXlsx);
$partialSheet=(string)$partialArchive->getFromName('xl/worksheets/sheet1.xml');
$partialArchive->close();@unlink($partialXlsx);
$assert(str_contains($partialSheet,'r="J2" s="3"')&&str_contains($sheet,'r="L2" s="3"')&&str_contains($sheet,'r="L3" s="1"'),'Excel must color partial diagnostic and payment statuses');
$partlyPaid=$trips[0];$partlyPaid['carrier_paid_amount']='5000.00';
$partlyPaid['carrier_paid_date']='2026-10-10';$partlyPaid['carrier_payment_plan']['remaining']=true;
$partialPaymentRow=\App\Service\WasteTripClosingReportService::rows([$partlyPaid],'2026-10-12')[0];
$assert($partialPaymentRow['payment_status']==='План'&&$partialPaymentRow['payment_date']==='15.10.2026','partial payment keeps the outstanding planned deadline');
$overdueTrip=$trips[0];$overdueTrip['carrier_payment_plan']['date']='2026-10-03';$overdueTrip['carrier_payment_plan']['due_date']='2026-10-03';
$overdueRow=\App\Service\WasteTripClosingReportService::rows([$overdueTrip],'2026-10-12')[0];
$assert($overdueRow['payment_status']==='Просрочка (9 дней)'&&$overdueRow['payment_date']==='03.10.2026'&&$overdueRow['payment_state']==='missing','overdue status and date must refer to the confirmed due date');
$overdueXlsx=\App\Service\WasteTripClosingReportService::xlsx([$overdueRow]);
$overdueArchive=new ZipArchive();$overdueArchive->open($overdueXlsx);
$overdueSheet=(string)$overdueArchive->getFromName('xl/worksheets/sheet1.xml');
$overdueArchive->close();@unlink($overdueXlsx);
$assert(str_contains($overdueSheet,'r="L2" s="2"')&&str_contains($overdueSheet,'<t xml:space="preserve">03.10.2026</t>'),'Excel must mark overdue in red and keep the due date in its own cell');
$overdueTrip['carrier_payment_plan']['due_date']=null;
$forecastRow=\App\Service\WasteTripClosingReportService::rows([$overdueTrip],'2026-10-12')[0];
$assert($forecastRow['payment_status']==='План'&&$forecastRow['payment_date']==='03.10.2026','forecast date must not become overdue before its actual event');
$mgPaid=$trips[1];$mgPaid['carrier_rate']=null;$mgPaid['carrier_payment_plan']=null;
$mgPaidRow=\App\Service\WasteTripClosingReportService::rows([$mgPaid],'2026-10-12')[0];
$assert($mgPaidRow['payment_status']==='Оплачено'&&$mgPaidRow['payment_date']==='10.10.2026','MG posted carrier payment must have an actual paid status without a Себес rate');
$assert(str_contains($html,'data-closing-payment-ready')&&str_contains($html,'data-closing-manager'),'payment and manager filters must be rendered');
$assert(str_contains($html,'data-closing-entity')&&str_contains($html,'<option value="planex">ПЛАНЭКС</option>')&&str_contains($html,'<option value="mg_logistic">МГ Логистика</option>'),'all trips selector must offer both legal entities');
$assert(str_contains($html,'data-entity="planex"')&&str_contains($html,'data-entity="mg_logistic"'),'each trip must expose its legal entity for filtering');
$assert(str_contains($html,'data-closing-filter="payment"')&&str_contains($html,'На оплату'),'payment queue must have its own section');
$assert(str_contains($html,'Готово к оплате')&&str_contains($html,'payment-stage'),'ready trip must offer payment queue action');
$assert((bool)preg_match('/name="action" value="queue"[^>]*><button[^>]*>Готово к оплате<\/button>/', $html),'payment action must be enabled for clean trip');
$assert(str_contains($html,'data-payment-ready="no"')&&str_contains($html,'data-payment-ready="yes"'),'payment readiness must match document and TN issues');
$assert(str_contains($html,'<option value="71">Менеджер</option>'),'manager must be selectable by membership ID');
$assert(str_contains($html,'<option value="72">Без рейсов</option>'),'active user without trips must remain selectable');
$assert(str_contains($html,'data-manager-id="71"'),'trip must expose its responsible membership ID');
$assert(str_contains($html,'data-business-id="1"'),'trip must expose its exact business number for list search');
$assert(str_contains($html,'<b>Нет документов:</b> Подписанный договор / заявка, Счёт, АКТ/УПД, Путевой лист, Диагностическая карта: Транспортная единица А111АА'),'missing documents must include the vehicle card');
$assert(str_contains($html,'<b>ТН:</b> нет скана ТН · заявок: 2'),'same TN problem must show one compact summary');
$assert(str_contains($html,'<b>ТН:</b> нет подписи водителя · заявок: 1'),'different TN problem must stay separate');
$assert(substr_count($html,'<b>ТН:</b> нет скана ТН')===1,'repeated TN reason must be shown once');
$assert(str_contains($html,'нет скана ТН · заявки №123, №124'),'expanded trip must retain every affected request number');
$assert(str_contains($html,'Нет ошибок, счет можно оплачивать'),'clean trip must show payment readiness');
$trips[1]['review_state']='payment';$trips[1]['review_version']=3;
ob_start();require BASE_PATH.'/app/View/pages/company_waste_trip_closing.php';$paymentHtml=(string)ob_get_clean();
$assert(str_contains($paymentHtml,'name="action" value="rework"')&&str_contains($paymentHtml,'Вернуть на доработку'),'queued trip must offer return to rework');
$assert(str_contains($paymentHtml,'class="closing-edit-fieldset" disabled'),'queued trip document editing must be disabled');
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
$assert(str_contains($html,'0 из 5 получено')&&str_contains($html,'5 из 5 получено'),'existing vehicle cards must not count as trip closing files');
$assert(substr_count($html,'<strong>Реестр</strong>')===1,'extra files with the same title must form one document');
$assert(substr_count($html,'name="document_files[]"')>=5,'document uploads must accept multiple files');
$assert(!str_contains($html,'Тягач · МАЗ В222ВВ')&&!str_contains($html,'Полуприцеп · ТОНАР С333СС'),'existing vehicle cards must not be requested again');
$assert(str_contains($html,'name="vehicle_unit_id" value="8"')&&!str_contains($html,'name="vehicle_unit_id" value="9"')&&!str_contains($html,'name="vehicle_unit_id" value="10"'),'only missing card can be uploaded from the trip');
$assert(!str_contains($html,'name="card_number"')&&!str_contains($html,'name="card_date"'),'diagnostic card form must ask for files only');
$assert(str_contains($html,'name="card_files[]"')&&str_contains($html,'name="card_files[]" accept=".pdf,.jpg,.jpeg,.png,.webp,.tif,.tiff,.doc,.docx" multiple required'),'diagnostic card must accept one or several files');
$assert(substr_count($html,'<strong>Диагностическая карта транспорта</strong>')===1,'only missing vehicle cards need a document row');
$assert(str_contains($html,'class="closing-upload-form closing-card-form"'),'diagnostic cards must use the same automatic upload form as other documents');
$assert(!str_contains($html,'Сохранить карту')&&!str_contains($html,'class="closing-transport"'),'separate card section and save button must be absent');
$assert(strpos($html,'<strong>Путевой лист</strong>')<strpos($html,'<strong>Диагностическая карта транспорта</strong>') && strpos($html,'<strong>Диагностическая карта транспорта</strong>')<strpos($html,'<strong>Реестр</strong>'),'required cards must appear before optional documents');
$assert(str_contains($html,'data-closing-action-result="success" hidden'),'successful action marker must remain available to inline update');
$assert(!str_contains($html,'class="notice success"'),'upload success banner must not be visible');
$assert(!str_contains($html,'Добавьте файлы. Отправка комплекта на почту временно отключена.'),'obsolete closing documents hint must be removed');
$assert(substr_count($html,'class="closing-trip-facts"')===2,'each expanded trip must display company and carrier finance facts');
$assert(str_contains($html,'<small>Компания рейса</small><b>ПЛАНЭКС</b>')&&str_contains($html,'<small>Компания рейса</small><b>МГ Логистика</b>'),'trip details must identify the operating company');
$assert(str_contains($html,'<small>Название перевозчика</small><b>Перевозчик</b>')&&str_contains($html,'<small>ФИО водителя</small><b>Иванов Иван</b>'),'trip details must show the carrier and full driver name');
$assert(str_contains($html,'<small>Ставка перевозчика · Себес, руб</small><b>25 000,00 ₽</b>')&&str_contains($html,'<small>Ставка перевозчика · Продажа</small><b>35 000,00 ₽</b>'),'trip rate must use Себес for PLANEX and sale for MG');
$assert(str_contains($html,'<small>Оплачено перевозчику</small><b>0,00 ₽</b>')&&str_contains($html,'<small>Оплачено перевозчику</small><b>25 000,00 ₽</b>')&&!str_contains($html,'<small>Стоимость перевозки</small>'),'trip details must show factual carrier payment instead of planned cost');
$assert(str_contains($html,'Срок оплаты: 15.10.2026')&&str_contains($html,'Оплачено: 10.10.2026'),'trip details must show payment term and actual payment date');
$overdueTrip=$trips[0];$overdueTrip['carrier_payment_plan']['date']='2026-10-10';$overdueTrip['carrier_payment_plan']['due_date']='2026-10-10';
$assert(str_contains(\App\Service\WasteTripClosingService::carrierPaymentDisplay($overdueTrip,'2026-10-12')['text'],'Просрочено на 2 дня'),'confirmed unpaid deadline must show overdue days');
$overdueTrip['carrier_payment_plan']['due_date']=null;
$assert(\App\Service\WasteTripClosingService::carrierPaymentDisplay($overdueTrip,'2026-10-12')['status']==='forecast','forecast date must not be marked overdue');
$assert(str_contains($html,'class="btn btn-primary closing-submit" disabled'),'review action must be disabled');
$assert(!str_contains($html,'data-review-form'),'email form must not be rendered');
$trips=[$trips[1]];
$trips[0]['review_state']='work';
$trips[0]['transport']['units'][1]['has_card']=false;
$reportRows=\App\Service\WasteTripClosingReportService::rows($trips,'2026-10-12');
ob_start();
require BASE_PATH.'/app/View/pages/company_waste_trip_closing.php';
$missingCardHtml=(string)ob_get_clean();
$assert(str_contains($missingCardHtml,'data-payment-ready="yes"')
    && (bool)preg_match('/name="action" value="queue"[^>]*><button[^>]*>Готово к оплате<\/button>/', $missingCardHtml)
    && str_contains($missingCardHtml,'Диагностическая карта: Полуприцеп С333СС'),
    'missing diagnostic card stays visible but does not disable payment action');
echo "waste_trip_closing_view: PASS\n";
