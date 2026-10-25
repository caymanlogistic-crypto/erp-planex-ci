<?php

foreach (['FeoLocationOverrideService','FeoTransferService','YandexGeocodingService'] as $service) require_once base_path('app/Service/'.$service.'.php');
require_once base_path('app/Http/Controllers/Company/FeoTransferController.php');
$feoTransferController=new \App\Http\Controllers\Company\FeoTransferController($config,$db);
$feoTransfersPage='company.feo.transfers';
$feoCoordinatesPage='company.feo.coordinates';
$router->get('/company/feo/requests/transfers',[$feoTransferController,'index'],$feoTransfersPage);
$router->post('/company/feo/requests/transfers/preview',[$feoTransferController,'preview'],$feoTransfersPage);
$router->post('/company/feo/requests/transfers/create',[$feoTransferController,'create'],$feoTransfersPage);
$router->get('/company/feo/requests/transfers/{id}/json',[$feoTransferController,'detail'],$feoTransfersPage);
$router->post('/company/feo/requests/transfers/{id}/revert',[$feoTransferController,'revert'],$feoTransfersPage);
$router->post('/company/feo/requests/transfers/{id}/delete',[$feoTransferController,'deleteReturned'],$feoTransfersPage);
$router->get('/company/feo/requests/transfers/{id}/export',[$feoTransferController,'export'],$feoTransfersPage);
$router->get('/company/feo/requests/coordinates',[$feoTransferController,'coordinates'],$feoCoordinatesPage);
$router->post('/company/feo/requests/coordinates/geocode',[$feoTransferController,'geocode'],[$feoCoordinatesPage,$feoTransfersPage]);
require_once base_path('app/Http/Controllers/Company/ServiceKeysController.php');
$serviceKeysController=new \App\Http\Controllers\Company\ServiceKeysController($config,$db);
$router->get('/company/settings/services',[$serviceKeysController,'index'],'company.service_keys');
$router->post('/company/settings/services/yandex',[$serviceKeysController,'saveYandex'],'company.service_keys');
// Compatibility for an already-open page; the same owner-only gate protects both routes.
$router->post('/company/feo/requests/coordinates/settings',[$serviceKeysController,'saveYandex'],'company.service_keys');
$router->post('/company/settings/services/mail',[$serviceKeysController,'saveMail'],'company.service_keys');
$router->get('/company/settings/services/report-recipients/suggest',[$serviceKeysController,'suggestReportRecipients'],'company.service_keys');
$router->post('/company/settings/services/report-recipients',[$serviceKeysController,'saveReportRecipients'],'company.service_keys');
$router->get('/company/settings/services/route-executor-review-recipients/suggest',[$serviceKeysController,'suggestRouteExecutorReviewRecipients'],'company.service_keys');
$router->post('/company/settings/services/route-executor-review-recipients',[$serviceKeysController,'saveRouteExecutorReviewRecipients'],'company.service_keys');
