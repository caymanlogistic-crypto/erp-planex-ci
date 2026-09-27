<?php

declare(strict_types=1);

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Service\RouteExecutorCombinedCreateService;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/entrypoint_dependencies.php';
require_once BASE_PATH . '/app/Support/crew_driver_helpers.php';

$dbName = trim((string) getenv('PLANEX_TEST_FIXED_DB'));
$allowed = array_filter(array_map('trim', explode(',', (string) getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allowed, true) || getenv('APP_ENV') !== 'test') throw new RuntimeException('Disposable test schema required.');
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1', (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306), $dbName), getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
cleanCompanyPoolDatabase($pdo);
applyLocalMigrations($pdo);
AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11, 7, ['company.route_executors']));

$payload = static function (string $inn, string $plate): array {
    return [
        'contractor' => ['name' => 'Тестовый перевозчик', 'inn' => $inn, 'contacts' => [['contact_person' => 'Тест', 'phone' => '+7 900 111-22-33', 'email' => 'test@example.test', 'comment' => 'Связь', 'is_primary' => '1']]],
        'driver' => ['full_name' => 'Иванов Иван Иванович', 'phone' => '+7 900 000-00-00', 'extra_phones' => ['+7 900 111-22-33'], 'extra_phone_comments' => ['Резерв'], 'passport_issue_date' => '01.02.2020', 'license_issue_date' => '03.04.2021'],
        'vehicle' => ['set_type' => 'single', 'units' => ['primary' => ['plate_number' => $plate, 'brand' => 'КАМАЗ', 'model' => '4310', 'vin' => 'XTC43100000000000', 'capacity_tons' => '10', 'volume_m3' => '20']]],
    ];
};
$central = new Database($config['database']);
$crewId = RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $payload('7701234567', 'А123ВС77'), [], 101, 'company_owner');
if ($crewId <= 0) throw new RuntimeException('No crew id returned.');
foreach (['contractors','contractor_contacts','drivers','driver_phones','vehicle_units','vehicle_sets','driver_vehicle_blocks','crews','crew_drivers'] as $table) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 1) throw new RuntimeException("Unexpected $table count after create.");
}
$before = (int) $pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn();
$tmp = tempnam(sys_get_temp_dir(), 'route-doc-');
file_put_contents($tmp, '%PDF-1.4 synthetic');
$files = ['contractor_predef_doc' => [
    'name' => ['company_card' => 'synthetic.pdf'],
    'type' => ['company_card' => 'application/pdf'],
    'tmp_name' => ['company_card' => $tmp],
    'error' => ['company_card' => UPLOAD_ERR_OK],
    'size' => ['company_card' => filesize($tmp)],
]];
try {
    RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $payload('7701234568', 'А124ВС77'), $files, 101, 'company_owner');
    throw new RuntimeException('Expected document upload failure.');
} catch (RuntimeException $expected) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn() !== $before) throw new RuntimeException('Partial contractor survived transaction rollback.');
} finally {
    @unlink($tmp);
}
echo "ROUTE_EXECUTOR_COMBINED_TRANSACTION=PASS\n";
