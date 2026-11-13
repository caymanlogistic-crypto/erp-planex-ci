<?php
declare(strict_types=1);

// One-time OWNER-authorized correction. Preview is read-only; apply needs its exact digest.
if (PHP_SAPI !== 'cli') exit(1);

try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/\\');
    $sha = (string)getenv('PLANEX_SOURCE_SHA');
    $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
    $mode = (string)(getenv('PLANEX_REPAIR_MODE') ?: 'preview');
    if (!in_array($mode, ['preview', 'apply'], true) || !preg_match('/^[0-9a-f]{40}$/D', $sha)
        || !is_file($root.'/bootstrap/app.php') || !is_file($marker)
        || trim((string)file_get_contents($marker)) !== $sha) {
        throw new RuntimeException('Exact deployed source and valid mode required.');
    }
    if ($mode === 'apply' && ((string)getenv('PLANEX_REPAIR_CONFIRM') !== 'APPLY_FEO_302980_132'
        || !preg_match('/^[0-9a-f]{64}$/D', (string)getenv('PLANEX_REPAIR_EXPECTED_DIGEST')))) {
        throw new RuntimeException('Exact preview digest and confirmation required.');
    }

    $config = require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/company_database.php';
    require_once $root.'/app/Service/FeoLegacyImportSync.php';
    require_once $root.'/app/Service/FeoLegacyParityService.php';
    $connect = static fn(array $db): PDO => new PDO(
        'mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'].';charset=utf8mb4',
        $db['username'], $db['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $central = $connect($config['database']);
    $tenantName = (string)env('FEO_LEGACY_SYNC_TENANT_DATABASE', '');
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $tenantName)) throw new RuntimeException('Tenant not configured.');
    $q = $central->prepare("SELECT * FROM companies WHERE db_identifier=? AND status='active' LIMIT 2");
    $q->execute([$tenantName]);
    $companies = $q->fetchAll();
    if (count($companies) !== 1) throw new RuntimeException('Active tenant ambiguous.');
    $tenant = $connect(companyDatabaseConfig($config, $companies[0]));
    if ((string)$tenant->query('SELECT DATABASE()')->fetchColumn() !== $tenantName) throw new RuntimeException('Tenant mismatch.');
    $sync = App\Service\FeoLegacyImportSync::forTenant($tenant);
    if ($sync === null) throw new RuntimeException('Legacy mirror is not configured.');
    $legacy = $sync->connection();
    $database = $sync->database();
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)) throw new RuntimeException('Legacy database invalid.');
    if ($legacy === $tenant || (string)$legacy->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Separate legacy connection required.');
    }
    $blockTable = '`'.$database.'`.`status_blocks`';
    $feoTable = '`'.$database.'`.`feo`';

    $lockName = 'planex_feo_placement_'.substr(hash('sha256', $tenantName), 0, 20);
    $q = $tenant->prepare('SELECT GET_LOCK(?,10)');
    $q->execute([$lockName]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('FEO placement is busy.');
    try {
        $legacy->exec($mode === 'apply' ? 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE' : 'SET TRANSACTION READ ONLY');
        $legacy->beginTransaction();
        try {
            $suffix = $mode === 'apply' ? ' FOR UPDATE' : '';
            $q = $tenant->prepare("SELECT b.id,b.block_type,b.status FROM feo_placement_block_requests br JOIN feo_placement_blocks b ON b.id=br.block_id WHERE br.zayavka_id=? ORDER BY b.id DESC");
            $q->execute(['302980']);
            $history = $q->fetchAll();
            if (!$history || (int)$history[0]['id'] !== 137 || $history[0]['block_type'] !== 'exclusion' || $history[0]['status'] !== 'applied') {
                throw new RuntimeException('ERP placement history changed.');
            }
            $q = $tenant->prepare('SELECT COUNT(*) FROM feo_placement_current WHERE zayavka_id=?');
            $q->execute(['302980']);
            if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('Request is present in ERP current set.');

            $q = $legacy->prepare('SELECT * FROM '.$blockTable.' WHERE id=132 AND status_type=\'dostupno\''.$suffix);
            $q->execute();
            $block = $q->fetch();
            if (!$block) throw new RuntimeException('Legacy available block 132 missing.');
            $parse = static fn(string $raw): array => array_values(array_filter(preg_split('/[,;\s]+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [], static fn(string $id): bool => $id !== ''));
            $ids = $parse((string)$block['zayavki_ids']);
            if (count($ids) !== count(array_unique($ids)) || count(array_filter($ids, static fn(string $id): bool => $id === '302980')) !== 1) {
                throw new RuntimeException('Request membership in block 132 changed.');
            }
            $q = $legacy->query('SELECT id,zayavki_ids FROM '.$blockTable." WHERE id>109 AND status_type='dostupno' ORDER BY id".$suffix);
            $occurrences = [];
            foreach ($q->fetchAll() as $row) {
                if (in_array('302980', $parse((string)$row['zayavki_ids']), true)) $occurrences[] = (int)$row['id'];
            }
            if ($occurrences !== [132]) throw new RuntimeException('Legacy available membership is not unique to block 132.');
            $parity = (new App\Service\FeoLegacyParityService())->state($tenant, $legacy);
            if (empty($parity['available']) || (int)$parity['mismatch_count'] !== 1
                || count($parity['only_legacy'] ?? []) !== 1
                || (string)$parity['only_legacy'][0]['zayavka_id'] !== '302980'
                || (float)$parity['only_legacy'][0]['legacy_mass_kg'] !== 10.0) {
                throw new RuntimeException('Observed parity is no longer the single authorized discrepancy.');
            }
            $remaining = array_values(array_filter($ids, static fn(string $id): bool => $id !== '302980'));
            if ($remaining === []) throw new RuntimeException('Block 132 would become empty; explicit delete plan required.');
            foreach ($remaining as $id) if (!preg_match('/^[0-9]+$/D', $id)) throw new RuntimeException('Invalid legacy request ID.');
            $massColumn = 'mass_netto';
            $mass = 0.0;
            foreach (array_chunk($remaining, 800) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $q = $legacy->prepare('SELECT zayavka_id,'.$massColumn.' FROM '.$feoTable.' WHERE zayavka_id IN ('.$marks.')');
                $q->execute($chunk);
                $rows = $q->fetchAll();
                if (count($rows) !== count($chunk)) throw new RuntimeException('Legacy request rows missing.');
                foreach ($rows as $row) $mass += (float)$row[$massColumn];
            }
            $mass = round($mass, 3);
            $plan = ['source_sha'=>$sha,'tenant'=>$tenantName,'legacy_database'=>$database,'block_id'=>132,
                'request_id'=>'302980','before'=>['ids'=>$ids,'count'=>$block['zayavki_count'],'mass_tonn'=>$block['total_mass_tonn']],
                'after'=>['ids'=>$remaining,'count'=>count($remaining),'mass_tonn'=>$mass]];
            $digest = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
            if ($mode === 'apply') {
                if (!hash_equals((string)getenv('PLANEX_REPAIR_EXPECTED_DIGEST'), $digest)) throw new RuntimeException('Preview changed.');
                $auditDir = dirname(dirname($root)).'/deploy/repairs/feo-302980-132';
                if (!is_dir($auditDir) && !mkdir($auditDir, 0700, true) && !is_dir($auditDir)) throw new RuntimeException('Backup directory unavailable.');
                $backup = $auditDir.'/before-'.$digest.'.json';
                $handle = fopen($backup, 'x');
                if ($handle === false) throw new RuntimeException('Exact backup already exists; refusing second apply.');
                $backupData = json_encode(['plan'=>$plan,'row'=>$block], JSON_THROW_ON_ERROR);
                if (!chmod($backup, 0600) || fwrite($handle, $backupData) !== strlen($backupData) || !fflush($handle)) {
                    fclose($handle);
                    throw new RuntimeException('Exact backup could not be persisted.');
                }
                fclose($handle);
                $q = $legacy->prepare('UPDATE '.$blockTable.' SET zayavki_ids=?,zayavki_count=?,total_mass_tonn=? WHERE id=132');
                $q->execute([implode(',', $remaining), count($remaining), $mass]);
                if ($q->rowCount() !== 1) throw new RuntimeException('Legacy block update did not affect one row.');
                $afterParity = (new App\Service\FeoLegacyParityService())->state($tenant, $legacy);
                if (empty($afterParity['available']) || (int)$afterParity['mismatch_count'] !== 0) {
                    throw new RuntimeException('Parity remains inconsistent after proposed update.');
                }
                $legacy->commit();
                echo 'FEO_302980_REPAIR_APPLY_OK digest='.$digest."\n";
            } else {
                $legacy->rollBack();
                echo json_encode(['plan'=>$plan,'digest'=>$digest], JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";
                echo "FEO_302980_REPAIR_READ_ONLY_OK\n";
            }
        } catch (Throwable $e) {
            if ($legacy->inTransaction()) $legacy->rollBack();
            throw $e;
        }
    } finally {
        $q = $tenant->prepare('SELECT RELEASE_LOCK(?)');
        $q->execute([$lockName]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FEO 302980 repair refused: '.$e->getMessage()."\n");
    exit(1);
}
