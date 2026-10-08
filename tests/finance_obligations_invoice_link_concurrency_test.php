<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Service/DateCalculationService.php';
require_once __DIR__.'/../app/Service/RoutePaymentStatusService.php';
require_once __DIR__.'/../app/Service/FinanceAuditLogService.php';
require_once __DIR__.'/../app/Service/FinanceOperationService.php';
require_once __DIR__.'/../app/Service/FinanceInvoiceService.php';
require_once __DIR__.'/../app/Service/FinanceSettlementCascadeService.php';
require_once __DIR__.'/../app/Service/FinanceObligationService.php';
require_once __DIR__.'/../app/Service/FinanceManualAllocationService.php';
require_once __DIR__.'/../app/Service/FinanceAllocationService.php';

use App\Service\FinanceAllocationService;
use App\Service\FinanceObligationService;

function raceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
}

function raceExpectFailure(callable $fn, string $message, ?string $contains = null): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($contains !== null && !str_contains($e->getMessage(), $contains)) {
            throw new RuntimeException('FAIL: '.$message.' Unexpected error: '.$e->getMessage(), 0, $e);
        }
        return $e;
    }
    throw new RuntimeException('FAIL: '.$message);
}

$dsn = getenv('PLANEX_FINANCE_TEST_DSN') ?: 'mysql:host='.(getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1').';port='.(getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306').';dbname='.(getenv('PLANEX_TEST_FIXED_DB') ?: 'erp_obligations_test').';charset=utf8mb4';
$dbUser = getenv('PLANEX_FINANCE_TEST_USER') ?: getenv('PLANEX_SCHEMA_DB_USER') ?: 'root';
$dbPass = getenv('PLANEX_FINANCE_TEST_PASSWORD') ?: getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'root';
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$pdo = new PDO($dsn,$dbUser,$dbPass,$options);
$editorPdo = new PDO($dsn,$dbUser,$dbPass,$options);
$writerPdo = new PDO($dsn,$dbUser,$dbPass,$options);
$writerPdo->exec('SET SESSION innodb_lock_wait_timeout=1');

$tables = [
    'finance_operation_allocations','finance_invoice_links','finance_obligations','finance_operations','finance_invoices',
    'finance_audit_log','linear_route_payments','linear_routes'
];
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

