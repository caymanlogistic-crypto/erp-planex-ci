<?php

require_once base_path('app/Support/crew_driver_helpers.php');
require_once base_path('app/Service/DriverTsDataService.php');
require_once base_path('app/Service/DocumentService.php');
require_once base_path('app/Http/Controllers/Company/DriverTsDataController.php');

$driverTsController = new \App\Http\Controllers\Company\DriverTsDataController($config, $db);
$driverTsPage = 'company.driver_ts_data';
$router->get('/company/driver-ts-data', [$driverTsController, 'index'], $driverTsPage);
$router->get('/company/driver-ts-data/{id}/detail', [$driverTsController, 'detail'], $driverTsPage);
$router->get('/company/driver-ts-data/{id}/files/{documentId}', [$driverTsController, 'file'], $driverTsPage);
$router->get('/company/driver-ts-data/{id}/archive', [$driverTsController, 'archive'], $driverTsPage);
