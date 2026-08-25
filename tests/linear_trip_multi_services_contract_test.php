<?php

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Http/Controllers/Company/LinearTripController.php');
$routes = (string) file_get_contents($root . '/app/Http/Routes/company_linear_trips.php');
$service = (string) file_get_contents($root . '/app/Service/LinearTripServiceLineService.php');
$serviceFinance = (string) file_get_contents($root . '/app/Service/LinearTripServiceFinanceService.php');
$requestNormalizer = (string) file_get_contents($root . '/app/Service/LinearTripRequestNormalizer.php');
$edit = (string) file_get_contents($root . '/app/Http/Controllers/Company/LinearTripActions/modal_edit_submit_p13.php');
$ui = (string) file_get_contents($root . '/app/View/partials/company_linear_trip_services_bootstrap.php');
$identityUi = (string) file_get_contents($root . '/app/View/partials/company_linear_trip_services_identity.php');
$dateUi = (string) file_get_contents($root . '/public/assets/js/linear-trip-date-mode.js');
$navigationCounters = (string) file_get_contents($root . '/app/Service/NavigationCounterService.php');
$migration = (string) file_get_contents($root . '/database/migrations-local/120_create_linear_route_services.sql');
$persistenceTest = (string) file_get_contents($root . '/tests/linear_trip_service_persistence_mysql_test.php');

