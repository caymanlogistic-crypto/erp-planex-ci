<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'test') throw new RuntimeException('Test CLI required');
$schema=(string)getenv('PLANEX_TEST_FIXED_DB');
if ($schema==='' || !in_array($schema,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) throw new RuntimeException('Disposable DB wrapper required');
foreach (['LEGACY_FLIGHTS_DB_HOST','LEGACY_FLIGHTS_DB_DATABASE','LEGACY_FLIGHTS_DB_USERNAME','LEGACY_FLIGHTS_DB_PASSWORD'] as $key) putenv($key.'=');
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/index.php';
$config=require dirname(__DIR__,2).'/bootstrap/app.php';
require BASE_PATH.'/app/Support/entrypoint_dependencies.php';
$config['database']=['host'=>getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>getenv('PLANEX_SCHEMA_DB_PORT'),'database'=>$schema,'username'=>getenv('PLANEX_SCHEMA_DB_USER'),'password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD'),'charset'=>'utf8mb4'];
$db=new App\Core\Database($config['database']);$pdo=$db->connection();$command=$argv[1]??'';
if ($command==='seed') {
 if ($pdo->query("SHOW TABLES LIKE 'companies'")->fetchColumn()===false) {
  $pdo->exec('CREATE TABLE companies(id INT PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),db_identifier VARCHAR(255))');
  $pdo->prepare('INSERT INTO companies VALUES(7,?,?,?)')->execute(['ООО «ПЛАНЭКС»','active',$schema]);
 }
 if ($pdo->query("SHOW TABLES LIKE 'feo_requests'")->fetchColumn()===false) $pdo->exec('CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
 foreach (['139_create_feo_transfers.sql','160_create_feo_weight_batches.sql'] as $file) {
  $migration=(string)file_get_contents(BASE_PATH.'/database/migrations-local/'.$file);
  foreach (array_filter(array_map('trim',preg_split('/;\s*(?:\R|$)/',$migration)?:[])) as $statement) $pdo->exec($statement);
 }
 $transfer=$pdo->prepare('INSERT IGNORE INTO feo_transfer_batches(id,name,mode,destination_json,request_count,status,operation_key,created_by_user_id,created_by_name,created_at) VALUES(18,?,?,?,?,?,?,?,?,?)');
 $transfer->execute(['Перемещение на склад','address',json_encode(['address'=>'Москва, улица Примерная, 12','quality'=>'Точный адрес'],JSON_UNESCAPED_UNICODE),12,'applied',str_repeat('a',64),101,'Владимир С.','2026-10-18 15:30:00']);
 $pdo->exec("INSERT IGNORE INTO feo_weight_batches(id,name,request_count,operation_key,status,created_by_user_id,created_by_name,created_at) VALUES(18,'Проверка веса',12,'".str_repeat('b',64)."','applied',101,'Владимир С.','2026-10-18 15:30:00')");
 echo 'SEEDED';exit;
}
if ($command==='fingerprint') {
 $data=[];foreach (['feo_transfer_batches','feo_weight_batches'] as $table) $data[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
 echo hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));exit;
}
if ($command!=='render') throw new RuntimeException('Unknown fixture command');
$kind=$argv[2]??'';$actor=$argv[3]??'owner';
if (!in_array($kind,['transfers','weights'],true)||!in_array($actor,['owner','member','denied'],true)) throw new RuntimeException('Bounded route only');
$uri='/company/feo/requests/'.$kind;$_SERVER['REQUEST_URI']=$uri;
$_SESSION=['user_id'=>$actor==='owner'?1:2,'account_id'=>$actor==='owner'?101:102,'membership_id'=>$actor==='owner'?11:12,'company_id'=>7,'role_code'=>$actor==='owner'?'company_owner':'logist','full_name'=>'Тестовый пользователь','auth_type'=>'company_user'];
$registry=new App\Security\PageRegistry();$key='company.feo.'.$kind;
$access=$actor==='owner'?App\Security\EffectiveAccess::systemOwner(11,7,$registry->pageKeys()):App\Security\EffectiveAccess::member(12,7,$actor==='member'?[$key=>true]:[],[],'logist');
App\Security\AccessRuntime::setCurrent($access);
$router=new App\Http\Router($registry);require BASE_PATH.'/app/Http/Routes/company_feo_requests.php';
ob_start();$captureLevel=ob_get_level();
register_shutdown_function(static function() use($captureLevel):void {while(ob_get_level()>$captureLevel)ob_end_flush();$html=(string)ob_get_clean();echo json_encode(['status'=>http_response_code()?:200,'html'=>$html],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);});
if (!$access->canAccessPage($key)){http_response_code(403);echo '403 Forbidden';exit;}
startCsrfFormInjection();$router->dispatch('GET',$uri);
