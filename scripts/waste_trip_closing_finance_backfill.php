<?php
declare(strict_types=1);

// Preview is read-only. Apply inserts missing links only; rollback uses the exact saved manifest.
if (PHP_SAPI !== 'cli') exit(1);

try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/\\');
    $mode = (string)(getenv('WASTE_TRIP_CLOSING_BACKFILL_MODE') ?: 'preview');
    if (!in_array($mode, ['preview', 'apply', 'rollback'], true)) throw new RuntimeException('Invalid backfill mode');
    if (!is_file($root.'/bootstrap/app.php') || !is_file($root.'/.env')) throw new RuntimeException('PLANEX_ROOT invalid');
    $sourceSha = (string)getenv('PLANEX_SOURCE_SHA');
    $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
    if (!preg_match('/^[a-f0-9]{40}$/D', $sourceSha) || !is_file($marker)
        || trim((string)file_get_contents($marker)) !== $sourceSha) {
        throw new RuntimeException('Exact deployed SHA required');
    }
    $runId = (string)getenv('WASTE_TRIP_CLOSING_BACKFILL_RUN_ID');
    if ($mode !== 'preview') {
        if (!preg_match('/^[1-9][0-9]{5,19}$/D', $runId)
            || getenv('WASTE_TRIP_CLOSING_BACKFILL_CONFIRM') !== strtoupper($mode).'_WASTE_TRIP_CLOSING_BACKFILL') {
            throw new RuntimeException('Run ID and explicit backfill confirmation required');
        }
    }
    $config = require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/crypto_helper.php';
    require_once $root.'/app/Support/company_database.php';
    require_once $root.'/app/Service/WasteTripClosingService.php';
    require_once $root.'/app/Service/WasteTripClosingFinanceBackfillService.php';
    $service = \App\Service\WasteTripClosingFinanceBackfillService::class;
    $connect = static fn(array $db): PDO => new PDO(
        'mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'].';charset=utf8mb4',
        $db['username'], $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $central = $connect($config['database']);
    $companies = $central->query("SELECT * FROM companies WHERE db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (!$companies) throw new RuntimeException('No active tenants');
    $dir = $root.'/storage/backfills/trip-closing-finance';
    if ($mode !== 'preview' && !is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create evidence directory');
    $tenants = [];
    $plans = [];
    foreach ($companies as $company) {
        $companyId = (int)$company['id'];
        if ($mode === 'rollback' && !is_file($dir.'/'.$runId.'-company-'.$companyId.'.json')) continue;
        if ($mode !== 'rollback' && $company['status'] !== 'active') continue;
        $pdo = $connect(companyDatabaseConfig($config, $company));
        if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']
            || !\App\Service\WasteTripClosingService::ready($pdo)) {
            throw new RuntimeException('Tenant database or closing schema mismatch: '.$companyId);
        }
        $tenants[$companyId] = $pdo;
        if ($mode !== 'rollback') {
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            try { $plans[$companyId] = $service::plan($pdo); }
            finally { $pdo->rollBack(); }
        }
    }
    $digest = $service::digest($plans);
    $results = [];
    if ($mode === 'preview') {
        foreach ($plans as $id => $plan) $results[] = ['company_id' => $id, 'links_to_add' => count($plan), 'plan_sha256' => $service::digest($plan)];
        echo 'WASTE_TRIP_CLOSING_BACKFILL_PLAN_SHA256='.$digest."\n";
        echo 'WASTE_TRIP_CLOSING_BACKFILL_SNAPSHOT='.json_encode($results, JSON_THROW_ON_ERROR)."\n";
        echo "WASTE_TRIP_CLOSING_BACKFILL_READ_ONLY_OK\n";
        exit(0);
    }
    if ($mode === 'apply') {
        if (!hash_equals($digest, (string)getenv('WASTE_TRIP_CLOSING_BACKFILL_EXPECTED_DIGEST'))) {
            throw new RuntimeException('Preview plan changed; apply refused');
        }
        $applied = [];
        try {
            foreach ($plans as $id => $plan) {
                $path = $dir.'/'.$runId.'-company-'.$id.'.json';
                $result = $service::apply($tenants[$id], $plan, $id, $sourceSha, $path);
                $applied[] = [$id, $path];
                $results[] = ['company_id' => $id] + $result;
            }
        } catch (Throwable $error) {
            $compensationErrors = [];
            foreach (array_reverse($applied) as [$id, $path]) {
                try { $service::rollback($tenants[$id], $id, $path); }
                catch (Throwable $rollbackError) { $compensationErrors[] = 'company '.$id.': '.$rollbackError->getMessage(); }
            }
            throw new RuntimeException('Apply failed: '.$error->getMessage().'; compensation: '.($compensationErrors ? implode('; ', $compensationErrors) : 'complete'), 0, $error);
        }
    } else {
        foreach ($tenants as $id => $pdo) {
            $path = $dir.'/'.$runId.'-company-'.$id.'.json';
            if (!is_file($path)) continue;
            $results[] = ['company_id' => $id] + $service::rollback($pdo, $id, $path);
        }
        if (!$results) throw new RuntimeException('No manifests found for run ID');
    }
    echo 'WASTE_TRIP_CLOSING_BACKFILL_SNAPSHOT='.json_encode($results, JSON_THROW_ON_ERROR)."\n";
    echo 'WASTE_TRIP_CLOSING_BACKFILL_'.strtoupper($mode)."_OK\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WASTE_TRIP_CLOSING_BACKFILL_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
