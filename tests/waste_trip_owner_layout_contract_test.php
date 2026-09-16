<?php
declare(strict_types=1);
$root=dirname(__DIR__);$source=(string)file_get_contents($root.'/app/Http/Controllers/Company/WasteTripController.php');$routes=(string)file_get_contents($root.'/app/Http/Routes/company_linear_trips.php');$page=(string)file_get_contents($root.'/app/View/pages/company_waste_trips.php');$view=(string)file_get_contents($root.'/app/View/partials/company_waste_trip_modal_view.php');$edit=(string)file_get_contents($root.'/app/View/partials/company_waste_trip_modal_edit.php');
$fail=static function(string$m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
foreach(['index','synchronizeSubmit']as$m){if(!str_contains($source,'public function '.$m.'('))$fail('missing '.$m);}
if(str_contains($source,'importIndex')||str_contains($source,'importSubmit'))$fail('old import controller actions remain');
foreach(['index']as$m){$start=strpos($source,'public function '.$m.'(');$next=strpos($source,"\n    public function ",$start+20);$body=substr($source,$start,$next===false?null:$next-$start);if(!str_contains($body,"require base_path('app/View/layouts/main.php');")||!str_contains($body,'$config=$this->config;')||!str_contains($body,'$db=$this->db;'))$fail($m.' main layout bindings missing');}
if(str_contains($routes,"/company/trips/departures/import"))$fail('old import route remains');
if(!str_contains($page, 'waste-count')||!str_contains($page, 'Registry::executorUnmatched'))$fail('registry count/mismatch contract missing');
if(substr_count($page,'id="waste-trip-view-modal"')!==1||str_contains($page,'id="waste-trip-edit-modal"'))$fail('Waste Trip edit must reuse the standard view modal shell');
if(!str_contains($page,"data-waste-cancel-edit")||!str_contains($page,"loadModal('view',c.dataset.wasteCancelEdit)"))$fail('edit cancel must return to Waste Trip view in the same modal');
if(!str_contains($page,"root.querySelector('form[data-linear-trip-form]')")||!str_contains($page,'window.initLinearTripForm(f)'))$fail('dynamically loaded edit form must initialize the existing Linear Trips form UX');
if(!str_contains($view,'class="btn btn-ghost" onclick="closeModal(\'waste-trip-view-modal\')">Закрыть</button>')||!str_contains($view,'class="btn btn-primary" data-waste-open-edit="<?=$tripId?>">Редактировать</button>'))$fail('view footer must use the Linear Trips Close/Edit design-system actions');
if(!str_contains($view,"!==WasteTripService::STATUS_CANCELLED):?><button type=\"button\" class=\"btn btn-primary\" data-waste-open-edit")||str_contains($view,"[WasteTripService::STATUS_COMPLETED,WasteTripService::STATUS_CANCELLED],true)):?><button type=\"button\" class=\"btn btn-primary\" data-waste-open-edit"))$fail('completed Waste Trips must retain the standard edit action; cancelled trips remain closed');
if(!str_contains($edit,'id="waste-trip-edit-form"')||!str_contains($edit,'data-waste-modal-title="Редактирование рейса #<?=$businessId?>"'))$fail('edit form/title contract missing');
if(!str_contains($edit,'class="btn btn-ghost" data-waste-cancel-edit="<?=$tripId?>">Отмена</button>')||!str_contains($edit,'class="btn btn-primary">Сохранить</button>'))$fail('edit footer must use the Linear Trips Cancel/Save design-system actions');
echo "WASTE_TRIP_OWNER_LAYOUT=PASS\n";

if(!str_contains($page,'Синхронизация рейсов со старой БД')||!str_contains($page,"app_url('/company/trips/departures/synchronize')")||!str_contains($page,'csrfField()'))$fail('sync button and CSRF required');
if(str_contains($routes,'departures/legacy')||str_contains($source,'materializeSubmit')||str_contains($source,'legacyIndex'))$fail('manual import remains');

require __DIR__.'/waste_trip_registry_render_test.php';
