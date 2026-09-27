<?php

require_once base_path('app/Http/Controllers/Company/RouteExecutorController.php');

$controller = new \App\Http\Controllers\Company\RouteExecutorController($config, $db);
$routeExecutorsPage = 'company.route_executors';

$router->get('/company/route-executors', [$controller, 'index'], $routeExecutorsPage);
$router->get('/company/route-executors/create', [$controller, 'createForm'], $routeExecutorsPage);
$router->post('/company/route-executors/create', [$controller, 'createSubmit'], $routeExecutorsPage);
$router->post('/company/route-executors/{id}/preview-delete', [$controller, 'previewDelete'], $routeExecutorsPage);
$router->get('/company/route-executors/{id}', [$controller, 'view'], $routeExecutorsPage);
$router->get('/company/route-executors/{id}/edit', [$controller, 'editForm'], $routeExecutorsPage);
$router->post('/company/route-executors/{id}/edit', [$controller, 'editSubmit'], $routeExecutorsPage);
$router->post('/company/route-executors/{id}/archive', [$controller, 'archive'], $routeExecutorsPage);
