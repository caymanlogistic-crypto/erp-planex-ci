<?php
declare(strict_types=1);

/** Preserve every existing environment byte; never replace an existing destination. */
function feoLegacySyncEnvironment(string $original, string $tenant, string $legacy): string
{
    foreach ([$tenant,$legacy] as $db) if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $db)) throw new RuntimeException('Invalid database identifier');
    if ($tenant === $legacy) throw new RuntimeException('Production legacy and tenant schemas must be different');
    $result = $original;
    foreach (['FEO_LEGACY_SYNC_TENANT_DATABASE'=>$tenant,'FEO_LEGACY_SYNC_DATABASE'=>$legacy] as $key=>$value) {
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

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$mode = (string)getenv('FEO_SYNC_CONFIGURE_MODE');
if (!in_array($mode, ['check','activate'], true) || getenv('FEO_SYNC_CONFIRM') !== 'CONFIGURE_FEO_IMPORT_SYNC') throw new RuntimeException('Explicit scoped configuration required');
$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
if (!is_file($root . '/bootstrap/app.php') || !is_file($root . '/.env') || is_link($root . '/.env')) throw new RuntimeException('Invalid application root');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Service/FeoXlsxParser.php';
require_once $root . '/app/Service/FeoLegacyImportSync.php';
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
$updated = feoLegacySyncEnvironment($environment, $tenant, $legacy);
foreach (['FEO_LEGACY_SYNC_TENANT_DATABASE'=>$tenant,'FEO_LEGACY_SYNC_DATABASE'=>$legacy] as $key=>$value) { $_ENV[$key]=$value; $_SERVER[$key]=$value; putenv($key.'='.$value); }
$pdo->beginTransaction();
try {
    $sync = App\Service\FeoLegacyImportSync::forTenant($pdo);
    if (!$sync) throw new RuntimeException('Tenant binding failed');
    $sync->validate(); // Metadata and grants only; never perform probe INSERT/UPDATE/DELETE.
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
    $pdo->rollBack(); // No business data or schema was changed.
    echo json_encode(['mode'=>$mode,'company_id'=>$companyId,'tenant_database'=>$tenant,'legacy_database'=>$legacy,'ready'=>true,'business_data_unchanged'=>true,'schema_unchanged'=>true],JSON_THROW_ON_ERROR) . "\n";
    echo "FEO_IMPORT_SYNC_CONFIGURATION_PASS\n";
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
