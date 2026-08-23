<?php

declare(strict_types=1);
$main=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_documents.php');
$modal=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_document_modal.php');
$exceptions=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_document_exceptions.php');
$controller=(string)file_get_contents(__DIR__.'/../app/Http/Controllers/Company/ClosingDocumentsController.php');
$routes=(string)file_get_contents(__DIR__.'/../app/Http/Routes/company_bank_finance.php');
foreach(['table-toolbar','found-label','toolbar-search','data-closing-search','data-closing-problem-only','Показывать только проблемные','dblclick','closing-document-modal'] as $token){if(!str_contains($main,$token))throw new RuntimeException('main UX missing '.$token);}
if(str_contains($main,'<th>Действия</th>'))throw new RuntimeException('actions column forbidden');
foreach(['Требуемый комплект','Привязанные документы','Привязать существующий документ','Загрузить новый документ','unlink-document','link-document','upload-document'] as $token){if(!str_contains($modal,$token))throw new RuntimeException('modal action missing '.$token);}
foreach(['data-recipient-search','closing-recipient-options','data-recipient-inn','data-exception-search','Поиск по наименованию или ИНН'] as $token){if(!str_contains($exceptions,$token))throw new RuntimeException('exception search missing '.$token);}
if(!str_contains($controller,'bankRecipientOptions')||!str_contains($controller,'function modalView'))throw new RuntimeException('controller UX endpoints missing');
if(!str_contains($routes,"/company/finance/closing-documents/{id}/modal-view"))throw new RuntimeException('modal route missing');
echo "closing_documents_invoice_ux_contract: PASS\n";
