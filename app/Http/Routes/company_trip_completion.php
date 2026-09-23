<?php
require_once base_path('app/Service/TripCompletionService.php');
require_once base_path('app/Http/Controllers/Company/TripCompletionController.php');
$tripCompletionController=new \App\Http\Controllers\Company\TripCompletionController($config,$db);
$tripCompletionPage='company.documents.collection';
$router->get('/company/logistics-assistant',[$tripCompletionController,'index'],$tripCompletionPage);
$router->post('/company/logistics-assistant/{tripId}/requests/{requestId}',[$tripCompletionController,'save'],$tripCompletionPage);
$router->post('/company/logistics-assistant/{tripId}/requests/{requestId}/documents',[$tripCompletionController,'upload'],$tripCompletionPage);
$router->post('/company/logistics-assistant/{tripId}/requests/{requestId}/documents/{documentId}/delete',[$tripCompletionController,'deleteFile'],$tripCompletionPage);
$router->get('/company/logistics-assistant/{tripId}/documents/{documentId}',[$tripCompletionController,'download'],$tripCompletionPage);
$router->get('/company/logistics-assistant/{tripId}/report',[$tripCompletionController,'report'],$tripCompletionPage);
$router->get('/company/logistics-assistant/{tripId}/package',[$tripCompletionController,'package'],$tripCompletionPage);
