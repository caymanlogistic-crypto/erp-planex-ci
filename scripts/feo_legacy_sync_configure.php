<?php
declare(strict_types=1);

/** Preserve every existing environment byte; never replace an existing destination. */
function feoLegacySyncEnvironment(string $original, string $tenant, string $legacy, string $connection = ''): string
{
    foreach ([$tenant,$legacy] as $db) if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $db)) throw new RuntimeException('Invalid database identifier');
    if ($tenant === $legacy) throw new RuntimeException('Production legacy and tenant schemas must be different');
    $result = $original;
    $entries=['FEO_LEGACY_SYNC_TENANT_DATABASE'=>$tenant,'FEO_LEGACY_SYNC_DATABASE'=>$legacy];
    if ($connection !== '') {
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/D',$connection)) throw new RuntimeException('Invalid protected connection encoding');
        $entries['FEO_LEGACY_SYNC_CONNECTION_B64']=$connection;
    }
    foreach ($entries as $key=>$value) {
        preg_match_all('/^\s*' . preg_quote($key, '/') . '\s*=([^\r\n]*)/m', $original, $matches);
        if (count($matches[1]) > 1) throw new RuntimeException('Duplicate FEO sync environment entry');
        if ($matches[1] !== []) {
            if (trim(trim($matches[1][0]), "\"'") !== $value) throw new RuntimeException('Existing FEO sync destination differs; refusing to overwrite');
            continue;
        }
        $result .= (str_ends_with($result, "\n") ? '' : "\n") . $key . '=' . $value . "\n";
    }
    return $result;
}

/** Enable only future transfer writes; preserve all other settings and reject duplicate flags. */
function feoTransferSyncEnvironment(string $original): string
{
    preg_match_all('/^[ \t]*FEO_LEGACY_TRANSFER_SYNC_ENABLED[ \t]*=([^\r\n]*)/m',$original,$matches,PREG_OFFSET_CAPTURE);
    if (count($matches[0])>1) throw new RuntimeException('Duplicate FEO transfer sync flag');
    if ($matches[0]===[]) return $original.(str_ends_with($original,"\n")?'':"\n")."FEO_LEGACY_TRANSFER_SYNC_ENABLED=1\n";
    $value=trim(trim($matches[1][0][0]),"\"'");
    if (!in_array($value,['0','1'],true)) throw new RuntimeException('Invalid FEO transfer sync flag');
    if ($value==='1') return $original;
    $offset=$matches[0][0][1];
    return substr_replace($original,'FEO_LEGACY_TRANSFER_SYNC_ENABLED=1',$offset,strlen($matches[0][0][0]));
}

