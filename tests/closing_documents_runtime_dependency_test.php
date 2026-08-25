<?php

declare(strict_types=1);
$manifest=(string)file_get_contents(__DIR__.'/../app/Support/entrypoint_dependencies.php');
$closing="require_once base_path('app/Service/ClosingDocumentService.php');";$nav="require_once base_path('app/Service/NavigationCounterService.php');";
if(($a=strpos($manifest,$closing))===false||($b=strpos($manifest,$nav))===false||$a>$b)throw new RuntimeException('closing runtime dependency manifest invalid');
require_once __DIR__.'/../app/Service/ClosingDocumentService.php';
if(!class_exists(\App\Service\ClosingDocumentService::class,false))throw new RuntimeException('ClosingDocumentService load failed');
if(!function_exists('e')){function e(?string $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
if(!function_exists('app_url')){function app_url(string $p=''):string{return '/erpv2/'.ltrim($p,'/');}}
$listProfile=\App\Service\ClosingDocumentService::profileFor('legal_entity','incoming');
$rows=[['id'=>11,'operation_date'=>'2026-08-24','document_number'=>'11','debit_amount'=>0,'credit_amount'=>1200,'direction'=>'incoming','counterparty_name'=>'ООО ТЕСТ','normalized_inn'=>'7700000000','purpose'=>'Тестовый платёж','profile'=>$listProfile,'is_exempt'=>false,'is_problem'=>true,'requirement_statuses'=>['agreement_or_application'=>['applicable'=>true,'satisfied'=>true],'act_or_upd'=>['applicable'=>true,'satisfied'=>false],'npd_receipt'=>['applicable'=>false,'satisfied'=>false]]]];$schemaReady=true;$dbError=null;$successFlash=null;$errorFlash=null;
ob_start();require __DIR__.'/../app/View/pages/company_finance_closing_documents.php';$html=(string)ob_get_clean();
foreach(['Закрывающие документы','table-toolbar','found-label','toolbar-search','data-closing-search','data-closing-mode','Показать все','Только требующие заполнения','value="requires_fill" selected','Проблемные','data-requires-fill="1"','data-closing-download','Скачать документы','closing-archive-modal','Подготовить архив','data-closing-transaction-id','dblclick','closing-document-modal','modal-view','Документы по входящим и исходящим банковским платежам','Договор / Заявка','Акт / УПД','Чек НПД','Контрагент','table-check table-check--yes','table-check table-check--no','aria-label="Документ есть"','aria-label="Документа нет"','>✓</span>','>×</span>'] as $token){if(!str_contains($html,$token))throw new RuntimeException('closing documents UX missing: '.$token);}
foreach(['Показывать только проблемные','type="checkbox" data-closing-problem-only','<th>Комплект</th>','<th>Документы</th>','<th>Действия</th>','<span class="dot"></span>Есть','<span class="dot"></span>Нет'] as $forbidden){if(str_contains($html,$forbidden))throw new RuntimeException('closing documents forbidden UI: '.$forbidden);}
if(!str_contains($html,'closing-documents-table-frame')||!str_contains($html,'table-layout:fixed')||!str_contains($html,'white-space:normal!important')||!str_contains($html,'<colgroup>'))throw new RuntimeException('closing documents fit-width contract failed');
if(str_contains($html,'class="table-scroll"'))throw new RuntimeException('closing documents must not use global horizontal-scroll container');
$exceptions=[];$schemaReady=true;$dbError=null;$successFlash=null;$errorFlash=null;
ob_start();require __DIR__.'/../app/View/pages/company_finance_closing_document_exceptions.php';$rules=(string)ob_get_clean();
foreach(['Исключения по документам','+ Исключение','Поиск по исключениям','ux-filter-panel','closing-exception-create-modal','closing-exception-edit-modal','dblclick'] as $token){if(!str_contains($rules,$token))throw new RuntimeException('matching-rules exceptions UX missing: '.$token);}
if(str_contains($rules,'data-recipient-search'))throw new RuntimeException('old recipient-autocomplete UX must be removed');
$rule=null;
ob_start();require __DIR__.'/../app/View/pages/company_finance_closing_document_exception_form.php';$exceptionForm=(string)ob_get_clean();
foreach(['name="inn"','name="purpose_contains"','Назначение содержит','Если оставить пустым'] as $token){if(!str_contains($exceptionForm,$token))throw new RuntimeException('exception modal form missing: '.$token);}
$profile=\App\Service\ClosingDocumentService::profileFor('legal_entity','incoming');
$row=['id'=>17,'operation_date'=>'2026-08-23','document_number'=>'17','debit_amount'=>0,'credit_amount'=>1000,'direction'=>'incoming','counterparty_name'=>'ООО ТЕСТ','normalized_inn'=>'7700000000','purpose'=>'Тестовый входящий платёж','profile'=>$profile,'links'=>[['id'=>21,'requirement_code'=>'agreement','original_name'=>'agreement.pdf','document_deleted_at'=>null,'is_edo'=>1]],'requirement_statuses'=>\App\Service\ClosingDocumentService::requirementStatuses($profile,[]),'is_exempt'=>false,'is_problem'=>true,'missing_requirements'=>[]];
$availableDocuments=[];$requirementLabels=\App\Service\ClosingDocumentService::requirementLabels();
ob_start();require __DIR__.'/../app/View/pages/company_finance_closing_document_modal.php';$modal=(string)ob_get_clean();
foreach(['entity-form-layout','entity-form-main','entity-form-docs','Статус документов','Документы','Добавить документ','closing-file-row','closing-existing-edo-form','document-edo','name="is_edo"','transaction_id','link_id','Входящий платёж','table-check table-check--no','aria-label="Документа нет"','>×</span>'] as $token){if(!str_contains($modal,$token))throw new RuntimeException('closing modal contract missing: '.$token);}
if(str_contains($modal,'<span class="dot"></span>Есть')||str_contains($modal,'<span class="dot"></span>Нет'))throw new RuntimeException('closing modal text status badges forbidden');
$incomingSelf=\App\Service\ClosingDocumentService::profileFor('self_employed','incoming');$incomingStatuses=\App\Service\ClosingDocumentService::requirementStatuses($incomingSelf,[]);if(!empty($incomingStatuses['npd_receipt']['applicable']))throw new RuntimeException('incoming self-employed NPD receipt must be not applicable');
$outgoingSelf=\App\Service\ClosingDocumentService::profileFor('self_employed','outgoing');$outgoingStatuses=\App\Service\ClosingDocumentService::requirementStatuses($outgoingSelf,[]);if(empty($outgoingStatuses['npd_receipt']['applicable']))throw new RuntimeException('outgoing self-employed NPD receipt must be required');
$routes=(string)file_get_contents(__DIR__.'/../app/Http/Routes/company_bank_finance.php');
$controller=(string)file_get_contents(__DIR__.'/../app/Http/Controllers/Company/ClosingDocumentsController.php');
foreach(['exceptions/create','exceptions/edit','exceptions/save','/company/finance/closing-documents/{id}/modal-view','/company/finance/closing-documents/document-edo','/company/finance/closing-documents/archive'] as $token){if(!str_contains($routes,$token))throw new RuntimeException('closing route missing '.$token);}
foreach(['function modalView','function exceptionCreateForm','function exceptionEditForm','function saveException','function updateDocumentEdo','function downloadArchive'] as $token){if(!str_contains($controller,$token))throw new RuntimeException('closing controller missing '.$token);}
echo "closing_documents_runtime_dependency_test: PASS\nclosing_documents_bidirectional_scope_ui: PASS\nclosing_documents_three_mode_filter: PASS\nclosing_documents_visible_rows_archive: PASS\nclosing_documents_existing_edo_edit: PASS\nclosing_documents_compact_system_modal: PASS\n";
