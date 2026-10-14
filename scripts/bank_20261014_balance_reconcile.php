<?php

declare(strict_types=1);

use App\Service\FinanceBankReconciliationCutoffService;

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    throw new RuntimeException('CLI only');
}

$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
$mode = (string)(getenv('BANK_BALANCE_MODE') ?: 'verify');
if (!in_array($mode, ['verify', 'apply'], true) || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('Invalid mode or PLANEX_ROOT');
}
if ($mode === 'apply' && !hash_equals('APPLY_BANK_20261014_BALANCE', (string)getenv('BANK_BALANCE_CONFIRM'))) {
    throw new RuntimeException('Explicit correction confirmation required');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/FinanceBankReconciliationService.php';
require_once $root . '/app/Service/FinanceBankReconciliationCutoffService.php';

$connect = static fn (array $cfg): PDO => new PDO(
    'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$central = $connect($config['database']);
$companies = $central->query("SELECT id,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>''")->fetchAll(PDO::FETCH_ASSOC);
$matches = [];
foreach ($companies as $company) {
    $pdo = $connect(companyDatabaseConfig($config, $company));
    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bank_daily_balances'")->fetchColumn();
    if ((int)$table !== 1) continue;
    $q = $pdo->prepare('SELECT id FROM bank_accounts WHERE account_number=?');
    $q->execute(['40702810500810141833']);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $accountId) $matches[] = [$pdo, (int)$accountId, (int)$company['id']];
}
if (count($matches) !== 1) throw new RuntimeException('Expected exactly one account across active tenants; found ' . count($matches));
[$pdo, $accountId, $companyId] = $matches[0];

$snapshot = static function (PDO $pdo, int $accountId, bool $lock): array {
    $suffix = $lock ? ' FOR UPDATE' : '';
    $q = $pdo->prepare('SELECT * FROM bank_daily_balances WHERE account_id=? AND statement_date=? ORDER BY id' . $suffix);
    $q->execute([$accountId, '2026-10-14']);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1 || (int)$rows[0]['id'] !== 1747) throw new RuntimeException('Target balance identity/count changed');
    $row = $rows[0];
    $q = $pdo->prepare('SELECT closing_balance FROM bank_daily_balances WHERE account_id=? AND statement_date=? ORDER BY id' . $suffix);
    $q->execute([$accountId, '2026-10-13']);
    $previous = $q->fetchAll(PDO::FETCH_COLUMN);
    if (count($previous) !== 1 || (string)$previous[0] !== '516464.03') throw new RuntimeException('Previous closing balance changed');
    $q = $pdo->prepare('SELECT operation_date,COUNT(*) AS n,CAST(SUM(debit_amount) AS CHAR) AS debit,CAST(SUM(credit_amount) AS CHAR) AS credit FROM bank_transactions WHERE account_id=? AND operation_date IN (?,?) GROUP BY operation_date ORDER BY operation_date');
    $q->execute([$accountId, '2026-10-13', '2026-10-14']);
    $tx = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($tx) !== 2 || $tx[0]['operation_date'] !== '2026-10-13' || (int)$tx[0]['n'] !== 4 || (float)$tx[0]['debit'] !== 316340.0 || (float)$tx[0]['credit'] !== 0.0 || $tx[1]['operation_date'] !== '2026-10-14' || (int)$tx[1]['n'] !== 2 || (float)$tx[1]['debit'] !== 290000.0 || (float)$tx[1]['credit'] !== 0.0) {
        throw new RuntimeException('Transaction totals/counts changed');
    }
    if ((string)$row['currency'] !== 'RUR' || (string)$row['credit_turnover'] !== '0.00' || (string)$row['closing_balance'] !== '226464.03') throw new RuntimeException('Target balance non-corrected fields changed');
    $old = (string)$row['opening_balance'] === '832804.03' && (string)$row['debit_turnover'] === '606340.00';
    $new = (string)$row['opening_balance'] === '516464.03' && (string)$row['debit_turnover'] === '290000.00';
    if (!$old && !$new) throw new RuntimeException('Target balance values differ from approved before/after states');
    return ['row' => $row, 'previous_closing' => $previous[0], 'transactions' => $tx, 'state' => $old ? 'NEEDS_CORRECTION' : 'CORRECTED'];
};

