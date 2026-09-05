<?php

declare(strict_types=1);

use App\Service\LocalMigrationService;
use Planex\Tools\Database\TenantSchemaCatalog;

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/LocalMigrationService.php';
require_once BASE_PATH . '/tools/database/TenantSchemaCatalog.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$fixedDb = trim((string) getenv('PLANEX_TEST_FIXED_DB'));
$fixedAllowlist = array_filter(array_map('trim', explode(',', (string) getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($fixedDb !== '' && !in_array($fixedDb, $fixedAllowlist, true)) {
    throw new RuntimeException('Fixed DB is not present in PLANEX_TEST_FIXED_DB_ALLOWLIST.');
}
$fixedMode = $fixedDb !== '';
$adminDsn = "mysql:host={$host};port={$port}" . ($fixedMode ? ";dbname={$fixedDb}" : '') . ';charset=utf8mb4';
$admin = new PDO($adminDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$prefix = 'planex_contract_' . getmypid() . '_' . bin2hex(random_bytes(3));
$dbEmpty = $fixedMode ? $fixedDb : $prefix . '_empty';
$dbDirty = $fixedMode ? $fixedDb : $prefix . '_dirty';

$quote = static fn(string $db): string => '`' . str_replace('`', '``', $db) . '`';
$connect = static fn(string $db): PDO => new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

if (!$fixedMode) {
    foreach ([$dbEmpty, $dbDirty] as $db) {
        $admin->exec('CREATE DATABASE ' . $quote($db) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
} else {
    cleanCompanyPoolDatabase($admin);
}
try {
    // CASE 1: truly empty resource -> canonical migrations.
    $empty = $connect($dbEmpty);
    LocalMigrationService::apply($empty);
    $migrationFiles = glob(BASE_PATH . '/database/migrations-local/*.sql') ?: [];
    sort($migrationFiles, SORT_STRING);
    $expectedLatest = basename((string) end($migrationFiles));
    $applied = $empty->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($applied) === count($migrationFiles), 'Empty DB has pending/missing migrations.');
    $assert((string) end($applied) === $expectedLatest, 'Empty DB latest migration mismatch.');

    foreach (['schema_migrations', 'users', 'clients', 'contractors', 'vehicle_units', 'documents', 'linear_routes', 'finance_invoices'] as $table) {
        $stmt = $empty->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $assert((int) $stmt->fetchColumn() === 1, 'Missing required core table: ' . $table);
    }
    foreach ([['users', 'email'], ['linear_routes', 'status'], ['finance_invoices', 'id']] as [$table, $column]) {
        $stmt = $empty->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $assert((int) $stmt->fetchColumn() === 1, "Missing representative column {$table}.{$column}");
    }
    $idx = $empty->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'PRIMARY'")->fetchColumn();
    $assert((int) $idx === 1, 'Representative users PRIMARY index missing.');
    $emptyCatalog = TenantSchemaCatalog::capture($empty);

    // CASE 2: pool resource with stale application objects/data/view/trigger/routine/event.
    // The guarded runner exposes one fixed DB per task, so the two canonical
    // scenarios execute sequentially against the same cleaned resource.
    if ($fixedMode) {
        cleanCompanyPoolDatabase($empty);
    }
    $dirty = $connect($dbDirty);
    $dirty->exec('CREATE TABLE stale_parent (id INT PRIMARY KEY, payload VARCHAR(30)) ENGINE=InnoDB');
    $dirty->exec("INSERT INTO stale_parent VALUES (1, 'legacy-data')");
    $dirty->exec('CREATE TABLE stale_child (id INT PRIMARY KEY, parent_id INT, CONSTRAINT fk_stale FOREIGN KEY (parent_id) REFERENCES stale_parent(id)) ENGINE=InnoDB');
    $dirty->exec('CREATE VIEW stale_view AS SELECT id FROM stale_parent');
    $dirty->exec('CREATE TRIGGER stale_trigger BEFORE INSERT ON stale_parent FOR EACH ROW SET NEW.payload = COALESCE(NEW.payload, \'legacy\')');
    $dirty->exec('CREATE PROCEDURE stale_proc() SELECT 1');
    try {
        $dirty->exec("CREATE EVENT stale_event ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY DO INSERT INTO stale_parent VALUES (2, 'event')");
    } catch (Throwable $e) {
        // Some MySQL test runners disable event creation. Other stale object classes remain mandatory.
    }

    cleanCompanyPoolDatabase($dirty);
    foreach (['BASE TABLE', 'VIEW'] as $type) {
        $stmt = $dirty->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?');
        $stmt->execute([$type]);
        $assert((int) $stmt->fetchColumn() === 0, "Cleanup left {$type} objects.");
    }
    $assert((int) $dirty->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchColumn() === 0, 'Cleanup left triggers.');
    $assert((int) $dirty->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchColumn() === 0, 'Cleanup left routines.');
    $assert((int) $dirty->query('SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()')->fetchColumn() === 0, 'Cleanup left events.');

    LocalMigrationService::apply($dirty);
    $dirtyApplied = $dirty->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    $assert($dirtyApplied === $applied, 'Cleaned dirty DB migration journal differs from empty DB.');
    $dirtyCatalog = TenantSchemaCatalog::capture($dirty);
    $emptyFp = TenantSchemaCatalog::fingerprint($emptyCatalog);
    $dirtyFp = TenantSchemaCatalog::fingerprint($dirtyCatalog);
    $assert(hash_equals($emptyFp, $dirtyFp), "Dirty/empty final schema fingerprint mismatch: {$dirtyFp} != {$emptyFp}");

    fwrite(STDOUT, "TENANT_PROVISIONING_EMPTY=PASS\nDIRTY_POOL_CLEANUP=PASS\nDIRTY_POOL_EQUIVALENCE=PASS\nLATEST_MIGRATION={$expectedLatest}\nSCHEMA_FINGERPRINT={$emptyFp}\n");
} finally {
    if ($fixedMode) {
        cleanCompanyPoolDatabase($admin);
    } else {
        foreach ([$dbEmpty, $dbDirty] as $db) {
            $admin->exec('DROP DATABASE IF EXISTS ' . $quote($db));
        }
    }
}
