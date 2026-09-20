<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(404);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/http_runtime.php';
require_once BASE_PATH.'/app/Service/MaxEventCatalog.php';
$_SESSION=['csrf_token'=>'fixture'];
$bots=[];$chats=[['id'=>1,'name'=>'Руководство','bot_name'=>'PLANEX','bot_id'=>1,'bot_is_active'=>1,'is_active'=>1,'max_status'=>'active','chat_id'=>'-9001','chat_type'=>'chat','discovered_via'=>'manual','max_title'=>'Руководство','purpose'=>'']];
$activeChats=$chats;$logs=[];$webhookUrls=[];$successFlash=null;$errorFlash=null;
$eventsReady=true;$eventDeliveries=[];$eventRules=[];
foreach(\App\Service\MaxEventCatalog::all() as $key=>$event) $eventRules[]=['event_key'=>$key,'is_enabled'=>0,'chat_config_id'=>1,'message_template'=>$event['template'],'message_format'=>'plain','notify'=>1]+$event;

$eventRules[0]['is_enabled']=1;
$eventRules[1]['chat_config_id']=null;
if (($argv[1] ?? '') === 'many') {
    for ($i=count($eventRules)+1;$i<=43;$i++) $eventRules[] = array_replace($eventRules[0], ['event_key'=>'fixture.event.'.$i,'title'=>'Тестовое событие '.$i,'description'=>'Синтетический каталог для проверки масштаба.','is_enabled'=>$i%2]);
}
if (($argv[1] ?? '') === 'draft') {
    $draft=['event_key'=>$eventRules[1]['event_key']];
    $eventRules[1]['message_template']='Несохранённый текст после ошибки';
    $errorFlash='Добавьте качество координат в шаблон.';
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/erp-ui.css"><style>body{height:auto;min-height:100vh;overflow:auto}</style></head><body><main class="workspace-shell-1690"><?php require BASE_PATH.'/app/View/pages/company_max_settings.php'; ?></main><script src="/assets/js/modal-shell.js"></script></body></html>
