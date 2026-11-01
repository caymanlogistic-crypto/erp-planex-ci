<?php
declare(strict_types=1);

$dbName = (string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV') !== 'test' || !preg_match('/^planex_[a-z0-9_]+$/D', $dbName)
    || getenv('PLANEX_TEST_DB_LEASE') !== $dbName
    || !in_array($dbName, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) {
    throw new RuntimeException('Trusted disposable central schema required');
}
$host = (string)getenv('PLANEX_SCHEMA_DB_HOST');
$port = (string)getenv('PLANEX_SCHEMA_DB_PORT');
$user = (string)getenv('PLANEX_SCHEMA_DB_USER');
$password = (string)getenv('PLANEX_SCHEMA_DB_PASSWORD');
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4", $user, $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE schema_migrations (migration VARCHAR(255) PRIMARY KEY, checksum CHAR(64) NOT NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE companies (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE deleted_entities (id INT UNSIGNED PRIMARY KEY, company_id INT UNSIGNED) ENGINE=InnoDB');
$pdo->exec('INSERT INTO companies VALUES (1)');
$pdo->exec('INSERT INTO deleted_entities VALUES (1,1)');
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'planex_cd021_' . bin2hex(random_bytes(8));
$migrationDir = $root . '/database/migrations';
mkdir($migrationDir, 0700, true);
mkdir($root . '/bootstrap', 0700);
file_put_contents($root . '/.env', "APP_ENV=test\n");
$config = ['database' => ['host' => $host, 'port' => $port, 'database' => $dbName, 'username' => $user, 'password' => $password]];
file_put_contents($root . '/bootstrap/app.php', '<?php return ' . var_export($config, true) . ';');
$sql = (string)file_get_contents(__DIR__ . '/../database/migrations/021_company_deletion_runs.sql');
file_put_contents($migrationDir . '/021_company_deletion_runs.sql', $sql);
$sha = str_repeat('a', 40);
$marker = $root . '/deployed.txt';
file_put_contents($marker, $sha);
$script = __DIR__ . '/../scripts/company_deletion_021_migration.php';
$baseEnv = getenv();
$baseEnv['PLANEX_ROOT'] = $root;
$baseEnv['PLANEX_SOURCE_SHA'] = $sha;
$baseEnv['PLANEX_DEPLOY_MARKER'] = $marker;
$baseEnv['PLANEX_MIGRATION_SHA256'] = hash('sha256', $sql);
$run = static function (array $extra = []) use ($script, $baseEnv): array {
    $pipes = [];
    $process = proc_open([PHP_BINARY, $script], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, null, array_merge($baseEnv, $extra));
    if (!is_resource($process)) throw new RuntimeException('Could not start migration subprocess');
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
};
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    [$code,$out] = $run();
    $assert($code === 0 && str_contains($out, '"ready":false'), 'Read-only preflight did not report absent table');
    [$code] = $run(['COMPANY_DELETION_021_MODE' => 'apply']);
    $assert($code !== 0, 'Apply without confirmation was accepted');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='company_deletion_runs'")->fetchColumn() === 0, 'Unconfirmed apply mutated schema');
    [$code,$out,$err] = $run(['COMPANY_DELETION_021_MODE' => 'apply', 'COMPANY_DELETION_021_CONFIRM' => 'APPLY_COMPANY_DELETION_021']);
    $assert($code === 0 && str_contains($out, 'COMPANY_DELETION_021_APPLY_OK'), 'Guarded apply failed: ' . $err);
    [$code,$out] = $run();
    $assert($code === 0 && str_contains($out, '"ready":true'), 'Postcheck did not confirm journal');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM deleted_entities')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM company_deletion_runs')->fetchColumn() === 0, 'Business data or runs changed');
    file_put_contents($marker, str_repeat('b', 40));
    [$code] = $run(['COMPANY_DELETION_021_MODE' => 'apply', 'COMPANY_DELETION_021_CONFIRM' => 'APPLY_COMPANY_DELETION_021']);
    $assert($code !== 0, 'Stale deployed marker was accepted');
    echo "COMPANY_DELETION_021_MIGRATION_MYSQL=PASS\n";
} finally {
    foreach ([$marker, $migrationDir . '/021_company_deletion_runs.sql', $root . '/bootstrap/app.php', $root . '/.env'] as $path) {
        if (is_file($path)) unlink($path);
    }
    rmdir($migrationDir); rmdir($root . '/database'); rmdir($root . '/bootstrap'); rmdir($root);
}
