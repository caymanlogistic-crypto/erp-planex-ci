<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$files = [
    'app/Service/RouteExecutorQuickCreateService.php',
    'app/Http/Routes/company_route_executors.php',
    'app/Http/Controllers/Company/RouteExecutorActions/quick_create_form.php',
    'app/Http/Controllers/Company/RouteExecutorActions/quick_create_submit.php',
    'app/View/partials/company_route_executor_quick_create_form.php',
    'app/View/partials/route_executor_modals.php',
    'public/assets/js/app.js',
    'public/assets/css/erp-ui.css',
];
foreach ($files as $file) {
    $assert(is_file($root . '/' . $file), 'Missing quick-create file: ' . $file);
}

require_once $root . '/app/Service/RouteExecutorQuickCreateService.php';
use App\Service\RouteExecutorQuickCreateService;

$assert(RouteExecutorQuickCreateService::normalizeMode('new_contractor') === 'new_contractor', 'New-contractor mode is not accepted.');
$assert(RouteExecutorQuickCreateService::normalizeMode('existing_contractor') === 'existing_contractor', 'Existing-contractor mode is not accepted.');
$assert(RouteExecutorQuickCreateService::normalizeMode('unexpected') === 'new_contractor', 'Unknown mode must fail safely to the new-contractor form.');

$routes = file_get_contents($root . '/app/Http/Routes/company_route_executors.php');
$assert(substr_count($routes, "'/company/route-executors/quick-create'") === 2, 'Quick-create GET/POST routes are incomplete.');
$assert(str_contains($routes, '$routeExecutorsPage'), 'Quick-create routes must retain the route-executor page gate.');

$modal = file_get_contents($root . '/app/View/partials/route_executor_modals.php');
foreach (['Новый подрядчик', 'Старый подрядчик, новый водитель и ТС', 'Все данные уже созданы'] as $copy) {
    $assert(str_contains($modal, $copy), 'Scenario chooser is missing: ' . $copy);
}

$form = file_get_contents($root . '/app/View/partials/company_route_executor_quick_create_form.php');
foreach (['enctype="multipart/form-data"', 'name="quick_docs[', '$upload(\'contractor\'', '$upload(\'driver\'', '$upload(\'primary\'', '$upload(\'secondary\'', 'data-quick-contractor-step', 'data-quick-details-step'] as $needle) {
    $assert(str_contains($form, $needle), 'Compact combined form is missing: ' . $needle);
}

$service = file_get_contents($root . '/app/Service/RouteExecutorQuickCreateService.php');
foreach (['RegulatedDataScopeService::assertCanCreate', 'RouteExecutorService::canAccessContractor', 'EntityAssignmentService::autoAssignCreator', 'DocumentService::validateUploadedFile', 'beginTransaction()', 'rollBack()', 'INSERT INTO crew_drivers'] as $needle) {
    $assert(str_contains($service, $needle), 'Quick-create safety/atomicity contract is missing: ' . $needle);
}

$js = file_get_contents($root . '/public/assets/js/app.js');
foreach (['openQuickCreateForm', 'bindQuickCreateForm', 'data-route-executor-quick-create-success', 'openExistingCreateForm', 'showCreateChoices'] as $needle) {
    $assert(str_contains($js, $needle), 'Quick-create modal behavior is missing: ' . $needle);
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "ROUTE_EXECUTOR_QUICK_CREATE_CONTRACT=PASS\n";
