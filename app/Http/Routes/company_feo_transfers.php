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
$router->get('/company/feo/requests/transfers/{id}/export',[$feoTransferController,'export'],$feoTransfersPage);
$router->get('/company/feo/requests/coordinates',[$feoTransferController,'coordinates'],$feoCoordinatesPage);
$router->post('/company/feo/requests/coordinates/geocode',[$feoTransferController,'geocode'],[$feoCoordinatesPage,$feoTransfersPage]);
$router->post('/company/feo/requests/coordinates/settings',[$feoTransferController,'saveKey'],'company.roles_access');
