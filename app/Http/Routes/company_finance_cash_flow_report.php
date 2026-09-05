<?php

require_once base_path('app/Http/Controllers/Company/FinanceCashFlowReportController.php');

$controller = new \App\Http\Controllers\Company\FinanceCashFlowReportController($config, $db);

$router->get('/company/finance/reports/cash-flow', [$controller, 'index'], 'company.finance.cash_flow');
