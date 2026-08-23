<?php

declare(strict_types=1);
$manifest=(string)file_get_contents(__DIR__.'/../app/Support/entrypoint_dependencies.php');
$closing="require_once base_path('app/Service/ClosingDocumentService.php');";$nav="require_once base_path('app/Service/NavigationCounterService.php');";
if(($a=strpos($manifest,$closing))===false||($b=strpos($manifest,$nav))===false||$a>$b)throw new RuntimeException('closing runtime dependency manifest invalid');
require_once __DIR__.'/../app/Service/ClosingDocumentService.php';
if(!class_exists(\App\Service\ClosingDocumentService::class,false))throw new RuntimeException('ClosingDocumentService load failed');
if(!function_exists('e')){function e(?string $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
if(!function_exists('app_url')){function app_url(string $p=''):string{return '/erpv2/'.ltrim($p,'/');}}
$rows=[];$schemaReady=true;$dbError=null;$successFlash=null;$errorFlash=null;
ob_start();require __DIR__.'/../app/View/pages/company_finance_closing_documents.php';$html=(string)ob_get_clean();
foreach(['Закрывающие документы','table-toolbar','found-label','toolbar-search','data-closing-search','data-closing-problem-only','Показывать только проблемные','data-closing-transaction-id','dblclick','closing-document-modal','modal-view'] as $token){if(!str_contains($html,$token))throw new RuntimeException('closing documents invoice-style UX missing: '.$token);}
if(str_contains($html,'<th>Действия</th>'))throw new RuntimeException('closing documents actions column must not exist');
if(!str_contains($html,'closing-documents-table-frame')||!str_contains($html,'table-layout:fixed')||!str_contains($html,'white-space:normal!important')||!str_contains($html,'<colgroup>'))throw new RuntimeException('closing documents fit-width contract failed');
if(str_contains($html,'class="table-scroll"'))throw new RuntimeException('closing documents must not use global horizontal-scroll container');
$exceptions=[];$recipientOptions=[];$schemaReady=true;$dbError=null;$successFlash=null;$errorFlash=null;
ob_start();require __DIR__.'/../app/View/pages/company_finance_closing_document_exceptions.php';$rules=(string)ob_get_clean();
foreach(['Исключения по документам','Добавить исключение','data-recipient-search','closing-recipient-options','data-recipient-inn','data-exception-search','Поиск по наименованию или ИНН'] as $token){if(!str_contains($rules,$token))throw new RuntimeException('exceptions recipient-search UX missing: '.$token);}
$row=['id'=>17,'operation_date'=>'2026-08-23','document_number'=>'17','debit_amount'=>1000,'counterparty_name'=>'ООО ТЕСТ','normalized_inn'=>'7700000000','purpose'=>'Тестовый платёж','profile'=>\App\Service\ClosingDocumentService::profileFor('legal_entity'),'links'=>[],'is_exempt'=>false,'is_problem'=>true,'missing_requirements'=>[]];
$availableDocuments=[];$requirementLabels=\App\Service\ClosingDocumentService::requirementLabels();
ob_start();require __DIR__.'/../app/View/pages/company_finance_closing_document_modal.php';$modal=(string)ob_get_clean();
foreach(['Требуемый комплект','Привязанные документы','Загрузить новый документ','transaction_id'] as $token){if(!str_contains($modal,$token))throw new RuntimeException('closing modal contract missing: '.$token);}
$routes=(string)file_get_contents(__DIR__.'/../app/Http/Routes/company_bank_finance.php');
$controller=(string)file_get_contents(__DIR__.'/../app/Http/Controllers/Company/ClosingDocumentsController.php');
if(!str_contains($routes,"/company/finance/closing-documents/{id}/modal-view")||!str_contains($controller,'function modalView'))throw new RuntimeException('closing modal route/controller missing');
if(!str_contains($controller,'bankRecipientOptions')||!str_contains($controller,'counterparty_name'))throw new RuntimeException('closing exception bank-recipient search source missing');
echo "closing_documents_runtime_dependency_test: PASS\nclosing_documents_invoice_style_toolbar: PASS\nclosing_documents_search_and_problem_checkbox: PASS\nclosing_documents_double_click_modal: PASS\nclosing_documents_no_actions_column: PASS\nclosing_document_exceptions_name_search: PASS\nclosing_documents_fit_width_contract: PASS\n";
