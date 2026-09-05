<?php

require_once base_path('app/Http/Controllers/Company/FinanceOperationController.php');

$controller = new \App\Http\Controllers\Company\FinanceOperationController($config, $db);
$operationsPage = 'company.finance.operations';

$router->get('/company/finance/operations', [$controller, 'index'], $operationsPage);
$router->get('/company/finance/operations/{id}/modal-view', [$controller, 'modalView'], $operationsPage);
$router->get('/company/finance/operations/{id}/allocate', [$controller, 'modalAllocateForm'], $operationsPage);
$router->post('/company/finance/operations/{id}/allocate', [$controller, 'allocateSubmit'], $operationsPage);
$router->post('/company/finance/operations/allocations/{id}/cancel', [$controller, 'allocationCancel'], $operationsPage);
$router->post('/company/finance/operations/{id}/cancel', [$controller, 'cancel'], $operationsPage);
$router->get('/company/finance/operations/{id}/history', [$controller, 'history'], $operationsPage);
$router->get('/company/finance/operations/route-payments', [$controller, 'routePayments'], $operationsPage);
