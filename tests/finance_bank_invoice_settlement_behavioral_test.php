<?php

declare(strict_types=1);

require __DIR__.'/finance_obligations_behavioral_test.php';
require_once __DIR__.'/../app/Service/FinanceAllocationService.php';
require_once __DIR__.'/../app/Service/FinanceBankInvoiceSettlementService.php';
require_once __DIR__.'/../app/Service/FinanceCarrierBankAutoSettlementService.php';
require_once __DIR__.'/../app/Service/FinanceSettlementStateService.php';

use App\Service\FinanceBankInvoiceSettlementService;
use App\Service\FinanceCarrierBankAutoSettlementService;
use App\Service\FinanceObligationService;
use App\Service\FinanceSettlementStateService;

$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$pdo->exec("ALTER TABLE clients ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active', ADD COLUMN deleted_at DATETIME NULL");
$pdo->exec("ALTER TABLE contractors ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active', ADD COLUMN deleted_at DATETIME NULL");
$pdo->exec("ALTER TABLE finance_operations
    ADD COLUMN source VARCHAR(30) NULL,
    ADD COLUMN bank_transaction_id INT UNSIGNED NULL,
    ADD COLUMN counterparty_entity_type VARCHAR(20) NULL,
    ADD COLUMN counterparty_entity_id INT UNSIGNED NULL,
    ADD COLUMN classification_status VARCHAR(20) NOT NULL DEFAULT 'UNALLOCATED',
    ADD COLUMN classification_rule_id INT UNSIGNED NULL,
    ADD COLUMN classification_locked TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN classification_updated_at DATETIME NULL");
$pdo->exec("CREATE TABLE bank_transactions(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_date DATE,
    document_number VARCHAR(100) NULL,
    counterparty_name VARCHAR(500) NULL,
    counterparty_inn VARCHAR(20) NULL,
    purpose TEXT NULL,
    is_internal_transfer TINYINT(1) NOT NULL DEFAULT 0,
    classification_status VARCHAR(20) NOT NULL DEFAULT 'UNALLOCATED',
    classification_rule_id INT UNSIGNED NULL,
    classification_locked TINYINT(1) NOT NULL DEFAULT 0,
    classification_updated_at DATETIME NULL
) ENGINE=InnoDB");

$pdo->exec("INSERT INTO linear_routes VALUES(20,1,2,'2026-12-01','2026-12-02','2026-12-01','2026-12-02',NULL,NULL)");
$pdo->exec("INSERT INTO linear_route_payments(linear_route_id,party_role,sort_order,amount,condition_type,days_count,days_kind,side,created_by_role,updated_by_role) VALUES(20,'customer',1,300,'end_day',0,'calendar','income','company_owner','company_owner'),(20,'carrier',1,250,'end_day',0,'calendar','expense','company_owner','company_owner')");
FinanceObligationService::syncLinearRoute($pdo,20);
$clientOb=(int)$pdo->query("SELECT id FROM finance_obligations WHERE source_parent_id=20 AND direction='RECEIVABLE' AND cancelled_at IS NULL LIMIT 1")->fetchColumn();
$carrierOb=(int)$pdo->query("SELECT id FROM finance_obligations WHERE source_parent_id=20 AND direction='PAYABLE' AND cancelled_at IS NULL LIMIT 1")->fetchColumn();
ok($clientOb>0&&$carrierOb>0,'client and carrier obligations created');

$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('OUTGOING','MAN-OUT-1','2026-12-02','client',1,'Клиент А','7701000001',300,'issued'),('INCOMING','MAN-IN-1','2026-12-02','contractor',2,'Перевозчик Б','7702000002',250,'received')");
$clientInvoice=(int)$pdo->query("SELECT id FROM finance_invoices WHERE number='MAN-OUT-1'")->fetchColumn();
$carrierInvoice=(int)$pdo->query("SELECT id FROM finance_invoices WHERE number='MAN-IN-1'")->fetchColumn();
FinanceObligationService::replaceInvoiceLinks($pdo,$clientInvoice,[['obligation_id'=>$clientOb,'amount'=>'300.00']],['user_id'=>7,'role_code'=>'company_owner']);
FinanceObligationService::replaceInvoiceLinks($pdo,$carrierInvoice,[['obligation_id'=>$carrierOb,'amount'=>'250.00']],['user_id'=>7,'role_code'=>'company_owner']);

$pdo->exec("INSERT INTO bank_transactions(operation_date,document_number,counterparty_name,counterparty_inn,purpose) VALUES('2026-12-03','PAY-1','Клиент А','7701000001','Оплата без номера счета'),('2026-12-04','PAY-2','Перевозчик Б','7702000002','Оплата перевозки')");
$clientTx=(int)$pdo->query("SELECT id FROM bank_transactions WHERE document_number='PAY-1'")->fetchColumn();
$carrierTx=(int)$pdo->query("SELECT id FROM bank_transactions WHERE document_number='PAY-2'")->fetchColumn();
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_inn,purpose,amount,status,source,bank_transaction_id) VALUES('2026-12-03','INCOME','7701000001','Оплата без номера счета',300,'POSTED','BANK_STATEMENT',{$clientTx}),('2026-12-04','EXPENSE','7702000002','Оплата перевозки',250,'POSTED','BANK_STATEMENT',{$carrierTx})");

