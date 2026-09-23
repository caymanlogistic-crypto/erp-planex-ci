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
    'public/assets/js/legal-entity-inn.js',
    'public/assets/css/app-base.css',
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
$assert(str_contains($modal, 'table-card table-card--standard'), 'Scenario chooser must reuse the standard registry table block.');
$assert(!str_contains($modal, 'route-executor-choice'), 'Scenario chooser must not introduce a custom choice-card design.');

$form = file_get_contents($root . '/app/View/partials/company_route_executor_quick_create_form.php');
foreach (['enctype="multipart/form-data"', 'name="quick_docs[', '$upload(\'driver\'', '$upload($unit', 'data-quick-contractor-step', 'data-quick-details-step', 'renderContactFields', 'data-quick-add-extra-phone', 'data-add-quick-custom-doc'] as $needle) {
    $assert(str_contains($form, $needle), 'Compact combined form is missing: ' . $needle);
}
foreach (['contractor[director_full_name]', 'contractor[director_position]', 'contractor[physical_address]', 'contractor[bank_account]', 'contractor[bank_bik]', 'contractor[bank_name]', 'contractor[bank_corr_account]', 'contractor[bank_inn]', 'contractor[comments]', 'vehicle[comments]'] as $fieldName) {
    $assert(str_contains($form, 'name="' . $fieldName . '"'), 'Original entity field is missing from quick-create: ' . $fieldName);
}
foreach (['form-section', 'vehicle-set-form-card', 'document-file-row', 'field-row field-row-group'] as $needle) {
    $assert(str_contains($form, $needle), 'Combined form must reuse an existing PLANEX form block: ' . $needle);
}
$assert(str_contains($form, 'driver-layout--docs-left'), 'Driver documents must use the original two-column entity form with files on the left.');
$assert(str_contains($form, 'vehicle-set-unit-card-body--docs-left'), 'Vehicle documents must use the original unit card with files on the left.');
$assert(str_contains($form, 'data-inn-autofill-btn'), 'New contractor form must reuse the standard INN autofill action.');
$assert(str_contains($form, 'data-inn-lookup-url'), 'New contractor form must expose the standard requisites lookup endpoint.');
$assert(!str_contains($form, 'quick_docs[contractor]'), 'Quick-create must not request contractor documents.');
$assert(str_contains($form, 'quick_custom_docs['), 'Driver and vehicle must support arbitrary documents.');
foreach (['quick-entity-card', 'quick-upload', 'quick-create-grid', 'quick-entity-index'] as $needle) {
    $assert(!str_contains($form, $needle), 'Combined form must not introduce custom visual blocks: ' . $needle);
}

$erpCss = file_get_contents($root . '/public/assets/css/erp-ui.css');
foreach (['.route-executor-choice', '.quick-entity-card', '.quick-upload', '.quick-create-grid'] as $needle) {
    $assert(!str_contains($erpCss, $needle), 'Quick-create must not add a separate visual language: ' . $needle);
}
$baseCss = file_get_contents($root . '/public/assets/css/app-base.css');
$assert(str_contains($baseCss, '#route-executor-create-modal .driver-create-modal'), 'Quick-create must reuse the existing wide form modal layout.');
$assert(str_contains($baseCss, '.driver-layout--docs-left'), 'Existing entity form layout must support the requested left document column.');
$assert(str_contains($baseCss, '.vehicle-set-unit-card-body--docs-left'), 'Existing vehicle unit layout must support the requested left document column.');

$service = file_get_contents($root . '/app/Service/RouteExecutorQuickCreateService.php');
foreach (['RegulatedDataScopeService::assertCanCreate', 'RouteExecutorService::canAccessContractor', 'EntityAssignmentService::autoAssignCreator', 'DocumentService::validateUploadedFile', 'beginTransaction()', 'rollBack()', 'INSERT INTO crew_drivers'] as $needle) {
    $assert(str_contains($service, $needle), 'Quick-create safety/atomicity contract is missing: ' . $needle);
}
$assert(str_contains($service, 'ContractorContactService::normalizeSubmittedContacts'), 'Quick-create must reuse contractor contact normalization.');
$assert(str_contains($service, 'INSERT INTO driver_phones'), 'Quick-create must persist additional driver phones.');
$assert(str_contains($service, '(set_type,primary_vehicle_unit_id,secondary_vehicle_unit_id,status,comments,'), 'Quick-create must persist the original vehicle-set comment field.');
$assert(!str_contains($service, "'contractor' => [\n            'company_card'"), 'Quick-create service must not accept contractor document presets.');

$js = file_get_contents($root . '/public/assets/js/app.js');
foreach (['openQuickCreateForm', 'bindQuickCreateForm', 'data-route-executor-quick-create-success', 'openExistingCreateForm', 'showCreateChoices', 'runLegalEntityInnLookup'] as $needle) {
    $assert(str_contains($js, $needle), 'Quick-create modal behavior is missing: ' . $needle);
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "ROUTE_EXECUTOR_QUICK_CREATE_CONTRACT=PASS\n";
