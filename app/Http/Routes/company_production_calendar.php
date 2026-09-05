<?php
require_once base_path('app/Http/Controllers/Company/ProductionCalendarController.php');
$controller = new \App\Http\Controllers\Company\ProductionCalendarController($config, $db);
$router->get('/company/misc/production-calendar', [$controller, 'index'], 'company.production_calendar');
$router->post('/company/misc/production-calendar/year/create', [$controller, 'createYear'], 'company.production_calendar');
$router->post('/company/misc/production-calendar/day/save', [$controller, 'saveDay'], 'company.production_calendar');
$router->post('/company/misc/production-calendar/year/confirm', [$controller, 'confirmYear'], 'company.production_calendar');
