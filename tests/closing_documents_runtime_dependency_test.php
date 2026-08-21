<?php

declare(strict_types=1);

$manifestPath = __DIR__ . '/../app/Support/entrypoint_dependencies.php';
$manifest = file_get_contents($manifestPath);
if ($manifest === false) {
    throw new RuntimeException('entrypoint dependency manifest missing');
}

$closingNeedle = "require_once base_path('app/Service/ClosingDocumentService.php');";
$navigationNeedle = "require_once base_path('app/Service/NavigationCounterService.php');";

$closingPos = strpos($manifest, $closingNeedle);
$navigationPos = strpos($manifest, $navigationNeedle);

if ($closingPos === false) {
    throw new RuntimeException('ClosingDocumentService is not loaded by production entrypoint dependency manifest');
}
if ($navigationPos === false) {
    throw new RuntimeException('NavigationCounterService dependency missing');
}
if ($closingPos > $navigationPos) {
    throw new RuntimeException('ClosingDocumentService must be loaded before NavigationCounterService');
}

require_once __DIR__ . '/../app/Service/ClosingDocumentService.php';
if (!class_exists(\App\Service\ClosingDocumentService::class, false)) {
    throw new RuntimeException('ClosingDocumentService runtime class load failed');
}

$view = file_get_contents(__DIR__ . '/../app/View/pages/company_finance_closing_documents.php');
if ($view === false || !str_contains($view, '\\App\\Service\\ClosingDocumentService::requirementLabels()')) {
    throw new RuntimeException('closing documents view runtime dependency contract changed');
}

fwrite(STDOUT, "closing_documents_runtime_dependency_test: PASS\n");
