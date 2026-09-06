<?php

require_once base_path('app/Http/Controllers/Company/ResponsibleAssignmentController.php');

$controller = new \App\Http\Controllers\Company\ResponsibleAssignmentController($config, $db);
$rolesAccessPage = 'company.roles_access';
$responsibilityPage = 'company.responsible_assignments';

$router->get('/company/responsible-assignments', [$controller, 'index'], $responsibilityPage);
$router->post('/company/responsible-assignments/reassign', [$controller, 'reassign'], $responsibilityPage);
$router->get('/company/roles-access/assignments', [$controller, 'assignments'], $rolesAccessPage);
$router->post('/company/roles-access/assignments/reassign', [$controller, 'explicitAssignment'], $rolesAccessPage);
