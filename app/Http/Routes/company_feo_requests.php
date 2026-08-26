<?php

require_once base_path('app/Service/FeoXlsxParser.php');
require_once base_path('app/Service/FeoRequestService.php');
require_once base_path('app/Service/FeoRequestInteractionService.php');
require_once base_path('app/Service/FeoPlacementInputParser.php');
require_once base_path('app/Service/FeoPlacementService.php');
require_once base_path('app/Support/feo_freshness_navigation.php');
require_once base_path('app/Http/Controllers/Company/FeoRequestsController.php');
require_once base_path('app/Http/Controllers/Company/FeoPlacementController.php');

registerFeoFreshnessNavigation($config, $db);

$feoController = new \App\Http\Controllers\Company\FeoRequestsController($config, $db);
$router->get('/company/feo/requests', [$feoController, 'index']);
$router->get('/company/feo/requests/data', [$feoController, 'data']);
$router->get('/company/feo/requests/{id}/json', [$feoController, 'detail']);
$router->post('/company/feo/requests/{id}/update', [$feoController, 'update']);
$router->post('/company/feo/requests/import/analyze', [$feoController, 'analyzeImport']);
$router->post('/company/feo/requests/import/commit', [$feoController, 'commitImport']);
$router->post('/company/feo/requests/import/rollback', [$feoController, 'rollbackImport']);
$router->post('/company/feo/requests/import/restore', [$feoController, 'restoreImport']);
$router->post('/company/feo/requests/import/delete', [$feoController, 'deleteImport']);

$feoPlacementController = new \App\Http\Controllers\Company\FeoPlacementController($config, $db);
$router->get('/company/feo/requests/map-placement', [$feoPlacementController, 'index']);
$router->post('/company/feo/requests/map-placement/analyze', [$feoPlacementController, 'analyze']);
$router->post('/company/feo/requests/map-placement/commit', [$feoPlacementController, 'commitCreate']);
$router->get('/company/feo/requests/map-placement/current', [$feoPlacementController, 'current']);
$router->get('/company/feo/requests/map-placement/blocks/{id}/json', [$feoPlacementController, 'detail']);
$router->get('/company/feo/requests/map-placement/blocks/{id}/download', [$feoPlacementController, 'download']);
$router->post('/company/feo/requests/map-placement/blocks/{id}/preview-status', [$feoPlacementController, 'previewStatus']);
$router->post('/company/feo/requests/map-placement/status/commit', [$feoPlacementController, 'commitStatus']);
$router->post('/company/feo/requests/map-placement/reconcile/preview', [$feoPlacementController, 'previewReconcile']);
$router->post('/company/feo/requests/map-placement/reconcile/commit', [$feoPlacementController, 'commitReconcile']);