ok(isset(FinanceSettlementStateService::unallocatedClientReceiptIds($pdo, [])[$clientTx]),'unallocated client receipt is marked for red register row');

$ctx=FinanceBankInvoiceSettlementService::fetchContext($pdo,$clientTx);
ok($ctx!==null,'known incoming client opens settlement context');
ok($ctx['counterparty']['type']==='client','incoming context resolves client');
ok(count($ctx['invoices'])===1&&(int)$ctx['invoices'][0]['id']===$clientInvoice,'client sees only compatible invoice');
$r=FinanceBankInvoiceSettlementService::manualAllocate($pdo,$clientTx,[['invoice_id'=>$clientInvoice,'amount'=>'125.00']],['user_id'=>7,'role_code'=>'company_owner']);
ok($r['allocated_amount']==='125.00'&&$r['remaining_amount']==='175.00','partial manual allocation preserved bank remainder');
ok(isset(FinanceSettlementStateService::unallocatedClientReceiptIds($pdo, [])[$clientTx]),'partially allocated client receipt stays red');
ok((string)$pdo->query("SELECT paid_amount FROM finance_invoices WHERE id={$clientInvoice}")->fetchColumn()==='125.00','partial allocation updates invoice');
FinanceObligationService::syncLinearRoute($pdo,20);
ok((string)$pdo->query("SELECT paid_amount FROM finance_obligations WHERE id={$clientOb}")->fetchColumn()==='125.00','partial allocation updates obligation');
$r=FinanceBankInvoiceSettlementService::manualAllocate($pdo,$clientTx,[['invoice_id'=>$clientInvoice,'amount'=>'175.00']],['user_id'=>7,'role_code'=>'company_owner']);
ok($r['remaining_amount']==='0.00','second manual allocation closes bank operation');
ok((string)$pdo->query("SELECT status FROM finance_invoices WHERE id={$clientInvoice}")->fetchColumn()==='paid','client invoice becomes paid');
FinanceObligationService::syncLinearRoute($pdo,20);
ok((string)$pdo->query("SELECT status FROM finance_obligations WHERE id={$clientOb}")->fetchColumn()==='paid','client obligation becomes paid');

$effective=FinanceSettlementStateService::effectiveBankStatuses($pdo);
ok(($effective[$clientTx]??null)==='MANUAL','fully manually allocated bank payment has effective manual status');
ok(!isset(FinanceSettlementStateService::unallocatedClientReceiptIds($pdo, $effective)[$clientTx]),'fully allocated client receipt leaves red register rows');
$sync=FinanceSettlementStateService::syncPersistedStatuses($pdo);
ok($sync['transactions']>=1,'settlement sync finds completed invoice payments');
ok((string)$pdo->query("SELECT classification_status FROM bank_transactions WHERE id={$clientTx}")->fetchColumn()==='MANUAL','manual invoice settlement persists bank status');
ok((int)$pdo->query("SELECT classification_locked FROM bank_transactions WHERE id={$clientTx}")->fetchColumn()===1,'manual invoice settlement is protected');

