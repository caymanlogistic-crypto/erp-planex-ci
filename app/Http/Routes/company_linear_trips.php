<?php

require_once base_path('app/Http/Controllers/Company/LinearTripController.php');

$controller = new \App\Http\Controllers\Company\LinearTripController($config, $db);

$router->get('/company/trips/linear', [$controller, 'index']);
$router->get('/company/trips/linear/service-lines', [$controller, 'serviceLines']);
$router->post('/company/trips/linear/create', [$controller, 'createSubmit']);
$router->get('/company/trips/linear/{id}/modal-view', [$controller, 'modalView']);
$router->get('/company/trips/linear/{id}/modal-edit', [$controller, 'modalEditForm']);
$router->post('/company/trips/linear/{id}/modal-edit', [$controller, 'modalEditSubmit']);
$router->post('/company/trips/linear/{id}/modal-delete', [$controller, 'modalDelete']);
$router->get('/company/trips/linear/cargo-types', [$controller, 'cargoTypes']);
$router->get('/company/trips/departures', [$controller, 'departuresPlaceholder']);
