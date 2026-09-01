<?php

declare(strict_types=1);

use App\Core\Database;
use App\Http\Controllers\Company\WasteTripController;
use App\Service\AccessControlService;
use App\Service\LinearRouteService;
use App\Service\WasteTripService;

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Service/WasteTripService.php';
require_once $root . '/app/Service/WasteTripLegacyImportService.php';
require_once $root . '/app/Http/Controllers/Company/WasteTripController.php';

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'], $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$central->exec('SET SESSION TRANSACTION READ ONLY');
$central->exec('START TRANSACTION READ ONLY');
$stmt = $central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1");
$company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$company) throw new RuntimeException('company 25 unavailable');
$tenantCfg = companyDatabaseConfig($config, $company);
$pdo = new PDO(
    'mysql:host=' . $tenantCfg['host'] . ';port=' . ($tenantCfg['port'] ?? 3306) . ';dbname=' . $tenantCfg['database'] . ';charset=utf8mb4',
    $tenantCfg['username'], $tenantCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->exec('START TRANSACTION READ ONLY');
$userId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();

function safeCall(string $name, callable $fn): array {
    try {
        $value = $fn();
        return ['name' => $name, 'ok' => true, 'count' => is_array($value) ? count($value) : null, 'type' => get_debug_type($value)];
    } catch (Throwable $e) {
        return [
            'name' => $name, 'ok' => false,
            'exception' => get_class($e), 'message' => $e->getMessage(),
            'file' => str_replace((string)getenv('PLANEX_ROOT'), '<ROOT>', $e->getFile()),
            'line' => $e->getLine(),
        ];
    }
}

$results = [];
foreach (['company_owner','senior_logist','logist'] as $role) {
    $user = ['user_id' => $userId, 'role_code' => $role];
    $roleResult = ['role' => $role, 'can_see_all' => AccessControlService::canSeeAllCompanyData($user), 'calls' => []];
    $roleResult['calls'][] = safeCall('fetchVisibleClients', fn() => LinearRouteService::fetchVisibleClients($pdo, $user));
    $roleResult['calls'][] = safeCall('fetchVisibleRouteExecutors', fn() => LinearRouteService::fetchVisibleRouteExecutors($pdo, $user));
    $roleResult['calls'][] = safeCall('fetchActiveUsers', fn() => WasteTripService::fetchActiveUsers($pdo));
    $roleResult['calls'][] = safeCall('fetchActiveUtilizers', fn() => WasteTripService::fetchActiveUtilizers($pdo));
    $roleResult['calls'][] = safeCall('fetchTrips', fn() => WasteTripService::fetchTrips($pdo, []));

    $_SESSION = [
        'user_id' => $userId,
        'user_name' => 'Waste Trips owner failure probe',
        'role_code' => $role,
        'company_id' => 25,
        '_csrf_token' => 'probe-only-not-used',
    ];
    $_GET = [];
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/erpv2/company/trips/departures';
    $controller = new WasteTripController($config, new Database($config['database']));
    ob_start();
    try {
        $controller->index();
        $html = (string)ob_get_clean();
        $roleResult['controller'] = [
            'ok' => true,
            'bytes' => strlen($html),
            'has_title' => str_contains($html, 'Перевозка отходов'),
            'has_create' => str_contains($html, 'Создать рейс'),
            'has_import' => str_contains($html, 'Импорт из старой системы'),
        ];
    } catch (Throwable $e) {
        ob_end_clean();
        $roleResult['controller'] = [
            'ok' => false,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => str_replace($root, '<ROOT>', $e->getFile()),
            'line' => $e->getLine(),
            'trace' => array_slice(array_map(static function(array $f) use ($root): array {
                return [
                    'file' => isset($f['file']) ? str_replace($root, '<ROOT>', (string)$f['file']) : null,
                    'line' => $f['line'] ?? null,
                    'function' => $f['function'] ?? null,
                    'class' => $f['class'] ?? null,
                ];
            }, $e->getTrace()), 0, 12),
        ];
    }
    $results[] = $roleResult;
}

echo json_encode($results, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), PHP_EOL;
