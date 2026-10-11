<?php
declare(strict_types=1);

$config=require dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/entrypoint_dependencies.php';

$router=new class {
    public function get(string $path, array $handler, ?string $page=null): void {}
    public function post(string $path, array $handler, ?string $page=null): void {}
};
$db=new \App\Core\Database($config['database']);
require BASE_PATH.'/app/Http/Routes/company_trip_closing.php';

if (!class_exists(\App\Service\WasteTripClosingTransportService::class, false)) {
    throw new RuntimeException('Trip closing route must load its transport dependency before dispatch');
}

echo "WASTE_TRIP_CLOSING_ROUTE_DEPENDENCIES=PASS\n";
