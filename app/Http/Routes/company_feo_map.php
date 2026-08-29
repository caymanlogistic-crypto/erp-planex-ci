<?php

require_once base_path('app/Service/FeoUtilizerService.php');
require_once base_path('app/Service/FeoMapService.php');
require_once base_path('app/Service/FeoMapTileService.php');
require_once base_path('app/Service/FeoRequestService.php');
require_once base_path('app/Http/Controllers/Company/FeoMapController.php');
require_once base_path('app/Http/Controllers/Company/FeoUtilizerController.php');

$feoMapController = new \App\Http\Controllers\Company\FeoMapController($config, $db);
$router->get('/company/feo/requests/map', [$feoMapController, 'index']);
$router->get('/company/feo/requests/map/data', [$feoMapController, 'data']);
$router->get('/company/feo/requests/map/tiles/{z}/{x}/{y}', [$feoMapController, 'tile']);

$feoUtilizerController = new \App\Http\Controllers\Company\FeoUtilizerController($config, $db);
$router->get('/company/feo/utilizers', [$feoUtilizerController, 'index']);
$router->post('/company/feo/utilizers', [$feoUtilizerController, 'create']);
$router->post('/company/feo/utilizers/{id}/update', [$feoUtilizerController, 'update']);
$router->post('/company/feo/utilizers/{id}/delete', [$feoUtilizerController, 'delete']);
$router->post('/company/feo/utilizers/rules', [$feoUtilizerController, 'createRule']);
$router->post('/company/feo/utilizers/rules/{id}/delete', [$feoUtilizerController, 'deleteRule']);
$router->post('/company/feo/utilizers/overrides', [$feoUtilizerController, 'setOverride']);
$router->post('/company/feo/utilizers/overrides/{id}/delete', [$feoUtilizerController, 'deleteOverride']);
