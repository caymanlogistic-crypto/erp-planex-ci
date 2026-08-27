<?php

declare(strict_types=1);

require_once __DIR__.'/../app/Service/FinanceAuditLogService.php';
require_once __DIR__.'/../app/Service/FinanceOperationService.php';
require_once __DIR__.'/../app/Service/FinanceSettlementCascadeService.php';
require_once __DIR__.'/../app/Service/FinanceManualAllocationService.php';
require_once __DIR__.'/../app/Service/FinanceAllocationService.php';

use App\Service\FinanceAllocationService;

function standaloneCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
}

function standaloneMoney(mixed $value): string
{
    $value = trim(str_replace(',', '.', (string)$value));
    if ($value === '') return '0.00';
    [$whole,$fraction] = array_pad(explode('.', $value, 2),2,'');
    return ((int)$whole).'.'.str_pad(substr($fraction,0,2),2,'0');
}

function standaloneExpectFailure(callable $fn, string $message): void
{
    try { $fn(); } catch (Throwable) { return; }
    throw new RuntimeException('FAIL: '.$message);
}

$dsn = getenv('PLANEX_FINANCE_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=erp_obligations_test;charset=utf8mb4';
$dbUser = getenv('PLANEX_FINANCE_TEST_USER') ?: 'root';
$dbPass = getenv('PLANEX_FINANCE_TEST_PASSWORD') ?: 'root';
$pdo = new PDO($dsn,$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$tables = ['finance_operation_allocations','finance_invoice_links','finance_obligations','finance_operations','finance_invoices','finance_audit_log'];
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($tables as $table) $pdo->exec("DROP TABLE IF EXISTS `$table`");
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
    source_type VARCHAR(50),source_id INT UNSIGNED NULL,source_parent_type VARCHAR(50),source_parent_id INT UNSIGNED,
    direction VARCHAR(20),counterparty_entity_type VARCHAR(20) NULL,counterparty_entity_id INT NULL,
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

$userCtx=['user_id'=>1,'role_code'=>'company_owner'];

// A normal invoice created without obligation selection is a valid standalone settlement class.
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,planned_payment_date,status)
           VALUES('INCOMING','STANDALONE-90','2026-08-27','contractor',50,'Standalone Supplier','7755000005',90,'2026-09-05','received')");
standaloneCheck((int)$pdo->query("SELECT COUNT(*) FROM finance_invoice_links WHERE invoice_id=1")->fetchColumn()===0,'standalone invoice has no invoice links');

$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES
('2026-08-27','EXPENSE','contractor',50,'Standalone Supplier','7755000005',40,'POSTED'),
('2026-08-27','EXPENSE','contractor',50,'Standalone Supplier','7755000005',50,'POSTED'),
('2026-08-27','EXPENSE','contractor',51,'Wrong Supplier','7755000099',10,'POSTED'),
('2026-08-27','INCOME','contractor',50,'Standalone Supplier','7755000005',10,'POSTED'),
('2026-08-27','EXPENSE','contractor',50,'Standalone Supplier','7755000005',60,'POSTED')");

$picker = FinanceAllocationService::fetchInvoicesForAllocation($pdo,'EXPENSE','7755000005');
$pickerIds = array_map(static fn(array $row): int => (int)$row['id'],$picker);
standaloneCheck(in_array(1,$pickerIds,true),'standalone unpaid invoice remains visible in manual allocation picker');

$partialId = FinanceAllocationService::createAllocation($pdo,1,['amount'=>'40.00','invoice_id'=>1,'allocation_date'=>'2026-08-27'],$userCtx);
$partial = $pdo->query("SELECT * FROM finance_operation_allocations WHERE id={$partialId}")->fetch();
standaloneCheck($partial!==false,'standalone partial allocation was created');
standaloneCheck((int)$partial['invoice_id']===1,'standalone allocation targets invoice');
standaloneCheck($partial['obligation_id']===null,'standalone allocation is the only allowed invoice-only obligation_id NULL class');
standaloneCheck($partial['linear_route_id']===null && $partial['linear_route_payment_id']===null,'standalone invoice does not fake route metadata');
standaloneCheck(standaloneMoney($pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=1")->fetchColumn())==='40.00','standalone partial payment updates invoice paid_amount');
standaloneCheck((string)$pdo->query("SELECT status FROM finance_invoices WHERE id=1")->fetchColumn()==='partially_paid','standalone partial payment updates invoice status');

standaloneExpectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,5,['amount'=>'60.00','invoice_id'=>1],$userCtx),'standalone invoice overpayment is rejected');
standaloneExpectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,3,['amount'=>'10.00','invoice_id'=>1],$userCtx),'standalone invoice counterparty mismatch is rejected');
standaloneExpectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,4,['amount'=>'10.00','invoice_id'=>1],$userCtx),'standalone invoice direction mismatch is rejected');

