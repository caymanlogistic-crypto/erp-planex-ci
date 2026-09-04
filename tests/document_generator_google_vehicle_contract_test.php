<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
require_once $root.'/bootstrap/app.php';
require_once $root.'/app/Service/AccessControlService.php';
require_once $root.'/app/Service/DocumentService.php';
require_once $root.'/app/Service/DocumentTemplateService.php';
require_once $root.'/app/Service/FeoRequestInteractionService.php';
require_once $root.'/app/Service/DocumentGeneratorService.php';

use App\Service\DocumentGeneratorService;
use App\Service\DocumentTemplateService;
use App\Service\GoogleSheetsRequestContactService;

$fail=static fn(string $m)=>throw new RuntimeException($m);
$ok=static function(bool $v,string $m)use($fail){if(!$v)$fail($m);};
$eq=static function(mixed $a,mixed $b,string $m)use($fail){if($a!==$b)$fail($m.' expected='.var_export($b,true).' actual='.var_export($a,true));};

$csv1="ID,c1,c2,c3,c4,c5,c6,c7,c8,contacts\n300001,,,,,,,,, Иван +79990000001 \n300002,,,,,,,,,\"Петр +79990000002 / Мария +79990000003\"\n300003,,,,,,,,,\n300001,,,,,,,,,Иван NEW +79990000004\n";
$service1=new GoogleSheetsRequestContactService(static fn(string $url):string=>$csv1);
$r1=$service1->loadContacts(['300001','300002','300003','300004']);
$eq($r1['contacts']['300001'],'Иван NEW +79990000004','G1/later duplicate must follow timeline mapping semantics');
$eq($r1['contacts']['300002'],'Петр +79990000002 / Мария +79990000003','G6 independent Google contact');
$eq($r1['contacts']['300003'],'—','G4 empty Google contact');
$eq($r1['contacts']['300004'],'—','G3 missing Google row');
$eq($r1['warning'],null,'Google available warning empty');

$access=(string)file_get_contents($root.'/app/Service/DocumentGeneratorAccessTrait.php');
$ok(!str_contains($access,'kontakt_tel')&&!str_contains($access,'tel_dopolnitelnyy')&&!str_contains($access,'kontakt_email'),'G2 generator query must not read legacy DB contacts');

$down=new GoogleSheetsRequestContactService(static function(string $url):string{throw new RuntimeException('offline');});
$rd=$down->loadContacts(['300001']);
$eq($rd['contacts']['300001'],'—','technical Google failure uses dash');
$ok(trim((string)$rd['warning'])!=='','technical Google failure exposes warning');

$oldSnapshot=['requests'=>[['zayavka_id'=>'300001','contacts'=>$r1['contacts']['300001']]]];
$csv2="ID,c1,c2,c3,c4,c5,c6,c7,c8,contacts\n300001,,,,,,,,,Петр FINAL +79990000005\n";
$r2=(new GoogleSheetsRequestContactService(static fn(string $url):string=>$csv2))->loadContacts(['300001']);
$newSnapshot=['requests'=>[['zayavka_id'=>'300001','contacts'=>$r2['contacts']['300001']]]];
$eq($oldSnapshot['requests'][0]['contacts'],'Иван NEW +79990000004','G5 old snapshot immutable');
$eq($newSnapshot['requests'][0]['contacts'],'Петр FINAL +79990000005','G5 new generation sees new Google value');
echo "DOCUMENT_GENERATOR_GOOGLE_CONTACTS=PASS\n";

$parts=glob($root.'/resources/document-templates/contract_vehicle_lease_crew_v3.docx.b64.part*')?:[];sort($parts,SORT_STRING);$ok($parts!==[],'v3 parts');$bytes='';foreach($parts as$p){$e=preg_replace('/\s+/','',(string)file_get_contents($p))??'';$d=base64_decode($e,true);$ok(is_string($d)&&$d!=='','v3 part decode');$bytes.=$d;}$eq(hash('sha256',$bytes),'87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2','v3 SHA');
$template=tempnam(sys_get_temp_dir(),'docgen_v3_').'.docx';file_put_contents($template,$bytes);
$validation=DocumentTemplateService::validateDocxFile($template);$ok($validation['ok']??false,'v3 validates');$ph=$validation['placeholders']??[];foreach(['vehicle.model','vehicle.plate','vehicle.vin','trailer.display','request.contacts'] as$n)$ok(in_array($n,$ph,true),'v3 placeholder '.$n);

