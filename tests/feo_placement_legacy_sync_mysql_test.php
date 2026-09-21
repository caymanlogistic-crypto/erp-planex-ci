<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/FeoPlacementService.php';

use App\Service\FeoPlacementService;

$database = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allowed = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if (getenv('APP_ENV') !== 'test' || $database === '' || !in_array($database, $allowed, true)) {
    throw new RuntimeException('Guarded fixed runner DB is required.');
}
$pdo = new PDO(
    'mysql:host=' . getenv('PLANEX_SCHEMA_DB_HOST') . ';port=' . (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306)
    . ';dbname=' . $database . ';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),
    getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$count = 0;
$ok = static function(bool $value, string $message) use (&$count): void {
    if (!$value) throw new RuntimeException($message);
    $count++;
};
$available = static function() use ($pdo): array {
    $rows = $pdo->query("SELECT id,zayavki_ids,zayavki_count,total_mass_tonn,comment FROM status_blocks WHERE id>109 AND status_type='dostupno' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['zayavki_count'] = (int)$row['zayavki_count'];
        $row['total_mass_tonn'] = (float)$row['total_mass_tonn'];
    }
    unset($row);
    return $rows;
};
$current = static fn(): array => array_map('strval', $pdo->query('SELECT zayavka_id FROM feo_placement_current ORDER BY zayavka_id')->fetchAll(PDO::FETCH_COLUMN));
$oldTenant = getenv('FEO_LEGACY_SYNC_TENANT_DATABASE');
$oldLegacy = getenv('FEO_LEGACY_SYNC_DATABASE');
$oldConnection = getenv('FEO_LEGACY_SYNC_CONNECTION_B64');

cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $migration = file_get_contents(BASE_PATH . '/database/migrations-local/123_create_feo_map_placement.sql');
    if ($migration === false) throw new RuntimeException('Migration 123 unavailable.');
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $migration) ?: [])) as $statement) $pdo->exec($statement);
    $pdo->exec("CREATE TABLE feo(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE status_blocks(
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        block_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        comment VARCHAR(255) NULL,
        additional_comment VARCHAR(500) NULL,
        status_type ENUM('dostupno','marshrut') NOT NULL,
        status_value VARCHAR(100) NOT NULL,
        zayavki_count INT NOT NULL DEFAULT 0,
        total_mass_tonn DECIMAL(10,3) NULL,
        cost DECIMAL(12,2) NULL,
        zayavki_ids TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=110");
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto) VALUES
        ('100001',1),('100002',2),('100003',3),('100004',4),('100005',5)");
    $pdo->exec("INSERT INTO feo(zayavka_id,mass_netto) VALUES
        ('100001',1),('100002',2),('100003',3),('100004',4)");
    $pdo->exec("INSERT INTO status_blocks(id,comment,status_type,status_value,zayavki_count,total_mass_tonn,zayavki_ids) VALUES
        (109,'История','dostupno','Доступно к вывозу',1,9,'999999'),
        (110,'Исходный блок','dostupno','Доступно к вывозу',2,3,'100001,100002'),
        (111,'Дубль из старой ERP','dostupno','Доступно к вывозу',1,1,'100001')");

    putenv('FEO_LEGACY_SYNC_TENANT_DATABASE=' . $database);
    putenv('FEO_LEGACY_SYNC_DATABASE=' . $database);
    if (!getenv('PLANEX_TEST_PLACEMENT_LEGACY_XA')) putenv('FEO_LEGACY_SYNC_CONNECTION_B64');

    $service = new FeoPlacementService();
    $create = static function(array $ids, string $type, string $name) use ($service, $pdo): array {
        $preview = $service->previewCreate($pdo, $ids, $type);
        return $service->createBlock($pdo, [
            'ids'=>$ids,'block_type'=>$type,'name'=>$name,'source_type'=>'manual',
            'created_by_user_id'=>1,'created_by_role'=>'company_owner',
        ], (string)$preview['state_hash']);
    };

    $base = $create(['100001','100002'], 'addition', 'Исходный состав');
    $ok($current() === ['100001','100002'], 'initial tenant composition');
    $ok(($base['legacy_sync']['created_block_id'] ?? null) === null, 'existing legacy membership is reused without duplicate block');
    $ok(count($available()) === 1, 'existing legacy duplicates are collapsed and their empty block is deleted');

    $added = $create(['100003','100004'], 'addition', 'Новые заявки');
    $rows = $available();
    $ok($current() === ['100001','100002','100003','100004'], 'tenant addition');
    $ok(count($rows) === 2 && $rows[1]['zayavki_ids'] === '100003,100004', 'addition creates one legacy available block');
    $ok($rows[1]['zayavki_count'] === 2 && $rows[1]['total_mass_tonn'] === 7.0, 'new legacy block count and mass');
    $ok(str_contains((string)$rows[1]['comment'], 'Новая ERP:'), 'new legacy block identifies authoritative source');
    $ok((int)($added['legacy_sync']['added'] ?? 0) === 2, 'addition result exposes mirrored delta');

    $excluded = $create(['100002','100003'], 'exclusion', 'Снятие двух заявок');
    $rows = $available();
    $ok($current() === ['100001','100004'], 'tenant exclusion');
    $ok($rows[0]['zayavki_ids'] === '100001' && $rows[0]['zayavki_count'] === 1 && $rows[0]['total_mass_tonn'] === 1.0, 'first legacy block recalculated');
    $ok($rows[1]['zayavki_ids'] === '100004' && $rows[1]['zayavki_count'] === 1 && $rows[1]['total_mass_tonn'] === 4.0, 'second legacy block recalculated');
    $ok((int)($excluded['legacy_sync']['updated_blocks'] ?? 0) === 2, 'both touched legacy blocks updated');

    $lastRemoval = $create(['100001'], 'exclusion', 'Опустошение исходного блока');
    $rows = $available();
    $ok($current() === ['100004'], 'last request removed from tenant');
    $ok(count($rows) === 1 && $rows[0]['zayavki_ids'] === '100004', 'empty legacy block deleted');
    $ok((int)($lastRemoval['legacy_sync']['deleted_blocks'] ?? 0) === 1, 'deleted block reported');
    $ok((string)$pdo->query('SELECT zayavki_ids FROM status_blocks WHERE id=109')->fetchColumn() === '999999', 'historical block 109 is untouched');

    $statusPreview = $service->previewStatusChange($pdo, (int)$excluded['block_id'], 'disabled');
    $statusResult = $service->changeStatus($pdo, (int)$excluded['block_id'], 'disabled', (string)$statusPreview['state_hash']);
    $rows = $available();
    $ok($current() === ['100002','100003','100004'], 'disabling exclusion restores tenant requests');
    $ok(count($rows) === 2 && $rows[1]['zayavki_ids'] === '100002,100003', 'status change creates one legacy block for restored requests');
    $ok((int)($statusResult['legacy_sync']['added'] ?? 0) === 2, 'status change mirrored');

    $deletePreview = $service->previewDelete($pdo, (int)$base['block_id']);
    $deleteResult = $service->deleteBlock($pdo, (int)$base['block_id'], (string)$deletePreview['state_hash']);
    $rows = $available();
    $ok($current() === ['100003','100004'], 'deleting addition removes only its effective request');
    $ok(count($rows) === 2 && $rows[1]['zayavki_ids'] === '100003', 'delete updates restored legacy block');
    $ok((int)($deleteResult['legacy_sync']['removed'] ?? 0) === 1, 'delete mirrored');

    $beforeBlocks = (int)$pdo->query('SELECT COUNT(*) FROM feo_placement_blocks')->fetchColumn();
    $beforeLegacy = $available();
    $preview = $service->previewCreate($pdo, ['100005'], 'addition');
    try {
        $service->createBlock($pdo, [
            'ids'=>['100005'],'block_type'=>'addition','name'=>'Нет в старой feo','source_type'=>'manual',
            'created_by_user_id'=>1,'created_by_role'=>'company_owner',
        ], (string)$preview['state_hash']);
        $failedClosed = false;
    } catch (RuntimeException $e) {
        $failedClosed = str_contains($e->getMessage(), 'В старой базе не найдены заявки');
    }
    $ok($failedClosed, 'missing legacy request fails closed');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM feo_placement_blocks')->fetchColumn() === $beforeBlocks, 'failed mirror rolls tenant block back');
    $ok($available() === $beforeLegacy && $current() === ['100003','100004'], 'failed mirror rolls both compositions back');

    echo "FEO_PLACEMENT_LEGACY_SYNC_MYSQL=PASS checks={$count}\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cleanCompanyPoolDatabase($pdo);
    foreach ([
        'FEO_LEGACY_SYNC_TENANT_DATABASE'=>$oldTenant,
        'FEO_LEGACY_SYNC_DATABASE'=>$oldLegacy,
        'FEO_LEGACY_SYNC_CONNECTION_B64'=>$oldConnection,
    ] as $key=>$value) putenv($value === false ? $key : $key . '=' . $value);
}
