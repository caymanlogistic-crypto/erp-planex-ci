<?php
declare(strict_types=1);

// Run in a fresh process: service tests' explicit requires must not mask a
// missing dependency in the actual route bootstrap (there is no autoloader).
$config = require dirname(__DIR__) . '/bootstrap/app.php';
require BASE_PATH . '/app/Support/entrypoint_dependencies.php';
$db = new class([]) extends App\Core\Database {
    public function connection(): PDO { throw new RuntimeException('Unexpected DB access during route bootstrap.'); }
};
$router = new App\Http\Router();
require BASE_PATH . '/app/Http/Routes/company_feo_requests.php';
require BASE_PATH . '/app/Http/Routes/company_max_messaging.php';
$service = new App\Service\FeoPlacementRouteNotificationService();
if ($service->additionIntents([], []) !== [] || count($service::ACTIVE_STATUSES) !== 4) {
    throw new RuntimeException('Placement notification runtime dependencies are incomplete.');
}
if (App\Service\FeoMapService::coordinates(55, 37)['status'] !== 'ok' || $db->isConnected()) {
    throw new RuntimeException('Unexpected route bootstrap state.');
}
echo "FEO_PLACEMENT_RUNTIME_BOOTSTRAP=PASS\n";
