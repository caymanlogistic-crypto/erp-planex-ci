<?php

require_once base_path('app/Service/WasteTripLegacySourceService.php');
require_once base_path('app/Service/WasteTripService.php');
require_once base_path('app/Service/WasteTripLegacySyncService.php');
require_once base_path('app/Http/Controllers/Company/LinearTripController.php');
require_once base_path('app/Http/Controllers/Company/WasteTripController.php');

$controller = new \App\Http\Controllers\Company\LinearTripController($config, $db);
$wasteTripController = new \App\Http\Controllers\Company\WasteTripController($config, $db);

$router->get('/company/trips/linear', [$controller, 'index']);
$router->get('/company/trips/linear/service-lines', [$controller, 'serviceLines']);
$router->post('/company/trips/linear/create', [$controller, 'createSubmit']);
$router->get('/company/trips/linear/{id}/modal-view', [$controller, 'modalView']);
$router->get('/company/trips/linear/{id}/modal-edit', [$controller, 'modalEditForm']);
$router->post('/company/trips/linear/{id}/modal-edit', [$controller, 'modalEditSubmit']);
$router->post('/company/trips/linear/{id}/modal-delete', [$controller, 'modalDelete']);
$router->get('/company/trips/linear/cargo-types', [$controller, 'cargoTypes']);

$router->get('/company/trips/departures', [$wasteTripController, 'index']);
$router->post('/company/trips/departures/preview', [$wasteTripController, 'previewSelection']);
$router->post('/company/trips/departures/create', [$wasteTripController, 'createSubmit']);
$router->get('/company/trips/departures/legacy', [$wasteTripController, 'legacyIndex']);
$router->post('/company/trips/departures/legacy/{legacyId}/materialize', [$wasteTripController, 'materializeSubmit']);
$router->get('/company/trips/departures/{id}/modal-view', [$wasteTripController, 'modalView']);
$router->get('/company/trips/departures/{id}/modal-edit', [$wasteTripController, 'modalEditForm']);
$router->post('/company/trips/departures/{id}/modal-edit', [$wasteTripController, 'modalEditSubmit']);
$router->post('/company/trips/departures/{id}/cancel', [$wasteTripController, 'cancelSubmit']);
