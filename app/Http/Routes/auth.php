<?php

require_once base_path('app/Http/Controllers/AuthController.php');

$controller = new \App\Http\Controllers\AuthController($config, $db);

$router->get('/login', [$controller, 'showLogin']);
$router->post('/login', [$controller, 'login']);
$router->post('/company/switch', [$controller, 'switchCompany']);
$router->post('/logout', [$controller, 'logout']);
