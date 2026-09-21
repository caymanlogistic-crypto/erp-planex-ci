<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Service/TripDocumentData.php';

use App\Service\TripDocumentData;

$failures=[];
$assert=static function(bool $condition,string $message) use (&$failures):void {if(!$condition)$failures[]=$message;};

$assert(
    TripDocumentData::tripLabel([
        'number'=>255,
        'comment'=>'Ростов 10тн_1',
        'driver_names'=>'Иванов Алексей Александрович',
        'contractor_name'=>'Индивидуальный предприниматель Пинчук Сергей Борисович',
    ])==='#255 Ростов 10тн_1 - Иванов А.А. - ИП Пинчук С.Б.',
    'Waste trip label does not match the agreed format.'
);
$assert(TripDocumentData::carrierDisplayName('ИП Пинчук С.Б.')==='ИП Пинчук С.Б.','Already shortened IP name is damaged.');
$assert(TripDocumentData::carrierDisplayName('ООО «Перевозчик»')==='ООО «Перевозчик»','Legal entity name must stay intact.');

$view=file_get_contents(dirname(__DIR__).'/app/View/pages/company_trip_documents.php');
$js=file_get_contents(dirname(__DIR__).'/public/assets/js/trip-documents.js');
$css=file_get_contents(dirname(__DIR__).'/public/assets/css/trip-documents.css');
$assert(str_contains($view,'class="page-head-left"')&&str_contains($view,'class="page-title"')&&str_contains($view,'class="page-summary"'),'Document pages do not use the standard ERP page box.');
$assert(str_contains($view,'table-card table-card--toolbar-only')&&str_contains($view,'class="table-toolbar"'),'Collection does not use the standard ERP table box.');
$assert(str_contains($view,'data-trip-search')&&str_contains($view,'data-trip-options')&&str_contains($view,'data-trip-option'),'Trip picker is not a searchable field.');
$assert(!str_contains($view,"e('Отходы #"),'Legacy waste trip labels remain in the page.');
$assert(str_contains($js,'const filterTrips=')&&str_contains($js,'.includes(query)')&&str_contains($js,"setCustomValidity('Выберите рейс из списка.')"),'Searchable trip filtering or validation is missing.');
$assert(str_contains($css,'.table-card.table-card--toolbar-only')&&str_contains($css,'grid-template-rows: 36px auto'),'Collection rows can be clipped by the base four-row table grid.');

if($failures){foreach($failures as $failure)fwrite(STDERR,"FAIL: {$failure}\n");exit(1);}
echo "TRIP_DOCUMENT_UI_CONTRACT=PASS\n";
