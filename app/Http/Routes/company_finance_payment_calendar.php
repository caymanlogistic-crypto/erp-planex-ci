<?php

require_once base_path('app/Http/Controllers/Company/FinancePaymentCalendarController.php');

$controller = new \App\Http\Controllers\Company\FinancePaymentCalendarController($config, $db);

$router->get('/company/finance/payment-calendar', [$controller, 'index'], 'company.finance.payment_calendar');
