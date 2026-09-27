<?php

require_once base_path('app/Service/WasteTripLegacySourceService.php');
require_once base_path('app/Service/WarehouseStockService.php');
require_once base_path('app/Service/WarehouseTripCorrectionService.php');
require_once base_path('app/Http/Controllers/Company/WarehouseStockController.php');
require_once base_path('app/Service/WasteTripIncidentService.php');
require_once base_path('app/Service/WasteTripCustomerPaymentService.php');
require_once base_path('app/Service/WasteTripGeneralPaymentService.php');
require_once base_path('app/Service/WasteTripPaymentSyncService.php');
require_once base_path('app/Service/WasteTripLegacyPriceService.php');
require_once base_path('app/Service/WasteTripCarrierPaymentService.php');
require_once base_path('app/Service/WasteTripStartStatisticsService.php');
require_once base_path('app/Service/WasteTripService.php');
require_once base_path('app/Service/FeoUtilizerService.php');
require_once base_path('app/Service/WasteTripLegacyMappingService.php');
require_once base_path('app/Service/WasteTripSynchronizationService.php');
require_once base_path('app/Service/WasteTripLegacySyncService.php');
require_once base_path('app/Http/Controllers/Company/LinearTripController.php');
require_once base_path('app/Http/Controllers/Company/WasteTripController.php');

$controller = new \App\Http\Controllers\Company\LinearTripController($config, $db);
$wasteTripController = new \App\Http\Controllers\Company\WasteTripController($config, $db);
$warehouseStockController = new \App\Http\Controllers\Company\WarehouseStockController($config, $db);

$router->get('/company/warehouses/stock', [$warehouseStockController, 'index'], 'company.warehouses.stock');
$router->get('/company/warehouses/stock/correction-preview', [$warehouseStockController, 'correctionPreview'], 'company.warehouses.stock');
$router->post('/company/warehouses/stock/correction', [$warehouseStockController, 'correctionSubmit'], 'company.warehouses.stock');
$router->get('/company/warehouses/stock/cancellation-preview', [$warehouseStockController, 'cancellationPreview'], 'company.warehouses.stock');
$router->post('/company/warehouses/stock/cancellation', [$warehouseStockController, 'cancellationSubmit'], 'company.warehouses.stock');

$router->get('/company/trips/linear', [$controller, 'index'], 'company.trips.linear');
$router->get('/company/trips/linear/service-lines', [$controller, 'serviceLines'], 'company.trips.linear');
$router->post('/company/trips/linear/create', [$controller, 'createSubmit'], 'company.trips.linear');
$router->get('/company/trips/linear/{id}/modal-view', [$controller, 'modalView'], 'company.trips.linear');
$router->get('/company/trips/linear/{id}/modal-edit', [$controller, 'modalEditForm'], 'company.trips.linear');
$router->post('/company/trips/linear/{id}/modal-edit', [$controller, 'modalEditSubmit'], 'company.trips.linear');
$router->post('/company/trips/linear/{id}/modal-delete', [$controller, 'modalDelete'], 'company.trips.linear');
$router->get('/company/trips/linear/cargo-types', [$controller, 'cargoTypes'], 'company.trips.linear');

$router->get('/company/trips/departures', [$wasteTripController, 'index'], 'company.trips.waste');
$router->post('/company/trips/departures/preview', [$wasteTripController, 'previewSelection'], 'company.trips.waste');
$router->post('/company/trips/departures/create', [$wasteTripController, 'createSubmit'], 'company.trips.waste');
$router->get('/company/trips/departures/{id}/modal-view', [$wasteTripController, 'modalView'], 'company.trips.waste');
$router->get('/company/trips/departures/{id}/modal-edit', [$wasteTripController, 'modalEditForm'], 'company.trips.waste');
$router->post('/company/trips/departures/{id}/modal-edit', [$wasteTripController, 'modalEditSubmit'], 'company.trips.waste');
$router->post('/company/trips/departures/{id}/cancel', [$wasteTripController, 'cancelSubmit'], 'company.trips.waste');

$router->post('/company/trips/departures/synchronize', [$wasteTripController, 'synchronizeSubmit'], 'company.trips.waste');
