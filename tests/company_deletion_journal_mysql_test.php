<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Service/CompanyDeletionJournal.php';
require_once __DIR__ . '/../app/Service/CompanyDeletionBackupVerifier.php';
require_once __DIR__ . '/../app/Service/CompanyDeletionService.php';

use App\Service\CompanyDeletionJournal as Journal;
use App\Service\CompanyDeletionService;

$db = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allowed = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if (getenv('APP_ENV') !== 'test' || $db === '' || !in_array($db, $allowed, true)) {
    throw new RuntimeException('Run only through the trusted disposable-schema wrapper.');
}
$pdo = new PDO(
    'mysql:host=' . getenv('PLANEX_SCHEMA_DB_HOST') . ';port=' . getenv('PLANEX_SCHEMA_DB_PORT') . ';dbname=' . $db . ';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec((string)file_get_contents(__DIR__ . '/../database/migrations/021_company_deletion_runs.sql'));
$pdo->exec((string)file_get_contents(__DIR__ . '/../database/migrations/009_create_deleted_entities.sql'));
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$expect = static function (callable $action, string $code) use ($assert): void {
    try { $action(); } catch (RuntimeException $e) {
        $assert($e->getMessage() === $code, 'Unexpected failure: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected ' . $code);
};
$backup = [
    'id' => bin2hex(random_bytes(16)), 'manifest_sha256' => str_repeat('a', 64),
    'database_sha256' => str_repeat('b', 64), 'storage_sha256' => str_repeat('c', 64),
    'restore_receipt_sha256' => str_repeat('d', 64),
    'created_at' => '2026-11-01 11:00:00', 'expires_at' => '2026-11-01 13:00:00',
];
$company = ['id' => 982171, 'name' => 'Disposable audit fixture', 'db_identifier' => $db];
$pdo->prepare("INSERT INTO deleted_entities (company_id, entity_type, entity_id, source_table, status) VALUES (?, 'client', 8, 'clients', 'archived')")
    ->execute([$company['id']]);
$expect(static fn() => Journal::begin($pdo, $company, 0, $backup), 'COMPANY_DELETE_ACTOR_MISSING');
$run = Journal::begin($pdo, $company, 71, $backup);
$assert($run > 0, 'Run ID missing');
$row = $pdo->query('SELECT * FROM company_deletion_runs WHERE id=' . $run)->fetch(PDO::FETCH_ASSOC);
$assert($row['actor_user_id'] == 71 && $row['stage'] === 'backup_verified' && $row['status'] === 'in_progress', 'Initial journal state wrong');
$assert($row['backup_id'] === $backup['id'], 'Backup identity not retained');
$expect(static fn() => Journal::begin($pdo, $company, 71, array_replace($backup, ['id' => bin2hex(random_bytes(16))])), 'COMPANY_DELETE_ALREADY_STARTED');
$expect(static fn() => Journal::begin($pdo, array_replace($company, ['id' => 982172]), 71, $backup), 'COMPANY_DELETE_ALREADY_STARTED');
$expect(static fn() => Journal::advance($pdo, $run, 'backup_verified', 'storage_deleted'), 'COMPANY_DELETE_JOURNAL_STAGE_INVALID');
Journal::advance($pdo, $run, 'backup_verified', 'tenant_deleted');
Journal::fail($pdo, $run, 'tenant_deleted', 'STORAGE_DELETE_FAILED');
$expect(static fn() => Journal::advance($pdo, $run, 'tenant_deleted', 'storage_deleted'), 'COMPANY_DELETE_JOURNAL_STAGE_CONFLICT');
$assert((int)$pdo->query('SELECT COUNT(*) FROM deleted_entities WHERE company_id=982171')->fetchColumn() === 1, 'Audit archive lost');

$backup2 = array_replace($backup, ['id' => bin2hex(random_bytes(16))]);
$run2 = Journal::begin($pdo, array_replace($company, ['id' => 982173]), 72, $backup2);
Journal::advance($pdo, $run2, 'backup_verified', 'tenant_deleted');
Journal::advance($pdo, $run2, 'tenant_deleted', 'storage_deleted');
Journal::advance($pdo, $run2, 'storage_deleted', 'central_deleted');
Journal::advance($pdo, $run2, 'central_deleted', 'completed');
$completed = $pdo->query('SELECT status,stage,completed_at FROM company_deletion_runs WHERE id=' . $run2)->fetch(PDO::FETCH_ASSOC);
$assert($completed['status'] === 'completed' && $completed['completed_at'] !== null, 'Completion is not durable');

// A missing signed manifest must stop the service before any tenant/storage write.
$priorRoot = getenv('PLANEX_COMPANY_DELETION_BACKUP_ROOT');
$priorKey = getenv('PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY');
putenv('PLANEX_COMPANY_DELETION_BACKUP_ROOT');
putenv('PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY');
try {
    $blocked = CompanyDeletionService::delete($pdo, $company, ['database' => []], 71);
    $assert($blocked['success'] === false && $blocked['steps'][0]['step'] === 'backup_and_journal', 'Missing backup did not fail closed');
} finally {
    if ($priorRoot !== false) putenv('PLANEX_COMPANY_DELETION_BACKUP_ROOT=' . $priorRoot);
    if ($priorKey !== false) putenv('PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY=' . $priorKey);
}
$assert((int)$pdo->query('SELECT COUNT(*) FROM deleted_entities WHERE company_id=982171')->fetchColumn() === 1, 'Preflight mutated audit archive');
echo "COMPANY_DELETION_JOURNAL_MYSQL=PASS\n";