/** Shared by the CLI entry point and its encrypted-company-credential regression. */
function feoLegacySyncLoadDependencies(string $root): void
{
    require_once $root . '/app/Support/crypto_helper.php';
    require_once $root . '/app/Support/company_database.php';
    require_once $root . '/app/Core/Database.php';
    require_once $root . '/app/Service/FeoXlsxParser.php';
    require_once $root . '/app/Service/FeoLegacyImportSync.php';
    require_once $root . '/app/Service/FeoImportTransaction.php';
    require_once $root . '/app/Service/FeoLegacyPlacementSync.php';
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$mode = (string)getenv('FEO_SYNC_CONFIGURE_MODE');
if (!in_array($mode, ['check','activate'], true) || getenv('FEO_SYNC_CONFIRM') !== 'CONFIGURE_FEO_IMPORT_SYNC') throw new RuntimeException('Explicit scoped configuration required');
$enableTransfer=(string)getenv('FEO_SYNC_TRANSFER_MIRROR')==='1';
if ($enableTransfer && getenv('FEO_SYNC_TRANSFER_CONFIRM')!=='ENABLE_FEO_TRANSFER_MIRROR') throw new RuntimeException('Explicit transfer mirror activation required');
$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
if (!is_file($root . '/bootstrap/app.php') || !is_file($root . '/.env') || is_link($root . '/.env')) throw new RuntimeException('Invalid application root');
$config = require $root . '/bootstrap/app.php';
feoLegacySyncLoadDependencies($root);
$central = (new App\Core\Database($config['database']))->connection();
$companyId = (int)getenv('FEO_SYNC_COMPANY_ID');
if ($companyId <= 0) {
    if ($mode !== 'check') throw new RuntimeException('Exact company ID required for activation');
    $companies = $central->query("SELECT id,name,db_identifier FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['mode'=>'inventory','companies'=>$companies], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR) . "\n";
    exit;
}
$q = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active'"); $q->execute([$companyId]);
$company = $q->fetch(PDO::FETCH_ASSOC);
if (!$company) throw new RuntimeException('Active company not found');
$pdo = (new App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
$tenant = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($tenant !== $company['db_identifier']) throw new RuntimeException('Tenant identity mismatch');
$legacy = trim((string)getenv('FEO_SYNC_LEGACY_DATABASE'));
$environment = file_get_contents($root . '/.env');
if ($environment === false) throw new RuntimeException('Cannot read application environment');
if ($enableTransfer) {
    foreach (['FEO_LEGACY_SYNC_TENANT_DATABASE'=>$tenant,'FEO_LEGACY_SYNC_DATABASE'=>$legacy] as $key=>$expected) {
        preg_match_all('/^[ \t]*'.preg_quote($key,'/').'[ \t]*=([^\r\n]*)/m',$environment,$found);
        if (count($found[1])!==1 || trim(trim($found[1][0]),"\"'")!==$expected) throw new RuntimeException('Transfer mirror requires the existing exact tenant and legacy binding');
    }
}
$connectionMode=(string)(getenv('FEO_SYNC_CONNECTION_MODE') ?: 'shared');
if (!in_array($connectionMode,['shared','separate'],true)) throw new RuntimeException('Invalid connection mode');
$connection='';
if ($connectionMode==='separate') {
    if ($enableTransfer && !str_contains($environment,'FEO_LEGACY_SYNC_CONNECTION_B64=')) throw new RuntimeException('Transfer mirror requires existing protected legacy connection');
    // The GitHub secret arrives only via SSH stdin, never a shell argument or uploaded public file.
    $raw=stream_get_contents(STDIN,65537);
    if ($raw===false || strlen($raw)>65536) throw new RuntimeException('Protected connection missing or too large');
    $credentials=json_decode($raw,true,16);
    if (!is_array($credentials) || !is_string($credentials['host']??null) || !preg_match('/^[a-zA-Z0-9_.-]+$/D',$credentials['host'])
        || !is_string($credentials['username']??null) || $credentials['username']==='' || !is_string($credentials['password']??null)) throw new RuntimeException('Protected connection invalid');
    $connection=base64_encode(json_encode($credentials,JSON_THROW_ON_ERROR));
    $_ENV['FEO_LEGACY_SYNC_CONNECTION_B64']=$connection; $_SERVER['FEO_LEGACY_SYNC_CONNECTION_B64']=$connection; putenv('FEO_LEGACY_SYNC_CONNECTION_B64='.$connection);
}
$updated = feoLegacySyncEnvironment($environment, $tenant, $legacy, $connection);
if ($enableTransfer) $updated=feoTransferSyncEnvironment($updated);
foreach (['FEO_LEGACY_SYNC_TENANT_DATABASE'=>$tenant,'FEO_LEGACY_SYNC_DATABASE'=>$legacy] as $key=>$value) { $_ENV[$key]=$value; $_SERVER[$key]=$value; putenv($key.'='.$value); }
$transaction=null;
try {
    $transaction=App\Service\FeoImportTransaction::begin($pdo);
    $sync = $transaction->sync();
    if (!$sync) throw new RuntimeException('Tenant binding failed');
    $sync->validate(); // Metadata and grants only; never perform probe INSERT/UPDATE/DELETE.
    (new App\Service\FeoLegacyPlacementSync($sync->connection(), $sync->database(), $pdo))->validate();
    $transaction->commit(); // Read-only readiness also proves XA PREPARE/COMMIT and durable journal capability.
    unset($transaction);
    if ($mode === 'activate' && $updated !== $environment) {
        $handle = fopen($root . '/.env', 'rb');
        if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('Environment lock unavailable');
        $temporary = null;
        try {
            if (stream_get_contents($handle) !== $environment || file_get_contents($root.'/.env') !== $environment) throw new RuntimeException('Environment changed during configuration');
            $backup = $root . '/.env.feo-sync-' . bin2hex(random_bytes(8));
            $b = fopen($backup,'xb');
            if (!$b) throw new RuntimeException('Cannot create configuration backup');
            chmod($backup,0600);
            $written = fwrite($b,$environment); fflush($b); fclose($b);
            if ($written !== strlen($environment)) throw new RuntimeException('Incomplete environment backup');
            $temporary = $root . '/.env.feo-sync-new-' . bin2hex(random_bytes(8));
            $f = fopen($temporary,'xb');
            if (!$f) throw new RuntimeException('Cannot create environment candidate');
            chmod($temporary,0600);
            $written = fwrite($f,$updated); fflush($f); fclose($f);
            if ($written !== strlen($updated) || !rename($temporary,$root.'/.env')) throw new RuntimeException('Cannot install environment candidate');
        } finally { if ($temporary && is_file($temporary)) unlink($temporary); flock($handle,LOCK_UN); fclose($handle); }
    }
    $pendingTransfers=$enableTransfer ? (int)$pdo->query("SELECT COUNT(*) FROM feo_transfer_outbox WHERE status='awaiting_connector'")->fetchColumn() : null;
    $effectiveEnvironment=$mode==='activate'?$updated:$environment;
    $transferEnabled=preg_match('/^[ \t]*FEO_LEGACY_TRANSFER_SYNC_ENABLED[ \t]*=1[ \t]*$/m',$effectiveEnvironment)===1;
    echo json_encode(['mode'=>$mode,'connection_mode'=>$connectionMode,'company_id'=>$companyId,'tenant_database'=>$tenant,'legacy_database'=>$legacy,
        'transfer_mirror_requested'=>$enableTransfer,'transfer_mirror_enabled'=>$transferEnabled,
        'historical_transfers_not_replayed'=>$pendingTransfers,'ready'=>true,'business_data_unchanged'=>true,'schema_unchanged'=>true],JSON_THROW_ON_ERROR) . "\n";
    echo "FEO_IMPORT_SYNC_CONFIGURATION_PASS\n";
} catch (Throwable $e) { ($transaction??null)?->rollback(); throw $e; }
