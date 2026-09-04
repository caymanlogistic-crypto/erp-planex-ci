<?php

require_once base_path('app/Service/FeoPlacementInputParser.php');
require_once base_path('app/Service/FeoRequestCostService.php');
require_once base_path('app/Service/FeoRequestCostEditService.php');
require_once base_path('app/Service/FeoRequestService.php');
require_once base_path('app/Http/Controllers/Company/FeoRequestCostController.php');

$feoRequestCostController = new \App\Http\Controllers\Company\FeoRequestCostController($config, $db);
$feoCostsPage = 'company.feo.costs';
$router->get('/company/feo/requests/costs', [$feoRequestCostController, 'index'], $feoCostsPage);
$router->post('/company/feo/requests/costs/validate', [$feoRequestCostController, 'validate'], $feoCostsPage);
$router->post('/company/feo/requests/costs/analyze', [$feoRequestCostController, 'analyzeCreate'], $feoCostsPage);
$router->post('/company/feo/requests/costs/commit', [$feoRequestCostController, 'commitCreate'], $feoCostsPage);
$router->get('/company/feo/requests/costs/search', [$feoRequestCostController, 'search'], $feoCostsPage);
$router->get('/company/feo/requests/costs/blocks/{id}/json', [$feoRequestCostController, 'detail'], $feoCostsPage);
$router->post('/company/feo/requests/costs/blocks/{id}/edit/validate', [$feoRequestCostController, 'validateEdit'], $feoCostsPage);
$router->post('/company/feo/requests/costs/blocks/{id}/edit/analyze', [$feoRequestCostController, 'analyzeEdit'], $feoCostsPage);
$router->post('/company/feo/requests/costs/edit/commit', [$feoRequestCostController, 'commitEdit'], $feoCostsPage);
