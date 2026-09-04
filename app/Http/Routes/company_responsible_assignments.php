<?php

require_once base_path('app/Http/Controllers/Company/ResponsibleAssignmentController.php');

$controller = new \App\Http\Controllers\Company\ResponsibleAssignmentController($config, $db);
$rolesAccessPage = 'company.roles_access';

$router->get('/company/responsible-assignments', [$controller, 'index']);
$router->get('/company/roles-access/assignments', [$controller, 'assignments'], $rolesAccessPage);
$router->post('/company/responsible-assignments/reassign', [$controller, 'reassign'], $rolesAccessPage);
