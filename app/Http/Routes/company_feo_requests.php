<?php

require_once base_path('app/Service/FeoXlsxParser.php');
require_once base_path('app/Service/FeoRequestService.php');
require_once base_path('app/Http/Controllers/Company/FeoRequestsController.php');

$feoController = new \App\Http\Controllers\Company\FeoRequestsController($config, $db);
$router->get('/company/feo/requests', [$feoController, 'index']);
$router->get('/company/feo/requests/{id}/json', [$feoController, 'detail']);
$router->post('/company/feo/requests/import/analyze', [$feoController, 'analyzeImport']);
$router->post('/company/feo/requests/import/commit', [$feoController, 'commitImport']);
$router->post('/company/feo/requests/import/rollback', [$feoController, 'rollbackImport']);
$router->post('/company/feo/requests/import/delete', [$feoController, 'deleteImport']);
