<?php

require_once base_path('app/Http/Controllers/Company/LogistController.php');

$c = new \App\Http\Controllers\Company\LogistController($config, $db);

$router->get('/company/logists', [$c, 'index'], 'company.roles_access');
$router->get('/company/logists/create', [$c, 'createForm'], 'company.roles_access');
$router->post('/company/logists/create', [$c, 'createSubmit'], 'company.roles_access');
$router->get('/company/logists/modal-create', [$c, 'modalCreateForm'], 'company.roles_access');
$router->post('/company/logists/modal-create', [$c, 'modalCreateSubmit'], 'company.roles_access');
$router->get('/company/logists/{id}', [$c, 'view'], 'company.roles_access');
$router->get('/company/logists/{id}/edit', [$c, 'editForm'], 'company.roles_access');
$router->post('/company/logists/{id}/edit', [$c, 'editSubmit'], 'company.roles_access');
$router->post('/company/logists/{id}/reset-password', [$c, 'resetPassword'], 'company.roles_access');
$router->get('/company/logists/{id}/modal-view', [$c, 'modalView'], 'company.roles_access');
$router->get('/company/logists/{id}/modal-edit', [$c, 'modalEditForm'], 'company.roles_access');
$router->post('/company/logists/{id}/modal-edit', [$c, 'modalEditSubmit'], 'company.roles_access');
$router->post('/company/logists/{id}/archive', [$c, 'archive'], 'company.roles_access');
