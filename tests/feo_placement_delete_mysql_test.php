<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/FeoPlacementService.php';

use App\Service\FeoPlacementService;

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dbName = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allow, true) || (string)getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Guarded fixed runner DB is required.');
}
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$ok = static function (bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};
$ids = static function (PDO $pdo): array {
    return array_map('strval', $pdo->query('SELECT zayavka_id FROM feo_placement_current ORDER BY zayavka_id')->fetchAll(PDO::FETCH_COLUMN));
};

cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $migration = file_get_contents(BASE_PATH . '/database/migrations-local/123_create_feo_map_placement.sql');
    if ($migration === false) throw new RuntimeException('Migration 123 unavailable.');
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $migration) ?: [])) as $statement) {
        $pdo->exec($statement);
    }
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto) VALUES ('100001',1.000),('100002',2.000),('100003',3.000)");

    $service = new FeoPlacementService();
    $create = static function (FeoPlacementService $service, PDO $pdo, array $requestIds, string $type, string $name): int {
        $preview = $service->previewCreate($pdo, $requestIds, $type);
        $result = $service->createBlock($pdo, [
            'ids'=>$requestIds,
            'block_type'=>$type,
            'name'=>$name,
            'source_type'=>'manual',
            'created_by_user_id'=>15,
            'created_by_role'=>'company_owner',
        ], (string)$preview['state_hash']);
        return (int)$result['block_id'];
    };

    $block1 = $create($service, $pdo, ['100001','100002'], 'addition', 'base');
    $block2 = $create($service, $pdo, ['100002'], 'exclusion', 'exclude');
    $block3 = $create($service, $pdo, ['100003'], 'addition', 'tail');
    $ok($ids($pdo) === ['100001','100003'], 'initial replay mismatch');

    $preview = $service->previewDelete($pdo, $block1);
    $ok(($preview['current_status'] ?? '') === 'applied', 'applied block delete preview must be allowed');
    $ok((int)($preview['effect']['removed_count'] ?? -1) === 1, 'applied addition delete must preview current removal');
    $ok((float)($preview['effect']['removed_mass_kg'] ?? -1) === 1000.0, 'applied addition delete mass mismatch');
    $result = $service->deleteBlock($pdo, $block1, (string)$preview['state_hash']);
    $ok($ids($pdo) === ['100003'], 'deleting active addition did not atomically rebuild current set');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM feo_placement_blocks WHERE id={$block1}")->fetchColumn() === 0, 'active addition block still exists');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM feo_placement_block_requests WHERE block_id={$block1}")->fetchColumn() === 0, 'deleted block membership did not cascade');
    $ok((int)($result['current']['request_count'] ?? -1) === 1, 'current summary after active addition delete mismatch');

    // Removing the still-applied exclusion must restore the request it was excluding.
    $preview = $service->previewDelete($pdo, $block2);
    $ok((int)($preview['effect']['added_count'] ?? -1) === 0, 'orphan exclusion should have no effect after its source addition was removed');
    $service->deleteBlock($pdo, $block2, (string)$preview['state_hash']);
    $ok($ids($pdo) === ['100003'], 'deleting orphan exclusion unexpectedly changed current set');

    // Fresh chain proves deletion of an applied exclusion replays later state correctly.
    $block4 = $create($service, $pdo, ['100001','100002'], 'addition', 'base2');
    $block5 = $create($service, $pdo, ['100002'], 'exclusion', 'exclude2');
    $ok($ids($pdo) === ['100001','100003'], 'second chain replay mismatch');
    $preview = $service->previewDelete($pdo, $block5);
    $ok((int)($preview['effect']['added_count'] ?? -1) === 1, 'applied exclusion delete must preview request restoration');
    $ok((float)($preview['effect']['added_mass_kg'] ?? -1) === 2000.0, 'applied exclusion delete mass mismatch');
    $service->deleteBlock($pdo, $block5, (string)$preview['state_hash']);
    $ok($ids($pdo) === ['100001','100002','100003'], 'deleting active exclusion did not restore request');

    // Stale confirmation must fail without deleting anything.
    $preview = $service->previewDelete($pdo, $block4);
    $pdo->exec("UPDATE feo_placement_blocks SET updated_at=DATE_ADD(updated_at, INTERVAL 1 SECOND) WHERE id={$block4}");
    try {
        $service->deleteBlock($pdo, $block4, (string)$preview['state_hash']);
        $staleRejected = false;
    } catch (RuntimeException $e) {
        $staleRejected = str_contains($e->getMessage(), 'Состав изменился после подтверждения');
    }
    $ok($staleRejected, 'stale delete preview was not rejected');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM feo_placement_blocks WHERE id={$block4}")->fetchColumn() === 1, 'stale delete mutated block');

    echo "FEO_PLACEMENT_DELETE_MYSQL=PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
