<?php

declare(strict_types=1);

$schema = (string)getenv('PLANEX_TEST_FIXED_DB');
if (!preg_match('/^planex_t_[a-f0-9]{24}$/', $schema) || (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST') !== $schema) throw new RuntimeException('Disposable schema required');
$host = (string)getenv('PLANEX_SCHEMA_DB_HOST');
$port = (int)getenv('PLANEX_SCHEMA_DB_PORT');
$user = (string)getenv('PLANEX_SCHEMA_DB_USER');
$password = (string)getenv('PLANEX_SCHEMA_DB_PASSWORD');
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$schema;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE companies (id INT PRIMARY KEY,status VARCHAR(20),db_identifier VARCHAR(100),db_host VARCHAR(100),db_port INT,db_username VARCHAR(100),db_password VARCHAR(100))");
$pdo->prepare("INSERT INTO companies VALUES (1,'active',?,?,?,?,?)")->execute([$schema, $host, $port, $user, $password]);
$migration = file_get_contents(__DIR__ . '/../database/migrations-local/042_create_bank_finance.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS `([^`]+)`\s*\(.*?\) ENGINE=InnoDB.*?;/s', $migration, $matches, PREG_SET_ORDER);
foreach ($matches as $match) if (in_array($match[1], ['bank_accounts', 'bank_transactions', 'bank_daily_balances'], true)) $pdo->exec($match[0]);
$pdo->exec('CREATE TABLE finance_money_accounts (id INT PRIMARY KEY,bank_account_id INT)');
$pdo->exec('CREATE TABLE finance_operations (id INT PRIMARY KEY,money_account_id INT,status VARCHAR(20),operation_date DATE,cancelled_at DATETIME,amount DECIMAL(15,2),operation_type VARCHAR(20),transfer_direction VARCHAR(10))');
$pdo->exec("INSERT INTO bank_accounts (id,account_number,bank_name,currency,opening_balance,closing_balance) VALUES (1,'40702810500810141833','VTB','RUR',832804.03,226464.03)");
$pdo->exec("INSERT INTO bank_daily_balances (id,account_id,statement_date,currency,opening_balance,debit_turnover,credit_turnover,closing_balance,dedupe_hash) VALUES (1746,1,'2026-10-13','RUR',832804.03,316340.00,0.00,516464.03,REPEAT('a',64)),(1747,1,'2026-10-14','RUR',832804.03,606340.00,0.00,226464.03,REPEAT('b',64))");
$q = $pdo->prepare('INSERT INTO bank_transactions (account_id,operation_date,debit_amount,credit_amount,dedupe_hash) VALUES (1,?,?,0.00,?)');
foreach ([['2026-10-13',40],['2026-10-13',1300],['2026-10-13',65000],['2026-10-13',250000],['2026-10-14',120000],['2026-10-14',170000]] as $i => [$date,$amount]) $q->execute([$date,$amount,hash('sha256',(string)$i)]);

$fixture = sys_get_temp_dir() . '/planex_bank_reconcile_' . bin2hex(random_bytes(6));
foreach (['bootstrap','app/Support','app/Service','backups'] as $dir) if (!mkdir($fixture . '/' . $dir, 0700, true)) throw new RuntimeException('Fixture directory failed');
$cfg = var_export(['database' => ['host' => $host, 'port' => $port, 'database' => $schema, 'username' => $user, 'password' => $password]], true);
file_put_contents($fixture . '/bootstrap/app.php', '<?php return ' . $cfg . ';');
file_put_contents($fixture . '/app/Support/crypto_helper.php', '<?php');
file_put_contents($fixture . '/app/Support/company_database.php', '<?php function companyDatabaseConfig(array $config,array $company):array { return ["host"=>$company["db_host"],"port"=>$company["db_port"],"database"=>$company["db_identifier"],"username"=>$company["db_username"],"password"=>$company["db_password"]]; }');
foreach (['FinanceBankReconciliationService','FinanceBankReconciliationCutoffService'] as $name) copy(__DIR__ . '/../app/Service/' . $name . '.php', $fixture . '/app/Service/' . $name . '.php');

$run = static function (string $mode) use ($fixture): array {
    putenv('PLANEX_ROOT=' . $fixture);
    putenv('BANK_BALANCE_MODE=' . $mode);
    putenv('BANK_BALANCE_CONFIRM=APPLY_BANK_20261014_BALANCE');
    putenv('BANK_BALANCE_BACKUP_DIR=' . $fixture . '/backups');
    $process = proc_open([PHP_BINARY, __DIR__ . '/../scripts/bank_20261014_balance_reconcile.php'], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('PHP child process failed');
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
[$code,$out,$err] = $run('verify');
if ($code !== 0 || !str_contains($out, 'BANK_BALANCE_STATE=NEEDS_CORRECTION')) throw new RuntimeException('Before verification failed: ' . $err . $out);
[$code,$out,$err] = $run('apply');
if ($code !== 0 || !str_contains($out, 'BANK_BALANCE_STATE=CORRECTED') || !str_contains($out, 'BANK_BALANCE_APPLIED backup=')) throw new RuntimeException('Guarded apply failed: ' . $err . $out);
$backups = glob($fixture . '/backups/*.json');
if (count($backups) !== 1) throw new RuntimeException('Backup missing');
$backup = json_decode(file_get_contents($backups[0]), true, 512, JSON_THROW_ON_ERROR);
if ($backup['snapshot']['row']['opening_balance'] !== '832804.03' || $backup['snapshot']['row']['debit_turnover'] !== '606340.00') throw new RuntimeException('Backup lacks exact old values');
[$code,$out,$err] = $run('verify');
if ($code !== 0 || !str_contains($out, '"target_arithmetic":"OK","target_continuity":"OK","target_transactions":"OK"')) throw new RuntimeException('After independent verification failed: ' . $err . $out);
[$code,$out] = $run('apply');
if ($code === 0 || count(glob($fixture . '/backups/*.json')) !== 1) throw new RuntimeException('Repeated apply was not rejected');
$pdo->exec("UPDATE bank_daily_balances SET debit_turnover=123.00 WHERE id=1747");
[$code] = $run('apply');
if ($code === 0 || count(glob($fixture . '/backups/*.json')) !== 1) throw new RuntimeException('Changed values were not rejected');
echo "BANK_BALANCE_RECONCILE_INTEGRATION_PASS\n";
