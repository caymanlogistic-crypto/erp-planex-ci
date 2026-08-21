<?php

declare(strict_types=1);
$manifest=(string)file_get_contents(__DIR__.'/../app/Support/entrypoint_dependencies.php');
$closing="require_once base_path('app/Service/ClosingDocumentService.php');";$nav="require_once base_path('app/Service/NavigationCounterService.php');";
if(($a=strpos($manifest,$closing))===false||($b=strpos($manifest,$nav))===false||$a>$b)throw new RuntimeException('closing runtime dependency manifest invalid');
require_once __DIR__.'/../app/Service/ClosingDocumentService.php';
if(!class_exists(\App\Service\ClosingDocumentService::class,false))throw new RuntimeException('ClosingDocumentService load failed');
if(!function_exists('e')){function e(?string $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}}
if(!function_exists('app_url')){function app_url(string $p=''):string{return '/erpv2/'.ltrim($p,'/');}}
$rows=[];$documentsByInn=[];$schemaReady=true;$dbError=null;$problemOnly=false;$successFlash=null;$errorFlash=null;
ob_start();require __DIR__.'/../app/View/pages/company_finance_closing_documents.php';$html=(string)ob_get_clean();
if(!str_contains($html,'Закрывающие документы')||!str_contains($html,'Исходящие банковские расходы')||!str_contains($html,'Исключения по документам'))throw new RuntimeException('closing documents view render smoke failed');
$exceptions=[];$schemaReady=true;$dbError=null;$successFlash=null;$errorFlash=null;
ob_start();require __DIR__.'/../app/View/pages/company_finance_closing_document_exceptions.php';$rules=(string)ob_get_clean();
if(!str_contains($rules,'Исключения по документам')||!str_contains($rules,'Добавить исключение'))throw new RuntimeException('exceptions view render smoke failed');
echo "closing_documents_runtime_dependency_test: PASS\nclosing_documents_view_render_smoke: PASS\nclosing_document_exceptions_view_render_smoke: PASS\n";
