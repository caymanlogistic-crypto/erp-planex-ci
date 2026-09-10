<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/WasteTripLegacySourceService.php';
require_once BASE_PATH . '/app/Service/FeoPlacementService.php';
require_once BASE_PATH . '/app/Service/FeoLegacyParityService.php';

use App\Service\FeoLegacyParityService;

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dbName = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allow, true) || (string)getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Guarded fixed runner DB is required.');
}
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$ok = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };

cleanCompanyPoolDatabase($pdo);
try {
    foreach ([122,123] as $id) {
        $matches = glob(BASE_PATH . "/database/migrations-local/{$id}_*.sql") ?: [];
        if (count($matches) !== 1) throw new RuntimeException('Missing FEO migration ' . $id);
        $pdo->exec((string)file_get_contents($matches[0]));
    }
    $pdo->exec("CREATE TABLE feo (zayavka_id VARCHAR(64) NOT NULL, mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE status_blocks (id INT NOT NULL PRIMARY KEY, zayavki_ids TEXT NULL, status_type VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $insertRequest = $pdo->prepare("INSERT INTO feo_requests(zayavka_id,mass_netto) VALUES(?,?)");
    foreach ([['100001',1.0],['100002',2.0],['100003',3.0]] as [$id,$mass]) $insertRequest->execute([$id,$mass]);
    $pdo->exec("INSERT INTO feo_placement_blocks(id,name,block_type,status,source_type) VALUES(1,'Основной','addition','applied','manual')");
    $insertMember = $pdo->prepare("INSERT INTO feo_placement_block_requests(block_id,zayavka_id,position_no) VALUES(1,?,?)");
    $insertCurrent = $pdo->prepare("INSERT INTO feo_placement_current(zayavka_id,source_block_id) VALUES(?,1)");
    foreach (['100001','100002','100003'] as $i=>$id) {
        $insertMember->execute([$id,$i+1]);
        $insertCurrent->execute([$id]);
    }
    $legacyInsert = $pdo->prepare("INSERT INTO feo(zayavka_id,mass_netto) VALUES(?,?)");
    foreach ([['100001',1.0],['100002',2.5],['100004',4.0],['777777',7.0],['888888',8.0]] as [$id,$mass]) $legacyInsert->execute([$id,$mass]);
    $pdo->exec("INSERT INTO status_blocks(id,zayavki_ids,status_type) VALUES
        (109,'777777','dostupno'),
        (110,'100001,100002,100004','dostupno'),
        (130,'888888','marshrut')");

    $state = (new FeoLegacyParityService())->state($pdo, $pdo);
    $ok($state['available'] === true, 'legacy parity must be available against supported schema');
    $ok($state['mismatch_count'] === 3, 'request-level mismatch count must include two unique IDs and one mass mismatch');
    $ok(array_column($state['only_new'], 'zayavka_id') === ['100003'], 'ERP-only ID');
    $ok(array_column($state['only_legacy'], 'zayavka_id') === ['100004'], 'only available legacy blocks participate');
    $ok(array_column($state['mass_mismatches'], 'zayavka_id') === ['100002'], 'mass mismatch ID');
    $ok(($state['only_new'][0]['blocks'][0]['id'] ?? 0) === 1, 'ERP-only request exposes placement block history');
    $ok(($state['mass_mismatches'][0]['source_block_id'] ?? 0) === 1, 'mass mismatch exposes current source block');
    $ok(($state['only_legacy'][0]['legacy_block_ids'] ?? []) === [110], 'legacy-only request exposes legacy available block');
    $ok(($state['mass_mismatches'][0]['legacy_block_ids'] ?? []) === [110], 'mass mismatch exposes legacy available block');
    $ok(!in_array('777777', array_column($state['only_legacy'], 'zayavka_id'), true), 'block id 109 is excluded by strict boundary');
    $ok(!in_array('888888', array_column($state['only_legacy'], 'zayavka_id'), true), 'non-available block is excluded even when its ID is above 109');
    $ok(abs((float)$state['delta']['total_mass_kg'] + 1500.0) < 0.001, 'aggregate weight delta is factual');

    $pdo->exec('DELETE FROM feo');
    $pdo->exec("UPDATE status_blocks SET zayavki_ids='100001,100002,100003' WHERE id=110");
    foreach ([['100001',1.0],['100002',2.0],['100003',3.0]] as [$id,$mass]) $legacyInsert->execute([$id,$mass]);
    $equal = (new FeoLegacyParityService())->state($pdo, $pdo);
    $ok($equal['available'] === true && $equal['is_consistent'] === true && $equal['mismatch_count'] === 0, 'equal legacy and ERP pools pass');

    // OWNER invariant: parity compares the logical current ERP set, not placement history.
    // Keep the materialized current table intentionally stale to prove that an applied
    // exclusion wins over an earlier addition for parity purposes.
    $pdo->exec("INSERT INTO feo_placement_blocks(id,name,block_type,status,source_type) VALUES(2,'Исключение','exclusion','applied','manual')");
    $pdo->exec("INSERT INTO feo_placement_block_requests(block_id,zayavka_id,position_no) VALUES(2,'100003',1)");
    $ok((int)$pdo->query("SELECT COUNT(*) FROM feo_placement_current WHERE zayavka_id='100003'")->fetchColumn() === 1, 'fixture keeps stale materialized row for removed request');
    $effective = (new FeoLegacyParityService())->state($pdo, $pdo);
    $ok(($effective['new']['request_count'] ?? -1) === 2, 'parity new-side count uses effective current requests after exclusion');
    $ok(array_column($effective['only_legacy'], 'zayavka_id') === ['100003'], 'added then excluded request is not current in new ERP');
    $ok(!in_array('100003', array_column($effective['only_new'], 'zayavka_id'), true), 'removed request can never be reported as ERP-current');

    $pdo->exec("INSERT INTO feo(zayavka_id,mass_netto) VALUES('100001',1.0)");
    $duplicate = (new FeoLegacyParityService())->state($pdo, $pdo);
    $ok($duplicate['available'] === false && in_array('100001', $duplicate['duplicate_ids'] ?? [], true), 'duplicate legacy request IDs fail attentive');

    echo "FEO_LEGACY_PARITY_MYSQL=PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
