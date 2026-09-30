<?php

declare(strict_types=1);

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Service\RouteExecutorCombinedCreateService;
use App\Service\RouteExecutorDeletionService;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/entrypoint_dependencies.php';
require_once BASE_PATH . '/app/Support/crew_driver_helpers.php';

$dbName = trim((string) getenv('PLANEX_TEST_FIXED_DB'));
$allowed = array_filter(array_map('trim', explode(',', (string) getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allowed, true) || getenv('APP_ENV') !== 'test') throw new RuntimeException('Disposable test schema required.');
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1', (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306), $dbName), getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
cleanCompanyPoolDatabase($pdo);
applyLocalMigrations($pdo);
$migration153 = file_get_contents(BASE_PATH . '/database/migrations-local/153_crew_active_uniqueness.sql');
if ($migration153 === false) throw new RuntimeException('Migration 153 is missing.');
$pdo->exec($migration153);
$indexColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crews' AND INDEX_NAME='uk_crew' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
if ($indexColumns !== ['contractor_id', 'driver_vehicle_block_id', 'active_crew_key']) throw new RuntimeException('Migration 153 index is not idempotent.');
AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11, 7, ['company.route_executors']));
$pdo->exec("CREATE TABLE company_roles (id INT PRIMARY KEY,company_id INT NOT NULL)");
$pdo->exec("CREATE TABLE user_accounts (id INT PRIMARY KEY,full_name VARCHAR(255) NOT NULL,login VARCHAR(100) NOT NULL,status VARCHAR(20) NOT NULL)");
$pdo->exec("CREATE TABLE company_memberships (id INT PRIMARY KEY,company_id INT NOT NULL,account_id INT NOT NULL,role_id INT NOT NULL,status VARCHAR(20) NOT NULL)");
$pdo->exec("INSERT INTO company_roles VALUES (1,7); INSERT INTO user_accounts VALUES (1,'Создатель','owner','active'),(2,'Ответственный логист','logist','active'),(3,'Другой компании','outsider','active'); INSERT INTO company_memberships VALUES (11,7,1,1,'active'),(12,7,2,1,'active'),(13,8,3,1,'active')");

