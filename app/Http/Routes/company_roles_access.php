<?php

require_once base_path('app/Service/IdentityNormalizer.php');
require_once base_path('app/Service/CompanyRoleService.php');
require_once base_path('app/Service/CompanyMembershipService.php');
require_once base_path('app/Http/Controllers/Company/RolesAccessController.php');

$rolesAccessController = new \App\Http\Controllers\Company\RolesAccessController($config, $db);
$rolesAccessPage = 'company.roles_access';

$router->get('/company/roles-access', [$rolesAccessController, 'index'], $rolesAccessPage);
$router->get('/company/roles-access/data', [$rolesAccessController, 'snapshot'], $rolesAccessPage);
$router->post('/company/roles-access/roles', [$rolesAccessController, 'createRole'], $rolesAccessPage);
$router->post('/company/roles-access/roles/{id}/copy', [$rolesAccessController, 'copyRole'], $rolesAccessPage);
$router->post('/company/roles-access/roles/{id}/save', [$rolesAccessController, 'saveRole'], $rolesAccessPage);
$router->post('/company/roles-access/roles/{id}/delete', [$rolesAccessController, 'deleteRole'], $rolesAccessPage);
$router->post('/company/roles-access/memberships', [$rolesAccessController, 'addMembership'], $rolesAccessPage);
$router->post('/company/roles-access/memberships/{id}/save', [$rolesAccessController, 'saveMembership'], $rolesAccessPage);
$router->post('/company/roles-access/memberships/{id}/remove', [$rolesAccessController, 'removeMembership'], $rolesAccessPage);
