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

    // Execute the actual guarded backfill scripts against the disposable DB.
    // Old journals must not cap the new OWNER-authorized scope at their boundary.
    $pdo->exec("ALTER TABLE company_users ADD role VARCHAR(30) NOT NULL DEFAULT 'company_owner', ADD status VARCHAR(20) NOT NULL DEFAULT 'active'");
    $pdo->exec("UPDATE company_users SET full_name='Спугов Владимир Владимирович',login='Spugov' WHERE id=20");
    $pdo->exec("CREATE TABLE companies(id INT PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),db_identifier VARCHAR(100),db_host VARCHAR(255),db_port INT,db_username VARCHAR(255),db_password VARCHAR(1024))");
    $companyInsert = $pdo->prepare("INSERT INTO companies VALUES(25,'Test','active',?,NULL,NULL,NULL,NULL)");
    $companyInsert->execute([$dbName]);
    $pdo->exec('CREATE TABLE schema_migrations(migration VARCHAR(255) PRIMARY KEY,checksum VARCHAR(64) NOT NULL)');
    $journal = $pdo->prepare('INSERT INTO schema_migrations VALUES(?,?)');
    $journal->execute(['feo_placement_author_backfill_20260912_max_2',hash('sha256','feo-placement-author-backfill-v1|login=Spugov|scope=existing-blocks-only')]);
    $journal->execute(['feo_placement_author_backfill_20260912_v2_max_2',hash('sha256','feo-placement-author-backfill-v2|login=Spugov|identity=central_company_user|role=company_owner|scope=existing-v1-boundary')]);
    $pdo->exec("UPDATE feo_placement_blocks SET updated_at='2020-01-01 00:00:00'");
    $fixture = BASE_PATH . '/storage/tmp/feo-author-test-' . bin2hex(random_bytes(8));
    $fixtureFiles = [];
    $oldRoot = getenv('PLANEX_ROOT');
    $oldBoundary = getenv('FEO_AUTHOR_EXPECTED_BOUNDARY');
    try {
        foreach (['bootstrap','app/Support','app/Service'] as $dir) mkdir($fixture . '/' . $dir, 0700, true);
        foreach (['app/Support/crypto_helper.php','app/Support/company_database.php','app/Service/FeoPlacementAuthorService.php'] as $file) {
            copy(BASE_PATH . '/' . $file, $fixture . '/' . $file);
            $fixtureFiles[] = $fixture . '/' . $file;
        }
        file_put_contents($fixture . '/.env', '');
        $fixtureFiles[] = $fixture . '/.env';
        $bootstrap = '<?php require ' . var_export(BASE_PATH . '/bootstrap/app.php', true) . ';' . <<<'PHP'
return ['database'=>[
    'host'=>getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>getenv('PLANEX_SCHEMA_DB_PORT'),
    'database'=>getenv('PLANEX_TEST_FIXED_DB'),'username'=>getenv('PLANEX_SCHEMA_DB_USER'),
    'password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD'),
]];
PHP;
        file_put_contents($fixture . '/bootstrap/app.php', $bootstrap);
        $fixtureFiles[] = $fixture . '/bootstrap/app.php';
        putenv('PLANEX_ROOT=' . $fixture);
        $runScript = static function (string $script, bool $success = true) use ($ok): string {
            $lines = [];
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/scripts/' . $script) . ' 2>&1', $lines, $code);
            $ok(($code === 0) === $success, $script . ' unexpected exit ' . $code . ': ' . implode("\n", $lines));
            return implode("\n", $lines);
        };
        $snapshot = static function (string $output): array {
            if (!preg_match('/^FEO_AUTHOR_BACKFILL_SNAPSHOT=(.+)$/m', $output, $match)) throw new RuntimeException('Snapshot missing');
            return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        };
        $before = $snapshot($runScript('feo_placement_author_verify.php'));
        $ok($before['historical_boundary'] === 4, 'new scope must include all existing blocks beyond v2');
        putenv('FEO_AUTHOR_EXPECTED_BOUNDARY');
        $runScript('feo_placement_author_backfill.php', false);
        $ok((int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === 2, 'missing boundary must not write journal');
        putenv('FEO_AUTHOR_EXPECTED_BOUNDARY=4');
        $runScript('feo_placement_author_backfill.php');
        $after = $snapshot($runScript('feo_placement_author_verify.php'));
        $ok($after['placement_semantic_hash'] === $before['placement_semantic_hash'], 'backfill changed block business fields, timestamps, membership or current state');
        $ok($after['historical_author_mismatch_count'] === 0 && $after['ui_author_mismatch_count'] === 0, 'all snapshot blocks must render as Spugov');
        $ok($after['marker_boundary'] === 4 && $after['v2_marker_boundary'] === 2 && $after['legacy_marker_boundary'] === 2, 'new journal must preserve v1/v2 boundaries');
        $pdo->exec("INSERT INTO feo_placement_blocks(id,block_type,created_by_user_id,created_by_role) VALUES(5,'addition',30,NULL)");
        $retry = $runScript('feo_placement_author_backfill.php');
        $ok(str_contains($retry, 'state=existing') && str_contains($retry, 'updated=0'), 'v3 repeat must be idempotent');
        $ok((int)$pdo->query('SELECT created_by_user_id FROM feo_placement_blocks WHERE id=5')->fetchColumn() === 30, 'future blocks must keep their real creator');
        $pdo->exec("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE migration='feo_placement_author_backfill_20260915_v3_max_4'");
        $runScript('feo_placement_author_backfill.php', false);
        $ok((int)$pdo->query('SELECT created_by_user_id FROM feo_placement_blocks WHERE id=5')->fetchColumn() === 30, 'invalid journal must reject without changing authors');
    } finally {
        putenv($oldRoot === false ? 'PLANEX_ROOT' : 'PLANEX_ROOT=' . $oldRoot);
        putenv($oldBoundary === false ? 'FEO_AUTHOR_EXPECTED_BOUNDARY' : 'FEO_AUTHOR_EXPECTED_BOUNDARY=' . $oldBoundary);
        foreach ($fixtureFiles as $file) if (is_file($file)) unlink($file);
        foreach (['app/Support','app/Service','app','bootstrap',''] as $dir) if (is_dir($fixture . '/' . $dir)) rmdir($fixture . '/' . $dir);
    }

    echo "FEO_PLACEMENT_AUTHOR_MYSQL=PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
