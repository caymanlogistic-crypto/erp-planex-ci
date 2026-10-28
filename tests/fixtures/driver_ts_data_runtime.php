<?php
declare(strict_types=1);
$config=require dirname(__DIR__,2).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/entrypoint_dependencies.php';
require_once BASE_PATH.'/app/Support/crew_driver_helpers.php';
require_once BASE_PATH.'/app/Service/DriverTsDataService.php';
$schema=(string)getenv('PLANEX_TEST_FIXED_DB');
if($schema===''||!in_array($schema,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)||getenv('APP_ENV')!=='test')throw new RuntimeException('Disposable schema required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$schema.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$action=$argv[1]??'';
if($action==='seed'){
 cleanCompanyPoolDatabase($pdo);\App\Service\LocalMigrationService::apply($pdo);
 $pdo->exec("INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES(1,11,'Тестовый логист','test','hash','logist','active');
  INSERT INTO clients(id,name,inn,status) VALUES(1,'Тестовый клиент','7700000000','active');
  INSERT INTO contractors(id,name,inn) VALUES(1,'Перевозчик','7700000001');
  INSERT INTO drivers(id,full_name,phone,email,snils) VALUES(1,'Иванов Иван','+70000000001','ivanov@example.test','123-456-789 00'),(2,'Петров Пётр','+70000000002','petrov@example.test','234-567-890 11');
  INSERT INTO vehicle_units(id,plate_number,brand,model) VALUES(1,'А123ВС77','КАМАЗ','65115'),(2,'М814КК16','ГАЗ','C41R13');
  INSERT INTO vehicle_sets(id,set_type,primary_vehicle_unit_id) VALUES(1,'single',1),(2,'single',2);
  INSERT INTO driver_vehicle_blocks(id,driver_id,vehicle_set_id) VALUES(1,1,1),(2,2,2);
  INSERT INTO crews(id,contractor_id,vehicle_id,driver_id,driver_vehicle_block_id) VALUES(1,1,1,1,1),(2,1,2,2,2);
  INSERT INTO waste_trips(id,business_id,client_id,route_executor_id,responsible_user_id,destination_type,status) VALUES(1,345,1,1,1,'warehouse','executor_assigned'),(2,346,1,1,1,'warehouse','cancelled');");
 echo 'SEEDED';return;
}
if($action==='clean'){cleanCompanyPoolDatabase($pdo);echo 'CLEANED';return;}
if($action==='fingerprint'){echo json_encode([$pdo->query('SELECT COUNT(*) FROM waste_trips')->fetchColumn(),$pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn()]);return;}
$_SERVER['REQUEST_URI']='/company/driver-ts-data';$_SERVER['SCRIPT_NAME']='/index.php';
$_SESSION=['company_id'=>1,'role_code'=>'company_owner','account_id'=>1,'user_id'=>1,'membership_id'=>11];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(1,1,array_column(\App\Security\PageRegistry::companyPages(),'key')));
$service=new \App\Service\DriverTsDataService($pdo);
if($action==='detail'){
 $detail=$service->detail((int)($argv[2]??0));
 if(!$detail)throw new RuntimeException('Missing executor');
 $sections=\App\Service\DriverTsDataService::tableRows($detail);
 require BASE_PATH.'/app/View/partials/driver_ts_data_detail.php';return;
}
if($action!=='render')throw new RuntimeException('Invalid action');
$company=['id'=>1,'name'=>'Тестовая компания','status'=>'active'];$error=null;$rows=$service->listing();
foreach($rows as &$row)$row['responsible_name']='Тестовый логист';unset($row);
$db=new class([]) extends \App\Core\Database {public function connection(): PDO {throw new RuntimeException('No central DB access in fixture');}};
$pageTitle='Данные Вод и ТС';$pageContext='Данные Вод и ТС › Компания';
$pageStylesheets=['/assets/css/route-executor-list-v016.css','/assets/css/registry-components.css'];
$pageScripts=['/assets/js/driver-ts-data.js','/assets/js/table-reference-select.js','/assets/js/registry-components.js'];
ob_start();require BASE_PATH.'/app/View/pages/company_driver_ts_data.php';$content=(string)ob_get_clean();
require BASE_PATH.'/app/View/layouts/main.php';
