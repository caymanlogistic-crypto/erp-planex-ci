<?php
declare(strict_types=1);

// One-time, exact-set correction of the OWNER-confirmed mixed pickups and warehouse ledger.
// Preview is read-only; apply requires the digest of that preview and an exact deployed SHA.
if (PHP_SAPI !== 'cli') exit(1);

try {
    $root = rtrim((string)getenv('PLANEX_ROOT'), '/\\');
    $sha = (string)getenv('PLANEX_SOURCE_SHA');
    $marker = (string)getenv('PLANEX_DEPLOY_MARKER');
    $mode = (string)(getenv('PLANEX_REPAIR_MODE') ?: 'preview');
    if (!in_array($mode, ['preview', 'apply'], true) || !preg_match('/^[0-9a-f]{40}$/D', $sha)
        || !is_file($root.'/bootstrap/app.php') || !is_file($marker) || trim((string)file_get_contents($marker)) !== $sha) {
        throw new RuntimeException('Exact deployed ERP source and valid mode required.');
    }
    if ($mode === 'apply' && (getenv('PLANEX_REPAIR_CONFIRM') !== 'APPLY_405_411_412'
        || !preg_match('/^[0-9a-f]{64}$/D', (string)getenv('PLANEX_REPAIR_EXPECTED_DIGEST')))) {
        throw new RuntimeException('Exact preview digest and confirmation required.');
    }
    $config = require $root.'/bootstrap/app.php';
    require_once $root.'/app/Support/crypto_helper.php';
    require_once $root.'/app/Support/company_database.php';
    require_once $root.'/app/Service/FeoLegacyImportSync.php';
    require_once $root.'/app/Service/WasteTripLegacySourceService.php';
    $connect = static fn(array $db): PDO => new PDO(
        'mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'].';charset=utf8mb4',
        $db['username'], $db['password'],
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $central = $connect($config['database']);
    $tenantName = (string)env('FEO_LEGACY_SYNC_TENANT_DATABASE', '');
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $tenantName)) throw new RuntimeException('Tenant not configured.');
    $findCompany = $central->prepare("SELECT * FROM companies WHERE db_identifier=? AND status='active' LIMIT 2");
    $findCompany->execute([$tenantName]);
    $companies = $findCompany->fetchAll();
    if (count($companies) !== 1) throw new RuntimeException('Active tenant ambiguous.');
    $tenant = $connect(companyDatabaseConfig($config, $companies[0]));
    if ((string)$tenant->query('SELECT DATABASE()')->fetchColumn() !== $tenantName) throw new RuntimeException('Tenant mismatch.');
    $sync = \App\Service\FeoLegacyImportSync::forTenant($tenant);
    if ($sync === null || $sync->database() !== (string)env('LEGACY_FLIGHTS_DB_DATABASE', '')) {
        throw new RuntimeException('Legacy writer and reader database mismatch.');
    }
    $legacy = $sync->connection();
    $database = $sync->database();
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)) throw new RuntimeException('Legacy database invalid.');
    $table = static fn(string $name): string => '`'.$database.'`.`'.$name.'`';
    $lock = null;
    if ($mode === 'apply') {
        $lock = 'planex_warehouse_correction';
        $stmt = $legacy->prepare('SELECT GET_LOCK(?,10)');
        $stmt->execute([$lock]);
        if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Warehouse correction is busy.');
        $legacy->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    } else {
        $legacy->exec('SET TRANSACTION READ ONLY');
    }
    $legacy->beginTransaction();
    try {
        $suffix = $mode === 'apply' ? ' FOR UPDATE' : '';
        $flights = [];
        $movements = [];
        $erp = [];
        foreach ([405, 411, 412] as $id) {
            $q = $legacy->prepare('SELECT id,status,route_type,unload_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,direct_zayavki_ids FROM '.$table('flights').' WHERE id=?'.$suffix);
            $q->execute([$id]);
            $flights[$id] = $q->fetch();
            if (!$flights[$id]) throw new RuntimeException('Flight missing: '.$id);
            $q = $legacy->prepare('SELECT id,status,movement_type,warehouse_id,source_warehouse_id,destination_warehouse_id,flight_id,zayavka_id,fkko_code,mass_netto,mass_brutto,volume,movement_date,comment FROM '.$table('warehouse_movements').' WHERE flight_id=? ORDER BY id'.$suffix);
            $q->execute([$id]);
            $movements[$id] = $q->fetchAll();
            $q = $tenant->prepare('SELECT wt.id,wt.business_id,wt.status,wt.legacy_flight_id,fr.zayavka_id,wtr.snapshot_mass_kg FROM waste_trips wt JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id AND wtr.removed_at IS NULL JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wt.business_id=? AND wt.deleted_at IS NULL ORDER BY wtr.position_no,wtr.id');
            $q->execute([$id]);
            $erp[$id] = $q->fetchAll();
            if (!$erp[$id] || array_unique(array_column($erp[$id], 'id')) !== [(string)$erp[$id][0]['id']]
                || (int)$erp[$id][0]['legacy_flight_id'] !== $id) throw new RuntimeException('ERP trip mismatch: '.$id);
        }
        $ids = static fn(string $raw): array => \App\Service\WasteTripLegacySourceService::parseRequestIdsStrict($raw);
        $direct = [405=>['291457','319343','325989'],412=>['327437','327435','324427']];
        $uncollected = ['325200','321682','323720'];
        $staleIssue = '321682';
        $warehouseIssue = '323445';
        $expected = [];
        foreach ($erp as $id => $rows) {
            $expected[$id] = array_map('strval', array_column($rows, 'zayavka_id'));
            if (count($expected[$id]) !== count(array_unique($expected[$id]))) throw new RuntimeException('Duplicate ERP request in '.$id);
            $legacyIds = $ids((string)$flights[$id]['zayavki_ids']);
            if ($id !== 411 && array_diff($expected[$id], $legacyIds) !== [] || $id !== 411 && array_diff($legacyIds, $expected[$id]) !== []) {
                throw new RuntimeException('Legacy/ERP request set drift: '.$id);
            }
        }
        if (count($expected[405]) !== 16 || count($expected[411]) !== 6 || count($expected[412]) !== 45
            || array_diff($direct[405], $expected[405]) || array_diff($direct[412], $expected[412])
            || in_array($warehouseIssue, $direct[412], true) || !in_array($warehouseIssue, $expected[412], true)
            || array_intersect($uncollected, $expected[411]) || in_array($staleIssue, $expected[412], true)) {
            throw new RuntimeException('OWNER-confirmed request composition changed.');
        }
        $warehouseIds = [];
        foreach ([405,411,412] as $id) {
            $active = array_values(array_filter($movements[$id], static fn(array $m): bool => $m['status'] === 'active'));
            $type = $id === 411 ? 'receipt' : 'issue';
            if (!$active || count($active) !== [405=>13,411=>9,412=>42][$id]) throw new RuntimeException('Movement count changed: '.$id);
            $warehouseIds[$id] = (int)$active[0]['warehouse_id'];
            foreach ($active as $m) if ($m['movement_type'] !== $type || (int)$m['warehouse_id'] !== $warehouseIds[$id]) throw new RuntimeException('Movement type/warehouse mismatch: '.$id);
            $actual = array_map('strval', array_column($active, 'zayavka_id'));
            if (count($actual) !== count(array_unique($actual))) throw new RuntimeException('Duplicate movement: '.$id);
            $want = match ($id) {
                405 => array_values(array_diff($expected[405], $direct[405])),
                411 => array_merge($expected[411], $uncollected),
                412 => array_merge(array_values(array_diff($expected[412], $direct[412], [$warehouseIssue])), [$staleIssue]),
            };
            sort($actual); sort($want);
            if ($actual !== $want) throw new RuntimeException('Movement request set changed: '.$id);
        }
        if ($warehouseIds[411] !== $warehouseIds[412] || $warehouseIds[405] === $warehouseIds[412]
            || !in_array((int)$flights[405]['source_warehouse_id'], [0,$warehouseIds[405]], true)
            || !in_array((int)$flights[412]['source_warehouse_id'], [0,$warehouseIds[412]], true)) {
            throw new RuntimeException('Source warehouse mismatch.');
        }
        $q = $legacy->prepare('SELECT id,name FROM '.$table('warehouses').' WHERE id IN (?,?)'.$suffix);
        $q->execute([$warehouseIds[405],$warehouseIds[412]]);
        $warehouseNames = array_column($q->fetchAll(), 'name', 'id');
        if (count($warehouseNames) !== 2 || !str_contains(mb_strtoupper((string)$warehouseNames[$warehouseIds[405]]), 'КРАСНОЯРСК')
            || !str_contains(mb_strtoupper((string)$warehouseNames[$warehouseIds[412]]), 'НОВОСИБИРСК')) {
            throw new RuntimeException('Warehouse names mismatch.');
        }
        foreach ([405,412] as $id) {
            if (!in_array((string)$flights[$id]['route_type'], ['generator_to_utilizer','mixed_to_utilizer'], true)
                || ($flights[$id]['route_type'] === 'mixed_to_utilizer' && array_diff($ids((string)$flights[$id]['direct_zayavki_ids']), $direct[$id]))) {
                throw new RuntimeException('Unexpected route state: '.$id);
            }
        }
        foreach ($movements[412] as $m) {
            if ((string)$m['zayavka_id'] === $warehouseIssue && $m['movement_type'] === 'issue'
                && (int)$m['warehouse_id'] === $warehouseIds[412]) throw new RuntimeException('323445 already has a movement row.');
        }
        $q = $legacy->prepare('SELECT zayavka_id,naim_otkhoda_fkko,mass_netto,mass_brutto,summarnyy_obem FROM '.$table('feo').' WHERE zayavka_id=?'.$suffix);
        $q->execute([$warehouseIssue]);
        $feo = $q->fetch();
        $erpMass = null;
        foreach ($erp[412] as $row) if ((string)$row['zayavka_id'] === $warehouseIssue) $erpMass = (float)$row['snapshot_mass_kg'];
        if (!$feo || $erpMass === null || $erpMass <= 0 || (float)$feo['mass_netto'] <= 0) throw new RuntimeException('323445 mass unavailable.');
        $net = number_format($erpMass / 1000, 4, '.', '');
        $ratio = (float)$net / (float)$feo['mass_netto'];
        $gross = number_format((float)$feo['mass_brutto'] * $ratio, 4, '.', '');
        $volume = number_format((float)$feo['summarnyy_obem'] * $ratio, 4, '.', '');
        $active412 = array_values(array_filter($movements[412], static fn(array $m): bool => $m['status'] === 'active'));
        $date = min(array_column($active412, 'movement_date'));
        $fingerprint = hash('sha256', json_encode([$flights,$movements,$erp,$warehouseNames,$feo,$net,$gross,$volume,$date], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $summary = ['flights'=>[405,411,412],'warehouse_ids'=>$warehouseIds,'route_to_mixed'=>[405,412],
            'direct'=>$direct,'cancel_receipts_411'=>$uncollected,'cancel_issue_412'=>$staleIssue,
            'add_issue_412'=>$warehouseIssue,'add_issue_kg'=>$erpMass,'plan_sha256'=>$fingerprint];
        echo 'PLANEX_REPAIR_PLAN='.json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        if ($mode === 'preview') {
            $legacy->rollBack();
            echo "PLANEX_REPAIR_READ_ONLY_OK\n";
            exit(0);
        }
        if (!hash_equals((string)getenv('PLANEX_REPAIR_EXPECTED_DIGEST'), $fingerprint)) throw new RuntimeException('Preview stale.');
        $auditDir = $root.'/storage/repairs/405-411-412';
        if (!is_dir($auditDir) && !mkdir($auditDir, 0700, true)) throw new RuntimeException('Cannot create audit directory.');
        $audit = $auditDir.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $manifest = ['sha'=>$sha,'plan'=>$summary,'before'=>['flights'=>$flights,'movements'=>$movements,'erp'=>$erp], 'created_at'=>gmdate('c')];
        if (file_put_contents($audit, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Cannot write audit manifest.');
        chmod($audit, 0600);
        $updateFlight = $legacy->prepare('UPDATE '.$table('flights').' SET route_type=?,source_warehouse_id=?,direct_zayavki_ids=? WHERE id=?');
        foreach ([405,412] as $id) {
            $updateFlight->execute(['mixed_to_utilizer',$warehouseIds[$id],implode(',', $direct[$id]),$id]);
        }
        $updateRequests = $legacy->prepare('UPDATE '.$table('flights').' SET zayavki_ids=? WHERE id=411');
        $updateRequests->execute([implode(',', $expected[411])]);
        $cancel = $legacy->prepare('UPDATE '.$table('warehouse_movements')." SET status='cancelled',updated_at=NOW() WHERE flight_id=? AND warehouse_id=? AND movement_type=? AND zayavka_id=? AND status='active'");
        foreach ($uncollected as $id) {
            $cancel->execute([411,$warehouseIds[411],'receipt',$id]);
            if ($cancel->rowCount() !== 1) throw new RuntimeException('Cannot cancel receipt '.$id);
        }
        $cancel->execute([412,$warehouseIds[412],'issue',$staleIssue]);
        if ($cancel->rowCount() !== 1) throw new RuntimeException('Cannot cancel stale issue.');
        $insert = $legacy->prepare('INSERT INTO '.$table('warehouse_movements').' (movement_type,warehouse_id,source_warehouse_id,destination_warehouse_id,flight_id,zayavka_id,fkko_code,mass_netto,mass_brutto,volume,movement_date,status,comment,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $insert->execute(['issue',$warehouseIds[412],$warehouseIds[412],null,412,$warehouseIssue,(string)$feo['naim_otkhoda_fkko'],$net,$gross,$volume,$date,'active','OWNER-confirmed warehouse pickup; ERP repair 405/411/412']);
        $newId = (int)$legacy->lastInsertId();
        if ($newId <= 0) throw new RuntimeException('New movement ID unavailable.');
        $affected = array_merge($uncollected, [$warehouseIssue]);
        $marks = implode(',', array_fill(0, count($affected), '?'));
        $balance = $legacy->prepare('SELECT zayavka_id,SUM(CASE WHEN movement_type IN (\'receipt\',\'transfer_in\') THEN mass_netto ELSE -mass_netto END) AS netto FROM '.$table('warehouse_movements')." WHERE status='active' AND warehouse_id=? AND zayavka_id IN (".$marks.') GROUP BY zayavka_id');
        $balance->execute(array_merge([$warehouseIds[412]], $affected));
        foreach ($balance->fetchAll() as $row) if ((float)$row['netto'] < -0.00005) throw new RuntimeException('Negative stock after repair for '.$row['zayavka_id']);
        $manifest['new_movement_id'] = $newId;
        if (file_put_contents($audit, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Cannot update audit manifest.');
        $legacy->commit();
        echo 'PLANEX_REPAIR_APPLY_OK movement_id='.$newId."\n";
    } catch (Throwable $e) {
        if ($legacy->inTransaction()) $legacy->rollBack();
        throw $e;
    } finally {
        if ($lock !== null) {
            $q = $legacy->prepare('SELECT RELEASE_LOCK(?)');
            $q->execute([$lock]);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'PLANEX_REPAIR_FAILED: '.$e->getMessage()."\n");
    exit(1);
}
