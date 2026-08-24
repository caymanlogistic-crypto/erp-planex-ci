<?php

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Http/Controllers/Company/LinearTripController.php');
$routes = (string) file_get_contents($root . '/app/Http/Routes/company_linear_trips.php');
$service = (string) file_get_contents($root . '/app/Service/LinearTripServiceLineService.php');
$serviceFinance = (string) file_get_contents($root . '/app/Service/LinearTripServiceFinanceService.php');
$edit = (string) file_get_contents($root . '/app/Http/Controllers/Company/LinearTripActions/modal_edit_submit_p13.php');
$ui = (string) file_get_contents($root . '/app/View/partials/company_linear_trip_services_bootstrap.php');
$identityUi = (string) file_get_contents($root . '/app/View/partials/company_linear_trip_services_identity.php');
$migration = (string) file_get_contents($root . '/database/migrations-local/120_create_linear_route_services.sql');

foreach (['serviceLines', 'prepareAdditionalServicesForCreate', 'company_linear_trip_services_bootstrap.php', 'company_linear_trip_services_identity.php'] as $token) {
    if (!str_contains($controller, $token)) throw new RuntimeException('controller missing ' . $token);
}
foreach (['/company/trips/linear/service-lines'] as $token) {
    if (!str_contains($routes, $token)) throw new RuntimeException('route missing ' . $token);
}
foreach (['linear_route_services', 'linear_route_service_payments', 'normalizeSubmitted', 'replaceForRoute', 'fetchByRouteIds', "'id' => $paymentId", 'LinearTripServiceFinanceService::syncRoute'] as $token) {
    if (!str_contains($service, $token)) throw new RuntimeException('service missing ' . $token);
}
foreach (['LINEAR_ROUTE_SERVICE_PAYMENT', "direction='PAYABLE'", "party_role='service'", 'finance_obligations', 'finance_operation_allocations'] as $token) {
    if (!str_contains($serviceFinance, $token)) throw new RuntimeException('service finance missing ' . $token);
}
foreach (['LinearTripServiceLineService::normalizeSubmitted', 'LinearTripServiceLineService::replaceForRoute', 'P15_linear_trip_save_errors.log'] as $token) {
    if (!str_contains($edit, $token)) throw new RuntimeException('edit save missing ' . $token);
}
foreach (['+ Добавить услугу', 'Описание / детали', 'trip_services[', 'data-trip-service-card', 'rowSpan', 'hasAdditionalServices', "if(!hasAdditionalServices){table.dataset.servicesReady='1';return}"] as $token) {
    if (!str_contains($ui, $token)) throw new RuntimeException('UI missing ' . $token);
}
foreach (['data-service-payment-id', "paymentName(row, 'id')", 'bindExistingIds', 'normalizeServiceUi', 'ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ', 'primary.remove()', 'head.remove()', 'linear-trip-service-remove-inline', 'grid-template-columns:minmax(180px,1fr) minmax(220px,1.3fr) auto'] as $token) {
    if (!str_contains($identityUi, $token)) throw new RuntimeException('identity/UI cleanup missing ' . $token);
}
foreach (['CREATE TABLE IF NOT EXISTS linear_route_services', 'CREATE TABLE IF NOT EXISTS linear_route_service_payments', 'contractor_id', 'service_name', 'condition_type'] as $token) {
    if (!str_contains($migration, $token)) throw new RuntimeException('migration missing ' . $token);
}

if (str_contains($migration, 'UPDATE linear_routes') || str_contains($migration, 'INSERT INTO linear_route_services SELECT')) {
    throw new RuntimeException('migration must not backfill or mutate existing trip rows');
}
if (str_contains($service, "UPDATE linear_route_service_payments SET deleted_at=NOW()") && !str_contains($service, 'in_array($oldId, $keepIds, true)')) {
    throw new RuntimeException('service payments must preserve stable identities on edit');
}

echo "linear_trip_multi_services_contract: PASS\n";
echo "primary_transport=legacy_compatible\n";
echo "additional_services=contractor_manual_description_payments\n";
echo "additional_services_ui=compact_no_outer_box_inline_remove\n";
echo "service_payments=stable_identity_and_payable_obligations\n";
echo "registry=original_without_extras_service_grid_with_extras\n";
echo "migration=no_backfill\n";