$ctx=FinanceBankInvoiceSettlementService::fetchContext($pdo,$carrierTx);
ok($ctx!==null&&$ctx['counterparty']['type']==='contractor','expense resolves carrier');
ok(count($ctx['invoices'])===1&&(int)$ctx['invoices'][0]['id']===$carrierInvoice,'carrier sees incoming invoice');
$r=FinanceBankInvoiceSettlementService::manualAllocate($pdo,$carrierTx,[['invoice_id'=>$carrierInvoice,'amount'=>'250.00']],['user_id'=>7,'role_code'=>'company_owner']);
ok($r['remaining_amount']==='0.00','carrier payment fully allocated');
ok((string)$pdo->query("SELECT status FROM finance_invoices WHERE id={$carrierInvoice}")->fetchColumn()==='paid','carrier invoice becomes paid');
FinanceObligationService::syncLinearRoute($pdo,20);
ok((string)$pdo->query("SELECT status FROM finance_obligations WHERE id={$carrierOb}")->fetchColumn()==='paid','carrier obligation becomes paid');
FinanceSettlementStateService::syncPersistedStatuses($pdo);
ok((string)$pdo->query("SELECT classification_status FROM bank_transactions WHERE id={$carrierTx}")->fetchColumn()==='MANUAL','manual carrier settlement persists bank status');

