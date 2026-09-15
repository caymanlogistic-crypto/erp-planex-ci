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
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/erp-ui.css"></head><body><main class="workspace-shell-1690"><?php require BASE_PATH.'/app/View/pages/company_max_settings.php'; ?></main></body></html>
