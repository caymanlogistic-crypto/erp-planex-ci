<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

$targetLogin = 'Spugov';
$targetRole = 'company_owner';
$v1MarkerPrefix = 'feo_placement_author_backfill_20260912_max_';
$v1Checksum = hash('sha256', 'feo-placement-author-backfill-v1|login=Spugov|scope=existing-blocks-only');
$v2MarkerPrefix = 'feo_placement_author_backfill_20260912_v2_max_';
$v2Checksum = hash('sha256', 'feo-placement-author-backfill-v2|login=Spugov|identity=central_company_user|role=company_owner|scope=existing-v1-boundary');

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
$singleMarker = static function(PDO $pdo, string $prefix): ?array {
    $q = $pdo->prepare('SELECT migration,checksum FROM schema_migrations WHERE migration LIKE ? ORDER BY migration');
    $q->execute([$prefix . '%']);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) throw new RuntimeException('Multiple FEO author backfill markers found for ' . $prefix);
    return $rows[0] ?? null;
};
$markerBoundary = static function(array $row, string $prefix, string $checksum): int {
    if (!hash_equals($checksum, (string)($row['checksum'] ?? ''))) throw new RuntimeException('FEO author backfill marker checksum mismatch for ' . $prefix);
    $marker = (string)($row['migration'] ?? '');
    if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/D', $marker, $m)) throw new RuntimeException('Invalid FEO author backfill marker ' . $marker);
    $boundary = (int)$m[1];
    if ($boundary <= 0) throw new RuntimeException('Invalid FEO author backfill boundary');
    return $boundary;
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
    if (!$tableExists($pdo, 'schema_migrations')) throw new RuntimeException('schema_migrations missing for target company ' . $company['id']);

    $ownerQ = $central->prepare("SELECT id,full_name,login,role,status FROM company_users WHERE company_id=? AND LOWER(login)=LOWER(?) AND role='company_owner'");
    $ownerQ->execute([(int)$company['id'], $targetLogin]);
    $owners = $ownerQ->fetchAll(PDO::FETCH_ASSOC);
    if (count($owners) > 1) throw new RuntimeException('Ambiguous central Spugov company_owner identity for company ' . $company['id']);
    if (count($owners) !== 1) continue;
    if ((string)($owners[0]['status'] ?? '') !== 'active') throw new RuntimeException('Central Spugov company_owner is not active for company ' . $company['id']);
    $candidates[] = ['company'=>$company, 'cfg'=>$cfg, 'owner'=>$owners[0], 'block_count'=>$blockCount];
}
if (count($candidates) !== 1) throw new RuntimeException('Expected exactly one active company with placement blocks and central company_owner login Spugov; found ' . count($candidates));

$target = $candidates[0];
$company = $target['company'];
$owner = $target['owner'];
$pdo = $connect($target['cfg']);
$lockName = 'planex_feo_author_backfill_' . substr(hash('sha256', (string)$company['db_identifier']), 0, 20);
$lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Tenant backfill lock failed for company ' . $company['id']);

try {
    $pdo->beginTransaction();

    $ownerQ = $central->prepare("SELECT id,full_name,login,role,status FROM company_users WHERE company_id=? AND LOWER(login)=LOWER(?) AND role='company_owner'");
    $ownerQ->execute([(int)$company['id'], $targetLogin]);
    $owners = $ownerQ->fetchAll(PDO::FETCH_ASSOC);
    if (count($owners) !== 1 || (string)($owners[0]['status'] ?? '') !== 'active') throw new RuntimeException('Central Spugov company_owner identity changed during backfill preflight');
    $owner = $owners[0];

    $v2Marker = $singleMarker($pdo, $v2MarkerPrefix);
    $v1Marker = $singleMarker($pdo, $v1MarkerPrefix);
    $state = 'existing';
    $updated = 0;

    if ($v2Marker !== null) {
        $boundary = $markerBoundary($v2Marker, $v2MarkerPrefix, $v2Checksum);
        $marker = (string)$v2Marker['migration'];
    } else {
        if ($v1Marker !== null) {
            $boundary = $markerBoundary($v1Marker, $v1MarkerPrefix, $v1Checksum);
            $state = 'corrected';
        } else {
            $boundary = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM feo_placement_blocks')->fetchColumn();
            if ($boundary <= 0) throw new RuntimeException('No placement blocks found at backfill commit');
            $state = 'applied';
        }

        $update = $pdo->prepare('UPDATE feo_placement_blocks SET created_by_user_id=?,created_by_role=? WHERE id<=?');
        $update->execute([(int)$owner['id'], $targetRole, $boundary]);
        $updated = $update->rowCount();
        $marker = $v2MarkerPrefix . $boundary;
        $insert = $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');
        $insert->execute([$marker, $v2Checksum]);
    }

    $check = $pdo->prepare("SELECT COUNT(*) FROM feo_placement_blocks WHERE id<=? AND (created_by_user_id<>? OR created_by_user_id IS NULL OR COALESCE(created_by_role,'')<>?)");
    $check->execute([$boundary, (int)$owner['id'], $targetRole]);
    if ((int)$check->fetchColumn() !== 0) throw new RuntimeException('Backfill verification failed inside transaction');
    $pdo->commit();

    printf(
        "FEO_AUTHOR_BACKFILL_OK company_id=%d db=%s state=%s boundary=%d user_id=%d identity=central_company_user role=%s login=%s updated=%d marker=%s checksum=%s\n",
        (int)$company['id'],
        (string)$company['db_identifier'],
        $state,
        $boundary,
        (int)$owner['id'],
        $targetRole,
        (string)$owner['login'],
        $updated,
        $marker,
        $v2Checksum
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}
