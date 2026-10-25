<?php

require_once base_path('app/Service/WasteTripStartStatisticsService.php');
require_once base_path('app/Http/Controllers/Company/ManagerStatisticsController.php');
require_once base_path('app/Service/FeoShareStatisticsService.php');
require_once base_path('app/Http/Controllers/Company/FeoShareStatisticsController.php');
require_once base_path('app/Service/CargoBalanceStatisticsService.php');
require_once base_path('app/Http/Controllers/Company/CargoBalanceStatisticsController.php');

$managerStatisticsController = new \App\Http\Controllers\Company\ManagerStatisticsController($config, $db);
$feoShareStatisticsController = new \App\Http\Controllers\Company\FeoShareStatisticsController($config, $db);
$cargoBalanceStatisticsController = new \App\Http\Controllers\Company\CargoBalanceStatisticsController($config, $db);

$router->get(
    '/company/statistics/managers',
    [$managerStatisticsController, 'index'],
    'company.statistics.managers'
);
$router->get(
    '/company/statistics/work',
    [$managerStatisticsController, 'personal'],
    'company.statistics.work'
);

$router->get('/company/statistics/feo-share', [$feoShareStatisticsController, 'index'], 'company.statistics.feo_share');
$router->get('/company/statistics/cargo-balance', [$cargoBalanceStatisticsController, 'index'], 'company.statistics.cargo_balance');
