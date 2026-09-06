<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Core/Database.php';
require_once BASE_PATH . '/app/Service/NavigationCounterService.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: '';
$password = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: '';
$dbName = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allowlist = array_values(array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')))));
if ($dbName === '' || !in_array($dbName, $allowlist, true) || (string)getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Guarded fixed test DB is required.');
}
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
cleanCompanyPoolDatabase($pdo);
try {
    foreach (['122_create_feo_requests.sql','123_create_feo_map_placement.sql','125_create_feo_map_utilizers.sql','126_create_feo_utilizer_aliases.sql'] as $migration) {
        $pdo->exec((string)file_get_contents(BASE_PATH . '/database/migrations-local/' . $migration));
    }

    $pdo->exec("INSERT INTO feo_imports(filename,file_hash,status,created_at) VALUES('old.xlsx',REPEAT('a',64),'applied',DATE_SUB(NOW(), INTERVAL 8 DAY))");
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,gruzopoluchatel) VALUES('1001','ООО Полигон'),('1002','Неизвестный получатель')");
    $pdo->exec("INSERT INTO feo_placement_blocks(id,name,block_type,status,source_type) VALUES(1,'QA','addition','applied','manual')");
    $pdo->exec("INSERT INTO feo_placement_current(zayavka_id,source_block_id) VALUES('1001',1),('1002',1)");
    $pdo->exec("INSERT INTO feo_utilizers(full_name,short_name,is_active) VALUES('ООО Полигон','Полигон',1)");

    $config = ['database'=>['host'=>$host,'port'=>$port,'database'=>$dbName,'username'=>$user,'password'=>$password,'charset'=>'utf8mb4']];
    $centralDb = new App\Core\Database($config['database']);
    $company = ['id'=>701,'status'=>'active','db_identifier'=>$dbName];
    $counters = App\Service\NavigationCounterService::forCompany($config, $centralDb, 701, $company);
    if (($counters['feo_freshness'] ?? null) !== 1) throw new RuntimeException('stale FEO import badge mismatch');
    if (($counters['feo_utilizer_problem'] ?? null) !== 1) throw new RuntimeException('unmatched utilizer badge mismatch');
    if (($counters['production_calendar_warning'] ?? null) !== 0 && (int)date('n') !== 12) throw new RuntimeException('calendar warning outside December');

    $pdo->exec("UPDATE feo_imports SET created_at=NOW() WHERE id=1");
    $companyFresh = ['id'=>702,'status'=>'active','db_identifier'=>$dbName];
    $fresh = App\Service\NavigationCounterService::forCompany($config, $centralDb, 702, $companyFresh);
    if (($fresh['feo_freshness'] ?? null) !== 0) throw new RuntimeException('fresh FEO import badge mismatch');
    if (($fresh['feo_utilizer_problem'] ?? null) !== 1) throw new RuntimeException('utilizer count changed with freshness');

    $pdo->exec("DELETE FROM feo_imports");
    $companyMissing = ['id'=>703,'status'=>'active','db_identifier'=>$dbName];
    $missing = App\Service\NavigationCounterService::forCompany($config, $centralDb, 703, $companyMissing);
    if (($missing['feo_freshness'] ?? null) !== 1) throw new RuntimeException('missing FEO import must require attention');

    echo "NAVIGATION_REGISTRY_BADGES_MYSQL=PASS\n";
    echo "FEO_STALE_BADGE=PASS\n";
    echo "FEO_FRESH_BADGE=PASS\n";
    echo "FEO_MISSING_IMPORT_BADGE=PASS\n";
    echo "FEO_UTILIZER_PROBLEM_COUNT=PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
