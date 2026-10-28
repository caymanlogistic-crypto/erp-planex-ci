<?php
declare(strict_types=1);

$config=require dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/entrypoint_dependencies.php';
require_once BASE_PATH.'/app/Support/crew_driver_helpers.php';
require_once BASE_PATH.'/app/Service/DriverTsDataService.php';

$schema=(string)getenv('PLANEX_TEST_FIXED_DB');
if ($schema==='' || !in_array($schema,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) throw new RuntimeException('Disposable schema required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$schema.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$check=static function(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); };
cleanCompanyPoolDatabase($pdo);
try {
    \App\Service\LocalMigrationService::apply($pdo);
    $pdo->exec("INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES(1,11,'Тестовый логист','test','hash','logist','active');
      INSERT INTO clients(id,name,inn,status) VALUES(1,'Тестовый клиент','7700000000','active');
      INSERT INTO contractors(id,name,inn) VALUES(1,'Перевозчик','7700000001');
      INSERT INTO drivers(id,full_name,phone,email,snils,passport_number) VALUES(1,'Иванов Иван','+70000000001','ivanov@example.test','123-456-789 00','4500 123456'),(2,'Петров Пётр','+70000000002','petrov@example.test','234-567-890 11','4500 654321');
      INSERT INTO vehicle_units(id,plate_number,brand,model) VALUES(1,'А123ВС77','КАМАЗ','65115'),(2,'А124ВС77','ГАЗ','3309');
      INSERT INTO vehicle_sets(id,set_type,primary_vehicle_unit_id) VALUES(1,'single',1),(2,'single',2);
      INSERT INTO driver_vehicle_blocks(id,driver_id,vehicle_set_id) VALUES(1,1,1),(2,2,2);
      INSERT INTO crews(id,contractor_id,vehicle_id,driver_id,driver_vehicle_block_id) VALUES(1,1,1,1,1),(2,1,2,2,2);
      INSERT INTO waste_trips(id,business_id,client_id,route_executor_id,responsible_user_id,destination_type,status) VALUES(1,345,1,1,1,'warehouse','executor_assigned'),(2,346,1,1,1,'warehouse','cancelled');
      INSERT INTO documents(entity_type,entity_id,document_type,original_name,stored_name,relative_path,mime_type,file_size) VALUES('driver',1,'Паспорт','passport.pdf','passport.pdf','companies/1/documents/driver/1/passport.pdf','application/pdf',12),('driver',2,'Чужой','other.pdf','other.pdf','companies/1/documents/driver/2/other.pdf','application/pdf',12);");
    $_SESSION=['account_id'=>1,'user_id'=>1,'company_id'=>1,'role_code'=>'company_owner'];
    \App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(1,1,array_column(\App\Security\PageRegistry::companyPages(),'key')));
    $service=new \App\Service\DriverTsDataService($pdo);
    $rows=$service->listing();
    $check(count($rows)===1 && (int)$rows[0]['crew_id']===1,'Only waste-assigned executor is listed');
    $check($rows[0]['trips']===[345,346],'Every assigned waste trip is shown, including cancelled');
    $check($service->detail(2)===null,'Unassigned executor detail is denied');
    $detail=$service->detail(1);
    $check($detail!==null && count($detail['files'])===1 && $detail['files'][0]['original_name']==='passport.pdf','Only own executor documents are included');
    $sections=\App\Service\DriverTsDataService::tableRows($detail);
    $check(in_array(['СНИЛС','123-456-789 00'],$sections['driver'],true),'Driver mail-table value appears in workbook data');
    $check(in_array(['Марка','КАМАЗ'],$sections['vehicle'],true),'Vehicle mail-table value appears in workbook data');
    $path=\App\Service\DriverTsDataService::workbook($sections);
    try {
        $zip=new ZipArchive();
        $check($zip->open($path)===true && $zip->locateName('xl/worksheets/sheet1.xml')!==false,'Valid XLSX package');
        $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
        $check(str_contains($sheet,'123-456-789 00') && str_contains($sheet,'КАМАЗ'),'XLSX contains current driver and vehicle data');
        $zip->close();
    } finally { @unlink($path); }
    $pdo->exec('UPDATE waste_trips SET deleted_at=NOW() WHERE id IN (1,2)');
    $check($service->listing()===[] && $service->detail(1)===null,'Deleted waste trips do not expose executor');
    echo "DRIVER_TS_DATA_PASS\n";
} finally { cleanCompanyPoolDatabase($pdo); }
