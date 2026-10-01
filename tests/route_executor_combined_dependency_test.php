<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap/app.php';
require BASE_PATH.'/app/Service/RouteExecutorCombinedCreateService.php';

if (!function_exists('syncCrewDrivers')) {
    throw new RuntimeException('Combined route executor creation did not load its crew-driver writer.');
}

echo "route_executor_combined_dependency_test: PASS\n";
