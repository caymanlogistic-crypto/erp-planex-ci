<?php
declare(strict_types=1);
// CLI subprocess boundary for bounded read tests. No production entrypoint includes this file.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'test') throw new RuntimeException('Test CLI required');
$schema = (string)getenv('PLANEX_TEST_FIXED_DB');
if ($schema === '' || !in_array($schema, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true)) throw new RuntimeException('Disposable DB wrapper required');
$_SERVER['REQUEST_METHOD'] = 'GET'; // Real migrations intentionally skip read requests.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$config = require dirname(__DIR__, 2).'/bootstrap/app.php';
require BASE_PATH.'/app/Support/entrypoint_dependencies.php';
$config['database'] = ['host'=>getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>getenv('PLANEX_SCHEMA_DB_PORT'),'database'=>$schema,'username'=>getenv('PLANEX_SCHEMA_DB_USER'),'password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD'),'charset'=>'utf8mb4'];
$db = new App\Core\Database($config['database']);
$pdo = $db->connection();
$command = $argv[1] ?? '';
if ($command === 'seed') {
    // Fresh wrapper-owned schema only. Never clean a database selected by a caller.
    if ($pdo->query('SHOW TABLES')->fetchColumn() !== false) throw new RuntimeException('Fresh disposable schema required');
    $pdo->exec('CREATE TABLE companies(id INT PRIMARY KEY,name VARCHAR(255),status VARCHAR(20),db_identifier VARCHAR(255))');
    $pdo->prepare('INSERT INTO companies VALUES(7,?,?,?)')->execute(['ООО Synthetic','active',$schema]);
    foreach (['003_create_company_contractors.sql','012_create_contractor_contacts.sql','017_create_vehicle_sets.sql','007_create_company_documents.sql','024_create_document_types.sql','009_create_entity_access_grants.sql'] as $migration) $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/'.$migration));
    $pdo->exec("ALTER TABLE contractors ADD contractor_type VARCHAR(40), ADD created_by_user_id INT, ADD created_by_membership_id INT, ADD created_by_role VARCHAR(30), ADD deleted_at DATETIME, ADD bank_account VARCHAR(30), ADD bank_name VARCHAR(255), ADD bank_bik VARCHAR(20), ADD bank_corr_account VARCHAR(30), ADD bank_inn VARCHAR(20), ADD director_full_name VARCHAR(255), ADD director_position VARCHAR(255)");
    $pdo->exec('ALTER TABLE vehicle_sets ADD created_by_membership_id INT, ADD deleted_at DATETIME');
    $pdo->exec('ALTER TABLE documents ADD document_type_id INT, ADD deleted_at DATETIME');
    $pdo->exec('CREATE TABLE vehicle_units(id INT PRIMARY KEY,plate_number VARCHAR(30),brand VARCHAR(255),model VARCHAR(100),unit_type VARCHAR(30),vin VARCHAR(30),vin_absent INT DEFAULT 0,capacity_tons DECIMAL(10,2),volume_m3 DECIMAL(10,2),diagnostic_card_number VARCHAR(50),diagnostic_card_date DATE,status VARCHAR(20),deleted_at DATETIME,created_by_user_id INT,created_by_membership_id INT)');
    $pdo->exec('CREATE TABLE entity_assignments(entity_type VARCHAR(30),entity_id INT,membership_id INT,assignment_kind VARCHAR(30))');
    $pdo->exec('CREATE TABLE entity_responsibilities(entity_type VARCHAR(30),entity_id INT,membership_id INT,UNIQUE(entity_type,entity_id))');
    $pdo->exec('CREATE TABLE legacy_access_projections(entity_type VARCHAR(30),entity_id INT,membership_id INT,access_level VARCHAR(20))');
    $pdo->exec('CREATE TABLE crews(id INT PRIMARY KEY,contractor_id INT,deleted_at DATETIME)');
    $pdo->exec('CREATE TABLE drivers(id INT PRIMARY KEY,full_name VARCHAR(255),phone VARCHAR(50),status VARCHAR(20))');
    foreach ([101=>12,102=>11,103=>11,104=>12,105=>13,106=>12] as $id=>$creator) {
        $pdo->prepare("INSERT INTO contractors(id,name,inn,contractor_type,created_by_user_id,created_by_membership_id,created_by_role,deleted_at) VALUES(?,?,?,'legal_entity',1,?,'company_owner',?)")
            ->execute([$id,'Перевозчик '.$id.' Полное название за многоточием','7700000'.$id,$creator,$id===106?'2026-01-01':null]);
    }
    $pdo->exec("INSERT INTO contractor_contacts(contractor_id,contact_person,phone,email,is_primary,is_document_email) VALUES(101,'Контакт 101','+70000000101','mail101@example.test',1,1),(102,'Контакт 102',NULL,'mail102@example.test',1,1)");
    foreach ([201=>12,202=>11,203=>11,204=>13,205=>12] as $id=>$creator) {
        $pdo->prepare("INSERT INTO vehicle_units(id,plate_number,brand,model,unit_type,capacity_tons,volume_m3,status,created_by_user_id,created_by_membership_id) VALUES(?,?,?,'Модель','single',20,88.5,'active',1,?)")
            ->execute([$id,'А'.$id.'АА77','Марка-'.$id,$creator]);
    }
    $pdo->exec("INSERT INTO entity_responsibilities VALUES('contractor',102,12),('contractor',104,13),('vehicle_unit',203,12),('vehicle_unit',205,13)");
    $pdo->exec("INSERT INTO entity_assignments VALUES('contractor',103,12,'manual'),('contractor',104,12,'creator_auto'),('vehicle_unit',202,12,'manual'),('vehicle_unit',205,12,'creator_auto')");
    $pdo->exec("INSERT INTO vehicle_sets(id,set_type,primary_vehicle_unit_id,secondary_vehicle_unit_id,created_by_membership_id,created_by_user_id,created_by_role) VALUES(301,'single',201,NULL,11,1,'company_owner'),(302,'coupling',202,203,11,1,'company_owner'),(303,'coupling',201,204,11,1,'company_owner'),(304,'coupling',204,205,11,1,'company_owner')");
    $pdo->exec("INSERT INTO document_types(id,name,code,entity_type) VALUES(1,'СТС','sts','vehicle_unit'),(2,'Диагностическая карта','diagnostic_card','vehicle_unit')");
    foreach ([201,202,203,204,205] as $unit) foreach ([1,2,3] as $type) {
        // Unit201 has one soft-deleted diagnostic card: its displayed state must remain НЕТ.
        $pdo->prepare("INSERT INTO documents(entity_type,entity_id,document_type_id,document_type,original_name,stored_name,relative_path,mime_type,file_size,deleted_at) VALUES('vehicle_unit',?,?,?,?,?,'synthetic/no-file',?,100,?)")
            ->execute([$unit,$type===3?null:$type,$type===1?'СТС':($type===2?'ДК':'Фото'),'unit-'.$unit.'-doc-'.$type.'.pdf','fixture.pdf',$type===3?'image/jpeg':'application/pdf',$unit===201&&$type===2?'2026-01-01':null]);
    }
    echo 'SEEDED'; exit;
}
if ($command === 'fingerprint') {
    $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);sort($tables);$data=[];
    foreach ($tables as $table) { // Identifiers only from this connection's schema metadata.
        if (!preg_match('/^[a-z_]+$/D',$table)) throw new RuntimeException('Unexpected fixture table');
        $rows=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $encoded=array_map(static fn($row)=>json_encode($row,JSON_UNESCAPED_UNICODE),$rows);sort($encoded);$data[$table]=$encoded;
    }
    echo hash('sha256',json_encode($data));exit;
}
if ($command !== 'render') throw new RuntimeException('Unknown fixture command');
$uri = $argv[2] ?? ''; $role=$argv[3] ?? 'owner';
if (!preg_match('~^/company/(contractors(?:/create(?:-full)?|/\d+/modal-(?:view|edit))?|vehicle-sets(?:/create|/\d+/modal-(?:view|edit))?)$~D',$uri) || !in_array($role,['owner','member'],true)) throw new RuntimeException('Only bounded GET routes permitted');
$_SERVER['REQUEST_URI']=$uri;
$_SERVER['HTTP_X_REQUESTED_WITH']='XMLHttpRequest';
$_SESSION=['user_id'=>$role==='owner'?1:2,'account_id'=>$role==='owner'?101:102,'membership_id'=>$role==='owner'?11:12,'company_id'=>7,'role_code'=>$role==='owner'?'company_owner':'logist','full_name'=>'Тестовый пользователь','auth_type'=>'company_user'];
$registry=new App\Security\PageRegistry();
$access=$role==='owner'?App\Security\EffectiveAccess::systemOwner(11,7,$registry->pageKeys()):App\Security\EffectiveAccess::member(12,7,['company.contractors'=>true,'company.vehicle_sets'=>true],['contractor'=>'own','vehicle_unit'=>'own'],'logist');
App\Security\AccessRuntime::setCurrent($access);
// Explicit page gate, as in the document fixture. This is not a central ACL resolver audit.
$page=str_starts_with($uri,'/company/contractors')?'company.contractors':'company.vehicle_sets';
if (!$access->canAccessPage($page)) throw new RuntimeException('Page denied');
$router=new App\Http\Router($registry);
require BASE_PATH.'/app/Http/Routes/company_contractors.php';
require BASE_PATH.'/app/Http/Routes/company_vehicle_sets.php';
ob_start();$captureLevel=ob_get_level();
register_shutdown_function(static function() use ($captureLevel):void {
    while(ob_get_level()>$captureLevel) ob_end_flush();
    $html=(string)ob_get_clean();
    echo json_encode(['status'=>http_response_code()?:200,'html'=>$html],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
});
startCsrfFormInjection();
$router->dispatch('GET',$uri);
