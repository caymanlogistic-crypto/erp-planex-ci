<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

$targetLogin = 'Spugov';
$markerPrefix = 'feo_placement_author_backfill_20260912_max_';
$specChecksum = hash('sha256', 'feo-placement-author-backfill-v1|login=Spugov|scope=existing-blocks-only');

$connect = static function(array $cfg): PDO {
    return new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
    );
};
$tableExists = static function(PDO $pdo, string $table): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return (int)$q->fetchColumn() === 1;
};

$central = $connect($config['database']);
$companies = $central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) throw new RuntimeException('No active tenant databases found');

$candidates = [];
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = $connect($cfg);
    if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant DB for company ' . $company['id']);
    if (!$tableExists($pdo, 'feo_placement_blocks')) continue;
    $blockCount = (int)$pdo->query('SELECT COUNT(*) FROM feo_placement_blocks')->fetchColumn();
    if ($blockCount === 0) continue;
    if (!$tableExists($pdo, 'users')) continue;
    $q = $pdo->prepare('SELECT id,full_name,login,role_code FROM users WHERE LOWER(login)=LOWER(?)');
    $q->execute([$targetLogin]);
    $users = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) > 1) throw new RuntimeException('Ambiguous Spugov identity for company ' . $company['id']);
    if (count($users) !== 1) continue;
    if (!$tableExists($pdo, 'schema_migrations')) throw new RuntimeException('schema_migrations missing for target company ' . $company['id']);
    $candidates[] = ['company'=>$company, 'cfg'=>$cfg, 'user'=>$users[0], 'block_count'=>$blockCount];
}
if (count($candidates) !== 1) throw new RuntimeException('Expected exactly one active company with placement blocks and login Spugov; found ' . count($candidates));

$target = $candidates[0];
$company = $target['company'];
$userRow = $target['user'];
$pdo = $connect($target['cfg']);
$lockName = 'planex_feo_author_backfill_' . substr(hash('sha256', (string)$company['db_identifier']), 0, 20);
$lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Tenant backfill lock failed for company ' . $company['id']);

try {
    $pdo->beginTransaction();
    $refresh = $pdo->prepare('SELECT id,full_name,login,role_code FROM users WHERE LOWER(login)=LOWER(?)');
    $refresh->execute([$targetLogin]);
    $users = $refresh->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) !== 1) throw new RuntimeException('Spugov identity changed during backfill preflight');
    $userRow = $users[0];

    $markerQ = $pdo->prepare('SELECT migration,checksum FROM schema_migrations WHERE migration LIKE ? ORDER BY migration');
    $markerQ->execute([$markerPrefix . '%']);
    $markers = $markerQ->fetchAll(PDO::FETCH_ASSOC);
    if (count($markers) > 1) throw new RuntimeException('Multiple FEO author backfill markers found');

    $state = 'existing';
    $updated = 0;
    if ($markers !== []) {
        $marker = (string)$markers[0]['migration'];
        if (!hash_equals($specChecksum, (string)$markers[0]['checksum'])) throw new RuntimeException('FEO author backfill marker checksum mismatch');
        if (!preg_match('/^' . preg_quote($markerPrefix, '/') . '(\d+)$/D', $marker, $m)) throw new RuntimeException('Invalid FEO author backfill marker');
        $boundary = (int)$m[1];
    } else {
        $boundary = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM feo_placement_blocks')->fetchColumn();
        if ($boundary <= 0) throw new RuntimeException('No placement blocks found at backfill commit');
        $update = $pdo->prepare('UPDATE feo_placement_blocks SET created_by_user_id=?,created_by_role=? WHERE id<=?');
        $update->execute([(int)$userRow['id'], (string)$userRow['role_code'], $boundary]);
        $updated = $update->rowCount();
        $marker = $markerPrefix . $boundary;
        $insert = $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');
        $insert->execute([$marker, $specChecksum]);
        $state = 'applied';
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM feo_placement_blocks WHERE id<=? AND (created_by_user_id<>? OR created_by_user_id IS NULL OR COALESCE(created_by_role,'')<>?)");
    $check->execute([$boundary, (int)$userRow['id'], (string)$userRow['role_code']]);
    if ((int)$check->fetchColumn() !== 0) throw new RuntimeException('Backfill verification failed inside transaction');
    $pdo->commit();

    printf(
        "FEO_AUTHOR_BACKFILL_OK company_id=%d db=%s state=%s boundary=%d user_id=%d role=%s login=%s updated=%d marker=%s checksum=%s\n",
        (int)$company['id'],
        (string)$company['db_identifier'],
        $state,
        $boundary,
        (int)$userRow['id'],
        (string)$userRow['role_code'],
        (string)$userRow['login'],
        $updated,
        $marker,
        $specChecksum
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}
