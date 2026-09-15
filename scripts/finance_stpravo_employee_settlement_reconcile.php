<?php

declare(strict_types=1);

use App\Service\ClosingDocumentComplianceService;
use App\Service\FinanceAuditLogService;

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}

$mode = strtolower(trim((string)(getenv('FINANCE_STPRAVO_MODE') ?: 'verify')));
if (!in_array($mode, ['verify', 'apply'], true)) {
    throw new RuntimeException('FINANCE_STPRAVO_MODE must be verify or apply');
}
if ($mode === 'apply' && !hash_equals('APPLY_FINANCE_STPRAVO_RECONCILE', (string)getenv('FINANCE_STPRAVO_CONFIRM'))) {
    throw new RuntimeException('Explicit FINANCE_STPRAVO_CONFIRM is required');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/ClosingDocumentService.php';
require_once $root . '/app/Service/ClosingDocumentComplianceService.php';
require_once $root . '/app/Service/FinanceAuditLogService.php';

$target = [
    'account_number' => '40702810500810141833',
    'operation_date' => '2026-09-10',
    'document_number' => '19',
    'counterparty_name' => 'ООО "СТПРАВО"',
    'counterparty_inn' => '9705228628',
    'debit_amount' => '50000.00',
    'credit_amount' => '0.00',
    'purpose' => 'Оплата по договору об оказании юридических услуг от 10.09.2026г. НДС не облагается',
];
$markerPrefix = 'finance_stpravo_employee_settlement_reconcile_20260915_tx_';
$markerChecksum = hash('sha256', 'finance-stpravo-reconcile-v1|2026-09-10|inn=9705228628|doc=19|debit=50000.00|scope=bank-employee-direct-settlement');

$connect = static function (array $cfg): PDO {
    return new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
};

$tableExists = static function (PDO $pdo, string $table): bool {
    $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    return (int)$q->fetchColumn() === 1;
};

$normalizeText = static function (?string $value): string {
    $value = str_replace(['«', '»', '“', '”'], '"', trim((string)$value));
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return mb_strtoupper($value, 'UTF-8');
};

$countRows = static function (PDO $pdo, string $table, string $column, int $id): int {
    foreach ([$table, $column] as $identifier) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $identifier)) throw new RuntimeException('Unsafe schema identifier');
    }
    $q = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=?");
    $q->execute([$id]);
    return (int)$q->fetchColumn();
};

$foreignReferences = static function (PDO $pdo, string $referencedTable, int $id, array $allowed = []) use ($countRows): array {
    $q = $pdo->prepare(
        "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()
            AND REFERENCED_TABLE_NAME=? AND REFERENCED_COLUMN_NAME='id'"
    );
    $q->execute([$referencedTable]);
    $hits = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string)$row['TABLE_NAME'] . '.' . (string)$row['COLUMN_NAME'];
        if (isset($allowed[$key])) continue;
        $count = $countRows($pdo, (string)$row['TABLE_NAME'], (string)$row['COLUMN_NAME'], $id);
        if ($count > 0) $hits[$key] = $count;
    }
    return $hits;
};

$central = $connect($config['database']);
$companies = $central->query(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password
       FROM companies
      WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>''
      ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$candidates = [];
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $tenant = $connect($cfg);
    foreach (['bank_accounts', 'bank_transactions', 'finance_operations', 'finance_employee_movements', 'schema_migrations', 'finance_audit_log'] as $requiredTable) {
        if (!$tableExists($tenant, $requiredTable)) continue 2;
    }
    $q = $tenant->prepare(
        "SELECT bt.id,bt.counterparty_name,bt.counterparty_inn,bt.purpose,bt.operation_date,bt.document_number,
                bt.debit_amount,bt.credit_amount,ba.account_number
           FROM bank_transactions bt
           JOIN bank_accounts ba ON ba.id=bt.account_id
          WHERE ba.account_number=? AND bt.operation_date=? AND COALESCE(bt.document_number,'')=?
            AND REPLACE(REPLACE(REPLACE(COALESCE(bt.counterparty_inn,''),' ',''),'-',''),'.','')=?
            AND ROUND(COALESCE(bt.debit_amount,0),2)=50000.00
            AND ROUND(COALESCE(bt.credit_amount,0),2)=0.00"
    );
    $q->execute([$target['account_number'], $target['operation_date'], $target['document_number'], $target['counterparty_inn']]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($normalizeText((string)$row['counterparty_name']) !== $normalizeText($target['counterparty_name'])) continue;
        if ($normalizeText((string)$row['purpose']) !== $normalizeText($target['purpose'])) continue;
        $candidates[] = ['company' => $company, 'cfg' => $cfg, 'transaction_id' => (int)$row['id']];
    }
}
if (count($candidates) !== 1) {
    throw new RuntimeException('Expected exactly one STPRAVO target transaction across active tenants; found ' . count($candidates));
}

