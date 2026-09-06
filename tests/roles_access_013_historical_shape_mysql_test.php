<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/scripts/roles_access_013_schema_proof.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$db = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = array_values(array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')))));
if ($db === '' || !in_array($db, $allow, true) || (string)getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Guarded fixed test DB required');
}
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
$ok = static function (bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};

cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE companies(id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB");
    $pdo->exec((string)file_get_contents(BASE_PATH . '/database/migrations/013_create_roles_access.sql'));

    $journal = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'")->fetchColumn();
    $ok($journal === 0, 'test must model historical 013 without a journal table');

    $proof = rolesAccessCentral013SchemaProof($pdo);
    $ok($proof['complete'] === true, 'complete factual 013 shape was rejected: ' . implode(',', $proof['missing']));
    $ok($proof['missing'] === [], 'complete factual 013 shape reported missing objects');

    $pdo->exec('ALTER TABLE company_roles DROP INDEX uk_company_roles_system');
    $broken = rolesAccessCentral013SchemaProof($pdo);
    $ok($broken['complete'] === false, 'incomplete factual 013 shape was accepted');
    $ok(in_array('index:company_roles.uk_company_roles_system', $broken['missing'], true), 'missing 013 index was not identified');

    echo "ROLES_ACCESS_013_HISTORICAL_SHAPE_MYSQL=PASS\n";
    echo "CENTRAL_013_JOURNAL_INVENTED=NO\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