$fullId = FinanceAllocationService::createAllocation($pdo,2,['amount'=>'50.00','invoice_id'=>1,'allocation_date'=>'2026-08-27'],$userCtx);
standaloneCheck(standaloneMoney($pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=1")->fetchColumn())==='90.00','standalone full payment updates invoice paid_amount');
standaloneCheck((string)$pdo->query("SELECT status FROM finance_invoices WHERE id=1")->fetchColumn()==='paid','standalone invoice becomes paid');

FinanceAllocationService::cancelAllocation($pdo,$fullId,$userCtx,'Standalone cancel regression');
standaloneCheck(standaloneMoney($pdo->query("SELECT paid_amount FROM finance_invoices WHERE id=1")->fetchColumn())==='40.00','cancel reopens standalone invoice paid_amount');
standaloneCheck((string)$pdo->query("SELECT status FROM finance_invoices WHERE id=1")->fetchColumn()==='partially_paid','cancel reopens standalone invoice status');

$retryId = FinanceAllocationService::createAllocation($pdo,2,['amount'=>'50.00','invoice_id'=>1,'allocation_date'=>'2026-08-27'],$userCtx);
standaloneCheck($retryId!==$fullId,'cancelled standalone allocation can be retried');
standaloneCheck((string)$pdo->query("SELECT status FROM finance_invoices WHERE id=1")->fetchColumn()==='paid','standalone retry closes invoice again');

$auditJson=(string)$pdo->query("SELECT new_values FROM finance_audit_log WHERE entity_type='finance_allocation' AND entity_id={$retryId} AND action='allocation_create' LIMIT 1")->fetchColumn();
standaloneCheck(str_contains($auditJson,'STANDALONE_INVOICE'),'audit trail identifies standalone invoice settlement class');

// Negative guard: as soon as ANY current finance_invoice_links row exists, the standalone bypass is disabled.
// The full finance_manual_allocation_obligations_behavioral_test.php separately proves that valid linked invoices split into obligation_id allocations.
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status)
           VALUES('INCOMING','LINKED-GUARD-10','2026-08-27','contractor',50,'Standalone Supplier','7755000005',10,'received')");
$pdo->exec("INSERT INTO finance_invoice_links(invoice_id,obligation_id,amount,side) VALUES(2,999,10,'carrier')");
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status)
           VALUES('2026-08-27','EXPENSE','contractor',50,'Standalone Supplier','7755000005',10,'POSTED')");
standaloneExpectFailure(fn()=>FinanceAllocationService::createAllocation($pdo,6,['amount'=>'10.00','invoice_id'=>2],$userCtx),'linked invoice cannot fall back to standalone obligation_id NULL allocation');
standaloneCheck((int)$pdo->query("SELECT COUNT(*) FROM finance_operation_allocations WHERE invoice_id=2 AND obligation_id IS NULL AND cancelled_at IS NULL")->fetchColumn()===0,'linked invoice never creates obligation_id NULL through standalone path');

echo "Finance standalone invoice allocation behavioral regression PASS\n";
