<?php

require_once base_path('app/Http/Controllers/Company/ClientController.php');

$controller = new \App\Http\Controllers\Company\ClientController($config, $db);

$router->get('/company/clients', [$controller, 'index'], 'company.clients');
$router->get('/company/clients/create', [$controller, 'createForm'], 'company.clients');
$router->post('/company/clients/create', [$controller, 'createSubmit'], 'company.clients');
$router->get('/company/clients/{id}', [$controller, 'show'], 'company.clients');
$router->get('/company/clients/{id}/edit', [$controller, 'editForm'], 'company.clients');
$router->post('/company/clients/{id}/edit', [$controller, 'editSubmit'], 'company.clients');
$router->post('/company/clients/{id}/archive', [$controller, 'archive'], 'company.clients');
$router->get('/company/clients/{id}/modal-view', [$controller, 'modalView'], 'company.clients');
$router->get('/company/clients/{id}/modal-edit', [$controller, 'modalEditForm'], 'company.clients');
$router->post('/company/clients/{id}/modal-edit', [$controller, 'modalEditSubmit'], 'company.clients');
$router->post('/company/clients/{id}/modal-archive', [$controller, 'modalArchive'], 'company.clients');
