<?php

require_once base_path('app/Http/Controllers/Company/DriverController.php');

$controller = new \App\Http\Controllers\Company\DriverController($config, $db);

$router->get('/company/drivers', [$controller, 'index'], 'company.drivers');
$router->get('/company/drivers/create', [$controller, 'createForm'], 'company.drivers');
$router->post('/company/drivers/create', [$controller, 'createSubmit'], 'company.drivers');
$router->post('/company/drivers/modal-create', [$controller, 'modalCreate'], 'company.drivers');
$router->get('/company/drivers/{id}', [$controller, 'show'], 'company.drivers');
$router->get('/company/drivers/{id}/edit', [$controller, 'editForm'], 'company.drivers');
$router->post('/company/drivers/{id}/edit', [$controller, 'editSubmit'], 'company.drivers');
$router->post('/company/drivers/{id}/archive', [$controller, 'archive'], 'company.drivers');
$router->get('/company/drivers/{id}/modal-view', [$controller, 'modalView'], 'company.drivers');
$router->get('/company/drivers/{id}/modal-edit', [$controller, 'modalEditForm'], 'company.drivers');
$router->post('/company/drivers/{id}/modal-edit', [$controller, 'modalEditSubmit'], 'company.drivers');
$router->post('/company/drivers/{id}/modal-delete', [$controller, 'modalDelete'], 'company.drivers');

// Phones
$router->post('/company/drivers/{driver_id}/phones/create', [$controller, 'phoneCreate'], 'company.drivers');
$router->post('/company/drivers/{driver_id}/phones/{phone_id}/edit', [$controller, 'phoneEdit'], 'company.drivers');
$router->post('/company/drivers/{driver_id}/phones/{phone_id}/delete', [$controller, 'phoneDelete'], 'company.drivers');
$router->post('/company/drivers/{driver_id}/phones/{phone_id}/set-main', [$controller, 'phoneSetMain'], 'company.drivers');

// Documents
$router->post('/company/drivers/{driver_id}/documents/upload', [$controller, 'documentUpload'], 'company.drivers');
$router->post('/company/drivers/{driver_id}/documents/{doc_id}/delete', [$controller, 'documentDelete'], 'company.drivers');
