<?php

require_once base_path('app/Service/WasteTripStartStatisticsService.php');
require_once base_path('app/Http/Controllers/Company/ManagerStatisticsController.php');

$managerStatisticsController = new \App\Http\Controllers\Company\ManagerStatisticsController($config, $db);

$router->get(
    '/company/statistics/managers',
    [$managerStatisticsController, 'index'],
    'company.statistics.managers'
);
