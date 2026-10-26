<?php

require_once base_path('app/Service/FeoTransferService.php');
require_once base_path('app/Service/FeoWeightBatchService.php');
require_once base_path('app/Service/FeoWeightChangeNotificationService.php');
require_once base_path('app/Http/Controllers/Company/FeoWeightBatchController.php');

$feoWeightController = new \App\Http\Controllers\Company\FeoWeightBatchController($config, $db);
$feoWeightPage = 'company.feo.weights';
$router->get('/company/feo/requests/weights', [$feoWeightController, 'index'], $feoWeightPage);
$router->post('/company/feo/requests/weights/preview', [$feoWeightController, 'preview'], $feoWeightPage);
$router->post('/company/feo/requests/weights/create', [$feoWeightController, 'create'], $feoWeightPage);
$router->get('/company/feo/requests/weights/{id}/json', [$feoWeightController, 'detail'], $feoWeightPage);
$router->post('/company/feo/requests/weights/{id}/revert', [$feoWeightController, 'revert'], $feoWeightPage);
$router->post('/company/feo/requests/weights/{id}/delete', [$feoWeightController, 'deleteReturned'], $feoWeightPage);
