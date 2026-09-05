<?php

/**
 * Legacy money-account URLs are historical-only from 2026-08-18.
 *
 * Keep the old endpoints registered only for backward compatibility with
 * bookmarks and historical links. No route below can create or mutate an old
 * money-account record. Historical finance_operations and related audit data
 * stay intact for reports and employee settlement history.
 */

$legacyMoneyAccountGet = static function (): void {
    redirect_to('/company/finance/employee-payments');
};

$legacyMoneyAccountPost = static function (): void {
    verifyCsrfRequest();
    $_SESSION['employee_payments_error'] = 'Этот устаревший способ операции отключён. Используйте операции банка и сотрудников.';
    redirect_to('/company/finance/employee-payments');
};

$cashPage = 'company.finance.cash';
$router->get('/company/finance/cash', $legacyMoneyAccountGet, $cashPage);
$router->get('/company/finance/cash/account-create', $legacyMoneyAccountGet, $cashPage);
$router->post('/company/finance/cash/account-create', $legacyMoneyAccountPost, $cashPage);
$router->get('/company/finance/cash/operation-create', $legacyMoneyAccountGet, $cashPage);
$router->post('/company/finance/cash/operation-create', $legacyMoneyAccountPost, $cashPage);
$router->get('/company/finance/cash/transfer-create', $legacyMoneyAccountGet, $cashPage);
$router->post('/company/finance/cash/transfer-create', $legacyMoneyAccountPost, $cashPage);
$router->post('/company/finance/cash/dispatch-employee', $legacyMoneyAccountPost, $cashPage);
