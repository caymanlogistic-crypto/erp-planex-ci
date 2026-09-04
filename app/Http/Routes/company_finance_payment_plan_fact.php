<?php

require_once base_path('app/Http/Controllers/Company/FinancePaymentPlanFactController.php');

$controller = new \App\Http\Controllers\Company\FinancePaymentPlanFactController($config, $db);

$router->get('/company/finance/reports/payment-plan-fact', [$controller, 'index'], 'company.finance.payment_plan_fact');
