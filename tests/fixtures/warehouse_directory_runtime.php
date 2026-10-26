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
    foreach (['warehouses','warehouse_contacts'] as $table) {
        if ($pdo->query("SHOW TABLES LIKE '".$table."'")->fetchColumn() !== false) throw new RuntimeException('Fresh warehouse fixture required');
    }
    if ($pdo->query("SHOW TABLES LIKE 'companies'")->fetchColumn() === false) {
        $pdo->exec('CREATE TABLE companies(id INT PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),db_identifier VARCHAR(255))');
        $pdo->prepare('INSERT INTO companies VALUES(7,?,?,?)')->execute(['ООО Synthetic','active',$schema]);
    }
    $pdo->exec('CREATE TABLE warehouses(id BIGINT UNSIGNED PRIMARY KEY,name VARCHAR(255),full_address VARCHAR(500),latitude DECIMAL(10,7) NULL,longitude DECIMAL(10,7) NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO warehouses VALUES(7,'Склад Север','Москва, Складская улица, 1, корпус 2',55.7500000,37.6200000),(8,'Склад Юг','Тула, Южная улица, 2',NULL,NULL)");
    $insert = $pdo->prepare('INSERT INTO warehouses VALUES(?,?,?,NULL,NULL)');
    for ($id=9; $id<58; $id++) $insert->execute([$id,'Склад '.$id,'Регион, Промышленная улица, '.$id]);
    $insert->execute([58,'Склад без адреса','']);
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-legacy/001_warehouse_contacts.sql'));
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-legacy/002_warehouse_coordinates.sql'));
    if ($pdo->query("SHOW TABLES LIKE 'drivers'")->fetchColumn() === false) {
        $pdo->exec('CREATE TABLE drivers(id BIGINT PRIMARY KEY,full_name VARCHAR(255),vehicle_make_plate VARCHAR(255))');
    } elseif (!in_array('vehicle_make_plate', $pdo->query('SHOW COLUMNS FROM drivers')->fetchAll(PDO::FETCH_COLUMN), true)) {
        $pdo->exec('ALTER TABLE drivers ADD COLUMN vehicle_make_plate VARCHAR(255) NULL');
    }
    $pdo->exec('CREATE TABLE flights(id BIGINT PRIMARY KEY,comment VARCHAR(500),status VARCHAR(40),route_type VARCHAR(40),source_warehouse_id BIGINT,destination_warehouse_id BIGINT,actual_start_date DATETIME,actual_end_date DATETIME,zayavki_ids TEXT,direct_zayavki_ids TEXT,driver_id BIGINT)');
    $pdo->exec('CREATE TABLE warehouse_movements(id BIGINT PRIMARY KEY,movement_type VARCHAR(40),warehouse_id BIGINT,flight_id BIGINT,zayavka_id VARCHAR(80),mass_netto DECIMAL(12,3),movement_date DATETIME,created_at DATETIME,updated_at DATETIME,status VARCHAR(40),comment TEXT)');
    $pdo->exec('CREATE TABLE waste_trips(id BIGINT PRIMARY KEY,business_id BIGINT,comment VARCHAR(500),status VARCHAR(40),actual_start_date DATETIME,legacy_flight_id BIGINT,legacy_source_warehouse_id BIGINT,legacy_destination_warehouse_id BIGINT,deleted_at DATETIME)');
    $pdo->exec('CREATE TABLE waste_trip_requests(id BIGINT PRIMARY KEY,waste_trip_id BIGINT,removed_at DATETIME,feo_request_id BIGINT,snapshot_mass_kg INT,position_no INT)');
    if ($pdo->query("SHOW TABLES LIKE 'feo_requests'")->fetchColumn() === false) {
        $pdo->exec('CREATE TABLE feo_requests(id BIGINT PRIMARY KEY,zayavka_id VARCHAR(80))');
    }
    $pdo->exec("INSERT INTO flights VALUES(300,'Поставка на склад','completed','utilizer_to_warehouse',NULL,7,'2026-10-20 09:00:00','2026-10-20 11:00:00','З-1, З-2','З-1, З-2',NULL)");
    $pdo->exec("INSERT INTO warehouse_movements VALUES(1,'receipt',7,300,'З-1',1.250,'2026-10-20 11:00:00','2026-10-20 11:00:00','2026-10-20 11:00:00','active',NULL),(2,'receipt',7,300,'З-2',2.500,'2026-10-20 11:01:00','2026-10-20 11:01:00','2026-10-20 11:01:00','active',NULL)");
    $contacts = json_encode([
        ['name'=>'Иванов Иван','position'=>'Заведующий','phone'=>'+7 900 000-00-01','email'=>'ivanov@example.test'],
        ['name'=>'Петров Пётр','position'=>'Приёмщик','phone'=>'+7 900 000-00-02','email'=>'petrov@example.test'],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO warehouse_contacts (warehouse_id,contacts_json,updated_by_user_id,updated_at) VALUES(?,?,12,CURRENT_TIMESTAMP(6))')->execute([7,$contacts]);
    echo 'SEEDED'; exit;
}
if ($command === 'cleanup') {
    foreach (['waste_trip_requests','waste_trips','warehouse_movements','flights','warehouse_contacts','warehouses'] as $table) $pdo->exec('DROP TABLE '.$table);
    echo 'CLEANED'; exit;
}
if ($command === 'fingerprint') {
    $data=[];
    foreach (['companies','warehouses','warehouse_contacts','drivers','flights','warehouse_movements','waste_trips','waste_trip_requests','feo_requests'] as $table) $data[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
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
