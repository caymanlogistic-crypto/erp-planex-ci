<?php
declare(strict_types=1);
$root=dirname(__DIR__);
foreach (['FeoLocationOverrideService','FeoTransferService','YandexGeocodingService','MaxEventCatalog'] as $c) require_once $root.'/app/Service/'.$c.'.php';
require_once $root.'/app/Security/PageRegistry.php';
require_once $root.'/app/Security/EffectiveAccess.php';
use App\Service\YandexGeocodingService;
use App\Service\FeoTransferService;
use App\Service\MaxEventCatalog;
function expectTransfer(bool $v,string $m): void { if (!$v) throw new RuntimeException($m); }
function rejectTransfer(callable $fn): void { $caught=false; try{$fn();}catch(RuntimeException){$caught=true;} expectTransfer($caught,'Expected rejection'); }
$parsed=FeoTransferService::parseIds("123456\n123457,123456;123458");
expectTransfer($parsed['ids']===['123456','123457','123458'] && $parsed['duplicates']===1,'Strict number parser');
rejectTransfer(fn()=>FeoTransferService::parseIds('123456 nonsense 999999'));
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
    rejectTransfer(fn()=>MaxEventCatalog::render($key,'No quality','plain',MaxEventCatalog::example($key)));
    $values=MaxEventCatalog::example($key); $values['address']='<script>alert(1)</script>';
    $text=MaxEventCatalog::render($key,MaxEventCatalog::event($key)['template'],'html',$values);
    expectTransfer(str_contains($text,'&lt;script&gt;') && str_contains($text,$values['quality']),'Escaped MAX destination and mandatory quality');
}
$registry=new App\Security\PageRegistry();
foreach (['company.feo.transfers','company.feo.coordinates'] as $key) {
    expectTransfer($registry->isGrantable($key),'New pages grantable');
    $denied=App\Security\EffectiveAccess::member(10,1,[$key=>false],[]);
    $allowed=App\Security\EffectiveAccess::member(10,1,[$key=>true],[]);
    expectTransfer(!$denied->canAccessPage($key) && $allowed->canAccessPage($key),'Effective individual visibility');
}
echo "FEO_TRANSFER_CONTRACT=PASS\n";
