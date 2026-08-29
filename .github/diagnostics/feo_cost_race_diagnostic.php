<?php

$root = dirname(__DIR__, 2);
require_once $root . '/app/Service/FeoRequestCostService.php';
require_once $root . '/app/Service/FeoRequestCostEditService.php';

if (($argv[1] ?? '') === 'race-worker') {
    $cfg = json_decode(base64_decode((string)($argv[2] ?? ''), true) ?: '', true);
    if (!is_array($cfg)) {
        fwrite(STDOUT, json_encode(['success' => false, 'class' => 'config', 'message' => 'invalid race config']));
        exit(0);
    }
    $pdo = new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
        $cfg['user'],
        $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $edit = new App\Service\FeoRequestCostEditService();
    while (microtime(true) < (float)$cfg['start_at']) {
        usleep(1000);
    }
    try {
        $result = $edit->editBlock(
            $pdo,
            (int)$cfg['block_id'],
            (array)$cfg['payload'],
            (string)$cfg['edit_fingerprint'],
            (array)$cfg['preview_contract']
        );
        fwrite(STDOUT, json_encode(['success' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {
        $errorInfo = $e instanceof PDOException ? $e->errorInfo : null;
        fwrite(STDOUT, json_encode([
            'success' => false,
            'class' => get_class($e),
            'code' => $e->getCode(),
            'error_info' => $errorInfo,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    exit(0);
}

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$migration = (string)file_get_contents($root . '/database/migrations-local/124_create_feo_request_costs.sql');

for ($iteration = 1; $iteration <= 12; $iteration++) {
    $dbName = 'planex_feo_cost_diag_' . getmypid() . '_' . $iteration . '_' . bin2hex(random_bytes(2));
    $quotedDb = '`' . str_replace('`', '``', $dbName) . '`';
    $admin->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("CREATE TABLE feo_requests (zayavka_id VARCHAR(64) NOT NULL PRIMARY KEY, mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE feo_placement_current (zayavka_id VARCHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (preg_split('/;\s*(?:\R|$)/', $migration) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
        $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto) VALUES ('444444',0.600000),('555555',0.700000),('666666',0.800000)");
        $pdo->exec("INSERT INTO feo_placement_current(zayavka_id) VALUES ('444444'),('555555'),('666666')");

        $service = new App\Service\FeoRequestCostService();
        $edit = new App\Service\FeoRequestCostEditService();
        $previewA = $service->previewCreate($pdo, ['444444'], 'agreement', '1000');
        $blockA = $service->createBlock($pdo, ['ids' => ['444444'], 'pricing_type' => 'agreement', 'manual_total' => '1000', 'comment' => 'race A', 'created_by_user_id' => 7, 'created_by_role' => 'logist'], (string)$previewA['selection_fingerprint']);
        $previewB = $service->previewCreate($pdo, ['555555'], 'agreement', '1000');
        $blockB = $service->createBlock($pdo, ['ids' => ['555555'], 'pricing_type' => 'agreement', 'manual_total' => '1000', 'comment' => 'race B', 'created_by_user_id' => 7, 'created_by_role' => 'logist'], (string)$previewB['selection_fingerprint']);
        $blockIdA = (int)$blockA['block_id'];
        $blockIdB = (int)$blockB['block_id'];
        $editA = $edit->previewEdit($pdo, $blockIdA, ['444444', '666666'], 'rate_38', null, 'race A add shared');
        $editB = $edit->previewEdit($pdo, $blockIdB, ['555555', '666666'], 'rate_38', null, 'race B add shared');

        $startAt = microtime(true) + 0.5;
        $base = ['host' => $host, 'port' => $port, 'user' => $user, 'pass' => $pass, 'db' => $dbName, 'start_at' => $startAt];
        $configs = [
            $base + ['block_id' => $blockIdA, 'payload' => ['ids' => ['444444', '666666'], 'pricing_type' => 'rate_38', 'manual_total' => null, 'comment' => 'race A add shared'], 'edit_fingerprint' => $editA['edit_fingerprint'], 'preview_contract' => $editA],
            $base + ['block_id' => $blockIdB, 'payload' => ['ids' => ['555555', '666666'], 'pricing_type' => 'rate_38', 'manual_total' => null, 'comment' => 'race B add shared'], 'edit_fingerprint' => $editB['edit_fingerprint'], 'preview_contract' => $editB],
        ];

        $processes = [];
        foreach ($configs as $cfg) {
            $arg = base64_encode(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $pipes = [];
            $proc = proc_open([PHP_BINARY, __FILE__, 'race-worker', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) {
                throw new RuntimeException('failed to start diagnostic race worker');
            }
            $processes[] = [$proc, $pipes];
        }

        $results = [];
        foreach ($processes as [$proc, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $results[] = ['exit' => $code, 'stderr' => $stderr, 'payload' => json_decode((string)$stdout, true), 'raw' => $stdout];
        }
        $q = $pdo->query("SELECT block_id,zayavka_id FROM feo_request_cost_block_requests WHERE zayavka_id='666666' ORDER BY block_id");
        $memberships = $q->fetchAll(PDO::FETCH_ASSOC);
        echo 'FEO_COST_RACE_DIAG_ITERATION=' . $iteration . ' RESULTS=' . json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' MEMBERSHIPS=' . json_encode($memberships, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS {$quotedDb}");
    }
}

echo "FEO_COST_RACE_DIAGNOSTIC_COMPLETE=PASS\n";
