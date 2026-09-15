<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/FeoPlacementAuthorService.php';
require_once BASE_PATH . '/app/Service/FeoPlacementService.php';

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

    // Real runtime sessions carry a global account ID AND a legacy UI role.
    // Colliding legacy IDs must never turn that role into an identity namespace.
    $pdo->exec("INSERT INTO user_accounts VALUES(20,'Спугов Владимир Владимирович','spugov.global'),(10,'Елена Орлова','elena.global')");
    $pdo->exec("CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $migration = (string)file_get_contents(BASE_PATH . '/database/migrations-local/123_create_feo_map_placement.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $migration) ?: [])) as $statement) $pdo->exec($statement);
    $pdo->exec("INSERT INTO feo_requests VALUES('100001',1)");
    $placement = new \App\Service\FeoPlacementService();
    foreach (['company_owner', 'senior_logist', 'logist', 'custom_role'] as $role) {
        $creator = FeoPlacementAuthorService::creatorFromSession(['account_id'=>20,'user_id'=>999,'role_code'=>$role]);
        $preview = $placement->previewCreate($pdo, ['100001'], 'addition');
        $result = $placement->createBlock($pdo, ['ids'=>['100001'],'block_type'=>'addition'] + $creator, $preview['state_hash']);
        $state = $placement->pageState($pdo);
        $listed = (new FeoPlacementAuthorService($pdo))->decorate($pdo, 25, $state['blocks'])[0];
        $ok((int)$listed['id'] === $result['block_id'], 'new block must be displayed first');
        $ok((int)$listed['created_by_user_id'] === 20 && $listed['created_by_role'] === null, 'global creator must retain account namespace');
        $ok($listed['created_by_display_name'] === 'Владимир С.' && $listed['created_by_login'] === 'spugov.global', 'runtime role must not resolve global ID as legacy owner or tenant');
    }
    $legacy = FeoPlacementAuthorService::creatorFromSession(['user_id'=>10,'role_code'=>'logist']);
    $legacyRow = (new FeoPlacementAuthorService($pdo))->decorate($pdo, 25, [$legacy])[0];
    $ok($legacyRow['created_by_login'] === 'Spugov', 'legacy tenant namespace must survive account ID collision');
    $legacyOwner = FeoPlacementAuthorService::creatorFromSession(['user_id'=>20,'role_code'=>'company_owner']);
    $ownerRow = (new FeoPlacementAuthorService($pdo))->decorate($pdo, 25, [$legacyOwner])[0];
    $ok($ownerRow['created_by_login'] === 'owner', 'legacy owner namespace must survive account ID collision');
    $rejected = false;
    try { FeoPlacementAuthorService::creatorFromSession([]); } catch (RuntimeException) { $rejected = true; }
    $ok($rejected, 'missing session identity must reject creation');

    echo "FEO_PLACEMENT_AUTHOR_MYSQL=PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
