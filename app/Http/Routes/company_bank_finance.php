<?php
require_once base_path('app/Http/Controllers/Company/BankFinanceController.php');
require_once base_path('app/Http/Controllers/Company/ClosingDocumentsController.php');
$controller=new \App\Http\Controllers\Company\BankFinanceController($config,$db);
$router->get('/company/finance/bank-accounts',[$controller,'index']);
$router->get('/company/bank-statement-settings',[$controller,'bankStatementSettings']);
$router->post('/company/finance/bank-accounts/import',[$controller,'import']);
$router->post('/company/finance/bank-accounts/refresh-from-mail',[$controller,'refreshFromMail']);
$router->post('/company/finance/bank-accounts/apply-rules',[$controller,'applyRules']);
$router->post('/company/finance/bank-accounts/settings',[$controller,'settings']);
$router->post('/company/finance/bank-accounts/import/delete',[$controller,'deleteImport']);
$router->get('/company/finance/bank-accounts/reconciliation/json',[$controller,'reconciliationJson']);
$router->get('/company/finance/bank-transactions/{id}/settlement',[$controller,'settlementForm']);
$router->post('/company/finance/bank-transactions/{id}/settlement',[$controller,'settlementSubmit']);
$router->get('/company/finance/bank-transactions/{id}/classify',[$controller,'classifyForm']);
$router->post('/company/finance/bank-transactions/{id}/classify',[$controller,'classifySubmit']);
$router->post('/company/finance/bank-transactions/{id}/classification/delete',[$controller,'clearClassification']);

$closingDocumentsController = new \App\Http\Controllers\Company\ClosingDocumentsController($config, $db);
$router->get('/company/finance/closing-documents', [$closingDocumentsController, 'index']);
$router->get('/company/finance/closing-documents/exceptions', [$closingDocumentsController, 'exceptions']);
$router->post('/company/finance/closing-documents/exceptions/add', [$closingDocumentsController, 'addException']);
$router->post('/company/finance/closing-documents/exceptions/remove', [$closingDocumentsController, 'removeException']);
$router->post('/company/finance/closing-documents/link-document', [$closingDocumentsController, 'linkDocument']);
$router->post('/company/finance/closing-documents/upload-document', [$closingDocumentsController, 'uploadDocument']);
$router->post('/company/finance/closing-documents/unlink-document', [$closingDocumentsController, 'unlinkDocument']);