$fixed=[];foreach($ph as$name){if(str_starts_with((string)$name,'request.'))continue;$fixed[(string)$name]='X';}
$fixed['vehicle.label']='Транспортное средство';$fixed['vehicle.model']='КАМАЗ 54901';$fixed['vehicle.plate']='А123ВС77';$fixed['vehicle.vin']='XTC11111111111111';$fixed['trailer.display']='';
$request=['request.index'=>'1','request.id'=>'300001','request.mass_kg'=>'1000','request.sender'=>'Отправитель','request.loading_address'=>'Адрес','request.contacts'=>'Иван +79990000001'];
$single=tempnam(sys_get_temp_dir(),'docgen_single_').'.docx';DocumentTemplateService::render($template,$single,$fixed,[$request]);
$visible=static function(string $path):string{$z=new ZipArchive();if($z->open($path,ZipArchive::RDONLY)!==true)throw new RuntimeException('open render');try{return DocumentTemplateService::visibleText((string)$z->getFromName('word/document.xml'));}finally{$z->close();}};
$singleText=$visible($single);foreach(['КАМАЗ 54901','А123ВС77','XTC11111111111111'] as$n)$ok(str_contains($singleText,$n),'V1 single render '.$n);$ok(!str_contains($singleText,'Полуприцеп:'),'V5 no empty secondary block');

$fixed['vehicle.label']='Тягач';$fixed['vehicle.model']='MAN TGX';$fixed['vehicle.plate']='А111АА77';$fixed['vehicle.vin']='WMA11111111111111';$fixed['trailer.display']="\nПолуприцеп: SCHMITZ S01\nгос. номер АВ123477\nVIN WSM22222222222222";
$combo=tempnam(sys_get_temp_dir(),'docgen_combo_').'.docx';DocumentTemplateService::render($template,$combo,$fixed,[$request]);$comboText=$visible($combo);foreach(['Тягач: MAN TGX','А111АА77','WMA11111111111111','Полуприцеп: SCHMITZ S01','АВ123477','WSM22222222222222'] as$n)$ok(str_contains($comboText,$n),'V2 combo render '.$n);
$z=new ZipArchive();$z->open($combo,ZipArchive::RDONLY);$xml=(string)$z->getFromName('word/document.xml');$z->close();$ok(substr_count($xml,'<w:br/>')>=4,'multiline secondary rendered with Word line breaks');

$validate=new ReflectionMethod(DocumentGeneratorService::class,'validateFixedPlaceholders');$labels=new ReflectionMethod(DocumentGeneratorService::class,'vehicleTypeLabel');
$baseSnapshot=['document'=>['number_mode'=>'automatic'],'company'=>['kpp'=>'1','ogrn'=>'1'],'executor'=>['inn'=>'7700000000','kpp'=>'1','ogrn'=>'1'],'vehicle'=>['id'=>1,'model'=>'КАМАЗ 54901','plate'=>'А123ВС77','vin'=>'XTC111'],'trailer'=>['id'=>0,'model'=>'','plate'=>'','vin'=>'','label'=>''],'parameters'=>[],'requests'=>[]];
$values=['vehicle.vin'=>'XTC111','trailer.display'=>''];
$errors=$validate->invoke(null,['vehicle.vin','trailer.display'],$values,$baseSnapshot);$eq($errors,[],'V5 no secondary VIN blocker');
$missing=$baseSnapshot;$missing['vehicle']['vin']='';$errors=$validate->invoke(null,['vehicle.vin'],['vehicle.vin'=>''],$missing);$ok((bool)array_filter($errors,static fn($e)=>str_contains((string)$e,'автомобиля')&&str_contains((string)$e,'VIN')),'V3 missing primary VIN blocks');
$secondary=$baseSnapshot;$secondary['vehicle']['label']='Тягач';$secondary['trailer']=['id'=>2,'model'=>'SCHMITZ','plate'=>'АВ123477','vin'=>'','label'=>'Полуприцеп'];$errors=$validate->invoke(null,['vehicle.vin','trailer.display'],['vehicle.vin'=>'XTC111','trailer.display'=>''],$secondary);$ok((bool)array_filter($errors,static fn($e)=>str_contains(mb_strtolower((string)$e),'полуприцеп')&&str_contains((string)$e,'VIN')),'V4 missing secondary VIN blocks');
$eq($labels->invoke(null,'tractor',false,true),'Тягач','V7 tractor label');$eq($labels->invoke(null,'trailer',true,true),'Прицеп','V7 trailer label');$eq($labels->invoke(null,'semi_trailer',true,true),'Полуприцеп','V7 semitrailer label');
$vinOld=['vehicle'=>['vin'=>'VIN-OLD']];$vinNew=['vehicle'=>['vin'=>'VIN-NEW']];$eq($vinOld['vehicle']['vin'],'VIN-OLD','V6 old VIN snapshot immutable');$eq($vinNew['vehicle']['vin'],'VIN-NEW','V6 new VIN snapshot uses current master data');
echo "DOCUMENT_GENERATOR_VEHICLE_IDENTITY=PASS\n";

@unlink($template);@unlink($single);@unlink($combo);
echo "DOCUMENT_GENERATOR_GOOGLE_VEHICLE_CONTRACT=PASS\n";