$payload = static function (string $inn, string $plate): array {
    return [
        'responsible_logist_membership_id' => '12',
        'contractor' => ['name' => 'Тестовый перевозчик', 'inn' => $inn, 'contacts' => [['contact_person' => 'Тест', 'phone' => '+7 900 111-22-33', 'email' => 'test@example.test', 'comment' => 'Связь', 'is_primary' => '1']]],
        'driver' => ['full_name' => 'Иванов Иван Иванович', 'phone' => '+7 900 000-00-00', 'extra_phones' => ['+7 900 111-22-33'], 'extra_phone_comments' => ['Резерв'], 'passport_issue_date' => '01.02.2020', 'license_issue_date' => '03.04.2021'],
        'vehicle' => ['set_type' => 'single', 'units' => ['primary' => ['plate_number' => $plate, 'brand' => 'КАМАЗ', 'model' => '4310', 'vin' => 'XTC43100000000000', 'capacity_tons' => '10', 'volume_m3' => '20']]],
    ];
};
$central = new Database(['host' => getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1', 'port' => getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306', 'database' => $dbName, 'username' => getenv('PLANEX_SCHEMA_DB_USER'), 'password' => getenv('PLANEX_SCHEMA_DB_PASSWORD')]);
$crewId = RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $payload('7701234567', 'А123ВС77'), [], 101, 'company_owner');
if ($crewId <= 0) throw new RuntimeException('No crew id returned.');
foreach (['contractors','contractor_contacts','drivers','driver_phones','vehicle_units','vehicle_sets','driver_vehicle_blocks','crews','crew_drivers'] as $table) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 1) throw new RuntimeException("Unexpected $table count after create.");
}
$responsibility=$pdo->query("SELECT membership_id,assigned_by_membership_id FROM entity_responsibilities WHERE entity_type='route_executor' AND entity_id=$crewId")->fetch(PDO::FETCH_ASSOC);
if (!$responsibility || (int)$responsibility['membership_id']!==12 || (int)$responsibility['assigned_by_membership_id']!==11) throw new RuntimeException('Selected responsible logist was not saved.');
$before = (int) $pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn();
foreach ([null, '13'] as $invalid) {
    $attempt=$payload('7701234590','А129ВС77');
    if ($invalid===null) unset($attempt['responsible_logist_membership_id']);
    else $attempt['responsible_logist_membership_id']=$invalid;
    $rejected=false;
    try { RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$attempt,[],101,'company_owner'); }
    catch (RuntimeException $expected) { $rejected=true; }
    if (!$rejected) throw new RuntimeException('Invalid responsible logist was accepted.');
    if ((int)$pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn()!==$before) throw new RuntimeException('Invalid logist created partial data.');
}
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
    if ((int) $pdo->query("SELECT COUNT(*) FROM entity_responsibilities WHERE entity_type='route_executor'")->fetchColumn() !== 1) throw new RuntimeException('Partial responsibility survived transaction rollback.');
} finally {
    @unlink($tmp);
}
$pdo->exec("INSERT INTO linear_routes(route_type,client_id,carrier_contractor_id,route_executor_id,cargo_type_id,planned_loading_date,planned_unloading_date,status) VALUES('direct',1,1,$crewId,1,'2026-09-27','2026-09-28','cancelled')");
$usage = RouteExecutorDeletionService::tripUsage($pdo, $crewId);
if ($usage['linear_count'] !== 1 || $usage['waste_count'] !== 0) throw new RuntimeException('Linear trip usage was not detected.');
try {
    RouteExecutorDeletionService::archive($pdo, $crewId, 101, 'company_owner');
    throw new RuntimeException('Linked linear trip allowed deletion.');
} catch (RuntimeException $expected) {
    if (!str_contains($expected->getMessage(), 'участвует')) throw $expected;
}
$pdo->exec('DELETE FROM linear_routes WHERE route_executor_id=' . $crewId);
$pdo->exec("INSERT INTO clients(id,name,inn,status) VALUES(1,'Тестовый клиент','7700000000','active')");
$pdo->exec("INSERT INTO users(id,full_name,login,password_hash,role_code,status) VALUES(101,'Тестовый пользователь','owner','hash','company_owner','active')");
$pdo->exec("INSERT INTO waste_trips(business_id,client_id,route_executor_id,responsible_user_id,destination_type,status) VALUES(144,1,$crewId,101,'warehouse','cancelled')");
$usage = RouteExecutorDeletionService::tripUsage($pdo, $crewId);
if ($usage['linear_count'] !== 0 || $usage['waste_count'] !== 1) throw new RuntimeException('Waste trip usage was not detected.');
try {
    RouteExecutorDeletionService::archive($pdo, $crewId, 101, 'company_owner');
    throw new RuntimeException('Linked waste trip allowed deletion.');
} catch (RuntimeException $expected) {
    if (!str_contains($expected->getMessage(), 'участвует')) throw $expected;
}
$pdo->exec('DELETE FROM waste_trips WHERE route_executor_id=' . $crewId);
RouteExecutorDeletionService::archive($pdo, $crewId, 101, 'company_owner');
$archived = $pdo->query('SELECT status,deleted_at FROM crews WHERE id=' . $crewId)->fetch(PDO::FETCH_ASSOC);
if (($archived['status'] ?? '') !== 'archived' || empty($archived['deleted_at'])) throw new RuntimeException('Unlinked crew was not archived.');
if ((new \App\Service\DriverService($config, $central))->checkDriverHasCrews($pdo, 1)) throw new RuntimeException('Archived crew still blocks driver deletion.');
if (\App\Service\RouteExecutorService::hasDuplicateCrew($pdo, 1, 1, 1)) throw new RuntimeException('Archived crew still counts as a duplicate.');
$pdo->exec('INSERT INTO crews(contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status,created_by_user_id,created_by_role) SELECT contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,\'active\',created_by_user_id,created_by_role FROM crews WHERE id=' . $crewId);
$recreatedCrewId = (int) $pdo->lastInsertId();
if ($recreatedCrewId <= $crewId || !\App\Service\RouteExecutorService::hasDuplicateCrew($pdo, 1, 1, 1)) throw new RuntimeException('Active crew duplicate detection failed after re-entry.');
try {
    $pdo->exec('INSERT INTO crews(contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status,created_by_user_id,created_by_role) SELECT contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,\'active\',created_by_user_id,created_by_role FROM crews WHERE id=' . $crewId);
    throw new RuntimeException('Second active crew with the same components was accepted.');
} catch (PDOException $expected) {
}
RouteExecutorDeletionService::archive($pdo, $recreatedCrewId, 101, 'company_owner');
$contractorService = new \App\Service\ContractorService($config, $central);
if (!isset($contractorService->validateContractor(['name' => 'Проверка', 'inn' => '7701234567'], $pdo)['inn'])) throw new RuntimeException('Live contractor duplicate was missed.');
$contractorService->archiveContractor($pdo, 1);
if (isset($contractorService->validateContractor(['name' => 'Проверка', 'inn' => '7701234567'], $pdo)['inn'])) throw new RuntimeException('Deleted contractor still reserves its INN.');
(new \App\Service\DriverService($config, $central))->archiveDriver($pdo, 1);
$pdo->exec('UPDATE vehicle_sets SET deleted_at=NOW() WHERE id=1');
$newCrewId = RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $payload('7701234567', 'А123ВС77'), [], 101, 'company_owner');
if ($newCrewId <= $recreatedCrewId) throw new RuntimeException('Previously deleted data could not be entered again.');
echo "ROUTE_EXECUTOR_COMBINED_TRANSACTION=PASS\n";
