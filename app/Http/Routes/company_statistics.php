<?php

require_once base_path('app/Service/WasteTripStartStatisticsService.php');
require_once base_path('app/Http/Controllers/Company/ManagerStatisticsController.php');
require_once base_path('app/Service/FeoShareStatisticsService.php');
require_once base_path('app/Http/Controllers/Company/FeoShareStatisticsController.php');

$managerStatisticsController = new \App\Http\Controllers\Company\ManagerStatisticsController($config, $db);
$feoShareStatisticsController = new \App\Http\Controllers\Company\FeoShareStatisticsController($config, $db);

$router->get(
    '/company/statistics/managers',
    [$managerStatisticsController, 'index'],
    'company.statistics.managers'
);

$router->get('/company/statistics/feo-share', [$feoShareStatisticsController, 'index'], 'company.statistics.feo_share');
