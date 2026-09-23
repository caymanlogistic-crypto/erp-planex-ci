<?php

require_once __DIR__.'/../app/Service/FinanceAuditLogService.php';
require_once __DIR__.'/../app/Service/FinanceCashService.php';
require_once __DIR__.'/../app/Service/FinanceEmployeePaymentService.php';
require_once __DIR__.'/../app/Service/FinanceCashResolutionService.php';

use App\Service\FinanceCashResolutionService as R;
use App\Service\FinanceEmployeePaymentService as E;
use App\Service\FinanceCashService as C;

function crOk(bool $v,string $m):void{if(!$v)throw new RuntimeException('FAIL: '.$m);}
function crThrows(callable $fn,string $needle,string $m):void{try{$fn();}catch(Throwable $e){crOk(str_contains($e->getMessage(),$needle),$m.' / '.$e->getMessage());return;}throw new RuntimeException('FAIL: '.$m.' / exception expected');}

$pdo=new PDO('mysql:host='.(getenv('EMPLOYEE_PAYMENTS_DB_HOST')?:'127.0.0.1').';port='.(getenv('EMPLOYEE_PAYMENTS_DB_PORT')?:'3306').';dbname='.(getenv('EMPLOYEE_PAYMENTS_DB_NAME')?:'erp_employee_payments_test').';charset=utf8mb4',getenv('EMPLOYEE_PAYMENTS_DB_USER')?:'root',getenv('EMPLOYEE_PAYMENTS_DB_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');foreach(['finance_cash_resolutions','finance_employee_movements','finance_audit_log','finance_operations','bank_transactions','bank_accounts','finance_money_accounts','company_users','users'] as $t)$pdo->exec("DROP TABLE IF EXISTS `$t`");$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE company_users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id INT UNSIGNED NOT NULL,full_name VARCHAR(255) NOT NULL,login VARCHAR(100) NOT NULL,role VARCHAR(50) NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE users(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,full_name VARCHAR(255) NOT NULL,login VARCHAR(255) NOT NULL,role_code VARCHAR(30) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_money_accounts(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,type VARCHAR(20) NOT NULL,name VARCHAR(255) NOT NULL,bank_account_id INT UNSIGNED NULL,currency VARCHAR(10) NOT NULL DEFAULT 'RUR',opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0.00,opening_balance_date DATE NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_by_user_id INT UNSIGNED NULL,created_by_role VARCHAR(20) NULL,updated_by_user_id INT UNSIGNED NULL,updated_by_role VARCHAR(20) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE bank_accounts(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_number VARCHAR(64) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE bank_transactions(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account_id INT UNSIGNED NOT NULL,operation_date DATE NOT NULL,debit_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,credit_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,is_internal_transfer TINYINT(1) NOT NULL DEFAULT 0,linked_cash_transaction_id INT UNSIGNED NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_operations(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,operation_type VARCHAR(20) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'POSTED',source VARCHAR(30) NOT NULL,money_account_id INT UNSIGNED NOT NULL,transfer_account_id INT UNSIGNED NULL,transfer_group_id VARCHAR(100) NULL,related_operation_id INT UNSIGNED NULL,transfer_direction VARCHAR(10) NULL,operation_date DATE NOT NULL,amount DECIMAL(15,2) NOT NULL,currency VARCHAR(10) NOT NULL DEFAULT 'RUR',dds_category_id INT UNSIGNED NULL,purpose TEXT NULL,comment TEXT NULL,bank_transaction_id INT UNSIGNED NULL,classification_rule_id INT UNSIGNED NULL,created_by_user_id INT UNSIGNED NULL,created_by_role VARCHAR(20) NULL,posted_by_user_id INT UNSIGNED NULL,posted_by_role VARCHAR(20) NULL,posted_at DATETIME NULL,cancelled_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_fop_bank_tx(bank_transaction_id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_employee_movements(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,employee_user_id INT UNSIGNED NULL,employee_identity_type VARCHAR(20) NOT NULL DEFAULT 'TENANT_USER',employee_identity_id INT UNSIGNED NULL,employee_name_snapshot VARCHAR(255) NULL,employee_role_snapshot VARCHAR(50) NULL,movement_type VARCHAR(20) NOT NULL,source_type VARCHAR(20) NOT NULL,finance_operation_id INT UNSIGNED NOT NULL,bank_transaction_id INT UNSIGNED NULL,note VARCHAR(1000) NULL,created_by_user_id INT UNSIGNED NULL,created_by_role VARCHAR(20) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_fem_finance_operation(finance_operation_id),UNIQUE KEY uk_fem_bank_transaction(bank_transaction_id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_cash_resolutions(id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_finance_operation_id INT UNSIGNED NOT NULL,resolution_type VARCHAR(32) NOT NULL,target_identity_type VARCHAR(32) NULL,target_identity_id INT UNSIGNED NULL,target_name_snapshot VARCHAR(255) NULL,outflow_finance_operation_id INT UNSIGNED NOT NULL,employee_movement_id INT UNSIGNED NULL,created_by_user_id INT UNSIGNED NULL,created_by_role VARCHAR(20) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uk_fcr_source_operation(source_finance_operation_id),UNIQUE KEY uk_fcr_outflow_operation(outflow_finance_operation_id),UNIQUE KEY uk_fcr_employee_movement(employee_movement_id)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE finance_audit_log(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(64) NOT NULL,entity_id INT UNSIGNED NOT NULL,action VARCHAR(64) NOT NULL,old_values JSON NULL,new_values JSON NULL,created_by_user_id INT UNSIGNED NOT NULL,created_by_role VARCHAR(64) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");

$pdo->exec("INSERT INTO company_users(id,company_id,full_name,login,role,status) VALUES(100,1,'Руководитель','owner','company_owner','active'),(101,2,'Чужой','foreign','company_owner','active')");
$pdo->exec("INSERT INTO users(id,full_name,login,role_code,status,deleted_at) VALUES(2,'Логист','logist','logist','active',NULL)");
$pdo->exec("INSERT INTO finance_money_accounts(id,type,name,currency,opening_balance,is_active) VALUES(1,'CASH','Основная касса','RUR',0.00,1),(2,'BANK','Расчётный счёт 4070','RUR',0.00,1),(3,'CASH','Резервная касса','RUR',0.00,1)");
$pdo->exec("INSERT INTO finance_operations(id,operation_type,status,source,money_account_id,transfer_account_id,transfer_direction,operation_date,amount,currency,purpose,posted_at) VALUES
(10,'TRANSFER','POSTED','TRANSFER',1,2,'in','2026-08-13',100.10,'RUR','Источник A',NOW()),
(11,'TRANSFER','POSTED','TRANSFER',1,2,'in','2026-08-14',50.20,'RUR','Источник B',NOW()),
(12,'INCOME','POSTED','CASH',3,NULL,NULL,'2026-08-14',9.99,'RUR','Не основная касса',NOW())");

$actor=['id'=>100,'role'=>'company_owner'];
$before=R::unresolvedSummary($pdo);crOk($before['count']===2,'two unresolved Main Cash sources');crOk($before['amount']==='150.30','unresolved total exact');

$ownerResult=R::dispatchToEmployee($pdo,$pdo,1,[10],'COMPANY_USER:100',$actor);
crOk($ownerResult['count']===1 && $ownerResult['total']==='100.10','central owner dispatch exact');
crOk((int)$pdo->query("SELECT COUNT(*) FROM finance_cash_resolutions WHERE source_finance_operation_id=10 AND resolution_type='EMPLOYEE'")->fetchColumn()===1,'source trace stored');
crOk((int)$pdo->query("SELECT COUNT(*) FROM finance_employee_movements WHERE employee_identity_type='COMPANY_USER' AND employee_identity_id=100 AND source_type='CASH'")->fetchColumn()===1,'central employee settlement created');
crOk(R::unresolvedCount($pdo)===1,'badge count decremented after one resolution');
crOk(C::getCashAccountBalance($pdo,1)==='50.20','Main Cash balance equals remaining unresolved source');
crThrows(fn()=>R::dispatchToEmployee($pdo,$pdo,1,[10],'TENANT_USER:2',$actor),'уже разнесена','same source is idempotently rejected');

$tenantResult=R::dispatchToEmployee($pdo,$pdo,1,[11],'TENANT_USER:2',$actor);
crOk($tenantResult['total']==='50.20','tenant employee dispatch exact');
crOk(R::unresolvedCount($pdo)===0,'all Main Cash positions resolved');
crOk(C::getCashAccountBalance($pdo,1)==='0.00','technical Main Cash returns to zero');
crOk((int)$pdo->query("SELECT COUNT(*) FROM finance_employee_movements WHERE employee_identity_type='TENANT_USER' AND employee_identity_id=2")->fetchColumn()===1,'tenant employee settlement created');

$pdo->exec("INSERT INTO finance_operations(id,operation_type,status,source,money_account_id,transfer_account_id,transfer_direction,operation_date,amount,currency,purpose,posted_at) VALUES(20,'INCOME','POSTED','CASH',1,NULL,NULL,'2026-08-15',20.00,'RUR','Источник C',NOW()),(21,'INCOME','POSTED','CASH',3,NULL,NULL,'2026-08-15',5.00,'RUR','Резерв',NOW())");
$beforeMovements=(int)$pdo->query('SELECT COUNT(*) FROM finance_employee_movements')->fetchColumn();
crThrows(fn()=>R::dispatchToEmployee($pdo,$pdo,1,[20,21],'TENANT_USER:2',$actor),'не относится','mixed invalid batch rejected');
crOk((int)$pdo->query('SELECT COUNT(*) FROM finance_employee_movements')->fetchColumn()===$beforeMovements,'invalid batch is atomic');
crOk((int)$pdo->query('SELECT COUNT(*) FROM finance_cash_resolutions WHERE source_finance_operation_id=20')->fetchColumn()===0,'invalid batch leaves source unresolved');
crThrows(fn()=>R::dispatchToEmployee($pdo,$pdo,1,[20],'COMPANY_USER:101',$actor),'неактивен','foreign company employee rejected');
crOk((int)$pdo->query('SELECT COUNT(*) FROM finance_cash_resolutions WHERE source_finance_operation_id=20')->fetchColumn()===0,'cross-company attempt does not mutate');

$view=file_get_contents(__DIR__.'/../app/View/pages/company_finance_cash.php');
crOk(str_contains($view,'cash-select-all'),'select all exists');
crOk(str_contains($view,'Передать сотруднику'),'employee action exists');
crOk(str_contains($view,'cash-dispatch-carrier-btn') && str_contains($view,'disabled'),'carrier action disabled');
$routes=file_get_contents(__DIR__.'/../app/Http/Routes/company_finance_cash.php');crOk(str_contains($routes,'dispatch-employee'),'dispatch route registered');
$layout=file_get_contents(__DIR__.'/../app/View/layouts/main.php');crOk(str_contains($layout,"'cash_attention' => 0") && str_contains($layout,'$navigationBadgeCount'),'sidebar cash badge wired through registry');

$rows=$pdo->query("SELECT source_finance_operation_id,outflow_finance_operation_id,employee_movement_id FROM finance_cash_resolutions ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $row){crOk((int)$row['source_finance_operation_id']>0 && (int)$row['outflow_finance_operation_id']>0 && (int)$row['employee_movement_id']>0,'resolution keeps complete provenance');}

echo "FINANCE_CASH_RESOLUTION_OK\n";
