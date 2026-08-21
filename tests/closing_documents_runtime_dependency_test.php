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

$viewPath = __DIR__ . '/../app/View/pages/company_finance_closing_documents.php';
$view = file_get_contents($viewPath);
if ($view === false || !str_contains($view, '\\App\\Service\\ClosingDocumentService::requirementLabels()')) {
    throw new RuntimeException('closing documents view runtime dependency contract changed');
}

if (!function_exists('e')) {
    function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('app_url')) {
    function app_url(string $path = ''): string { return '/erpv2/' . ltrim($path, '/'); }
}

$rows = [];
$counterpartyOptions = [];
$documentsByEntity = [];
$schemaReady = true;
$dbError = null;
$problemOnly = false;
$successFlash = null;
$errorFlash = null;

ob_start();
require $viewPath;
$html = (string)ob_get_clean();
if (!str_contains($html, 'Закрывающие документы')) {
    throw new RuntimeException('closing documents view render smoke missing page title');
}
if (!str_contains($html, 'Все релевантные операции')) {
    throw new RuntimeException('closing documents view render smoke missing report body');
}

fwrite(STDOUT, "closing_documents_runtime_dependency_test: PASS\n");
fwrite(STDOUT, "closing_documents_view_render_smoke: PASS\n");
