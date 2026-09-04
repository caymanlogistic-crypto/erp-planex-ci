<?php
declare(strict_types=1);
$root=dirname(__DIR__);$source=(string)file_get_contents($root.'/app/Http/Controllers/Company/WasteTripController.php');$routes=(string)file_get_contents($root.'/app/Http/Routes/company_linear_trips.php');$page=(string)file_get_contents($root.'/app/View/pages/company_waste_trips.php');$legacy=(string)file_get_contents($root.'/app/View/pages/company_waste_trip_legacy.php');$prefill=(string)file_get_contents($root.'/app/Service/WasteTripLegacyPrefillService.php');
$fail=static function(string$m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
foreach(['index','legacyIndex','materializeSubmit']as$m){if(!str_contains($source,'public function '.$m.'('))$fail('missing '.$m);}
if(str_contains($source,'importIndex')||str_contains($source,'importSubmit'))$fail('old import controller actions remain');
foreach(['index','legacyIndex']as$m){$start=strpos($source,'public function '.$m.'(');$next=strpos($source,"\n    public function ",$start+20);$body=substr($source,$start,$next===false?null:$next-$start);if(!str_contains($body,"require base_path('app/View/layouts/main.php');")||!str_contains($body,'$config=$this->config;')||!str_contains($body,'$db=$this->db;'))$fail($m.' main layout bindings missing');}
if(!str_contains($routes,"/company/trips/departures/legacy")||!str_contains($routes,'materializeSubmit'))$fail('legacy catalog/create route missing');
if(str_contains($routes,"/company/trips/departures/import"))$fail('old import route remains');
if(!str_contains($page,'Рейсы из старой БД')||str_contains($page,'Импорт из старой системы'))$fail('target navigation not canonical');
foreach(['waste-request-list','flex-wrap:wrap','overflow-wrap:anywhere','word-break:break-word','max-width:0','text-overflow:clip!important']as$n)if(!str_contains($page,$n))$fail('registry wrap contract missing '.$n);
if(str_contains($page,'white-space:nowrap')||str_contains($page,'text-overflow:ellipsis'))$fail('registry contains nowrap/ellipsis that can hide request list');
foreach(['Создание рейса','Заявки ФЭО *','Заказчик *','Менеджер *','Статус *','Исполнитель','Назначение *','Планирование начала *','Плановое окончание','Фактическое начало','Фактическое окончание','Описание рейса','Создать рейс','Отмена']as$n)if(!str_contains($legacy,$n))$fail('legacy ordinary create form missing '.$n);
if(substr_count($legacy,'class="modal-overlay"')!==1||substr_count($legacy,'id="waste-trip-create-modal"')!==1)$fail('legacy catalog must contain one page-specific modal overlay');
foreach(['Заявка ','из старой базы не найдена в ERP','found_request_ids','missing_request_ids','planned_finish_date','data-legacy-other-warnings']as$n)if(!str_contains($legacy,$n))$fail('legacy reference/warning UI missing '.$n);
if(!str_contains($prefill,"SOURCE_SYSTEM = 'legacy_prefill'")||!str_contains($prefill,'createFromLegacyPrefill'))$fail('legacy_prefill persistence contract missing');
if(str_contains($legacy,'Материализация')||str_contains($legacy,'Материализовать'))$fail('legacy catalog still exposes materialization popup wording');
echo "WASTE_TRIP_OWNER_LAYOUT=PASS\n";
