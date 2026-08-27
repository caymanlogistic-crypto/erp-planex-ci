<?php

require_once base_path('app/Service/FeoPlacementInputParser.php');
require_once base_path('app/Service/FeoRequestCostService.php');
require_once base_path('app/Service/FeoRequestService.php');
require_once base_path('app/Http/Controllers/Company/FeoRequestCostController.php');

$feoRequestCostController = new \App\Http\Controllers\Company\FeoRequestCostController($config, $db);
$router->get('/company/feo/requests/costs', [$feoRequestCostController, 'index']);
$router->post('/company/feo/requests/costs/validate', [$feoRequestCostController, 'validate']);
$router->post('/company/feo/requests/costs/analyze', [$feoRequestCostController, 'analyzeCreate']);
$router->post('/company/feo/requests/costs/commit', [$feoRequestCostController, 'commitCreate']);
$router->get('/company/feo/requests/costs/search', [$feoRequestCostController, 'search']);
$router->get('/company/feo/requests/costs/blocks/{id}/json', [$feoRequestCostController, 'detail']);
$router->post('/company/feo/requests/costs/blocks/{id}/edit/analyze', [$feoRequestCostController, 'analyzeEdit']);
$router->post('/company/feo/requests/costs/edit/commit', [$feoRequestCostController, 'commitEdit']);
