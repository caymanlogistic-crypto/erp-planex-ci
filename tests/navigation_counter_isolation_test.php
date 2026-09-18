<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Core/Database.php';
require_once BASE_PATH . '/app/Service/NavigationCounterService.php';

$runnerDb = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
if ($runnerDb !== '') {
    $host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
    $port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
    $user = getenv('PLANEX_SCHEMA_DB_USER') ?: '';
    $password = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: '';
    $dbName = $runnerDb;
    $allowlist = array_values(array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')))));
    if (!in_array($dbName, $allowlist, true) || (string)getenv('APP_ENV') !== 'test') {
        throw new RuntimeException('Guarded fixed runner DB is required.');
    }
} else {
    $host = getenv('EMPLOYEE_PAYMENTS_DB_HOST') ?: '';
    $port = (int)(getenv('EMPLOYEE_PAYMENTS_DB_PORT') ?: 0);
    $user = getenv('EMPLOYEE_PAYMENTS_DB_USER') ?: '';
    $password = getenv('EMPLOYEE_PAYMENTS_DB_PASSWORD') ?: '';
    $dbName = trim((string)getenv('EMPLOYEE_PAYMENTS_DB_NAME'));
    if ($host === '' || $port <= 0 || $user === '' || $dbName !== 'erp_employee_payments_test') {
        throw new RuntimeException('Explicit isolated finance CI database is required.');
    }
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
);
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE bank_transactions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        classification_status VARCHAR(32) NOT NULL,
        is_internal_transfer TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO bank_transactions (classification_status,is_internal_transfer) VALUES
        ('UNALLOCATED',0),('NEEDS_REVIEW',0),('CLASSIFIED',0),('UNALLOCATED',1)");

    $pdo->exec("CREATE TABLE finance_money_accounts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(16) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        name VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO finance_money_accounts (id,type,is_active,name) VALUES (1,'CASH',1,'Основная касса')");

    $pdo->exec("CREATE TABLE finance_operations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        money_account_id INT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL,
        operation_type VARCHAR(16) NOT NULL,
        transfer_direction VARCHAR(8) DEFAULT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO finance_operations (money_account_id,status,operation_type,transfer_direction)
        VALUES (1,'POSTED','INCOME',NULL)");

    // Deliberately omit finance_cash_resolutions. Cash counter must fail in isolation
    // without suppressing the unrelated bank attention counter.
    $config = ['database'=>[
        'host'=>$host,'port'=>$port,'database'=>$dbName,
        'username'=>$user,'password'=>$password,'charset'=>'utf8mb4',
    ]];
    $company = ['id'=>77,'status'=>'active','db_identifier'=>$dbName];
    $centralDb = new App\Core\Database($config['database']);
    $counters = App\Service\NavigationCounterService::forCompany($config, $centralDb, 77, $company);

    if (($counters['bank_attention'] ?? null) !== 2) {
        throw new RuntimeException('Bank attention badge was suppressed by unrelated missing cash schema.');
    }
    if (($counters['cash_attention'] ?? null) !== 0) {
        throw new RuntimeException('Cash attention must fail closed when finance_cash_resolutions is absent.');
    }

    $pdo->exec('ALTER TABLE bank_transactions ADD debit_amount DECIMAL(15,2) DEFAULT 0, ADD credit_amount DECIMAL(15,2) DEFAULT 0');
    $pdo->exec("ALTER TABLE finance_operations ADD bank_transaction_id INT NULL, ADD amount DECIMAL(15,2) DEFAULT 0, ADD source VARCHAR(30)");
    $pdo->exec("CREATE TABLE finance_employee_movements(id INT PRIMARY KEY, finance_operation_id INT, bank_transaction_id INT NULL, source_type VARCHAR(20), movement_type VARCHAR(20), employee_identity_type VARCHAR(20), employee_identity_id INT, employee_name_snapshot VARCHAR(255))");
    $pdo->exec("UPDATE bank_transactions SET debit_amount=50000 WHERE id=1");
    $pdo->exec("INSERT INTO finance_operations(id,money_account_id,status,operation_type,bank_transaction_id,amount,source) VALUES(2,1,'POSTED','EXPENSE',1,50000,'BANK_STATEMENT')");
    $pdo->exec("INSERT INTO finance_employee_movements VALUES(1,2,NULL,'COMPANY_FUNDED','PAYMENT','COMPANY_USER',100,'Спугов')");
    $company['id']=78;
    $attributedCounters=App\Service\NavigationCounterService::forCompany($config,$centralDb,78,$company);
    if ($attributedCounters['bank_attention']!==1) throw new RuntimeException('Employee-attributed bank payment must not remain in attention badge.');

    echo "NAVIGATION_COUNTER_ISOLATION_OK bank=2 cash=0\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
