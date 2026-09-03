<?php
declare(strict_types=1);
$root=dirname(__DIR__);$source=(string)file_get_contents($root.'/app/Http/Controllers/Company/WasteTripController.php');$routes=(string)file_get_contents($root.'/app/Http/Routes/company_linear_trips.php');$page=(string)file_get_contents($root.'/app/View/pages/company_waste_trips.php');
$fail=static function(string$m):never{fwrite(STDERR,"FAIL: {$m}\n");exit(1);};
foreach(['index','legacyIndex','materializeSubmit']as$m){if(!str_contains($source,'public function '.$m.'('))$fail('missing '.$m);}
if(str_contains($source,'importIndex')||str_contains($source,'importSubmit'))$fail('old import controller actions remain');
foreach(['index','legacyIndex']as$m){$start=strpos($source,'public function '.$m.'(');$next=strpos($source,"\n    public function ",$start+20);$body=substr($source,$start,$next===false?null:$next-$start);if(!str_contains($body,"require base_path('app/View/layouts/main.php');")||!str_contains($body,'$config=$this->config;')||!str_contains($body,'$db=$this->db;'))$fail($m.' main layout bindings missing');}
if(!str_contains($routes,"/company/trips/departures/legacy" )||!str_contains($routes,'materializeSubmit'))$fail('legacy catalog/materialization route missing');
if(str_contains($routes,"/company/trips/departures/import"))$fail('old import route remains');
if(!str_contains($page,'Рейсы из старой БД')||str_contains($page,'Импорт из старой системы'))$fail('target navigation not canonical');
echo "WASTE_TRIP_OWNER_LAYOUT=PASS\n";
