<?php

declare(strict_types=1);
$main=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_documents.php');
$modal=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_document_modal.php');
$exceptions=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_document_exceptions.php');
$exceptionForm=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_document_exception_form.php');
$controller=(string)file_get_contents(__DIR__.'/../app/Http/Controllers/Company/ClosingDocumentsController.php');
$routes=(string)file_get_contents(__DIR__.'/../app/Http/Routes/company_bank_finance.php');
foreach(['table-toolbar','found-label','toolbar-search','data-closing-search','data-closing-problem-only checked','Показывать только проблемные','dblclick','closing-document-modal','class="is-problem"','.closing-documents-table tbody tr.is-problem>td{background:#fff1f1}'] as $token){if(!str_contains($main,$token))throw new RuntimeException('main UX missing '.$token);}
if(str_contains($main,'<th>Действия</th>'))throw new RuntimeException('actions column forbidden');
if(str_contains($main,'<span class="badge badge-success"><span class="dot"></span>Исключение</span>'))throw new RuntimeException('exempt type badge forbidden');
if(!str_contains($main,"if(!empty($row['is_exempt'])):?><span class=\"text-muted\">—</span>"))throw new RuntimeException('exempt type must render dash');
foreach(['Требуемый комплект','Привязанные документы','Привязать существующий документ','Загрузить новый документ','unlink-document','link-document','upload-document'] as $token){if(!str_contains($modal,$token))throw new RuntimeException('modal action missing '.$token);}
foreach(['ux-filter-panel','+ Исключение','Поиск по исключениям','data-exception-row','dblclick','closing-exception-create-modal','closing-exception-edit-modal','setRemoteHtml','credentials: \'same-origin\''] as $token){if(!str_contains($exceptions,$token))throw new RuntimeException('matching-rules exception UX missing '.$token);}
if(str_contains($exceptions,'data-recipient-search'))throw new RuntimeException('recipient autocomplete must not replace explicit INN + purpose rule');
if(str_contains($exceptions,'planexSetHtmlAndRunScripts'))throw new RuntimeException('closing exception modal must not depend on nonexistent global planexSetHtmlAndRunScripts helper');
foreach(['name="inn"','name="purpose_contains"','Назначение содержит','Если оставить пустым'] as $token){if(!str_contains($exceptionForm,$token))throw new RuntimeException('exception form missing '.$token);}
foreach(['function modalView','function exceptionCreateForm','function exceptionEditForm','function saveException'] as $token){if(!str_contains($controller,$token))throw new RuntimeException('controller UX endpoint missing '.$token);}
foreach(['/company/finance/closing-documents/{id}/modal-view','exceptions/create','exceptions/edit','exceptions/save'] as $token){if(!str_contains($routes,$token))throw new RuntimeException('route missing '.$token);}
echo "closing_documents_invoice_ux_contract: PASS\nclosing_problem_filter_default=checked\nclosing_exempt_type=dash\nclosing_problem_row_highlight=light_red\nclosing_exception_rules_ux=matching_rules_pattern\nclosing_exception_remote_form_loader=local_safe_helper\n";
