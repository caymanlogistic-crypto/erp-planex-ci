<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Support/company_database.php';
require_once __DIR__ . '/../app/Service/CompanyDeletionBackupVerifier.php';
require_once __DIR__ . '/../app/Service/CompanyDeletionJournal.php';
require_once __DIR__ . '/../app/Service/CompanyDeletionService.php';

use App\Service\CompanyDeletionBackupVerifier as Verifier;
use App\Service\CompanyDeletionService;

$centralDb = trim((string)getenv('PLANEX_DELETE_TEST_CENTRAL_DB'));
$tenantDb = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
if (getenv('APP_ENV') !== 'test'
    || $centralDb === '' || $tenantDb === '' || $centralDb === $tenantDb
    || getenv('PLANEX_DELETE_TEST_CENTRAL_LEASE') !== $centralDb
    || getenv('PLANEX_TEST_DB_LEASE') !== $tenantDb
    || !in_array($tenantDb, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) {
    throw new RuntimeException('Two independent trusted disposable schemas are required.');
}
$host = (string)getenv('PLANEX_SCHEMA_DB_HOST');
$port = (string)getenv('PLANEX_SCHEMA_DB_PORT');
$central = new PDO("mysql:host=$host;port=$port;dbname=$centralDb;charset=utf8mb4",
    getenv('PLANEX_DELETE_TEST_CENTRAL_USER'), getenv('PLANEX_DELETE_TEST_CENTRAL_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$tenant = new PDO("mysql:host=$host;port=$port;dbname=$tenantDb;charset=utf8mb4",
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$central->exec('CREATE TABLE companies (id INT UNSIGNED PRIMARY KEY, name VARCHAR(255), db_identifier VARCHAR(64)) ENGINE=InnoDB');
$central->exec('CREATE TABLE company_features (company_id INT UNSIGNED) ENGINE=InnoDB');
$central->exec('CREATE TABLE company_users (company_id INT UNSIGNED) ENGINE=InnoDB');
$central->exec((string)file_get_contents(__DIR__ . '/../database/migrations/009_create_deleted_entities.sql'));
$central->exec((string)file_get_contents(__DIR__ . '/../database/migrations/021_company_deletion_runs.sql'));
ensureCompanyDbPoolUsageTable($central);
$tenant->exec('CREATE TABLE synthetic_company_data (id INT PRIMARY KEY) ENGINE=InnoDB');
$tenant->exec('INSERT INTO synthetic_company_data VALUES (1)');
$assert = static function (bool $yes, string $message): void { if (!$yes) throw new RuntimeException($message); };
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'planex_delete_e2e_' . bin2hex(random_bytes(8));
mkdir($root . '/manifests', 0700, true);
mkdir($root . '/archives', 0700, true);
$key = str_repeat('s', 48);
putenv('PLANEX_COMPANY_DELETION_BACKUP_ROOT=' . $root);
putenv('PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY=' . $key);
$companyId = random_int(810000000, 890000000);
$storageDir = storage_path('companies/' . $companyId);
$assert(!file_exists($storageDir), 'Refusing to reuse a storage directory');
mkdir($storageDir, 0700, true);
file_put_contents($storageDir . '/synthetic.txt', 'disposable');
$makeManifest = static function (int $id) use ($root, $tenantDb, $key): void {
    $dbFile = $root . '/archives/' . $id . '-tenant.sql.gz';
    $storageFile = $root . '/archives/' . $id . '-storage.tar.gz';
    file_put_contents($dbFile, 'synthetic test-only database backup');
    file_put_contents($storageFile, 'synthetic test-only storage backup');
    $restoredAt = gmdate('Y-m-d\TH:i:s\Z', time() - 30);
    $receiptFile = $root . '/archives/' . $id . '-restore-receipt.json';
    file_put_contents($receiptFile, json_encode([
        'status' => 'PASS', 'company_id' => $id, 'db_identifier' => $tenantDb,
        'database_sha256' => hash_file('sha256', $dbFile),
        'storage_sha256' => hash_file('sha256', $storageFile),
        'verified_at_utc' => $restoredAt,
    ], JSON_THROW_ON_ERROR));
    $manifest = [
        'id' => bin2hex(random_bytes(16)), 'company_id' => $id, 'db_identifier' => $tenantDb,
        'created_at_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
        'restore_verified_at_utc' => $restoredAt,
        'expires_at_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
        'database_archive' => ['path' => 'archives/' . basename($dbFile), 'sha256' => hash_file('sha256', $dbFile)],
        'storage_archive' => ['path' => 'archives/' . basename($storageFile), 'sha256' => hash_file('sha256', $storageFile)],
        'restore_receipt' => ['path' => 'archives/' . basename($receiptFile), 'sha256' => hash_file('sha256', $receiptFile)],
    ];
    $manifest['signature'] = Verifier::sign($manifest, $key);
    file_put_contents($root . '/manifests/company-' . $id . '.json', json_encode($manifest, JSON_THROW_ON_ERROR));
};
$company = [
    'id' => $companyId, 'name' => 'Disposable deletion fixture', 'db_identifier' => $tenantDb,
    'db_host' => $host, 'db_port' => $port,
    'db_username' => (string)getenv('PLANEX_SCHEMA_DB_USER'),
    'db_password' => (string)getenv('PLANEX_SCHEMA_DB_PASSWORD'),
];
$config = ['database' => [
    'host' => $host, 'port' => $port, 'database' => $centralDb,
    'username' => (string)getenv('PLANEX_DELETE_TEST_CENTRAL_USER'),
    'password' => (string)getenv('PLANEX_DELETE_TEST_CENTRAL_PASSWORD'),
]];
try {
    $makeManifest($companyId);
    $central->prepare('INSERT INTO companies VALUES (?,?,?)')->execute([$companyId, $company['name'], $tenantDb]);
    $central->prepare('INSERT INTO company_features VALUES (?)')->execute([$companyId]);
    $central->prepare('INSERT INTO company_users VALUES (?)')->execute([$companyId]);
    $central->prepare("INSERT INTO deleted_entities (company_id,company_name,entity_type,entity_id,source_table,status) VALUES (?,?, 'client',1,'clients','archived')")
        ->execute([$companyId, $company['name']]);
    $central->prepare('INSERT INTO company_db_pool_usage (db_identifier, company_id) VALUES (?,?)')->execute([$tenantDb, $companyId]);
    $result = CompanyDeletionService::delete($central, $company, $config, 71);
    $assert($result['success'] === true, 'Synthetic deletion failed: ' . json_encode($result['errors']));
    $assert((int)$central->query("SELECT COUNT(*) FROM companies WHERE id=$companyId")->fetchColumn() === 0, 'Company row remained');
    $assert((int)$central->query("SELECT COUNT(*) FROM deleted_entities WHERE company_id=$companyId")->fetchColumn() === 1, 'OWNER audit record was lost');
    $assert((int)$tenant->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=" . $tenant->quote($tenantDb) . " AND TABLE_NAME='synthetic_company_data'")->fetchColumn() === 0, 'Tenant data remained');
    $assert(!is_dir($storageDir), 'Storage remained');
    $row = $central->query("SELECT status,stage,actor_user_id FROM company_deletion_runs WHERE company_id=$companyId")->fetch(PDO::FETCH_ASSOC);
    $assert($row['status'] === 'completed' && $row['stage'] === 'completed' && (int)$row['actor_user_id'] === 71, 'Journal completion/actor missing');
    $again = CompanyDeletionService::delete($central, $company, $config, 71);
    $assert($again['success'] === false && $again['steps'][0]['step'] === 'backup_and_journal', 'One-time backup was reused');

    $failedId = $companyId + 1;
    $makeManifest($failedId);
    $failed = array_replace($company, ['id' => $failedId, 'db_username' => 'invalid_fixture_user']);
    $central->prepare('INSERT INTO companies VALUES (?,?,?)')->execute([$failedId, $failed['name'], $tenantDb]);
    $failure = CompanyDeletionService::delete($central, $failed, $config, 71);
    $assert($failure['success'] === false, 'Tenant connection failure was ignored');
    $failedJournal = $central->query("SELECT status,stage,error_code FROM company_deletion_runs WHERE company_id=$failedId")->fetch(PDO::FETCH_ASSOC);
    $assert($failedJournal['status'] === 'failed' && $failedJournal['stage'] === 'backup_verified'
        && $failedJournal['error_code'] === 'TENANT_DELETE_FAILED', 'Failure stage not durable');
    $retry = CompanyDeletionService::delete($central, $failed, $config, 71);
    $assert($retry['success'] === false && $retry['steps'][0]['step'] === 'backup_and_journal', 'Failed run was restarted without recovery');

    $missingJournalId = $companyId + 2;
    $makeManifest($missingJournalId);
    $central->prepare('INSERT INTO companies VALUES (?,?,?)')->execute([$missingJournalId, $company['name'], $tenantDb]);
    $tenant->exec('CREATE TABLE no_journal_must_not_delete (id INT PRIMARY KEY) ENGINE=InnoDB');
    $central->exec('DROP TABLE company_deletion_runs');
    $blocked = CompanyDeletionService::delete($central, array_replace($company, ['id' => $missingJournalId]), $config, 71);
    $assert($blocked['success'] === false && $blocked['steps'][0]['step'] === 'backup_and_journal', 'Missing central migration did not fail closed');
    $assert((int)$tenant->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=" . $tenant->quote($tenantDb) . " AND TABLE_NAME='no_journal_must_not_delete'")->fetchColumn() === 1, 'Tenant changed without journal');
    $assert((int)$central->query("SELECT COUNT(*) FROM companies WHERE id=$missingJournalId")->fetchColumn() === 1, 'Company changed without journal');
    echo "COMPANY_DELETION_ISOLATED_MYSQL=PASS\n";
} finally {
    putenv('PLANEX_COMPANY_DELETION_BACKUP_ROOT');
    putenv('PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY');
    // The service normally removes its own storage. A failure cleanup may
    // touch only this random directory inside the current task worktree.
    $storageRoot = realpath(storage_path('companies'));
    $resolved = realpath($storageDir);
    if ($resolved !== false && $storageRoot !== false && str_starts_with($resolved, $storageRoot . DIRECTORY_SEPARATOR)) {
        foreach (array_diff(scandir($resolved) ?: [], ['.', '..']) as $item) unlink($resolved . DIRECTORY_SEPARATOR . $item);
        rmdir($resolved);
    }
    foreach (glob($root . '/manifests/*') ?: [] as $path) unlink($path);
    foreach (glob($root . '/archives/*') ?: [] as $path) unlink($path);
    rmdir($root . '/manifests'); rmdir($root . '/archives'); rmdir($root);
}