$pdo->exec("CREATE TABLE finance_invoices(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    direction VARCHAR(20),number VARCHAR(100),invoice_date DATE,
    counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT NULL,
    counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,
    amount DECIMAL(12,2),planned_payment_date DATE NULL,status VARCHAR(30),
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,cancelled_at DATETIME NULL,
    first_paid_at DATE NULL,fully_paid_at DATE NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_invoice_links(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT UNSIGNED,obligation_id INT UNSIGNED NULL,
    linear_route_id INT UNSIGNED NULL,linear_route_payment_id INT UNSIGNED NULL,
    amount DECIMAL(12,2) NULL,side VARCHAR(20) NULL,
    created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fil_invoice(invoice_id),KEY idx_fil_obligation(obligation_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operations(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_date DATE,operation_type VARCHAR(20),transfer_direction VARCHAR(10) NULL,
    counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT UNSIGNED NULL,
    counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,
    purpose TEXT NULL,amount DECIMAL(12,2),status VARCHAR(30),cancelled_at DATETIME NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operation_allocations(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_id INT UNSIGNED,invoice_id INT UNSIGNED NULL,obligation_id INT UNSIGNED NULL,
    linear_route_id INT UNSIGNED NULL,linear_route_payment_id INT UNSIGNED NULL,
    amount DECIMAL(12,2),allocation_date DATE,method VARCHAR(30),comment TEXT NULL,
    created_by_user_id INT NULL,created_by_role VARCHAR(50) NULL,
    cancelled_at DATETIME NULL,cancelled_by_user_id INT NULL,cancelled_by_role VARCHAR(50) NULL,
    cancel_reason TEXT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_foa_operation(operation_id),KEY idx_foa_obligation(obligation_id),KEY idx_foa_invoice(invoice_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_obligations(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_type VARCHAR(50),source_key VARCHAR(255) NULL,source_id INT UNSIGNED NULL,
    source_parent_type VARCHAR(50),source_parent_id INT UNSIGNED,
    direction VARCHAR(20),party_role VARCHAR(20) NULL,
    counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT NULL,
    counterparty_name VARCHAR(500) NULL,counterparty_inn VARCHAR(20) NULL,amount DECIMAL(12,2),
    status VARCHAR(30),cancelled_at DATETIME NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_audit_log(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(64),entity_id INT UNSIGNED,action VARCHAR(64),
    old_values TEXT NULL,new_values TEXT NULL,
    created_by_user_id INT NULL,created_by_role VARCHAR(64) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_routes(
    id INT UNSIGNED PRIMARY KEY,
    created_by_user_id INT UNSIGNED NULL,
    closing_documents_received_date DATE NULL,
    deleted_at DATETIME NULL
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE linear_route_payments(
    id INT UNSIGNED PRIMARY KEY,
    linear_route_id INT UNSIGNED,
    amount DECIMAL(12,2),
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_status VARCHAR(30) NOT NULL DEFAULT 'planned',
    calculated_due_date DATE NULL,
    cancelled_at DATETIME NULL,
    condition_type VARCHAR(50) NULL,
    deleted_at DATETIME NULL,
    paid_at DATE NULL,
    status_updated_at DATETIME NULL
) ENGINE=InnoDB");

$userCtx=['user_id'=>1,'role_code'=>'company_owner'];
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES
('INCOMING','RACE-STANDALONE-40','2026-08-27','contractor',50,'Race Supplier','7755000005',40,'received'),
('INCOMING','RACE-SAME-MAP-20','2026-08-27','contractor',50,'Race Supplier','7755000005',20,'received'),
('INCOMING','RACE-LINK-FIRST-30','2026-08-27','contractor',50,'Race Supplier','7755000005',30,'received')");
$pdo->exec("INSERT INTO finance_obligations(source_type,source_key,source_id,source_parent_type,source_parent_id,direction,party_role,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES
('TEST','test:1',1,'TEST',1,'PAYABLE','carrier','contractor',50,'Race Supplier','7755000005',40,'planned'),
('TEST','test:2',2,'TEST',2,'PAYABLE','carrier','contractor',50,'Race Supplier','7755000005',20,'planned'),
('LINEAR_ROUTE_PAYMENT','route:30:payment:300',300,'LINEAR_ROUTE',30,'PAYABLE','carrier','contractor',50,'Race Supplier','7755000005',30,'planned')");
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES
('2026-08-27','EXPENSE','contractor',50,'Race Supplier','7755000005',40,'POSTED'),
('2026-08-27','EXPENSE','contractor',50,'Race Supplier','7755000005',5,'POSTED'),
('2026-08-27','EXPENSE','contractor',50,'Race Supplier','7755000005',30,'POSTED')");

// Order A: edit/controller read sees paid=0, then standalone production allocation commits first.
$staleControllerPaid = (string)$editorPdo->query("SELECT paid_amount FROM finance_invoices WHERE id=1")->fetchColumn();
raceCheck((float)$staleControllerPaid===0.0,'edit pre-read starts from paid=0');
$standaloneId = FinanceAllocationService::createAllocation(
    $writerPdo,
    1,
    ['amount'=>'40.00','invoice_id'=>1,'allocation_date'=>'2026-08-27'],
    $userCtx
);
$standalone = $pdo->query("SELECT * FROM finance_operation_allocations WHERE id={$standaloneId}")->fetch();
raceCheck($standalone!==false && $standalone['obligation_id']===null,'production standalone writer created invoice-only allocation');

// Deliberately make the cached invoice field stale: the invariant must derive fact from active POSTED allocations.
$pdo->exec("UPDATE finance_invoices SET paid_amount=0,status='received' WHERE id=1");
$editorPdo->beginTransaction();
raceExpectFailure(
    fn()=>FinanceObligationService::replaceInvoiceLinks($editorPdo,1,[['obligation_id'=>1,'amount'=>'40.00']],$userCtx),
    'paid standalone invoice cannot be converted to obligation-backed after the standalone commit',
    'После поступления оплаты нельзя менять привязки счёта к платёжным обязательствам.'
);
$editorPdo->rollBack();
raceCheck((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=1")->fetchColumn()===0,'rejected edit leaves standalone invoice link-free');
raceCheck((int)$pdo->query("SELECT COUNT(*) FROM finance_operation_allocations WHERE invoice_id=1 AND obligation_id IS NULL AND cancelled_at IS NULL")->fetchColumn()===1,'standalone payment remains intact after rejected link mutation');

// A paid obligation-backed invoice may re-save the exact same canonical link map.
$pdo->beginTransaction();
FinanceObligationService::replaceInvoiceLinks($pdo,2,[['obligation_id'=>2,'amount'=>'20.00']],$userCtx);
$pdo->commit();
$pdo->exec("INSERT INTO finance_operation_allocations(operation_id,invoice_id,obligation_id,amount,allocation_date,method,created_by_user_id,created_by_role) VALUES(2,2,2,5,'2026-08-27','manual',1,'company_owner')");
$pdo->exec("UPDATE finance_invoices SET paid_amount=0,status='received' WHERE id=2");
$pdo->beginTransaction();
FinanceObligationService::replaceInvoiceLinks($pdo,2,[['obligation_id'=>2,'amount'=>'20.00']],$userCtx);
$pdo->commit();
raceCheck((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=2 AND obligation_id=2 AND amount=20")->fetchColumn()===1,'identical canonical link map is allowed after factual POSTED payment');

// Re-generating a package may re-save the same invoice after another invoice has occupied
// the obligation. Keep the existing link unchanged, but reject any changed allocation.
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('INCOMING','RACE-OTHER-10','2026-08-27','contractor',50,'Race Supplier','7755000005',10,'received')");
$otherInvoiceId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO finance_invoice_links(invoice_id,obligation_id,amount,side) VALUES($otherInvoiceId,2,10,'carrier')");
$originalLinkId=(int)$pdo->query('SELECT id FROM finance_invoice_links WHERE invoice_id=2 AND obligation_id=2')->fetchColumn();
$pdo->beginTransaction();
FinanceObligationService::replaceInvoiceLinks($pdo,2,[['obligation_id'=>2,'amount'=>'20.00']],$userCtx);
$pdo->commit();
raceCheck((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=2 AND obligation_id=2 AND amount=20")->fetchColumn()===1,'unchanged invoice link survives a later overlapping invoice');
raceCheck((int)$pdo->query('SELECT id FROM finance_invoice_links WHERE invoice_id=2 AND obligation_id=2')->fetchColumn()===$originalLinkId,'identical re-save preserves physical link identity');
raceCheck((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=$otherInvoiceId AND obligation_id=2 AND amount=10")->fetchColumn()===1,'other invoice remains untouched');
raceExpectFailure(
    fn()=>FinanceObligationService::replaceInvoiceLinks($pdo,$otherInvoiceId,[['obligation_id'=>2,'amount'=>'9.00']],$userCtx),
    'a different invoice cannot reallocate the overbooked obligation',
    'Сумма связи превышает свободный остаток обязательства'
);

// Order B: link transaction acquires the invoice lock first. Standalone writer must wait/fail while the lock is held.
$editorPdo->beginTransaction();
FinanceObligationService::replaceInvoiceLinks($editorPdo,3,[['obligation_id'=>3,'amount'=>'30.00']],$userCtx);
raceCheck((int)$editorPdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=3 AND obligation_id=3")->fetchColumn()===1,'link transaction has created the canonical link before commit');
raceExpectFailure(
    fn()=>FinanceAllocationService::createAllocation($writerPdo,3,['amount'=>'30.00','invoice_id'=>3,'allocation_date'=>'2026-08-27'],$userCtx),
    'standalone writer cannot pass an invoice row locked by link replacement'
);
raceCheck((int)$pdo->query("SELECT COUNT(*) FROM finance_operation_allocations WHERE invoice_id=3 AND obligation_id IS NULL AND cancelled_at IS NULL")->fetchColumn()===0,'blocked writer created no invoice-only allocation');
$editorPdo->commit();

// Make the linked obligation source active only after the link transaction commits, then retry the exact production writer.
$pdo->exec("INSERT INTO linear_routes(id,created_by_user_id,deleted_at) VALUES(30,1,NULL)");
$pdo->exec("INSERT INTO linear_route_payments(id,linear_route_id,amount,paid_amount,payment_status,calculated_due_date,cancelled_at,condition_type,deleted_at) VALUES(300,30,30,0,'planned','2026-09-10',NULL,'specific_date',NULL)");
$linkedAllocationId = FinanceAllocationService::createAllocation(
    $writerPdo,
    3,
    ['amount'=>'30.00','invoice_id'=>3,'allocation_date'=>'2026-08-27'],
    $userCtx
);
$linkedAllocation = $pdo->query("SELECT * FROM finance_operation_allocations WHERE id={$linkedAllocationId}")->fetch();
raceCheck($linkedAllocation!==false && (int)$linkedAllocation['obligation_id']===3,'writer retries through obligation-aware path after link commit');
raceCheck((int)$pdo->query("SELECT COUNT(*) FROM finance_operation_allocations WHERE invoice_id=3 AND obligation_id IS NULL AND cancelled_at IS NULL")->fetchColumn()===0,'link-first order can never create obligation_id NULL');

echo "Finance invoice-link settlement concurrency regression PASS\n";
