<?php

declare(strict_types=1);

use App\Service\LinearRouteService;
use App\Service\WasteTripService;

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$out = (string)(getenv('OUT_JSON') ?: '/tmp/waste_trip_mapping_options.json');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}

require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/entrypoint_dependencies.php';
require_once $root . '/app/Service/LinearRouteService.php';
require_once $root . '/app/Service/WasteTripService.php';

function roPdo(array $cfg): PDO
{
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');
    return $pdo;
}

function safeExecutorRow(array $row): array
{
    $out = [];
    foreach ($row as $key => $value) {
        $k = (string)$key;
        if (preg_match('/pass|secret|token|phone|email|passport|bank|account|address|comment|document/i', $k)) {
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $out[$k] = $value;
        } elseif (is_array($value)) {
            $vals = [];
            foreach ($value as $v) {
                if (is_scalar($v) || $v === null) $vals[] = $v;
            }
            $out[$k] = $vals;
        }
    }
    return $out;
}

$central = roPdo($config['database']);
$company = $central->query("SELECT * FROM companies WHERE id=25 AND status='active' LIMIT 1")->fetch() ?: null;
if (!$company) throw new RuntimeException('PLANEX tenant unavailable');
$tenantCfg = companyDatabaseConfig($config, $company);
$tenant = roPdo($tenantCfg);

$userId = (int)$tenant->query("SELECT id FROM users WHERE status='active' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if ($userId <= 0) throw new RuntimeException('active tenant user unavailable');
$sessionUser = ['user_id' => $userId, 'role_code' => 'company_owner', 'company_id' => 25];

$clients = $tenant->query("SELECT id,name,inn,status FROM clients WHERE status='active' AND deleted_at IS NULL ORDER BY id")->fetchAll();
$users = WasteTripService::fetchActiveUsers($tenant);
$utilizers = WasteTripService::fetchActiveUtilizers($tenant);
$executorsRaw = LinearRouteService::fetchVisibleRouteExecutors($tenant, $sessionUser);
$executors = array_map('safeExecutorRow', $executorsRaw);

$feoCustomerStmt = $tenant->prepare("SELECT inn_zakazchika, polnoe_naim_zakazchika, COUNT(*) cnt FROM feo_requests WHERE inn_zakazchika=? GROUP BY inn_zakazchika, polnoe_naim_zakazchika ORDER BY cnt DESC");
$feoCustomerStmt->execute(['4714004270']);
$feoCustomers = $feoCustomerStmt->fetchAll();

$result = [
    'company_id' => 25,
    'clients' => $clients,
    'users' => $users,
    'utilizers' => $utilizers,
    'route_executors' => $executors,
    'feo_customer_4714004270' => $feoCustomers,
    'counts' => [
        'clients' => count($clients),
        'users' => count($users),
        'utilizers' => count($utilizers),
        'route_executors' => count($executors),
    ],
];
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo 'WASTE_MAPPING_OPTIONS_COUNTS=' . json_encode($result['counts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo "WASTE_MAPPING_OPTIONS=PASS\n";
