<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'test') throw new RuntimeException('Test CLI required');
$schema = (string) getenv('PLANEX_TEST_FIXED_DB');
if ($schema === '' || !in_array($schema, explode(',', (string) getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) throw new RuntimeException('Disposable DB wrapper required');
// An ERP route read in this fixture must never reach an external legacy database.
foreach (['LEGACY_FLIGHTS_DB_HOST','LEGACY_FLIGHTS_DB_DATABASE','LEGACY_FLIGHTS_DB_USERNAME','LEGACY_FLIGHTS_DB_PASSWORD'] as $key) putenv($key . '=');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$config = require dirname(__DIR__, 2) . '/bootstrap/app.php';
require BASE_PATH . '/app/Support/entrypoint_dependencies.php';
$config['database'] = ['host'=>getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>getenv('PLANEX_SCHEMA_DB_PORT'),'database'=>$schema,'username'=>getenv('PLANEX_SCHEMA_DB_USER'),'password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD'),'charset'=>'utf8mb4'];
$db = new App\Core\Database($config['database']);
$pdo = $db->connection();
$command = $argv[1] ?? '';

if ($command === 'seed') {
    if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) throw new RuntimeException('Fresh disposable schema required');
    $pdo->exec('CREATE TABLE companies(id INT PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),db_identifier VARCHAR(255))');
    $pdo->prepare('INSERT INTO companies VALUES(7,?,?,?)')->execute(['ООО «ПЛАНЭКС»','active',$schema]);
    $pdo->exec('CREATE TABLE user_accounts(id BIGINT UNSIGNED PRIMARY KEY,full_name VARCHAR(255),login VARCHAR(100))');
    $pdo->exec("INSERT INTO user_accounts VALUES(101,'Петрова Анна Сергеевна','owner')");
    $pdo->exec('CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec("INSERT INTO feo_requests VALUES('302545',1.2305),('285664',0.62),('281861',0.38)");
    $migration = (string) file_get_contents(BASE_PATH . '/database/migrations-local/123_create_feo_map_placement.sql');
    foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $migration) ?: [])) as $statement) $pdo->exec($statement);
    $pdo->exec("INSERT INTO feo_placement_blocks(id,name,block_type,status,source_type,created_by_user_id,created_at) VALUES(74,'Площадка Сосновый Бор','addition','applied','manual',101,'2026-10-17 10:45:00'),(75,'Резервный список','addition','disabled','manual',101,'2026-10-18 09:20:00')");
    $pdo->exec("INSERT INTO feo_placement_block_requests(block_id,zayavka_id,position_no) VALUES(74,'302545',1),(74,'285664',2),(75,'281861',1)");
    $pdo->exec("INSERT INTO feo_placement_current(zayavka_id,source_block_id) VALUES('302545',74),('285664',74)");
    echo 'SEEDED';exit;
}
if ($command === 'fingerprint') {
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);$data=[];
    foreach ($tables as $table) {
        if (!preg_match('/^[a-z_]+$/D',$table)) throw new RuntimeException('Unexpected fixture table');
        $rows=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $encoded=array_map(static fn($row)=>json_encode($row,JSON_UNESCAPED_UNICODE),$rows);sort($encoded);$data[$table]=$encoded;
    }
    echo hash('sha256',json_encode($data));exit;
}
if ($command !== 'render') throw new RuntimeException('Unknown fixture command');
$uri=$argv[2] ?? '';$actor=$argv[3] ?? 'owner';
if (!in_array($uri,['/company/feo/requests/map-placement','/company/feo/requests/map-placement/search-blocks','/company/feo/requests/map-placement/current','/company/feo/requests/map-placement/blocks/74/json'],true)
    || !in_array($actor,['owner','member','denied'],true)) throw new RuntimeException('Only bounded placement GET routes permitted');
$_SERVER['REQUEST_URI']=$uri;
$_SERVER['HTTP_X_REQUESTED_WITH']='XMLHttpRequest';
if (str_ends_with($uri,'/search-blocks')) $_GET=['request_ids'=>(string)($argv[4] ?? '302545')];
$_SESSION=['user_id'=>$actor==='owner'?1:2,'account_id'=>$actor==='owner'?101:102,'membership_id'=>$actor==='owner'?11:12,'company_id'=>7,'role_code'=>$actor==='owner'?'company_owner':'logist','full_name'=>'Тестовый пользователь','auth_type'=>'company_user'];
$registry=new App\Security\PageRegistry();
$access=$actor==='owner'
    ? App\Security\EffectiveAccess::systemOwner(11,7,$registry->pageKeys())
    : App\Security\EffectiveAccess::member(12,7,$actor==='member'?['company.feo.map_placement'=>true]:[],[],'logist');
App\Security\AccessRuntime::setCurrent($access);
$router=new App\Http\Router($registry);
require BASE_PATH . '/app/Http/Routes/company_feo_requests.php';
ob_start();$captureLevel=ob_get_level();
register_shutdown_function(static function() use ($captureLevel):void {
    while(ob_get_level()>$captureLevel) ob_end_flush();
    $html=(string)ob_get_clean();
    echo json_encode(['status'=>http_response_code()?:200,'html'=>$html],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
});
// The public entrypoint attaches a current EffectiveAccess resolver to Router.
// Reproduce its page gate here; this bounded fixture does not recreate central ACL storage.
if (!$access->canAccessPage('company.feo.map_placement')) {
    http_response_code(403);
    echo '403 Forbidden';
    exit;
}
startCsrfFormInjection();
$router->dispatch('GET',$uri);
