<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/FeoPlacementAuthorService.php';

use App\Service\FeoPlacementAuthorService;

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
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(255) NOT NULL,login VARCHAR(100) NOT NULL,role_code VARCHAR(50) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE company_users(id INT UNSIGNED PRIMARY KEY,company_id INT UNSIGNED NOT NULL,full_name VARCHAR(255) NOT NULL,login VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE user_accounts(id BIGINT UNSIGNED PRIMARY KEY,full_name VARCHAR(255) NOT NULL,login VARCHAR(100) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT INTO users VALUES(10,'Владимир Владимирович Спугов','Spugov','logist'),(11,'Мария Петрова','maria','senior_logist')");
    $pdo->exec("INSERT INTO company_users VALUES(20,25,'Анна Иванова','owner')");
    $pdo->exec("INSERT INTO user_accounts VALUES(30,'Пётр Сидоров','global.user')");

    $blocks = [
        ['id'=>1,'created_by_user_id'=>10,'created_by_role'=>'logist'],
        ['id'=>2,'created_by_user_id'=>20,'created_by_role'=>'company_owner'],
        ['id'=>3,'created_by_user_id'=>30,'created_by_role'=>null],
        ['id'=>4,'created_by_user_id'=>999,'created_by_role'=>'logist'],
    ];
    $rows = (new FeoPlacementAuthorService($pdo))->decorate($pdo, 25, $blocks);
    $ok(($rows[0]['created_by_display_name'] ?? '') === 'Владимир С.', 'tenant creator short name mismatch');
    $ok(($rows[0]['created_by_login'] ?? '') === 'Spugov', 'tenant creator login mismatch');
    $ok(FeoPlacementAuthorService::shortName('Спугов Владимир Вдамимирович', 'spugov') === 'Владимир С.', 'surname-first creator short name mismatch');
    $ok(($rows[1]['created_by_display_name'] ?? '') === 'Анна И.', 'company owner creator mismatch');
    $ok(($rows[2]['created_by_display_name'] ?? '') === 'Пётр С.', 'global account creator mismatch');
    $ok(($rows[3]['created_by_display_name'] ?? '') === '—', 'unknown creator must fail closed to dash');

    echo "FEO_PLACEMENT_AUTHOR_MYSQL=PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
