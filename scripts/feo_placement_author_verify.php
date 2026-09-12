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
$candidates = [];
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = $connect($cfg);
    if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant DB for company ' . $company['id']);
    if (!$tableExists($pdo, 'feo_placement_blocks') || !$tableExists($pdo, 'users')) continue;
    $blockCount = (int)$pdo->query('SELECT COUNT(*) FROM feo_placement_blocks')->fetchColumn();
    if ($blockCount === 0) continue;
    $q = $pdo->prepare('SELECT id,full_name,login,role_code FROM users WHERE LOWER(login)=LOWER(?)');
    $q->execute([$targetLogin]);
    $users = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) > 1) throw new RuntimeException('Ambiguous Spugov identity for company ' . $company['id']);
    if (count($users) !== 1) continue;
    if (!$tableExists($pdo, 'schema_migrations')) throw new RuntimeException('schema_migrations missing for target company ' . $company['id']);
    $candidates[] = ['company'=>$company,'cfg'=>$cfg,'user'=>$users[0]];
}
if (count($candidates) !== 1) throw new RuntimeException('Expected exactly one active company with placement blocks and login Spugov; found ' . count($candidates));

$target = $candidates[0];
$company = $target['company'];
$userRow = $target['user'];
$pdo = $connect($target['cfg']);
$markersQ = $pdo->prepare('SELECT migration,checksum FROM schema_migrations WHERE migration LIKE ? ORDER BY migration');
$markersQ->execute([$markerPrefix . '%']);
$markers = $markersQ->fetchAll(PDO::FETCH_ASSOC);
if (count($markers) > 1) throw new RuntimeException('Multiple FEO author backfill markers found');
$marker = null;
$boundary = null;
$markerChecksumOk = null;
if ($markers !== []) {
    $marker = (string)$markers[0]['migration'];
    $markerChecksumOk = hash_equals($specChecksum, (string)$markers[0]['checksum']);
    if (!preg_match('/^' . preg_quote($markerPrefix, '/') . '(\d+)$/D', $marker, $m)) throw new RuntimeException('Invalid FEO author backfill marker');
    $boundary = (int)$m[1];
}

$blocks = $pdo->query('SELECT id,name,block_type,status,source_type,source_filename,source_file_key,created_at FROM feo_placement_blocks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$members = $tableExists($pdo, 'feo_placement_block_requests') ? $pdo->query('SELECT block_id,zayavka_id,position_no FROM feo_placement_block_requests ORDER BY block_id,position_no,id')->fetchAll(PDO::FETCH_ASSOC) : [];
$current = $tableExists($pdo, 'feo_placement_current') ? $pdo->query('SELECT zayavka_id,source_block_id FROM feo_placement_current ORDER BY zayavka_id')->fetchAll(PDO::FETCH_ASSOC) : [];
$semanticHash = hash('sha256', json_encode([$blocks,$members,$current], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$authors = $pdo->query("SELECT COALESCE(CAST(created_by_user_id AS CHAR),'NULL') AS user_id,COALESCE(created_by_role,'NULL') AS role,COUNT(*) AS cnt FROM feo_placement_blocks GROUP BY created_by_user_id,created_by_role ORDER BY user_id,role")->fetchAll(PDO::FETCH_ASSOC);
$mismatch = null;
if ($boundary !== null) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM feo_placement_blocks WHERE id<=? AND (created_by_user_id<>? OR created_by_user_id IS NULL OR COALESCE(created_by_role,'')<>?)");
    $q->execute([$boundary, (int)$userRow['id'], (string)$userRow['role_code']]);
    $mismatch = (int)$q->fetchColumn();
}

$snapshot = [
    'active_companies'=>count($companies),
    'target_company_id'=>(int)$company['id'],
    'target_db'=>(string)$company['db_identifier'],
    'spugov_user_id'=>(int)$userRow['id'],
    'spugov_full_name'=>(string)$userRow['full_name'],
    'spugov_login'=>(string)$userRow['login'],
    'spugov_role'=>(string)$userRow['role_code'],
    'block_count'=>count($blocks),
    'block_max_id'=>$blocks === [] ? 0 : max(array_map(static fn(array $row): int => (int)$row['id'], $blocks)),
    'block_request_count'=>count($members),
    'current_request_count'=>count($current),
    'placement_semantic_hash'=>$semanticHash,
    'author_distribution'=>$authors,
    'marker'=>$marker,
    'marker_boundary'=>$boundary,
    'marker_checksum_ok'=>$markerChecksumOk,
    'historical_author_mismatch_count'=>$mismatch,
];
echo 'FEO_AUTHOR_BACKFILL_SNAPSHOT=' . json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
echo "FEO_AUTHOR_BACKFILL_READ_ONLY_OK\n";