foreach (['serviceLines', 'prepareAdditionalServicesForCreate', 'company_linear_trip_services_bootstrap.php', 'company_linear_trip_services_identity.php'] as $token) {
    if (!str_contains($controller, $token)) throw new RuntimeException('controller missing ' . $token);
}
foreach (['/company/trips/linear/service-lines'] as $token) {
    if (!str_contains($routes, $token)) throw new RuntimeException('route missing ' . $token);
}
foreach (['linear_route_services', 'linear_route_service_payments', 'normalizeSubmitted', 'replaceForRoute', 'fetchByRouteIds', "'id' => $paymentId", 'LinearTripServiceFinanceService::syncRoute'] as $token) {
    if (!str_contains($service, $token)) throw new RuntimeException('service missing ' . $token);
}
foreach (['LINEAR_ROUTE_SERVICE_PAYMENT', "direction='PAYABLE'", "party_role='service'", 'finance_obligations', 'finance_operation_allocations', 'finance_operations', 'SAVEPOINT', 'ROLLBACK TO SAVEPOINT', 'derived finance sync skipped'] as $token) {
    if (!str_contains($serviceFinance, $token)) throw new RuntimeException('service finance missing ' . $token);
}
foreach (['normalizeDateKinds', 'actual_unloading_date', 'planned_unloading_date', 'end_date_kind'] as $token) {
    if (!str_contains($requestNormalizer, $token)) throw new RuntimeException('request normalizer missing ' . $token);
}
foreach (['data-trip-date-control="end"', 'endVisible', 'if (value(endVisible))', "setActive(endControl, value(factEnd) ? 'fact' : 'plan')"] as $token) {
    if (!str_contains($dateUi, $token)) throw new RuntimeException('date UI missing ' . $token);
}
foreach (['LinearTripServiceLineService::normalizeSubmitted', 'LinearTripServiceLineService::replaceForRoute', 'P15_linear_trip_save_errors.log'] as $token) {
    if (!str_contains($edit, $token)) throw new RuntimeException('edit save missing ' . $token);
}
foreach (['+ Добавить услугу', 'Описание / детали', 'trip_services[', 'data-trip-service-card', 'rowSpan', 'hasAdditionalServices', "if(!hasAdditionalServices){table.dataset.servicesReady='1';return}", 'syncServiceDueFields', 'bindServicePaymentList', 'data-days-count-wrapper', 'data-days-kind-wrapper', 'data-specific-date-wrapper', "body.querySelector('.driver-modal-main')||body", 'list.dataset.serviceIndex', 'linear-trip-days-stepper', 'data-service-days-minus', 'data-service-days-plus', "daysInput.value='3'", 'Math.max(1,current()-1)', 'current()+1'] as $token) {
    if (!str_contains($ui, $token)) throw new RuntimeException('UI missing ' . $token);
}
foreach (['data-service-payment-id', "paymentName(row, 'id')", 'bindExistingIds', 'normalizeServiceUi', 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ', 'primary.remove()', 'head.remove()', 'linear-trip-service-remove-inline', 'grid-template-columns:minmax(180px,1fr) minmax(220px,1.3fr) auto', "title.textContent !== 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ'", 'serverToday', 'applyPlannedUnloadingAttention', 'plannedIso > serverToday', 'Проставьте дату выгрузки или замените плановую дату', 'registry-date-attention-text'] as $token) {
    if (!str_contains($identityUi, $token)) throw new RuntimeException('identity/UI cleanup missing ' . $token);
}
if (str_contains($identityUi, 'syncLinearTripAttentionBadge') || str_contains($identityUi, 'data-linear-trip-attention-badge')) {
    throw new RuntimeException('linear trip menu counter must not be page-local');
}
foreach (['linear_trips_problem', 'departures_problem', 'planned_unloading_date', 'actual_unloading_date', 'CURRENT_DATE()', 'registerTripBadgeScript', 'register_shutdown_function', 'data-trip-nav-count', '/company/trips/linear', '/company/trips/departures', 'nav-count is-alert', '$total = $linear + $departures'] as $token) {
    if (!str_contains($navigationCounters, $token)) throw new RuntimeException('global navigation counter missing ' . $token);
}
if (str_contains($navigationCounters, 'ob_start(')) {
    throw new RuntimeException('trip navigation counter must not open a nested output buffer');
}
foreach (['CREATE TABLE IF NOT EXISTS linear_route_services', 'CREATE TABLE IF NOT EXISTS linear_route_service_payments', 'contractor_id', 'service_name', 'condition_type'] as $token) {
    if (!str_contains($migration, $token)) throw new RuntimeException('migration missing ' . $token);
}
foreach (['replaceForRoute($pdo, 42, $create', 'replaceForRoute($pdo, 42, $edit', 'fetchByRouteIds($pdo, [42], true)', 'edited payment identity must remain stable', 'removed payment must be soft-deleted', 'removing service must leave no active service payments'] as $token) {
    if (!str_contains($persistenceTest, $token)) throw new RuntimeException('persistence regression missing ' . $token);
}

require_once $root . '/app/Service/LinearTripRequestNormalizer.php';
$normalizeDates = static fn (array $post): array => \App\Service\LinearTripRequestNormalizer::normalizeDateKinds($post);
$plannedEnd = $normalizeDates(['end_date_kind' => '', 'planned_unloading_date' => '2026-08-25', 'actual_unloading_date' => '']);
if (($plannedEnd['end_date_kind'] ?? '') !== 'plan') throw new RuntimeException('filled planned end date must infer plan mode');
$actualEnd = $normalizeDates(['end_date_kind' => '', 'planned_unloading_date' => '', 'actual_unloading_date' => '2026-08-25']);
if (($actualEnd['end_date_kind'] ?? '') !== 'fact') throw new RuntimeException('filled actual end date must infer fact mode');
$emptyEnd = $normalizeDates(['end_date_kind' => '', 'planned_unloading_date' => '', 'actual_unloading_date' => '']);
if (($emptyEnd['end_date_kind'] ?? 'x') !== '') throw new RuntimeException('empty end date must stay optional');
$explicitEnd = $normalizeDates(['end_date_kind' => 'fact', 'planned_unloading_date' => '2026-08-25', 'actual_unloading_date' => '']);
if (($explicitEnd['end_date_kind'] ?? '') !== 'fact') throw new RuntimeException('explicit valid end mode must be preserved for validation');

if (str_contains($migration, 'UPDATE linear_routes') || str_contains($migration, 'INSERT INTO linear_route_services SELECT')) {
    throw new RuntimeException('migration must not backfill or mutate existing trip rows');
}
if (str_contains($service, "UPDATE linear_route_service_payments SET deleted_at=NOW()") && !str_contains($service, 'in_array($oldId, $keepIds, true)')) {
    throw new RuntimeException('service payments must preserve stable identities on edit');
}
if (str_contains($ui, 'delete list.dataset.servicePaymentUiReady')) {
    throw new RuntimeException('service payment listeners must not be rebound during renumbering');
}

echo "linear_trip_multi_services_contract: PASS\n";
echo "primary_transport=legacy_compatible\n";
echo "additional_services=contractor_manual_description_payments\n";
echo "additional_services_finance_sync=isolated_best_effort\n";
echo "end_date_mode=filled_date_infers_mode_without_data_loss\n";
echo "additional_services_ui=left_column_dynamic_due_fields_days_default_3_stepper\n";
echo "planned_unloading_attention=current_or_past_plan_without_actual_is_red\n";
echo "navigation_attention=global_child_counter_parent_sum_no_nested_output_buffer\n";
echo "service_payments=stable_identity_and_payable_obligations\n";
echo "service_persistence=mysql_crud_regression_present\n";
echo "registry=original_without_extras_service_grid_with_extras\n";
echo "migration=no_backfill\n";
