<?php
namespace App\Service {
    require_once __DIR__ . '/../app/Service/BankFinanceService.php';
    require_once __DIR__ . '/../app/Service/FinanceMatchingRuleRegisterTrait.php';
    require_once __DIR__ . '/../app/Service/FinanceEmployeeCompanyFundedExpenseService.php';

    if (!class_exists(__NAMESPACE__ . '\\FinanceDdsCategoryService', false)) {
        final class FinanceDdsCategoryService { public static function fetchCategories(\PDO $pdo): array { return []; } }
    }
    final class BankRegisterFilterHarness {
        use FinanceMatchingRuleRegisterTrait;
        public static function fetchCashFlowCenters(\PDO $pdo, bool $activeOnly = false): array { return []; }
        public static function lower(string $value): string { return mb_strtolower($value, 'UTF-8'); }
    }
}

namespace {
use App\Service\BankRegisterFilterHarness as R;

function assertTrue(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}

$pdo = new PDO(
    'mysql:host=' . (getenv('EMPLOYEE_PAYMENTS_DB_HOST') ?: getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('EMPLOYEE_PAYMENTS_DB_PORT') ?: getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306') . ';dbname=' . (getenv('EMPLOYEE_PAYMENTS_DB_NAME') ?: getenv('PLANEX_TEST_FIXED_DB') ?: 'erp_employee_payments_test') . ';charset=utf8mb4',
    getenv('EMPLOYEE_PAYMENTS_DB_USER') ?: getenv('PLANEX_SCHEMA_DB_USER') ?: 'root',
    getenv('EMPLOYEE_PAYMENTS_DB_PASSWORD') ?: getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('DROP TABLE IF EXISTS finance_employee_movements');
$pdo->exec('DROP TABLE IF EXISTS finance_operations');
$pdo->exec('DROP TABLE IF EXISTS bank_transactions');
$pdo->exec('DROP TABLE IF EXISTS bank_accounts');
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE bank_accounts(id INT UNSIGNED PRIMARY KEY, account_number VARCHAR(64) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE bank_transactions(
 id INT UNSIGNED PRIMARY KEY, account_id INT UNSIGNED NOT NULL, operation_date DATE NOT NULL,
 document_number VARCHAR(100) NULL, operation_type VARCHAR(50) NULL, counterparty_name VARCHAR(255) NULL,
 counterparty_inn VARCHAR(20) NULL, counterparty_bank_bik VARCHAR(20) NULL, counterparty_account VARCHAR(64) NULL,
 debit_amount DECIMAL(15,2) NOT NULL DEFAULT 0, credit_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
 purpose TEXT NULL, classification_status VARCHAR(30) NULL, cash_flow_center_id INT UNSIGNED NULL, dds_category_id INT UNSIGNED NULL, is_internal_transfer TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB");
$pdo->exec("INSERT INTO bank_accounts(id,account_number) VALUES(1,'40702810000000000001')");
$pdo->exec("INSERT INTO bank_transactions(id,account_id,operation_date,document_number,counterparty_name,counterparty_inn,debit_amount,credit_amount,purpose,classification_status) VALUES
 (1,1,'2026-08-10','101','АЛЬФА','7701000001',100,0,'Оплата аренды','AUTO'),
 (2,1,'2026-08-11','102','БЕТА','7702000002',200,0,'Покупка топлива','UNALLOCATED'),
 (3,1,'2026-08-12','103','ГАММА','7703000003',0,300,'Возврат средств','MANUAL'),
 (4,1,'2026-08-13','104','ДЕЛЬТА','7704000004',400,0,'Комиссия банка','NEEDS_REVIEW')");

$r = R::fetchBankRegister($pdo, ['date_from'=>'', 'date_to'=>'', 'classification_status'=>'UNALLOCATED', 'page'=>1, 'per_page'=>100]);
assertTrue($r['total'] === 1 && (int)$r['data'][0]['id'] === 2, 'empty dates must not break status filter');

$r = R::fetchBankRegister($pdo, ['date_from'=>'', 'date_to'=>'', 'search'=>'топлива', 'page'=>1, 'per_page'=>100]);
assertTrue($r['total'] === 1 && (int)$r['data'][0]['id'] === 2, 'empty dates must not break search filter');

$r = R::fetchBankRegister($pdo, ['date_from'=>'2026-08-11', 'date_to'=>'2026-08-12', 'page'=>1, 'per_page'=>100]);
assertTrue($r['total'] === 2, 'date range must filter inclusively');
assertTrue(array_map('intval', array_column($r['data'], 'id')) === [3,2], 'date range order/result');

$r = R::fetchBankRegister($pdo, ['date_from'=>'2026-08-10', 'date_to'=>'2026-08-13', 'search'=>'7704', 'classification_status'=>'NEEDS_REVIEW', 'page'=>9, 'per_page'=>100]);
assertTrue($r['total'] === 1 && $r['page'] === 1 && (int)$r['data'][0]['id'] === 4, 'combined filters and stale page reset');

// An attribution made before this release must be visible without rewriting the bank fact.
$pdo->exec("CREATE TABLE finance_operations(id INT PRIMARY KEY, bank_transaction_id INT, amount DECIMAL(15,2), status VARCHAR(20), operation_type VARCHAR(20), source VARCHAR(30)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_employee_movements(id INT PRIMARY KEY, finance_operation_id INT, bank_transaction_id INT NULL, source_type VARCHAR(20), movement_type VARCHAR(20), employee_identity_type VARCHAR(20), employee_identity_id INT, employee_name_snapshot VARCHAR(255)) ENGINE=InnoDB");
$pdo->exec("UPDATE bank_transactions SET debit_amount=50000,counterparty_name='ООО СТПРАВО' WHERE id=2");
$pdo->exec("INSERT INTO finance_operations VALUES(20,2,50000,'POSTED','EXPENSE','BANK_STATEMENT')");
$pdo->exec("INSERT INTO finance_employee_movements VALUES(30,20,NULL,'COMPANY_FUNDED','PAYMENT','COMPANY_USER',100,'Спугов Владимир')");
$bankBefore=$pdo->query('SELECT * FROM bank_transactions WHERE id=2')->fetch();
$operationBefore=$pdo->query('SELECT * FROM finance_operations WHERE id=20')->fetch();
$r=R::fetchBankRegister($pdo,['classification_status'=>'UNALLOCATED']);
assertTrue($r['total']===0,'historical employee attribution no longer appears unallocated');
$r=R::fetchBankRegister($pdo,['classification_status'=>'MANUAL','search'=>'Спугов','per_page'=>1,'page'=>9]);
assertTrue($r['total']===1&&$r['page']===1&&(int)$r['data'][0]['id']===2,'employee search, effective status and pagination agree');
$attribution=$r['data'][0]['company_funded_attribution'];
assertTrue($attribution['employee_ref']==='COMPANY_USER:100'&&$attribution['amount']==='50000.00','register resolves exact employee and full amount');
assertTrue($bankBefore===$pdo->query('SELECT * FROM bank_transactions WHERE id=2')->fetch()&&$operationBefore===$pdo->query('SELECT * FROM finance_operations WHERE id=20')->fetch(),'register never rewrites source bank or money fact');
$pdo->exec("UPDATE finance_operations SET status='CANCELLED' WHERE id=20");
assertTrue(R::fetchBankRegister($pdo,['classification_status'=>'UNALLOCATED'])['total']===1,'cancelled operation cannot imply allocated status');
$pdo->exec("UPDATE finance_operations SET status='POSTED' WHERE id=20");
$pdo->exec('DELETE FROM finance_employee_movements WHERE id=30');
assertTrue(R::fetchBankRegister($pdo,['classification_status'=>'UNALLOCATED'])['total']===1,'unmark immediately restores underlying status');
assertTrue(R::fetchBankRegister($pdo,['search'=>'Спугов'])['total']===0,'unmark removes employee search attribution');

$wrapper = file_get_contents(__DIR__ . '/../app/Http/Controllers/Company/BankFinanceActions/indexAutoFilters.php');
$register = file_get_contents(__DIR__ . '/../app/Service/FinanceMatchingRuleRegisterTrait.php');
assertTrue(str_contains($register, 'unallocatedClientReceiptIds') && str_contains($register, "\$tx['classification_status']='UNALLOCATED'"), 'unsettled client receipts override generic classification for red display');
assertTrue(str_contains($wrapper, "'classification_status'"), 'status auto-filter hook');
assertTrue(str_contains($wrapper, "'[name=\"q\"]'"), 'search auto-filter hook');
assertTrue(str_contains($wrapper, 'setTimeout(submitNow,350)'), 'search debounce');
assertTrue(str_contains($wrapper, "textContent||'').trim()==='Применить'"), 'Apply button removal');
assertTrue(str_contains($wrapper, '.bank-transactions-table th:nth-child(2)'), 'account column hidden');
assertTrue(str_contains($wrapper, 'width:38%'), 'purpose column widened');
assertTrue(str_contains($wrapper, '.bank-transactions-table th:nth-child(10)'), 'status column hidden before DOM cleanup');
assertTrue(str_contains($wrapper, "(th.textContent||'').trim()==='Статус'"), 'status header located by semantic label');
assertTrue(str_contains($wrapper, 'statusCell.remove()') && str_contains($wrapper, 'statusHeader.remove()'), 'status column removed from DOM');
assertTrue(str_contains($wrapper, 'row.dataset.classificationStatus=status'), 'classification source preserved on row after status column removal');
assertTrue(str_contains($wrapper, 'bank-row-unallocated') && str_contains($wrapper, '#fff0ed'), 'unallocated/review rows use light red background');
assertTrue(str_contains($wrapper, 'bank-row-manual') && str_contains($wrapper, '#eff8ed'), 'manual rows use light green background');
assertTrue(str_contains($wrapper, 'bank-row-auto') && str_contains($wrapper, 'background:#fff!important'), 'automatic rows use white background');
assertTrue(str_contains($wrapper, "if(text.indexOf('вручную')!==-1) return 'MANUAL'"), 'manual classification maps to manual row state');
assertTrue(str_contains($wrapper, "if(text.indexOf('автоматически')!==-1) return 'AUTO'"), 'automatic classification maps to auto row state');
assertTrue(str_contains($wrapper, "if(text.indexOf('не разнесено')!==-1) return 'UNALLOCATED'"), 'unallocated classification maps to warning row state');
assertTrue(str_contains($wrapper, "return 'NEEDS_REVIEW'"), 'conflict/unknown classification remains warning state');

echo "FINANCE_BANK_REGISTER_FILTERS_OK\n";
}
