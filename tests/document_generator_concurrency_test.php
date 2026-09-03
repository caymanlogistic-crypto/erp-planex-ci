<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$repoRoot = getenv('PLANEX_REPO_ROOT') ?: dirname(__DIR__);
require_once $repoRoot . '/app/Service/DocumentNumberSequenceService.php';

use App\Service\DocumentNumberSequenceService;

$fail = static function (string $message): never {
    throw new RuntimeException($message);
};
$ok = static function (bool $value, string $message) use ($fail): void {
    if (!$value) {
        $fail($message);
    }
};

if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
    if (getenv('GITHUB_ACTIONS') === 'true' || getenv('PLANEX_DOCGEN_REQUIRE_RUNTIME') === '1') {
        $fail('pdo_mysql is required for document generator concurrency test');
    }
    echo "DOCUMENT_GENERATOR_CONCURRENCY=REMOTE_REQUIRED\n";
    echo "DOCUMENT_GENERATOR_UNIQUE=REMOTE_REQUIRED\n";
    exit(0);
}

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
$admin = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$db = 'planex_docgen_race_' . getmypid() . '_' . bin2hex(random_bytes(3));
$quotedDb = '`' . $db . '`';
$admin->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$tmp = sys_get_temp_dir() . '/planex_docgen_race_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);

$cleanup = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
        @unlink($path . DIRECTORY_SEPARATOR . $name);
    }
    @rmdir($path);
};

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("CREATE TABLE document_number_sequences (
        sequence_key VARCHAR(64) NOT NULL PRIMARY KEY,
        prefix VARCHAR(16) NOT NULL,
        next_number BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $stmt = $pdo->prepare('INSERT INTO document_number_sequences(sequence_key,prefix,next_number) VALUES(?,?,?)');
    $stmt->execute([
        DocumentNumberSequenceService::VEHICLE_LEASE_CONTRACT_KEY,
        DocumentNumberSequenceService::VEHICLE_LEASE_CONTRACT_PREFIX,
        DocumentNumberSequenceService::VEHICLE_LEASE_CONTRACT_FIRST,
    ]);
    $pdo->exec("CREATE TABLE generated_documents (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        document_type VARCHAR(64) NOT NULL,
        document_number VARCHAR(128) NULL,
        UNIQUE KEY uk_generated_documents_type_number(document_type,document_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $childScript = $tmp . '/child.php';
    $servicePath = $repoRoot . '/app/Service/DocumentNumberSequenceService.php';
    $child = <<<'PHPCHILD'
<?php
require_once $argv[1];
$host=$argv[2];$port=(int)$argv[3];$db=$argv[4];$user=$argv[5];$pass=$argv[6];$start=$argv[7];$result=$argv[8];
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$deadline=microtime(true)+10;
while(!is_file($start)){if(microtime(true)>$deadline)throw new RuntimeException('start barrier timeout');usleep(10000);}
$pdo->beginTransaction();
try{
    $number=App\Service\DocumentNumberSequenceService::allocateVehicleLeaseContractNumber($pdo);
    usleep(250000);
    $pdo->commit();
    file_put_contents($result,$number,LOCK_EX);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();file_put_contents($result,'ERROR:'.$e->getMessage(),LOCK_EX);exit(2);}
PHPCHILD;
    file_put_contents($childScript, $child);
    $startFile = $tmp . '/start';
    $results = [$tmp . '/result1', $tmp . '/result2'];
    $processes = [];
    foreach ($results as $result) {
        $cmd = [PHP_BINARY, $childScript, $servicePath, $host, (string)$port, $db, $user, $pass, $startFile, $result];
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $ok(is_resource($proc), 'failed to start allocator child');
        $processes[] = [$proc, $pipes];
    }
    usleep(100000);
    file_put_contents($startFile, "go\n", LOCK_EX);

    foreach ($processes as [$proc, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $ok($code === 0, 'allocator child failed: ' . trim((string)$stdout . ' ' . (string)$stderr));
    }

    $numbers = array_map(static fn(string $path): string => trim((string)file_get_contents($path)), $results);
    sort($numbers, SORT_NATURAL);
    $ok($numbers === ['П 105', 'П 106'], 'concurrent allocation must yield exactly П 105 and П 106; got ' . implode(', ', $numbers));
    $next = (int)$pdo->query("SELECT next_number FROM document_number_sequences WHERE sequence_key='vehicle_lease_crew_contract'")->fetchColumn();
    $ok($next === 107, 'sequence next_number must be 107 after two committed allocators');
    echo "DOCUMENT_GENERATOR_CONCURRENCY=PASS\n";

    $insert = $pdo->prepare('INSERT INTO generated_documents(document_type,document_number) VALUES(?,?)');
    $insert->execute(['vehicle_lease_crew', 'П 105']);
    $duplicateBlocked = false;
    try {
        $insert->execute(['vehicle_lease_crew', 'П 105']);
    } catch (PDOException) {
        $duplicateBlocked = true;
    }
    $ok($duplicateBlocked, 'database unique index must reject duplicate document number within document type');
    $insert->execute(['waybill_driver_assignment', 'П 105']);
    echo "DOCUMENT_GENERATOR_UNIQUE=PASS\n";
} finally {
    $cleanup($tmp);
    try {
        $admin->exec("DROP DATABASE IF EXISTS {$quotedDb}");
    } catch (Throwable) {
    }
}
