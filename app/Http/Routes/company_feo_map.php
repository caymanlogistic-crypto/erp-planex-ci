<?php

require_once base_path('app/Service/FeoMapService.php');
require_once base_path('app/Service/FeoMapTileService.php');
require_once base_path('app/Service/FeoUtilizerService.php');
require_once base_path('app/Http/Controllers/Company/FeoMapController.php');
require_once base_path('app/Http/Controllers/Company/FeoUtilizerController.php');

$feoMapController = new \App\Http\Controllers\Company\FeoMapController($config, $db);
$feoMapPage = 'company.feo.map';
$router->get('/company/feo/requests/map', [$feoMapController, 'index'], $feoMapPage);
$router->get('/company/feo/requests/map/data', [$feoMapController, 'data'], $feoMapPage);
$router->get('/company/feo/requests/map/tiles/{z}/{x}/{y}', [$feoMapController, 'tile'], $feoMapPage);

$feoUtilizerController = new \App\Http\Controllers\Company\FeoUtilizerController($config, $db);
$feoUtilizersPage = 'company.feo.utilizers';
$router->get('/company/feo/utilizers', [$feoUtilizerController, 'index'], $feoUtilizersPage);
$router->post('/company/feo/utilizers', [$feoUtilizerController, 'create'], $feoUtilizersPage);
$router->get('/company/feo/utilizers/{id}/modal-view', [$feoUtilizerController, 'modalView'], $feoUtilizersPage);
$router->get('/company/feo/utilizers/{id}/modal-edit', [$feoUtilizerController, 'modalEditForm'], $feoUtilizersPage);
$router->post('/company/feo/utilizers/{id}/modal-edit', [$feoUtilizerController, 'modalEditSubmit'], $feoUtilizersPage);
$router->post('/company/feo/utilizers/{id}/modal-delete', [$feoUtilizerController, 'modalDelete'], $feoUtilizersPage);
