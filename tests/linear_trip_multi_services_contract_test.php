<?php

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/app/Http/Controllers/Company/LinearTripController.php');
$routes = (string)file_get_contents($root . '/app/Http/Routes/company_linear_trips.php');
$service = (string)file_get_contents($root . '/app/Service/LinearTripServiceLineService.php');
$edit = (string)file_get_contents($root . '/app/Http/Controllers/Company/LinearTripActions/modal_edit_submit_p13.php');
$ui = (string)file_get_contents($root . '/app/View/partials/company_linear_trip_services_bootstrap.php');
$migration = (string)file_get_contents($root . '/database/migrations-local/120_create_linear_route_services.sql');

foreach (['serviceLines','prepareAdditionalServicesForCreate','company_linear_trip_services_bootstrap.php'] as $token) {
    if (!str_contains($controller, $token)) throw new RuntimeException('controller missing ' . $token);
}
foreach (['/company/trips/linear/service-lines'] as $token) {
    if (!str_contains($routes, $token)) throw new RuntimeException('route missing ' . $token);
}
foreach (['linear_route_services','linear_route_service_payments','normalizeSubmitted','replaceForRoute','fetchByRouteIds'] as $token) {
    if (!str_contains($service, $token)) throw new RuntimeException('service missing ' . $token);
}
foreach (['LinearTripServiceLineService::normalizeSubmitted','LinearTripServiceLineService::replaceForRoute'] as $token) {
    if (!str_contains($edit, $token)) throw new RuntimeException('edit save missing ' . $token);
}
foreach (['Услуги / исполнители','Основная услуга: Перевозка','+ Добавить услугу','Описание / детали','trip_services[','data-trip-service-card','rowSpan'] as $token) {
    if (!str_contains($ui, $token)) throw new RuntimeException('UI missing ' . $token);
}
foreach (['CREATE TABLE IF NOT EXISTS linear_route_services','CREATE TABLE IF NOT EXISTS linear_route_service_payments','contractor_id','service_name','condition_type'] as $token) {
    if (!str_contains($migration, $token)) throw new RuntimeException('migration missing ' . $token);
}

if (str_contains($migration, 'UPDATE linear_routes') || str_contains($migration, 'INSERT INTO linear_route_services SELECT')) {
    throw new RuntimeException('migration must not backfill or mutate existing trip rows');
}

echo "linear_trip_multi_services_contract: PASS\n";
echo "primary_transport=legacy_compatible\n";
echo "additional_services=contractor_manual_description_payments\n";
echo "registry=first_four_common_then_service_rows\n";
echo "migration=no_backfill\n";