$candidate = $candidates[0];
$company = $candidate['company'];
$pdo = $connect($candidate['cfg']);
$transactionId = (int)$candidate['transaction_id'];
$marker = $markerPrefix . $transactionId;

$closingRow = static function (PDO $pdo, int $transactionId): ?array {
    if (!ClosingDocumentComplianceService::schemaReady($pdo)) throw new RuntimeException('Closing documents schema is not ready in target tenant');
    foreach (ClosingDocumentComplianceService::reportRows($pdo, false) as $row) {
        if ((int)($row['id'] ?? 0) === $transactionId) return $row;
    }
    return null;
};

$snapshot = static function (PDO $pdo) use ($transactionId, $marker, $closingRow, $foreignReferences, $tableExists): array {
    $forUpdate = $pdo->inTransaction() ? ' FOR UPDATE' : '';
    $q = $pdo->prepare('SELECT * FROM bank_transactions WHERE id=?' . $forUpdate);
    $q->execute([$transactionId]);
    $bt = $q->fetch(PDO::FETCH_ASSOC);
    if (!$bt) throw new RuntimeException('Target bank transaction disappeared');

    $q = $pdo->prepare("SELECT * FROM finance_operations WHERE bank_transaction_id=? AND status<>'CANCELLED' ORDER BY id" . $forUpdate);
    $q->execute([$transactionId]);
    $bankOps = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($bankOps) !== 1) throw new RuntimeException('Expected exactly one active bank finance operation; found ' . count($bankOps));
    $bankOp = $bankOps[0];

    $q = $pdo->prepare('SELECT * FROM finance_employee_movements WHERE bank_transaction_id=? ORDER BY id' . $forUpdate);
    $q->execute([$transactionId]);
    $movements = $q->fetchAll(PDO::FETCH_ASSOC);

    $expectedTransferGroup = 'TRF_EMP_DIRECT_' . substr(hash('sha256', 'employee_bank_direct:' . $transactionId), 0, 24);
    $counterpartId = (int)($bt['linked_cash_transaction_id'] ?? 0);
    if ($counterpartId <= 0 && $movements !== []) $counterpartId = (int)($movements[0]['finance_operation_id'] ?? 0);
    $counterpart = null;
    if ($counterpartId > 0) {
        $q = $pdo->prepare('SELECT * FROM finance_operations WHERE id=?' . $forUpdate);
        $q->execute([$counterpartId]);
        $counterpart = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $q = $pdo->prepare("SELECT * FROM finance_operations WHERE transfer_group_id=? AND source='TRANSFER' ORDER BY id" . $forUpdate);
        $q->execute([$expectedTransferGroup]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) throw new RuntimeException('Ambiguous historical employee counterpart operations');
        if (count($rows) === 1) {
            $counterpart = $rows[0];
            $counterpartId = (int)$counterpart['id'];
        }
    }

    $markerQ = $pdo->prepare('SELECT migration,checksum FROM schema_migrations WHERE migration=? LIMIT 1');
    $markerQ->execute([$marker]);
    $markerRow = $markerQ->fetch(PDO::FETCH_ASSOC) ?: null;

    $movementRefs = count($movements) === 1 ? $foreignReferences($pdo, 'finance_employee_movements', (int)$movements[0]['id']) : [];
    $counterpartRefs = $counterpartId > 0
        ? $foreignReferences($pdo, 'finance_operations', $counterpartId, ['finance_employee_movements.finance_operation_id' => true])
        : [];

    $bankAllocationCount = 0;
    if ($tableExists($pdo, 'finance_operation_allocations')) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM finance_operation_allocations WHERE finance_operation_id=?');
        $q->execute([(int)$bankOp['id']]);
        $bankAllocationCount = (int)$q->fetchColumn();
    }
    $bankMatchingCount = 0;
    if ($tableExists($pdo, 'finance_matching_results')) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM finance_matching_results WHERE finance_operation_id=?');
        $q->execute([(int)$bankOp['id']]);
        $bankMatchingCount = (int)$q->fetchColumn();
    }

    $closing = $closingRow($pdo, $transactionId);
    return [
        'bank_transaction' => [
            'id' => (int)$bt['id'],
            'is_internal_transfer' => (int)($bt['is_internal_transfer'] ?? 0),
            'linked_cash_transaction_id' => $bt['linked_cash_transaction_id'] !== null ? (int)$bt['linked_cash_transaction_id'] : null,
            'classification_status' => (string)($bt['classification_status'] ?? ''),
            'classification_rule_id' => $bt['classification_rule_id'] !== null ? (int)$bt['classification_rule_id'] : null,
            'classification_locked' => (int)($bt['classification_locked'] ?? 0),
            'cash_flow_center_id' => $bt['cash_flow_center_id'] !== null ? (int)$bt['cash_flow_center_id'] : null,
            'dds_category_id' => $bt['dds_category_id'] !== null ? (int)$bt['dds_category_id'] : null,
        ],
        'bank_operation' => [
            'id' => (int)$bankOp['id'],
            'operation_type' => (string)$bankOp['operation_type'],
            'status' => (string)$bankOp['status'],
            'source' => (string)$bankOp['source'],
            'money_account_id' => (int)$bankOp['money_account_id'],
            'transfer_account_id' => $bankOp['transfer_account_id'] !== null ? (int)$bankOp['transfer_account_id'] : null,
            'transfer_group_id' => $bankOp['transfer_group_id'] !== null ? (string)$bankOp['transfer_group_id'] : null,
            'transfer_direction' => $bankOp['transfer_direction'] !== null ? (string)$bankOp['transfer_direction'] : null,
            'classification_status' => (string)($bankOp['classification_status'] ?? ''),
            'classification_rule_id' => $bankOp['classification_rule_id'] !== null ? (int)$bankOp['classification_rule_id'] : null,
            'classification_locked' => (int)($bankOp['classification_locked'] ?? 0),
            'cash_flow_center_id' => $bankOp['cash_flow_center_id'] !== null ? (int)$bankOp['cash_flow_center_id'] : null,
            'dds_category_id' => $bankOp['dds_category_id'] !== null ? (int)$bankOp['dds_category_id'] : null,
            'amount' => (string)$bankOp['amount'],
        ],
        'movement_count' => count($movements),
        'movement' => count($movements) === 1 ? [
            'id' => (int)$movements[0]['id'],
            'movement_type' => (string)$movements[0]['movement_type'],
            'source_type' => (string)$movements[0]['source_type'],
            'finance_operation_id' => (int)$movements[0]['finance_operation_id'],
        ] : null,
        'movement_foreign_references' => $movementRefs,
        'counterpart_operation' => is_array($counterpart) ? [
            'id' => (int)$counterpart['id'],
            'operation_type' => (string)$counterpart['operation_type'],
            'status' => (string)$counterpart['status'],
            'source' => (string)$counterpart['source'],
            'money_account_id' => (int)$counterpart['money_account_id'],
            'transfer_account_id' => $counterpart['transfer_account_id'] !== null ? (int)$counterpart['transfer_account_id'] : null,
            'transfer_group_id' => $counterpart['transfer_group_id'] !== null ? (string)$counterpart['transfer_group_id'] : null,
            'transfer_direction' => $counterpart['transfer_direction'] !== null ? (string)$counterpart['transfer_direction'] : null,
            'amount' => (string)$counterpart['amount'],
        ] : null,
        'counterpart_foreign_references' => $counterpartRefs,
        'bank_allocation_count' => $bankAllocationCount,
        'bank_matching_result_count' => $bankMatchingCount,
        'closing_documents' => is_array($closing) ? [
            'present' => true,
            'direction' => (string)($closing['direction'] ?? ''),
            'profile_code' => (string)($closing['profile']['code'] ?? ''),
            'normalized_inn' => (string)($closing['normalized_inn'] ?? ''),
        ] : ['present' => false],
        'marker' => $markerRow,
    ];
};

