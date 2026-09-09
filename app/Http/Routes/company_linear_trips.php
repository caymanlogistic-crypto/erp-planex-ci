<?php

require_once base_path('app/Service/WasteTripLegacySourceService.php');
require_once base_path('app/Service/WasteTripIncidentService.php');
require_once base_path('app/Service/WasteTripService.php');
require_once base_path('app/Service/WasteTripLegacyPrefillService.php');
require_once base_path('app/Service/WasteTripLegacySyncService.php');
require_once base_path('app/Http/Controllers/Company/LinearTripController.php');
require_once base_path('app/Http/Controllers/Company/WasteTripController.php');

$controller = new \App\Http\Controllers\Company\LinearTripController($config, $db);
$wasteTripController = new \App\Http\Controllers\Company\WasteTripController($config, $db);

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
$router->get('/company/trips/departures/legacy', [$wasteTripController, 'legacyIndex'], 'company.trips.waste');
$router->post('/company/trips/departures/legacy/{legacyId}/materialize', [$wasteTripController, 'materializeSubmit'], 'company.trips.waste');
$router->get('/company/trips/departures/{id}/modal-view', [$wasteTripController, 'modalView'], 'company.trips.waste');
$router->get('/company/trips/departures/{id}/modal-edit', [$wasteTripController, 'modalEditForm'], 'company.trips.waste');
$router->post('/company/trips/departures/{id}/modal-edit', [$wasteTripController, 'modalEditSubmit'], 'company.trips.waste');
$router->post('/company/trips/departures/{id}/cancel', [$wasteTripController, 'cancelSubmit'], 'company.trips.waste');
