<?php

require_once base_path('app/Http/Controllers/Company/FinanceDashboardController.php');

$controller = new \App\Http\Controllers\Company\FinanceDashboardController($config, $db);

$router->get('/company/finance/dashboard', [$controller, 'index'], 'company.finance.dashboard');