$assertBefore = static function (array $s): void {
    $bt = $s['bank_transaction'];
    $bo = $s['bank_operation'];
    $mv = $s['movement'];
    $co = $s['counterpart_operation'];
    if ($bt['is_internal_transfer'] !== 1 || $bt['linked_cash_transaction_id'] === null) throw new RuntimeException('Target is not expected internal BANK↔EMPLOYEE transaction');
    if ($bt['classification_status'] !== 'MANUAL' || $bt['classification_rule_id'] !== null || $bt['classification_locked'] !== 1) throw new RuntimeException('Unexpected target bank classification state');
    if ($bo['operation_type'] !== 'TRANSFER' || $bo['status'] !== 'POSTED' || $bo['source'] !== 'TRANSFER' || $bo['transfer_direction'] !== 'out') throw new RuntimeException('Unexpected bank transfer state');
    if (!is_string($bo['transfer_group_id']) || !str_starts_with($bo['transfer_group_id'], 'TRF_EMP_DIRECT_')) throw new RuntimeException('Unexpected direct employee transfer group');
    if ($s['movement_count'] !== 1 || !is_array($mv) || $mv['movement_type'] !== 'PAYMENT' || $mv['source_type'] !== 'BANK') throw new RuntimeException('Unexpected employee movement state');
    if (!is_array($co) || $co['id'] !== $bt['linked_cash_transaction_id'] || $mv['finance_operation_id'] !== $co['id']) throw new RuntimeException('Employee counterpart link mismatch');
    if ($co['operation_type'] !== 'TRANSFER' || $co['status'] !== 'POSTED' || $co['source'] !== 'TRANSFER' || $co['transfer_direction'] !== 'in') throw new RuntimeException('Unexpected employee counterpart state');
    if ($co['transfer_group_id'] !== $bo['transfer_group_id'] || $co['money_account_id'] !== $bo['transfer_account_id'] || $co['transfer_account_id'] !== $bo['money_account_id']) throw new RuntimeException('Transfer legs are not symmetric');
    if ((float)$bo['amount'] !== 50000.0 || (float)$co['amount'] !== 50000.0) throw new RuntimeException('Unexpected direct-settlement amount');
    if ($s['movement_foreign_references'] !== [] || $s['counterpart_foreign_references'] !== []) throw new RuntimeException('Direct settlement has downstream references');
    if ($s['bank_allocation_count'] !== 0 || $s['bank_matching_result_count'] !== 0) throw new RuntimeException('Bank operation has downstream allocation/matching state');
    if (!empty($s['closing_documents']['present'])) throw new RuntimeException('Target unexpectedly already appears in closing documents');
    if ($s['marker'] !== null) throw new RuntimeException('Reconciliation marker already exists in pre-state');
};

