<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/FeoPlacementAuthorService.php';

$targetLogin = 'Spugov';
$targetRole = 'company_owner';
$v1MarkerPrefix = 'feo_placement_author_backfill_20260912_max_';
$v1Checksum = hash('sha256', 'feo-placement-author-backfill-v1|login=Spugov|scope=existing-blocks-only');
$v2MarkerPrefix = 'feo_placement_author_backfill_20260912_v2_max_';
$v2Checksum = hash('sha256', 'feo-placement-author-backfill-v2|login=Spugov|identity=central_company_user|role=company_owner|scope=existing-v1-boundary');
$v3MarkerPrefix = 'feo_placement_author_backfill_20260915_v3_max_';
$v3Checksum = hash('sha256', 'feo-placement-author-backfill-v3|login=Spugov|identity=central_company_user|role=company_owner|scope=owner-all-existing-20260915');

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
$markerInfo = static function(?array $row, string $prefix, string $checksum): array {
    if ($row === null) return [null, null, null];
    $marker = (string)($row['migration'] ?? '');
    $checksumOk = hash_equals($checksum, (string)($row['checksum'] ?? ''));
    if (!preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/D', $marker, $m)) throw new RuntimeException('Invalid FEO author backfill marker ' . $marker);
    $boundary = (int)$m[1];
    if ($boundary <= 0) throw new RuntimeException('Invalid FEO author backfill boundary');
    return [$marker, $boundary, $checksumOk];
};

$central = $connect($config['database']);
$companies = $central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
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
    $candidates[] = ['company'=>$company,'cfg'=>$cfg,'owner'=>$owners[0]];
}
if (count($candidates) !== 1) throw new RuntimeException('Expected exactly one active company with placement blocks and central company_owner login Spugov; found ' . count($candidates));

$target = $candidates[0];
$company = $target['company'];
$owner = $target['owner'];
$pdo = $connect($target['cfg']);

[$legacyMarker, $legacyBoundary, $legacyChecksumOk] = $markerInfo($singleMarker($pdo, $v1MarkerPrefix), $v1MarkerPrefix, $v1Checksum);
[$v2Marker, $v2Boundary, $v2ChecksumOk] = $markerInfo($singleMarker($pdo, $v2MarkerPrefix), $v2MarkerPrefix, $v2Checksum);
[$marker, $boundary, $markerChecksumOk] = $markerInfo($singleMarker($pdo, $v3MarkerPrefix), $v3MarkerPrefix, $v3Checksum);

$blocks = $pdo->query('SELECT id,name,block_type,status,source_type,source_filename,source_file_key,created_at,updated_at FROM feo_placement_blocks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$blockMaxId = $blocks === [] ? 0 : max(array_map(static fn(array $row): int => (int)$row['id'], $blocks));
$historicalBoundary = $boundary ?? $blockMaxId;
$members = $tableExists($pdo, 'feo_placement_block_requests') ? $pdo->query('SELECT block_id,zayavka_id,position_no FROM feo_placement_block_requests ORDER BY block_id,position_no,id')->fetchAll(PDO::FETCH_ASSOC) : [];
$current = $tableExists($pdo, 'feo_placement_current') ? $pdo->query('SELECT zayavka_id,source_block_id FROM feo_placement_current ORDER BY zayavka_id')->fetchAll(PDO::FETCH_ASSOC) : [];
$semanticHash = hash('sha256', json_encode([$blocks,$members,$current], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$authors = $pdo->query("SELECT COALESCE(CAST(created_by_user_id AS CHAR),'NULL') AS user_id,COALESCE(created_by_role,'NULL') AS role,COUNT(*) AS cnt FROM feo_placement_blocks GROUP BY created_by_user_id,created_by_role ORDER BY user_id,role")->fetchAll(PDO::FETCH_ASSOC);

$mismatch = null;
$uiMismatch = null;
if ($historicalBoundary > 0) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM feo_placement_blocks WHERE id<=? AND (created_by_user_id<>? OR created_by_user_id IS NULL OR COALESCE(created_by_role,'')<>?)");
    $q->execute([$historicalBoundary, (int)$owner['id'], $targetRole]);
    $mismatch = (int)$q->fetchColumn();

    $identityQ = $pdo->prepare('SELECT id,created_by_user_id,created_by_role FROM feo_placement_blocks WHERE id<=? ORDER BY id');
    $identityQ->execute([$historicalBoundary]);
    $decorated = (new \App\Service\FeoPlacementAuthorService($central))->decorate($pdo, (int)$company['id'], $identityQ->fetchAll(PDO::FETCH_ASSOC));
    $uiMismatch = 0;
    foreach ($decorated as $row) {
        if (strtolower((string)($row['created_by_login'] ?? '')) !== strtolower($targetLogin)
            || (string)($row['created_by_display_name'] ?? '') !== 'Владимир С.') {
            $uiMismatch++;
        }
    }
}

$displayName = \App\Service\FeoPlacementAuthorService::shortName((string)$owner['full_name'], (string)$owner['login']);
$snapshot = [
    'active_companies'=>count($companies),
    'target_company_id'=>(int)$company['id'],
    'target_db'=>(string)$company['db_identifier'],
    'spugov_user_id'=>(int)$owner['id'],
    'spugov_identity_type'=>'central_company_user',
    'spugov_full_name'=>(string)$owner['full_name'],
    'spugov_display_name'=>$displayName,
    'spugov_login'=>(string)$owner['login'],
    'spugov_role'=>$targetRole,
    'block_count'=>count($blocks),
    'block_max_id'=>$blockMaxId,
    'historical_boundary'=>$historicalBoundary,
    'block_request_count'=>count($members),
    'current_request_count'=>count($current),
    'placement_semantic_hash'=>$semanticHash,
    'author_distribution'=>$authors,
    'legacy_marker'=>$legacyMarker,
    'legacy_marker_boundary'=>$legacyBoundary,
    'legacy_marker_checksum_ok'=>$legacyChecksumOk,
    'v2_marker'=>$v2Marker,
    'v2_marker_boundary'=>$v2Boundary,
    'v2_marker_checksum_ok'=>$v2ChecksumOk,
    'marker'=>$marker,
    'marker_boundary'=>$boundary,
    'marker_checksum_ok'=>$markerChecksumOk,
    'historical_author_mismatch_count'=>$mismatch,
    'ui_author_mismatch_count'=>$uiMismatch,
];
echo 'FEO_AUTHOR_BACKFILL_SNAPSHOT=' . json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
echo "FEO_AUTHOR_BACKFILL_READ_ONLY_OK\n";