if ($mode === 'apply') {
    $pdo->beginTransaction();
    try {
        $before = $snapshot($pdo, $accountId, true);
        if ($before['state'] !== 'NEEDS_CORRECTION') throw new RuntimeException('Correction already applied or state changed');
        $backupDir = (string)getenv('BANK_BALANCE_BACKUP_DIR');
        if ($backupDir === '' || !is_dir($backupDir) || !is_writable($backupDir)) throw new RuntimeException('Private backup directory required');
        $backup = rtrim($backupDir, '/') . '/bank_balance_20261014_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
        $payload = json_encode(['company_id' => $companyId, 'account_id' => $accountId, 'snapshot' => $before], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $handle = fopen($backup, 'x');
        if (!$handle) throw new RuntimeException('Backup create failed');
        chmod($backup, 0600);
        try {
            if (fwrite($handle, $payload . "\n") !== strlen($payload) + 1 || !fflush($handle)) throw new RuntimeException('Backup write failed');
        } finally { fclose($handle); }
        $q = $pdo->prepare("UPDATE bank_daily_balances SET opening_balance='516464.03', debit_turnover='290000.00' WHERE id=1747 AND account_id=? AND statement_date='2026-10-14' AND opening_balance='832804.03' AND debit_turnover='606340.00' AND credit_turnover='0.00' AND closing_balance='226464.03' AND currency='RUR'");
        $q->execute([$accountId]);
        if ($q->rowCount() !== 1) throw new RuntimeException('Guarded update did not affect exactly one row');
        $after = $snapshot($pdo, $accountId, true);
        if ($after['state'] !== 'CORRECTED') throw new RuntimeException('Post-update snapshot mismatch');
        $pdo->commit();
        echo 'BANK_BALANCE_APPLIED backup=' . $backup . ' sha256=' . hash_file('sha256', $backup) . "\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

$state = $snapshot($pdo, $accountId, false);
$report = FinanceBankReconciliationCutoffService::reconcileAll($pdo, '2026-07-24');
$accountReport = array_values(array_filter($report['accounts'], static fn (array $item): bool => (int)$item['account_id'] === $accountId));
if (count($accountReport) !== 1) throw new RuntimeException('Independent reconciliation account missing');
$recon = $accountReport[0];
$arithmetic = array_values(array_filter($recon['statement_arithmetic'], static fn (array $item): bool => $item['statement_date'] === '2026-10-14'));
$continuity = array_values(array_filter($recon['continuity']['items'] ?? [], static fn (array $item): bool => $item['statement_date'] === '2026-10-14'));
$aggregate = array_values(array_filter($recon['transaction_aggregates']['items'], static fn (array $item): bool => $item['statement_date'] === '2026-10-14'));
if (count($arithmetic) !== 1 || count($continuity) !== 1 || count($aggregate) !== 1) throw new RuntimeException('Independent reconciliation target rows missing');
if ($state['state'] === 'CORRECTED' && ($arithmetic[0]['status'] !== 'OK' || $continuity[0]['status'] !== 'OK' || $aggregate[0]['status'] !== 'OK')) throw new RuntimeException('Independent target reconciliation failed');
echo 'BANK_BALANCE_STATE=' . $state['state'] . "\n";
echo 'BANK_BALANCE_ROW=' . json_encode($state['row'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
echo 'BANK_BALANCE_RECONCILIATION=' . json_encode(['account_status' => $recon['status'], 'target_arithmetic' => $arithmetic[0]['status'], 'target_continuity' => $continuity[0]['status'], 'target_transactions' => $aggregate[0]['status']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
echo "BANK_BALANCE_VERIFY_OK\n";