$assertAfter = static function (array $s, string $checksum): void {
    $bt = $s['bank_transaction'];
    $bo = $s['bank_operation'];
    $co = $s['counterpart_operation'];
    if ($bt['is_internal_transfer'] !== 0 || $bt['linked_cash_transaction_id'] !== null) throw new RuntimeException('Bank transaction is still an internal transfer');
    if ($bt['classification_status'] !== 'UNALLOCATED' || $bt['classification_rule_id'] !== null || $bt['classification_locked'] !== 0) throw new RuntimeException('Bank classification was not reset');
    if ($bt['cash_flow_center_id'] !== null || $bt['dds_category_id'] !== null) throw new RuntimeException('Erroneous classification dimensions remain on bank transaction');
    if ($bo['operation_type'] !== 'EXPENSE' || $bo['status'] !== 'POSTED' || $bo['source'] !== 'BANK_STATEMENT') throw new RuntimeException('Bank operation was not restored');
    if ($bo['transfer_account_id'] !== null || $bo['transfer_group_id'] !== null || $bo['transfer_direction'] !== null) throw new RuntimeException('Transfer metadata remains on bank operation');
    if ($bo['classification_status'] !== 'UNALLOCATED' || $bo['classification_rule_id'] !== null || $bo['classification_locked'] !== 0) throw new RuntimeException('Bank operation classification was not reset');
    if ($bo['cash_flow_center_id'] !== null || $bo['dds_category_id'] !== null) throw new RuntimeException('Erroneous classification dimensions remain on bank operation');
    if ($s['movement_count'] !== 0) throw new RuntimeException('Employee movement still exists');
    if (!is_array($co) || $co['status'] !== 'CANCELLED') throw new RuntimeException('Employee counterpart was not cancelled');
    $closing = $s['closing_documents'];
    if (empty($closing['present']) || $closing['direction'] !== 'outgoing' || $closing['profile_code'] !== 'legal_entity' || $closing['normalized_inn'] !== '9705228628') throw new RuntimeException('STPRAVO payment is not restored to closing-documents legal-entity scope');
    if (!is_array($s['marker']) || !hash_equals($checksum, (string)($s['marker']['checksum'] ?? ''))) throw new RuntimeException('Reconciliation marker missing or invalid');
};

