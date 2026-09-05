<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dbName = trim((string) getenv('PLANEX_TEST_FIXED_DB'));
$allowlist = array_filter(array_map('trim', explode(',', (string) getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allowlist, true)) {
    throw new RuntimeException('A guarded fixed runner DB is required.');
}
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB');
    foreach (['clients', 'contractors', 'vehicle_units', 'vehicle_sets', 'drivers', 'linear_routes', 'waste_trips'] as $table) {
        $pdo->exec("CREATE TABLE `{$table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, created_by_user_id INT UNSIGNED NULL, PRIMARY KEY(id)) ENGINE=InnoDB");
    }

    $migration = (string) file_get_contents(BASE_PATH . '/database/migrations-local/131_create_roles_access_projection.sql');
    $pdo->exec($migration);
    $pdo->exec($migration);

    $pdo->exec('ALTER TABLE clients DROP INDEX idx_clients_creator_membership');
    $pdo->exec($migration);

    foreach ([
        ['users', 'membership_id', 'uk_users_membership_id'],
        ['clients', 'created_by_membership_id', 'idx_clients_creator_membership'],
        ['contractors', 'created_by_membership_id', 'idx_contractors_creator_membership'],
        ['vehicle_units', 'created_by_membership_id', 'idx_vehicle_units_creator_membership'],
        ['vehicle_sets', 'created_by_membership_id', 'idx_vehicle_sets_creator_membership'],
        ['drivers', 'created_by_membership_id', 'idx_drivers_creator_membership'],
        ['linear_routes', 'created_by_membership_id', 'idx_linear_routes_creator_membership'],
        ['waste_trips', 'created_by_membership_id', 'idx_waste_trips_creator_membership'],
    ] as [$table, $column, $index]) {
        $columnStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $columnStmt->execute([$table, $column]);
        $assert((int) $columnStmt->fetchColumn() === 1, "missing {$table}.{$column}");

        $indexStmt = $pdo->prepare('SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $indexStmt->execute([$table, $index]);
        $assert((int) $indexStmt->fetchColumn() === 1, "missing {$table}.{$index}");
    }

    $projectionStmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_access_projections'");
    $assert((int) $projectionStmt->fetchColumn() === 1, 'missing legacy_access_projections');

    fwrite(STDOUT, "roles access tenant migration recovery: PASS\n");
} finally {
    cleanCompanyPoolDatabase($pdo);
}
