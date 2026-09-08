<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
foreach ([
    '/app/Core/Database.php',
    '/app/Service/FinanceCashResolutionService.php',
    '/app/Service/ClosingDocumentComplianceService.php',
    '/app/Service/FeoPlacementService.php',
    '/app/Service/FeoUtilizerService.php',
    '/app/Service/FeoMapService.php',
    '/app/Service/FeoRequestCostService.php',
    '/app/Service/ProductionCalendarService.php',
    '/app/Service/NavigationCounterService.php',
] as $file) require_once BASE_PATH . $file;

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dbName = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allow, true) || (string)getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Guarded fixed runner DB is required.');
}
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$ok = static function(bool $v, string $m): void { if (!$v) throw new RuntimeException($m); };

cleanCompanyPoolDatabase($pdo);
try {
    foreach ([122,123,124,125,126] as $id) {
        $matches = glob(BASE_PATH . "/database/migrations-local/{$id}_*.sql") ?: [];
        if (count($matches) !== 1) throw new RuntimeException('Missing FEO migration ' . $id);
        $pdo->exec((string)file_get_contents($matches[0]));
    }

    $pdo->exec("INSERT INTO feo_imports(id,filename,file_hash,status,created_at) VALUES(1,'qa.xlsx',REPEAT('a',64),'applied',DATE_SUB(NOW(),INTERVAL 8 DAY))");
    $insert = $pdo->prepare("INSERT INTO feo_requests(zayavka_id,gruzopoluchatel,mass_netto,mno_sh,mno_d,current_import_id) VALUES(?,?,?,?,?,1)");
    $insert->execute(['100001','Known',1.0,'','']);
    $insert->execute(['100002','Known',2.0,'55.1','']);
    $insert->execute(['100003','Unknown',3.0,'37.6','55.7']);
    $insert->execute(['100004','Known',4.0,'37.7','55.8']);

    $pdo->exec("INSERT INTO feo_utilizers(id,full_name,short_name,is_active,color_hex) VALUES(1,'Known','Known',1,'#2563EB')");
    $pdo->exec("INSERT INTO feo_placement_blocks(id,name,block_type,status,source_type) VALUES(1,'QA','addition','applied','manual')");
    foreach (['100001','100002','100003','100004'] as $i=>$id) {
        $st=$pdo->prepare('INSERT INTO feo_placement_block_requests(block_id,zayavka_id,position_no) VALUES(1,?,?)');
        $st->execute([$id,$i+1]);
    }
    $pdo->exec("INSERT INTO feo_placement_blocks(id,name,block_type,status,source_type) VALUES(2,'QA remove','exclusion','applied','manual')");
    $pdo->exec("INSERT INTO feo_placement_block_requests(block_id,zayavka_id,position_no) VALUES(2,'100004',1)");
    foreach (['100001','100002','100003'] as $id) {
        $st=$pdo->prepare('INSERT INTO feo_placement_current(zayavka_id,source_block_id) VALUES(?,1)');
        $st->execute([$id]);
    }

    $pdo->exec("CREATE TABLE feo (zayavka_id VARCHAR(64) NOT NULL, mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE status_blocks (id INT NOT NULL PRIMARY KEY, zayavki_ids TEXT NULL, status_type VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $legacy=$pdo->prepare('INSERT INTO feo(zayavka_id,mass_netto) VALUES(?,?)');
    foreach ([['100001',1.0],['100002',2.5],['100005',5.0],['999999',9.0]] as [$id,$mass]) $legacy->execute([$id,$mass]);
    $pdo->exec("INSERT INTO status_blocks(id,zayavki_ids,status_type) VALUES (128,'999999','dostupno'),(129,'100001,100002,100005','dostupno')");

    $pdo->exec("INSERT INTO feo_request_cost_blocks(id,pricing_type,snapshot_request_count,snapshot_total_mass_kg,total_cost) VALUES(1,'agreement',1,999.000,100.00)");
    $pdo->exec("INSERT INTO feo_request_cost_block_requests(block_id,zayavka_id,snapshot_mass_kg,position_no) VALUES(1,'100001',999.000,1)");

    putenv('LEGACY_FLIGHTS_DB_HOST=' . $host);
    putenv('LEGACY_FLIGHTS_DB_PORT=' . $port);
    putenv('LEGACY_FLIGHTS_DB_DATABASE=' . $dbName);
    putenv('LEGACY_FLIGHTS_DB_USERNAME=' . $user);
    putenv('LEGACY_FLIGHTS_DB_PASSWORD=' . $pass);
    putenv('LEGACY_FLIGHTS_DB_CHARSET=utf8mb4');

    $config=['database'=>['host'=>$host,'port'=>$port,'database'=>$dbName,'username'=>$user,'password'=>$pass,'charset'=>'utf8mb4']];
    $central = new App\Core\Database($config['database']);
    $company=['id'=>501,'status'=>'active','db_identifier'=>$dbName];
    $c=App\Service\NavigationCounterService::forCompany($config,$central,501,$company);

    $expected=['feo_freshness'=>1,'feo_placement_problem'=>3,'feo_map_problem'=>3,'feo_utilizer_problem'=>1,'feo_cost_problem'=>1];
    foreach ($expected as $key=>$value) $ok(($c[$key]??null)===$value, "{$key} expected {$value}, got " . json_encode($c[$key]??null));
    $sum=0; foreach (array_keys($expected) as $key) $sum += (int)$c[$key];
    $ok($sum===9, 'FEO parent child-derived sum must equal 9 in factual fixture.');

    echo "FEO_CHILD_BADGES_MYSQL=PASS\n";
    echo "FEO_PARENT_BADGE_SUM_MYSQL=PASS sum={$sum}\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
