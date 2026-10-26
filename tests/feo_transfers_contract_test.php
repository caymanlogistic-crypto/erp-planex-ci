<?php
declare(strict_types=1);
$root=dirname(__DIR__);
foreach (['FeoLocationOverrideService','FeoTransferService','YandexGeocodingService','MaxEventCatalog','FeoWeightChangeNotificationService'] as $c) require_once $root.'/app/Service/'.$c.'.php';
require_once $root.'/app/Security/PageRegistry.php';
require_once $root.'/app/Security/EffectiveAccess.php';
use App\Service\YandexGeocodingService;
use App\Service\FeoTransferService;
use App\Service\MaxEventCatalog;
use App\Service\FeoWeightChangeNotificationService;
function expectTransfer(bool $v,string $m): void { if (!$v) throw new RuntimeException($m); }
function rejectTransfer(callable $fn): void { $caught=false; try{$fn();}catch(RuntimeException){$caught=true;} expectTransfer($caught,'Expected rejection'); }
$parsed=FeoTransferService::parseIds("123456\n123457,123456;123458");
expectTransfer($parsed['ids']===['123456','123457','123458'] && $parsed['duplicates']===1,'Strict number parser');
expectTransfer(FeoTransferService::parseIds('123456 nonsense 999999')['ids']===['123456','999999'],'Free text yields independent six-digit IDs');
expectTransfer(FeoTransferService::parseIds("123456\t987654\n| 234567 | 345678 |")['ids']===['123456','234567'],'Tab and markdown paste use the first column');
expectTransfer(FeoTransferService::parseIds('123456, 123456, 123456')['duplicates']===2,'Repeated IDs counted individually');
rejectTransfer(fn()=>FeoTransferService::parseIds('1234567'));
rejectTransfer(fn()=>FeoTransferService::parseIds(''));
$response=static function(string $precision,string $position='37.123 55.456'): string { return json_encode(['response'=>['GeoObjectCollection'=>['featureMember'=>[['GeoObject'=>['Point'=>['pos'=>$position],'metaDataProperty'=>['GeocoderMetaData'=>['text'=>'Найденный адрес','precision'=>$precision,'kind'=>'house']]]]]]]]); };
foreach (['exact','number','near','range','street','other','unknown'] as $precision) {
    $service=new YandexGeocodingService('test',static fn($url)=>$response($precision));
    $r=$service->geocode('Адрес');
    expectTransfer($r['latitude']==='55.456' && $r['longitude']==='37.123','Coordinate order');
    expectTransfer($r['precision']===$precision && $r['quality']!=='','Precision is information, not a save blocker');
}
rejectTransfer(fn()=>(new YandexGeocodingService('test',static fn()=>$response('exact','181 55')))->geocode('Адрес'));
rejectTransfer(fn()=>(new YandexGeocodingService('test',static fn()=>'not json'))->geocode('Адрес'));
rejectTransfer(fn()=>(new YandexGeocodingService('test',static fn()=>'{}'))->geocode('Адрес'));
rejectTransfer(fn()=>(new YandexGeocodingService(''))->geocode('Адрес'));
foreach (['created','reverted'] as $event) {
    $key='feo.transfer.'.$event;
    $values=MaxEventCatalog::example($key);
    expectTransfer(MaxEventCatalog::formatForTemplate($key,'plain',MaxEventCatalog::event($key)['template'])==='markdown','Approved transfer copy uses Markdown');
    if ($event==='created') {
        $values['request_ids']='310500, 310501'; $values['address']='Липецк, улица Передельческая, 5Б';
        $values['source_request_id']='345356';
        $text=MaxEventCatalog::render($key,MaxEventCatalog::event($key)['template'],'markdown',$values);
        expectTransfer(str_contains($text,'**310500, 310501**') && str_contains($text,'Перемещены на адрес заявки **345356**:'),'Source-request transfer wording');
        $values['source_request_id']='';
        $text=MaxEventCatalog::render($key,MaxEventCatalog::event($key)['template'],'markdown',$values);
        expectTransfer(str_contains($text,'Перемещены на новый адрес:'),'Entered-address transfer wording');
        $values['address']='A**<script>';
        $text=MaxEventCatalog::render($key,MaxEventCatalog::event($key)['template'],'markdown',$values);
        expectTransfer(str_contains($text,'A\\*\\*') && !str_contains($text,'A**'),'Markdown dynamic address escaped');
    } else {
        $values['returned_requests']="310500: Липецк, дом 5Б\n310501: Ростов, дом 6Б";
        $text=MaxEventCatalog::render($key,MaxEventCatalog::event($key)['template'],'markdown',$values);
        expectTransfer(str_contains($text,'**310500: Липецк, дом 5Б**') && str_contains($text,'**310501: Ростов, дом 6Б**'),'Return lists original address for each request');
    }
}
$legacy="Создан пакет переноса №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nАдрес: {{address}}\nКачество координат: {{quality}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}";
expectTransfer(MaxEventCatalog::currentTemplate('feo.transfer.created',$legacy)===MaxEventCatalog::event('feo.transfer.created')['template'],'Legacy transfer template upgrades to detailed default');
$previous="Создан пакет перемещения №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nНомера заявок: {{request_ids}}\nМесто назначения: {{address}}\nКачество координат: {{quality}}\nИзменения:\n{{changes}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}";
expectTransfer(MaxEventCatalog::currentTemplate('feo.transfer.created',$previous)===MaxEventCatalog::event('feo.transfer.created')['template'],'Previous movement template upgrades from package to block wording');
$current="Создан блок перемещения №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nНомера заявок: {{request_ids}}\nМесто назначения: {{address}}\nКачество координат: {{quality}}\nИзменения:\n{{changes}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}";
expectTransfer(MaxEventCatalog::currentTemplate('feo.transfer.created',$current)===MaxEventCatalog::event('feo.transfer.created')['template'],'Current system template upgrades to approved copy');
foreach (['created'=>$legacy,'reverted'=>"Возвращён пакет переноса №{{batch_id}}: {{batch_name}}\nЗаявок: {{request_count}}\nАдрес: {{address}}\nКачество координат: {{quality}}\nАвтор: {{author}}\n{{company}} · {{created_at}}\n{{comment}}"] as $action=>$standard) {
    $key='feo.transfer.'.$action;
    $crlf=str_replace("\n","\r\n",$standard);
    expectTransfer(MaxEventCatalog::currentTemplate($key,$crlf)===MaxEventCatalog::event($key)['template'],'Production CRLF legacy '.$action.' template upgrades to approved copy');
    expectTransfer(MaxEventCatalog::formatForTemplate($key,$action==='created'?'html':'plain',$crlf)==='markdown','Production CRLF legacy '.$action.' format upgrades to Markdown');
    $custom=str_replace('Заявок:', 'Наших заявок:', $crlf);
    expectTransfer(MaxEventCatalog::currentTemplate($key,$custom)===$custom,'Owner-customized CRLF '.$action.' template remains unchanged');
    expectTransfer(MaxEventCatalog::currentTemplate($key,$crlf."\r\n")===$crlf."\r\n",'Unknown extra line remains customized '.$action.' template');
}
expectTransfer(MaxEventCatalog::currentTemplate('feo.transfer.created','OWNER custom {{request_ids}}')==='OWNER custom {{request_ids}}','OWNER custom transfer copy preserved');
expectTransfer(MaxEventCatalog::formatForTemplate('feo.transfer.created','plain','OWNER custom {{request_ids}}')==='plain','OWNER custom format preserved');
expectTransfer(MaxEventCatalog::event('feo.transfer.created')['title']==='ФЭО — создан блок перемещения','Movement event uses block wording');
$weight=FeoWeightChangeNotificationService::change(['zayavka_id'=>'123456','mass_netto'=>'1.250'],['zayavka_id'=>'123456','mass_netto'=>'1.750']);
expectTransfer($weight!==null && $weight['before_kg']===1250.0 && $weight['after_kg']===1750.0 && $weight['difference_kg']===500.0,'Weight change uses kilograms');
expectTransfer(FeoWeightChangeNotificationService::change(['zayavka_id'=>'123456','mass_netto'=>'1.250'],['zayavka_id'=>'123456','mass_netto'=>'1.2500'])===null,'Equivalent weight does not notify');
$nullToZero=FeoWeightChangeNotificationService::change(['zayavka_id'=>'123456','mass_netto'=>null],['zayavka_id'=>'123456','mass_netto'=>'0']);
expectTransfer($nullToZero!==null && $nullToZero['before_kg']===null && $nullToZero['after_kg']===0.0 && $nullToZero['difference_kg']===null,'Absent net weight changing to real zero is reported without false delta');
$zeroToNull=FeoWeightChangeNotificationService::change(['zayavka_id'=>'123456','mass_brutto'=>'0'],['zayavka_id'=>'123456','mass_brutto'=>null]);
expectTransfer($zeroToNull!==null && $zeroToNull['before_gross_kg']===0.0 && $zeroToNull['after_gross_kg']===null && $zeroToNull['gross_difference_kg']===null,'Real zero changing to absent gross weight is reported without false delta');
expectTransfer(FeoWeightChangeNotificationService::change(['zayavka_id'=>'123456','mass_netto'=>null,'mass_brutto'=>null],['zayavka_id'=>'123456','mass_netto'=>'','mass_brutto'=>null])===null,'Equivalent absent weights do not notify');
$oldWeightDefault="ФЭО — ИЗМЕНЁН ВЕС ЗАЯВОК\n{{part}}\nИсточник: {{source}}\nЗаявок: {{request_count}}\nНетто: {{old_mass_kg}} → {{new_mass_kg}} кг ({{difference_kg}} кг)\nБрутто: {{old_gross_kg}} → {{new_gross_kg}} кг ({{gross_difference_kg}} кг)\n{{changes}}\nОтветственный: {{author}}\n{{company}} · {{created_at}}";
$oldWeightCrLf=str_replace("\n","\r\n",$oldWeightDefault);
expectTransfer(MaxEventCatalog::currentTemplate('feo.request.weight_changed',$oldWeightCrLf)===MaxEventCatalog::event('feo.request.weight_changed')['template'],'Known weight default with CRLF upgrades to concise standard');
expectTransfer(MaxEventCatalog::formatForTemplate('feo.request.weight_changed','plain',$oldWeightCrLf)==='markdown','Known weight default switches to Markdown');
$legacyWeightDefault=str_replace("Нетто: {{old_mass_kg}} → {{new_mass_kg}} кг ({{difference_kg}} кг)\nБрутто: {{old_gross_kg}} → {{new_gross_kg}} кг ({{gross_difference_kg}} кг)","Общая масса: {{old_mass_kg}} → {{new_mass_kg}} кг ({{difference_kg}} кг)",$oldWeightDefault);
expectTransfer(MaxEventCatalog::currentTemplate('feo.request.weight_changed',str_replace("\n","\r\n",$legacyWeightDefault))===MaxEventCatalog::event('feo.request.weight_changed')['template'],'First-generation weight default with CRLF upgrades');
$productionWeightDefault=str_replace('ФЭО — ИЗМЕНЁН ВЕС ЗАЯВОК','ВЕС ЗАЯВКИ ИЗМЕНИСЯ',$legacyWeightDefault);
expectTransfer(MaxEventCatalog::currentTemplate('feo.request.weight_changed',str_replace("\n","\r\n",$productionWeightDefault))===MaxEventCatalog::event('feo.request.weight_changed')['template'],'Exact previous production weight template with CRLF upgrades');
expectTransfer(MaxEventCatalog::formatForTemplate('feo.request.weight_changed','plain',str_replace("\n","\r\n",$productionWeightDefault))==='markdown','Exact previous production weight template uses Markdown');
expectTransfer(MaxEventCatalog::currentTemplate('feo.request.weight_changed',str_replace('ИЗМЕНИСЯ','ИЗМЕНИЛСЯ',$productionWeightDefault))!==MaxEventCatalog::event('feo.request.weight_changed')['template'],'Similar owner-customized production template remains unchanged');
$customWeight=str_replace('Ответственный:', 'Автор:', $oldWeightCrLf);
expectTransfer(MaxEventCatalog::currentTemplate('feo.request.weight_changed',$customWeight)===$customWeight,'Customized weight template is preserved');
expectTransfer(MaxEventCatalog::formatForTemplate('feo.request.weight_changed','plain',$customWeight)==='plain','Customized weight format is preserved');
expectTransfer(MaxEventCatalog::event('feo.request.weight_changed')['title']==='ФЭО — изменён вес заявок','Weight event registered in MAX catalog');
$registry=new App\Security\PageRegistry();
expectTransfer($registry->page('company.feo.transfers')['label']==='Перемещение заявок','Exact movement menu label');
foreach (['company.feo.transfers','company.feo.coordinates'] as $key) {
    expectTransfer($registry->isGrantable($key),'New pages grantable');
    $denied=App\Security\EffectiveAccess::member(10,1,[$key=>false],[]);
    $allowed=App\Security\EffectiveAccess::member(10,1,[$key=>true],[]);
    expectTransfer(!$denied->canAccessPage($key) && $allowed->canAccessPage($key),'Effective individual visibility');
}
echo "FEO_TRANSFER_CONTRACT=PASS\n";
