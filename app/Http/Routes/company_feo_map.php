<?php

require_once base_path('app/Service/FeoMapService.php');
require_once base_path('app/Service/FeoMapTileService.php');
require_once base_path('app/Service/FeoUtilizerService.php');
require_once base_path('app/Http/Controllers/Company/FeoMapController.php');
require_once base_path('app/Http/Controllers/Company/FeoUtilizerController.php');

$feoMapController = new \App\Http\Controllers\Company\FeoMapController($config, $db);
$router->get('/company/feo/requests/map', [$feoMapController, 'index']);
$router->get('/company/feo/requests/map/data', [$feoMapController, 'data']);
$router->get('/company/feo/requests/map/tiles/{z}/{x}/{y}', [$feoMapController, 'tile']);

$feoUtilizerController = new \App\Http\Controllers\Company\FeoUtilizerController($config, $db);
$router->get('/company/feo/utilizers', [$feoUtilizerController, 'index']);
$router->post('/company/feo/utilizers', [$feoUtilizerController, 'create']);
$router->get('/company/feo/utilizers/{id}/modal-view', [$feoUtilizerController, 'modalView']);
$router->get('/company/feo/utilizers/{id}/modal-edit', [$feoUtilizerController, 'modalEditForm']);
$router->post('/company/feo/utilizers/{id}/modal-edit', [$feoUtilizerController, 'modalEditSubmit']);
$router->post('/company/feo/utilizers/{id}/modal-delete', [$feoUtilizerController, 'modalDelete']);
