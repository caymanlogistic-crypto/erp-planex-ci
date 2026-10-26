<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'test') throw new RuntimeException('Test CLI required');
$schema = (string)getenv('PLANEX_TEST_FIXED_DB');
if ($schema === '' || !in_array($schema, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) throw new RuntimeException('Disposable DB wrapper required');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$config = require dirname(__DIR__, 2).'/bootstrap/app.php';
require BASE_PATH.'/app/Support/entrypoint_dependencies.php';
$host = (string)getenv('PLANEX_SCHEMA_DB_HOST');
$port = (string)getenv('PLANEX_SCHEMA_DB_PORT');
$user = (string)getenv('PLANEX_SCHEMA_DB_USER');
$password = (string)getenv('PLANEX_SCHEMA_DB_PASSWORD');
$config['database'] = ['host'=>$host,'port'=>$port,'database'=>$schema,'username'=>$user,'password'=>$password,'charset'=>'utf8mb4'];
$db = new App\Core\Database($config['database']);
$pdo = $db->connection();
$command = $argv[1] ?? '';
if ($command === 'seed') {
    if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) throw new RuntimeException('Fresh disposable schema required');
    $pdo->exec('CREATE TABLE companies(id INT PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),db_identifier VARCHAR(255))');
    $pdo->prepare('INSERT INTO companies VALUES(7,?,?,?)')->execute(['ООО Synthetic','active',$schema]);
    $pdo->exec('CREATE TABLE warehouses(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(255),full_address VARCHAR(500)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO warehouses VALUES(7,'Склад Север','Москва, Складская улица, 1, корпус 2'),(8,'Склад Юг','Тула, Южная улица, 2')");
    $insert = $pdo->prepare('INSERT INTO warehouses VALUES(?,?,?)');
    for ($id=9; $id<58; $id++) $insert->execute([$id,'Склад '.$id,'Регион, Промышленная улица, '.$id]);
    $insert->execute([58,'Склад без адреса','']);
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-legacy/001_warehouse_contacts.sql'));
    $contacts = json_encode([
        ['name'=>'Иванов Иван','position'=>'Заведующий','phone'=>'+7 900 000-00-01','email'=>'ivanov@example.test'],
        ['name'=>'Петров Пётр','position'=>'Приёмщик','phone'=>'+7 900 000-00-02','email'=>'petrov@example.test'],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO warehouse_contacts VALUES(?,?,12,CURRENT_TIMESTAMP(6))')->execute([7,$contacts]);
    echo 'SEEDED'; exit;
}
if ($command === 'fingerprint') {
    $data=[];
    foreach (['companies','warehouses','warehouse_contacts'] as $table) $data[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    echo hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); exit;
}
if ($command !== 'render') throw new RuntimeException('Unknown command');
$role = $argv[2] ?? '';
if (!in_array($role,['owner','logist','denied'],true)) throw new RuntimeException('Unknown role');
$uri = '/company/warehouses/addresses';
$_SERVER['REQUEST_URI']=$uri;
$_SESSION=['user_id'=>$role==='owner'?1:2,'account_id'=>$role==='owner'?101:102,'membership_id'=>$role==='owner'?11:12,
    'company_id'=>7,'role_code'=>$role==='owner'?'company_owner':'logist','full_name'=>'Тестовый пользователь','auth_type'=>'company_user'];
$registry = new App\Security\PageRegistry();
$access = $role==='owner' ? App\Security\EffectiveAccess::systemOwner(11,7,$registry->pageKeys())
    : App\Security\EffectiveAccess::member(12,7,['company.warehouses.stock'=>$role==='logist'],[], 'logist');
App\Security\AccessRuntime::setCurrent($access);
if (!$access->canAccessPage('company.warehouses.addresses')) {
    echo json_encode(['status'=>403,'html'=>'Доступ запрещён'],JSON_UNESCAPED_UNICODE); exit;
}
putenv('LEGACY_FLIGHTS_DB_HOST='.$host);
putenv('LEGACY_FLIGHTS_DB_PORT='.$port);
putenv('LEGACY_FLIGHTS_DB_DATABASE='.$schema);
putenv('LEGACY_FLIGHTS_DB_USERNAME='.$user);
putenv('LEGACY_FLIGHTS_DB_PASSWORD='.$password);
$router = new App\Http\Router($registry);
require BASE_PATH.'/app/Http/Routes/company_linear_trips.php';
ob_start(); $captureLevel=ob_get_level();
register_shutdown_function(static function() use ($captureLevel): void {
    while (ob_get_level() > $captureLevel) ob_end_flush();
    echo json_encode(['status'=>http_response_code() ?: 200,'html'=>(string)ob_get_clean()],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
});
startCsrfFormInjection();
$router->dispatch('GET',$uri);