// Payable-side automatic regression: one 145 payment references a 195 incoming
// invoice with two obligations (58.5 + 136.5). It must allocate 58.5 + 86.5,
// leave 50 outstanding, and mark the bank transaction AUTO after state sync.
$pdo->exec("INSERT INTO linear_routes VALUES(21,1,2,'2026-12-10','2026-12-11','2026-12-10','2026-12-11',NULL,NULL)");
$pdo->exec("INSERT INTO linear_route_payments(linear_route_id,party_role,sort_order,amount,condition_type,days_count,days_kind,side,created_by_role,updated_by_role) VALUES(21,'carrier',1,58.50,'end_day',0,'calendar','expense','company_owner','company_owner'),(21,'carrier',2,136.50,'end_day',2,'calendar','expense','company_owner','company_owner')");
FinanceObligationService::syncLinearRoute($pdo,21);
$carrierObs=$pdo->query("SELECT id FROM finance_obligations WHERE source_parent_id=21 AND direction='PAYABLE' AND cancelled_at IS NULL ORDER BY due_date,id")->fetchAll(PDO::FETCH_COLUMN);
ok(count($carrierObs)===2,'two payable obligations created for partial auto test');
$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('INCOMING','№26/3947','2026-12-10','contractor',2,'Перевозчик Б','7702000002',195,'received')");
$partialCarrierInvoice=(int)$pdo->lastInsertId();
FinanceObligationService::replaceInvoiceLinks($pdo,$partialCarrierInvoice,[
    ['obligation_id'=>(int)$carrierObs[0],'amount'=>'58.50'],
    ['obligation_id'=>(int)$carrierObs[1],'amount'=>'136.50'],
],['user_id'=>7,'role_code'=>'company_owner']);
$pdo->exec("INSERT INTO bank_transactions(operation_date,document_number,counterparty_name,counterparty_inn,purpose) VALUES('2026-12-12','PAY-PARTIAL-CARRIER','Перевозчик Б','7702000002','Частичная оплата по счету №26/3947 за перевозку')");
$partialCarrierTx=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_inn,purpose,amount,status,source,bank_transaction_id) VALUES('2026-12-12','EXPENSE','7702000002','Частичная оплата по счету №26/3947 за перевозку',145,'POSTED','BANK_STATEMENT',{$partialCarrierTx})");
$partialCarrierOp=(int)$pdo->lastInsertId();
$autoCarrier=FinanceCarrierBankAutoSettlementService::autoAllocateOutgoingCarrierPayments($pdo,['user_id'=>7,'role_code'=>'company_owner']);
ok($autoCarrier['operations']===1,'one carrier bank payment auto matched');
ok($autoCarrier['allocations']===2,'partial carrier bank payment split across two obligations');
ok($autoCarrier['amount']==='145.00','partial carrier bank amount allocated exactly');
$parts=$pdo->query("SELECT amount FROM finance_operation_allocations WHERE operation_id={$partialCarrierOp} AND invoice_id={$partialCarrierInvoice} AND cancelled_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
ok($parts===['58.50','86.50'],'145 payment allocated 58.50 + 86.50');
ok((string)$pdo->query("SELECT paid_amount FROM finance_invoices WHERE id={$partialCarrierInvoice}")->fetchColumn()==='145.00','incoming invoice paid amount becomes 145');
ok((string)$pdo->query("SELECT status FROM finance_invoices WHERE id={$partialCarrierInvoice}")->fetchColumn()==='partially_paid','incoming invoice becomes partially paid');
FinanceObligationService::syncLinearRoute($pdo,21);
ok((string)$pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=".(int)$carrierObs[0])->fetchColumn()==='58.50','first payable obligation closed by partial payment');
ok((string)$pdo->query("SELECT paid_amount FROM finance_obligations WHERE id=".(int)$carrierObs[1])->fetchColumn()==='86.50','second payable obligation partially paid');
FinanceSettlementStateService::syncPersistedStatuses($pdo);
ok((string)$pdo->query("SELECT classification_status FROM bank_transactions WHERE id={$partialCarrierTx}")->fetchColumn()==='AUTO','fully allocated carrier bank transaction becomes AUTO');

$pdo->exec("INSERT INTO finance_invoices(direction,number,invoice_date,counterparty_entity_type,counterparty_entity_id,counterparty_name,counterparty_inn,amount,status) VALUES('OUTGOING','AUTO-STALE-1','2026-12-06','client',1,'Клиент А','7701000001',90,'issued')");
$autoInvoice=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO bank_transactions(operation_date,document_number,counterparty_name,counterparty_inn,purpose) VALUES('2026-12-06','PAY-AUTO','Клиент А','7701000001','Оплата счета AUTO-STALE-1')");
$autoTx=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_inn,purpose,amount,status,source,bank_transaction_id) VALUES('2026-12-06','INCOME','7701000001','Оплата счета AUTO-STALE-1',90,'POSTED','BANK_STATEMENT',{$autoTx})");
$autoOp=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO finance_operation_allocations(operation_id,invoice_id,amount,allocation_date,method,comment,created_by_role) VALUES({$autoOp},{$autoInvoice},90,'2026-12-06','auto_exact','Автоматическая оплата','system')");
$effective=FinanceSettlementStateService::effectiveBankStatuses($pdo);
ok(($effective[$autoTx]??null)==='AUTO','stale automatic invoice allocation is derived as auto');
FinanceSettlementStateService::syncPersistedStatuses($pdo);
ok((string)$pdo->query("SELECT classification_status FROM bank_transactions WHERE id={$autoTx}")->fetchColumn()==='AUTO','automatic invoice settlement persists bank status');
ok((string)$pdo->query("SELECT status FROM finance_invoices WHERE id={$autoInvoice}")->fetchColumn()==='paid','stale paid invoice status is repaired from allocation');

$pdo->exec("INSERT INTO bank_transactions(operation_date,counterparty_name,counterparty_inn,purpose) VALUES('2026-12-05','Неизвестный','9999999999','Прочий платеж')");
$unknownTx=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO finance_operations(operation_date,operation_type,counterparty_inn,purpose,amount,status,source,bank_transaction_id) VALUES('2026-12-05','INCOME','9999999999','Прочий платеж',10,'POSTED','BANK_STATEMENT',{$unknownTx})");
ok(FinanceBankInvoiceSettlementService::fetchContext($pdo,$unknownTx)===null,'unknown counterparty falls back to generic classification');

echo "FINANCE_BANK_INVOICE_SETTLEMENT_BEHAVIOR_OK\n";