$before = $snapshot($pdo);
if ($mode === 'verify') {
    if (is_array($before['marker'])) {
        $assertAfter($before, $markerChecksum);
        $state = 'RECONCILED';
    } else {
        $assertBefore($before);
        $state = 'NEEDS_RECONCILE';
    }
    echo 'FINANCE_STPRAVO_STATE=' . $state . PHP_EOL;
    echo 'FINANCE_STPRAVO_SNAPSHOT=' . json_encode(['company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],'transaction_id'=>$transactionId,'state'=>$state,'snapshot'=>$before], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "FINANCE_STPRAVO_VERIFY_OK\n";
    exit(0);
}

$lockName = 'planex_fin_stpravo_' . substr(hash('sha256', (string)$company['db_identifier'] . ':' . $transactionId), 0, 20);
$lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Could not acquire reconciliation lock');

try {
    $pdo->beginTransaction();
    $locked = $snapshot($pdo);
    if (is_array($locked['marker'])) {
        $assertAfter($locked, $markerChecksum);
        $pdo->commit();
        echo 'FINANCE_STPRAVO_RECONCILE_OK state=existing transaction_id=' . $transactionId . PHP_EOL;
    } else {
        $assertBefore($locked);
        $movementId = (int)$locked['movement']['id'];
        $bankOperationId = (int)$locked['bank_operation']['id'];
        $counterpartId = (int)$locked['counterpart_operation']['id'];

        $q = $pdo->prepare('DELETE FROM finance_employee_movements WHERE id=? AND bank_transaction_id=?');
        $q->execute([$movementId, $transactionId]);
        if ($q->rowCount() !== 1) throw new RuntimeException('Failed to remove erroneous employee movement');

        $q = $pdo->prepare("UPDATE finance_operations SET status='CANCELLED',classification_locked=0,classification_updated_at=NOW() WHERE id=? AND status='POSTED'");
        $q->execute([$counterpartId]);
        if ($q->rowCount() !== 1) throw new RuntimeException('Failed to cancel employee counterpart operation');

        $q = $pdo->prepare("UPDATE finance_operations SET operation_type='EXPENSE',source='BANK_STATEMENT',transfer_account_id=NULL,transfer_group_id=NULL,transfer_direction=NULL,dds_category_id=NULL,cash_flow_center_id=NULL,classification_status='UNALLOCATED',classification_rule_id=NULL,classification_locked=0,classification_updated_at=NOW() WHERE id=? AND bank_transaction_id=? AND status='POSTED'");
        $q->execute([$bankOperationId, $transactionId]);
        if ($q->rowCount() !== 1) throw new RuntimeException('Failed to restore ordinary bank operation');

        $q = $pdo->prepare("UPDATE bank_transactions SET is_internal_transfer=0,linked_cash_transaction_id=NULL,dds_category_id=NULL,cash_flow_center_id=NULL,classification_status='UNALLOCATED',classification_rule_id=NULL,classification_locked=0,classification_updated_at=NOW() WHERE id=?");
        $q->execute([$transactionId]);
        if ($q->rowCount() !== 1) throw new RuntimeException('Failed to restore bank transaction');

        FinanceAuditLogService::log(
            $pdo,
            'bank_transaction',
            $transactionId,
            'owner_reconcile_employee_settlement',
            ['employee_movement_id'=>$movementId,'bank_operation_id'=>$bankOperationId,'employee_counterpart_operation_id'=>$counterpartId,'classification_status'=>'MANUAL','is_internal_transfer'=>1],
            ['employee_movement_removed'=>true,'employee_counterpart_status'=>'CANCELLED','bank_operation_type'=>'EXPENSE','bank_operation_source'=>'BANK_STATEMENT','classification_status'=>'UNALLOCATED','is_internal_transfer'=>0,'owner_authorization'=>'explicit_task_2026_09_15'],
            0,
            'system'
        );

        $q = $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');
        $q->execute([$marker, $markerChecksum]);
        $pdo->commit();
        echo 'FINANCE_STPRAVO_RECONCILE_OK state=applied transaction_id=' . $transactionId . PHP_EOL;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}

$after = $snapshot($pdo);
$assertAfter($after, $markerChecksum);
echo 'FINANCE_STPRAVO_SNAPSHOT=' . json_encode(['company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],'transaction_id'=>$transactionId,'state'=>'RECONCILED','snapshot'=>$after], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo "FINANCE_STPRAVO_VERIFY_OK\n";
