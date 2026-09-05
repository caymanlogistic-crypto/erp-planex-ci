<?php

require_once base_path('app/Http/Controllers/Company/FinanceManagementBalanceController.php');

$controller = new \App\Http\Controllers\Company\FinanceManagementBalanceController($config, $db);

$router->get('/company/finance/reports/management-balance', [$controller, 'index'], 'company.finance.management_balance');
