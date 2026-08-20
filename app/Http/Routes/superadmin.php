<?php

require_once base_path('app/Http/Controllers/Superadmin/CompanyController.php');
require_once base_path('app/Http/Controllers/Superadmin/CompanyOwnerController.php');
require_once base_path('app/Http/Controllers/Superadmin/UiPatternLabController.php');

$companyController = new \App\Http\Controllers\Superadmin\CompanyController($config, $db);
$ownerController = new \App\Http\Controllers\Superadmin\CompanyOwnerController($config, $db);
$uiPatternLabController = new \App\Http\Controllers\Superadmin\UiPatternLabController();

$router->get('/ui-library', [$uiPatternLabController, 'index']);
$router->get('/superadmin/companies', [$companyController, 'index']);
$router->get('/superadmin/companies/create', [$companyController, 'createForm']);
$router->post('/superadmin/companies/create', [$companyController, 'createSubmit']);
$router->get('/superadmin/companies/{id}', [$companyController, 'view']);
$router->get('/superadmin/companies/{id}/edit', [$companyController, 'editForm']);
$router->post('/superadmin/companies/{id}/edit', [$companyController, 'editSubmit']);
$router->get('/superadmin/companies/{id}/modal-view', [$companyController, 'modalView']);
$router->get('/superadmin/companies/{id}/modal-edit', [$companyController, 'modalEditForm']);
$router->post('/superadmin/companies/{id}/modal-edit', [$companyController, 'modalEditSubmit']);

$router->get('/superadmin/companies/{id}/create-owner', [$ownerController, 'createForm']);
$router->get('/superadmin/companies/{id}/create-owner/modal', [$ownerController, 'createFormModal']);
$router->post('/superadmin/companies/{id}/create-owner', [$ownerController, 'createSubmit']);
$router->get('/superadmin/companies/{id}/owner', [$ownerController, 'view']);
$router->get('/superadmin/companies/{id}/owner/edit', [$ownerController, 'editForm']);
$router->post('/superadmin/companies/{id}/owner/edit', [$ownerController, 'editSubmit']);
$router->post('/superadmin/companies/{id}/owner/reset-password', [$ownerController, 'resetPassword']);

$router->get('/superadmin/companies/{id}/owner/modal-view', [$ownerController, 'modalView']);
$router->get('/superadmin/companies/{id}/owner/modal-edit', [$ownerController, 'modalEditForm']);
$router->post('/superadmin/companies/{id}/owner/modal-edit', [$ownerController, 'modalEditSubmit']);

$router->get('/superadmin/requisites/lookup-by-inn', [$companyController, 'lookupInn']);
$router->post('/superadmin/requisites/lookup-by-inn', [$companyController, 'lookupInn']);
