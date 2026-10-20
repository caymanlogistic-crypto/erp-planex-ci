<?php

declare(strict_types=1);

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Service\RouteExecutorCombinedCreateService;
use App\Service\RouteExecutorReviewMaterializationService;
use App\Service\RouteExecutorDeletionService;
use App\Service\RouteExecutorExistingEntityService;

$config = require dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/entrypoint_dependencies.php';
require_once BASE_PATH . '/app/Support/crew_driver_helpers.php';
require_once BASE_PATH . '/app/Service/RouteExecutorReviewMaterializationService.php';
require_once BASE_PATH . '/app/Service/RouteExecutorReviewPackage.php';
require_once BASE_PATH . '/app/Service/RouteExecutorReviewCardService.php';

$dbName = trim((string) getenv('PLANEX_TEST_FIXED_DB'));
$allowed = array_filter(array_map('trim', explode(',', (string) getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allowed, true) || getenv('APP_ENV') !== 'test') throw new RuntimeException('Disposable test schema required.');
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1', (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306), $dbName), getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
cleanCompanyPoolDatabase($pdo);
applyLocalMigrations($pdo);
$migration153 = file_get_contents(BASE_PATH . '/database/migrations-local/153_crew_active_uniqueness.sql');
if ($migration153 === false) throw new RuntimeException('Migration 153 is missing.');
$pdo->exec($migration153);
$indexColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crews' AND INDEX_NAME='uk_crew' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
if ($indexColumns !== ['contractor_id', 'driver_vehicle_block_id', 'active_crew_key']) throw new RuntimeException('Migration 153 index is not idempotent.');
AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11, 7, ['company.route_executors']));
$pdo->exec("CREATE TABLE company_roles (id INT PRIMARY KEY,company_id INT NOT NULL)");
$pdo->exec("CREATE TABLE user_accounts (id INT PRIMARY KEY,full_name VARCHAR(255) NOT NULL,login VARCHAR(100) NOT NULL,status VARCHAR(20) NOT NULL)");
$pdo->exec("CREATE TABLE company_memberships (id INT PRIMARY KEY,company_id INT NOT NULL,account_id INT NOT NULL,role_id INT NOT NULL,status VARCHAR(20) NOT NULL)");
$pdo->exec("INSERT INTO company_roles VALUES (1,7); INSERT INTO user_accounts VALUES (1,'Создатель','owner','active'),(2,'Ответственный логист','logist','active'),(3,'Другой компании','outsider','active'); INSERT INTO company_memberships VALUES (11,7,1,1,'active'),(12,7,2,1,'active'),(13,8,3,1,'active')");

$payload = static function (string $inn, string $plate): array {
    return [
        'responsible_logist_membership_id' => '12',
        'contractor' => ['name' => 'Тестовый перевозчик', 'inn' => $inn, 'contacts' => [['contact_person' => 'Тест', 'phone' => '+7 900 111-22-33', 'email' => 'test@example.test', 'comment' => 'Связь', 'is_primary' => '1']]],
        'driver' => ['full_name' => 'Иванов Иван Иванович', 'phone' => '+7 900 000-00-00', 'extra_phones' => ['+7 900 111-22-33'], 'extra_phone_comments' => ['Резерв'], 'passport_issue_date' => '01.02.2020', 'license_issue_date' => '03.04.2021'],
        'vehicle' => ['set_type' => 'single', 'units' => ['primary' => ['plate_number' => $plate, 'brand' => 'КАМАЗ', 'model' => '4310', 'vin' => 'XTC43100000000000', 'capacity_tons' => '10', 'volume_m3' => '20']]],
    ];
};
$central = new Database(['host' => getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1', 'port' => getenv('PLANEX_SCHEMA_DB_PORT') ?: '3306', 'database' => $dbName, 'username' => getenv('PLANEX_SCHEMA_DB_USER'), 'password' => getenv('PLANEX_SCHEMA_DB_PASSWORD')]);
$preflight = $payload('7701234567', 'А123ВС77');
$preflight['contractor']['bank_corr_account'] = '30101810300000000601 ';
$rejected = false;
try {
    RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $preflight, [], 101, 'company_owner', null, false, true);
} catch (RuntimeException $expected) {
    $rejected = str_contains($expected->getMessage(), 'Корр');
}
if (!$rejected) throw new RuntimeException('Final-send preflight accepted a malformed correspondent account.');
$preflight['contractor']['bank_corr_account'] = '30101810300000000601';
$preflight['driver']['phone'] = '';
$rejected = false;
try {
    RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $preflight, [], 101, 'company_owner', null, false, true);
} catch (RuntimeException $expected) {
    $rejected = str_contains($expected->getMessage(), 'телефон водителя');
}
if (!$rejected) throw new RuntimeException('Final-send preflight accepted a missing driver phone.');
$preflight['driver']['phone'] = '+7 900 000-00-00';
RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $preflight, [], 101, 'company_owner', null, false, true);
$withoutVin=$payload('7701234567','А123ВС77');
$withoutVin['vehicle']['units']['primary']['vin']='';
$vinRejected=false;
try { RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$withoutVin,[],101,'company_owner',null,false,true); }
catch (RuntimeException $expected) { $vinRejected=str_contains($expected->getMessage(),'VIN'); }
if (!$vinRejected) throw new RuntimeException('Final-send preflight accepted blank VIN without a declaration.');
$withoutVin['vehicle']['units']['primary']['vin_absent']='1';
RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$withoutVin,[],101,'company_owner',null,false,true);
foreach (['contractors', 'drivers', 'vehicle_units', 'crews'] as $table) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 0) throw new RuntimeException("Preflight wrote to $table.");
}
$crewId = RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $payload('7701234567', 'А123ВС77'), [], 101, 'company_owner');
if ($crewId <= 0) throw new RuntimeException('No crew id returned.');
foreach (['contractors','contractor_contacts','drivers','driver_phones','vehicle_units','vehicle_sets','driver_vehicle_blocks','crews','crew_drivers'] as $table) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() !== 1) throw new RuntimeException("Unexpected $table count after create.");
}
$responsibility=$pdo->query("SELECT membership_id,assigned_by_membership_id FROM entity_responsibilities WHERE entity_type='route_executor' AND entity_id=$crewId")->fetch(PDO::FETCH_ASSOC);
if (!$responsibility || (int)$responsibility['membership_id']!==12 || (int)$responsibility['assigned_by_membership_id']!==11) throw new RuntimeException('Selected responsible logist was not saved.');
$before = (int) $pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn();
foreach ([null, '13'] as $invalid) {
    $attempt=$payload('7701234590','А129ВС77');
    if ($invalid===null) unset($attempt['responsible_logist_membership_id']);
    else $attempt['responsible_logist_membership_id']=$invalid;
    $rejected=false;
    try { RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$attempt,[],101,'company_owner'); }
    catch (RuntimeException $expected) { $rejected=true; }
    if (!$rejected) throw new RuntimeException('Invalid responsible logist was accepted.');
    if ((int)$pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn()!==$before) throw new RuntimeException('Invalid logist created partial data.');
}
$tmp = tempnam(sys_get_temp_dir(), 'route-doc-');
file_put_contents($tmp, '%PDF-1.4 synthetic');
$files = ['contractor_predef_doc' => [
    'name' => ['company_card' => 'synthetic.pdf'],
    'type' => ['company_card' => 'application/pdf'],
    'tmp_name' => ['company_card' => $tmp],
    'error' => ['company_card' => UPLOAD_ERR_OK],
    'size' => ['company_card' => filesize($tmp)],
]];
try {
    RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $payload('7701234568', 'А124ВС77'), $files, 101, 'company_owner');
    throw new RuntimeException('Expected document upload failure.');
} catch (RuntimeException $expected) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn() !== $before) throw new RuntimeException('Partial contractor survived transaction rollback.');
    if ((int) $pdo->query("SELECT COUNT(*) FROM entity_responsibilities WHERE entity_type='route_executor'")->fetchColumn() !== 1) throw new RuntimeException('Partial responsibility survived transaction rollback.');
} finally {
    @unlink($tmp);
}
$pdo->exec("INSERT INTO linear_routes(route_type,client_id,carrier_contractor_id,route_executor_id,cargo_type_id,planned_loading_date,planned_unloading_date,status) VALUES('direct',1,1,$crewId,1,'2026-09-27','2026-09-28','cancelled')");
$usage = RouteExecutorDeletionService::tripUsage($pdo, $crewId);
if ($usage['linear_count'] !== 1 || $usage['waste_count'] !== 0) throw new RuntimeException('Linear trip usage was not detected.');
try {
    RouteExecutorDeletionService::archive($pdo, $crewId, 101, 'company_owner');
    throw new RuntimeException('Linked linear trip allowed deletion.');
} catch (RuntimeException $expected) {
    if (!str_contains($expected->getMessage(), 'участвует')) throw $expected;
}
$pdo->exec('DELETE FROM linear_routes WHERE route_executor_id=' . $crewId);
$pdo->exec("INSERT INTO clients(id,name,inn,status) VALUES(1,'Тестовый клиент','7700000000','active')");
$pdo->exec("INSERT INTO users(id,full_name,login,password_hash,role_code,status) VALUES(101,'Тестовый пользователь','owner','hash','company_owner','active')");
$pdo->exec("INSERT INTO waste_trips(business_id,client_id,route_executor_id,responsible_user_id,destination_type,status) VALUES(144,1,$crewId,101,'warehouse','cancelled')");
$usage = RouteExecutorDeletionService::tripUsage($pdo, $crewId);
if ($usage['linear_count'] !== 0 || $usage['waste_count'] !== 1) throw new RuntimeException('Waste trip usage was not detected.');
try {
    RouteExecutorDeletionService::archive($pdo, $crewId, 101, 'company_owner');
    throw new RuntimeException('Linked waste trip allowed deletion.');
} catch (RuntimeException $expected) {
    if (!str_contains($expected->getMessage(), 'участвует')) throw $expected;
}
$pdo->exec('DELETE FROM waste_trips WHERE route_executor_id=' . $crewId);
RouteExecutorDeletionService::archive($pdo, $crewId, 101, 'company_owner');
$archived = $pdo->query('SELECT status,deleted_at FROM crews WHERE id=' . $crewId)->fetch(PDO::FETCH_ASSOC);
if (($archived['status'] ?? '') !== 'archived' || empty($archived['deleted_at'])) throw new RuntimeException('Unlinked crew was not archived.');
if ((new \App\Service\DriverService($config, $central))->checkDriverHasCrews($pdo, 1)) throw new RuntimeException('Archived crew still blocks driver deletion.');
if (\App\Service\RouteExecutorService::hasDuplicateCrew($pdo, 1, 1, 1)) throw new RuntimeException('Archived crew still counts as a duplicate.');
$pdo->exec('INSERT INTO crews(contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status,created_by_user_id,created_by_role) SELECT contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,\'active\',created_by_user_id,created_by_role FROM crews WHERE id=' . $crewId);
$recreatedCrewId = (int) $pdo->lastInsertId();
if ($recreatedCrewId <= $crewId || !\App\Service\RouteExecutorService::hasDuplicateCrew($pdo, 1, 1, 1)) throw new RuntimeException('Active crew duplicate detection failed after re-entry.');
try {
    $pdo->exec('INSERT INTO crews(contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status,created_by_user_id,created_by_role) SELECT contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,\'active\',created_by_user_id,created_by_role FROM crews WHERE id=' . $crewId);
    throw new RuntimeException('Second active crew with the same components was accepted.');
} catch (PDOException $expected) {
}
RouteExecutorDeletionService::archive($pdo, $recreatedCrewId, 101, 'company_owner');
$contractorService = new \App\Service\ContractorService($config, $central);
if (!isset($contractorService->validateContractor(['name' => 'Проверка', 'inn' => '7701234567'], $pdo)['inn'])) throw new RuntimeException('Live contractor duplicate was missed.');
$contractorService->archiveContractor($pdo, 1);
if (isset($contractorService->validateContractor(['name' => 'Проверка', 'inn' => '7701234567'], $pdo)['inn'])) throw new RuntimeException('Deleted contractor still reserves its INN.');
(new \App\Service\DriverService($config, $central))->archiveDriver($pdo, 1);
$pdo->exec('UPDATE vehicle_sets SET deleted_at=NOW() WHERE id=1');
$newCrewId = RouteExecutorCombinedCreateService::create($pdo, $central, $config, 7, $payload('7701234567', 'А123ВС77'), [], 101, 'company_owner');
if ($newCrewId <= $recreatedCrewId) throw new RuntimeException('Previously deleted data could not be entered again.');
$control=static fn(string $form,string $name,string $value):array=>['formId'=>'lab-'.$form.'-form','name'=>$name,'value'=>$value];
$snapshot=['logist'=>'12','controls'=>[
    $control('contractor','name','ИП Проверка Переноса'),$control('contractor','inn','7701234569'),
    $control('contractor','contacts[0][contact_person]','Контакт'),$control('contractor','contacts[0][phone]','+7 900 111-22-33'),
    $control('contractor','contacts[0][email]','contact@example.test'),
    $control('driver','full_name','Петров Пётр Петрович'),$control('driver','phone','+7 900 000-00-01'),
    $control('driver','passport_issue_date','01.02.2020'),$control('driver','license_issue_date','03.04.2021'),
    $control('vehicle','set_type','single'),$control('vehicle','units[primary][plate_number]','А125ВС77'),
    $control('vehicle','units[primary][brand]','КАМАЗ'),$control('vehicle','units[primary][vin]','XTC43100000000000'),
    $control('vehicle','units[primary][capacity_tons]','10'),$control('vehicle','units[primary][volume_m3]','20'),
]];
$storedSource=tempnam(sys_get_temp_dir(),'route-review-doc-');
file_put_contents($storedSource,'%PDF-1.4 review document');
$stored=[];
foreach ([['lab-contractor-form|predef_doc[company_card][]','contractor.pdf'],
    ['lab-driver-form|predef_doc[passport][]','passport.pdf'],
    ['lab-driver-form|predef_doc[passport][]','passport-second.pdf'],
    ['lab-driver-form|predef_doc[driver_license][]','license.pdf'],
    ['lab-driver-form|predef_doc[snils][]','snils.pdf'],
    ['lab-vehicle-form|predef_doc[primary][sts][]','sts.pdf']] as [$key,$name]) {
    $stored[]=['key'=>$key,'index'=>0,'name'=>$name,'mime'=>'application/pdf','tmp_name'=>$storedSource,
        'error'=>UPLOAD_ERR_OK,'size'=>filesize($storedSource)];
}
try {
    $checkboxSnapshot=['controls'=>[$control('vehicle','units[primary][vin]',''),
        ['formId'=>'lab-vehicle-form','name'=>'units[primary][vin_absent]','value'=>'1','type'=>'checkbox','checked'=>true]]];
    [$checkboxPost]=RouteExecutorReviewMaterializationService::standardInput($checkboxSnapshot,[]);
    if (($checkboxPost['vehicle']['units']['primary']['vin_absent']??null)!=='1') throw new RuntimeException('Checked VIN absence was lost while mapping the review snapshot.');
    $checkboxSnapshot['controls'][1]['checked']=false;
    [$uncheckedPost]=RouteExecutorReviewMaterializationService::standardInput($checkboxSnapshot,[]);
    if (isset($uncheckedPost['vehicle']['units']['primary']['vin_absent'])) throw new RuntimeException('Unchecked VIN absence was submitted.');
    [$post,$files]=RouteExecutorReviewMaterializationService::standardInput($snapshot,$stored);
    if ($post['contractor']['contacts'][0]['email']!=='contact@example.test'
        || $post['vehicle']['units']['primary']['plate_number']!=='А125ВС77'
        || $files['vehicle_predef_doc']['name']['primary']['sts'][0]!=='sts.pdf') throw new RuntimeException('Review snapshot was not mapped to standard input.');
    $movedCrewId=RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$post,$files,101,'company_owner',null,true);
    if ($movedCrewId<=$newCrewId) throw new RuntimeException('Review crew was not created.');
    $docs=$pdo->query("SELECT d.entity_type,d.relative_path,d.original_name,d.document_type,t.name type_name FROM documents d JOIN document_types t ON t.id=d.document_type_id WHERE d.original_name IN ('contractor.pdf','passport.pdf','passport-second.pdf','license.pdf','snils.pdf','sts.pdf') ORDER BY d.id")->fetchAll(PDO::FETCH_ASSOC);
    $expectedTypes=['contractor.pdf'=>'Карточка предприятия','passport.pdf'=>'Паспорт','passport-second.pdf'=>'Паспорт','license.pdf'=>'Водительское удостоверение','snils.pdf'=>'СНИЛС','sts.pdf'=>'СТС'];
    if (count($docs)!==6) throw new RuntimeException('Review lost a file from a repeated historical control index.');
    foreach ($docs as $doc) {
        if ($doc['document_type']!==$expectedTypes[$doc['original_name']] || $doc['type_name']!==$expectedTypes[$doc['original_name']]) throw new RuntimeException('Canonical Russian document label was not persisted without a hidden browser label.');
        $file=storage_path($doc['relative_path']);
        if (!is_file($file) || file_get_contents($file)!=='%PDF-1.4 review document') throw new RuntimeException('Stored review document was not copied into the standard location.');
        @unlink($file);
    }
    if (!is_file($storedSource)) throw new RuntimeException('Review source document was moved instead of copied.');
} finally { @unlink($storedSource); }
$cardId='22222222-2222-4222-8222-222222222222';
$reviewDir=storage_path('companies/7/route-executor-review/'.$cardId);
if (!is_dir($reviewDir) && !mkdir($reviewDir,0750,true)) throw new RuntimeException('Review fixture directory unavailable.');
$fullControls=[
    $control('contractor','name','ИП Полный Комплект'),$control('contractor','inn','123456789012'),
    $control('contractor','contractor_type','individual'),$control('contractor','ogrn','123456789012345'),
    $control('contractor','director_full_name','Полный Комплект ИП'),$control('contractor','director_position','ИП'),
    $control('contractor','legal_address','Тестовый адрес'),$control('contractor','bank_account','12345678901234567890'),
    $control('contractor','bank_bik','123456789'),$control('contractor','bank_name','Тестовый банк'),
    $control('contractor','bank_corr_account','12345678901234567890'),
    $control('contractor','contacts[0][contact_person]','Контакт'),$control('contractor','contacts[0][phone]','+7 900 111-22-33'),
    $control('contractor','contacts[0][email]','contact@example.test'),
    $control('driver','full_name','Сидоров Сидор Сидорович'),$control('driver','phone','+7 900 000-00-02'),
    $control('driver','snils','12345678900'),$control('driver','passport_number','1234567890'),
    $control('driver','passport_issued_by','Тестовое ОВД'),$control('driver','passport_issue_date','01.02.2020'),
    $control('driver','license_number','1234567890'),$control('driver','license_issue_date','03.04.2021'),
    $control('driver','email','driver@example.test'),
    $control('vehicle','set_type','single'),$control('vehicle','units[primary][plate_number]','А126ВС77'),
    $control('vehicle','units[primary][brand]','КАМАЗ'),$control('vehicle','units[primary][vin]','XTC43100000000000'),
    $control('vehicle','units[primary][capacity_tons]','10'),$control('vehicle','units[primary][volume_m3]','20'),
];
$fullFiles=[];$fixturePaths=[];
foreach ([['lab-contractor-form','predef_doc[company_card][]'],['lab-driver-form','predef_doc[passport][]'],
    ['lab-driver-form','predef_doc[driver_license][]'],['lab-vehicle-form','predef_doc[primary][sts][]'],
    ['lab-vehicle-form','predef_doc[primary][photo][]']] as $index=>[$form,$field]) {
    $filename='review-'.$index.'.pdf';$relative='companies/7/route-executor-review/'.$cardId.'/'.$filename;
    $absolute=storage_path($relative);file_put_contents($absolute,'%PDF-1.4 full review '.$index);$fixturePaths[]=$absolute;
    $fullFiles[]=['id'=>str_pad((string)$index,32,'0'),'formId'=>$form,'name'=>$field,'index'=>0,
        'original_name'=>$filename,'mime'=>'application/pdf','size'=>filesize($absolute),'path'=>$relative];
}
$fullSnapshot=['controls'=>$fullControls,'logist'=>'12','trip_id'=>'285','trip_label'=>'#285 Тестовый рейс'];
$pdo->prepare('INSERT INTO route_executor_review_cards(card_id,actor_account_id,last_editor_account_id,revision,snapshot_json,files_json) VALUES(?,?,?,1,?,?)')
    ->execute([$cardId,101,101,json_encode($fullSnapshot,JSON_UNESCAPED_UNICODE),json_encode($fullFiles,JSON_UNESCAPED_UNICODE)]);
$pdo->prepare("INSERT INTO route_executor_review_sends(card_id,actor_account_id,stage,final_mail_status) VALUES(?,?,'final','accepted')")
    ->execute([$cardId,101]);
try {
    $materializer=new RouteExecutorReviewMaterializationService($pdo,$central,$config,7);
    $converted=$materializer->materialize($cardId,101,'company_owner');
    if ($converted<=$movedCrewId || (int)$pdo->query('SELECT COUNT(*) FROM route_executor_review_cards WHERE deleted_at IS NULL')->fetchColumn()!==0)
        throw new RuntimeException('Final card was not converted to ordinary crew.');
    $convertedDocs=$pdo->query("SELECT d.entity_type,d.relative_path FROM documents d WHERE d.original_name LIKE 'review-%.pdf' ORDER BY d.id")->fetchAll(PDO::FETCH_ASSOC);
    if (count($convertedDocs)!==5) throw new RuntimeException('Full review files were not registered as standard documents.');
    foreach ($convertedDocs as $doc) {
        $absolute=storage_path($doc['relative_path']);
        if (!is_file($absolute)) throw new RuntimeException('Full review file missing from standard storage.');
        @unlink($absolute);
    }
    $crewCount=(int)$pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn();
    try { $materializer->materialize($cardId,101,'company_owner'); throw new RuntimeException('Final card converted twice.'); }
    catch (DomainException $expected) {}
    if ((int)$pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn()!==$crewCount) throw new RuntimeException('Retry created duplicate crew.');
} finally {
    foreach ($fixturePaths as $path) @unlink($path);
    @rmdir($reviewDir);
}
$reuseContractorId=(int)$pdo->query('SELECT c.id FROM contractors c LEFT JOIN documents d ON d.entity_type=\'contractor\' AND d.entity_id=c.id AND d.deleted_at IS NULL WHERE c.deleted_at IS NULL AND d.id IS NULL ORDER BY c.id DESC LIMIT 1')->fetchColumn();
$reuseDriverId=(int)$pdo->query('SELECT id FROM drivers WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
$reuseVehicleId=(int)$pdo->query('SELECT id FROM vehicle_sets WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
$contractorCount=(int)$pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn();
$driverCount=(int)$pdo->query('SELECT COUNT(*) FROM drivers')->fetchColumn();
$vehicleCount=(int)$pdo->query('SELECT COUNT(*) FROM vehicle_sets')->fetchColumn();
$reusePost=$payload('7701234591','А701ВС77');
$reusePost['existing']=['contractor'=>$reuseContractorId];
$reusedCrew=RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$reusePost,[],101,'company_owner');
$reusedRow=$pdo->query('SELECT contractor_id FROM crews WHERE id='.$reusedCrew)->fetch(PDO::FETCH_ASSOC);
if ((int)$reusedRow['contractor_id']!==$reuseContractorId || (int)$pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn()!==$contractorCount
    || (int)$pdo->query('SELECT COUNT(*) FROM drivers')->fetchColumn()!==$driverCount+1
    || (int)$pdo->query('SELECT COUNT(*) FROM vehicle_sets')->fetchColumn()!==$vehicleCount+1) throw new RuntimeException('Existing contractor was duplicated instead of linking a new driver.');
$reusePost=$payload('7701234592','А702ВС77');
$reusePost['existing']=['driver'=>$reuseDriverId,'vehicle'=>$reuseVehicleId];
$reusedCrew=RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$reusePost,[],101,'company_owner');
$reusedRow=$pdo->query('SELECT c.driver_id,b.vehicle_set_id FROM crews c JOIN driver_vehicle_blocks b ON b.id=c.driver_vehicle_block_id WHERE c.id='.$reusedCrew)->fetch(PDO::FETCH_ASSOC);
if ((int)$reusedRow['driver_id']!==$reuseDriverId || (int)$reusedRow['vehicle_set_id']!==$reuseVehicleId
    || (int)$pdo->query('SELECT COUNT(*) FROM drivers')->fetchColumn()!==$driverCount+1
    || (int)$pdo->query('SELECT COUNT(*) FROM vehicle_sets')->fetchColumn()!==$vehicleCount+1) throw new RuntimeException('Existing driver or vehicle set was duplicated.');
$existingDir=storage_path("companies/7/documents/contractor/$reuseContractorId");
if (!is_dir($existingDir) && !mkdir($existingDir,0750,true)) throw new RuntimeException('Existing document fixture directory unavailable.');
$existingPath=$existingDir.'/existing-card.pdf';
file_put_contents($existingPath,'%PDF-1.4 existing card');
$relative="companies/7/documents/contractor/$reuseContractorId/existing-card.pdf";
try {
    $q=$pdo->prepare("INSERT INTO documents(entity_type,entity_id,document_type,original_name,stored_name,relative_path,mime_type,file_size,status) VALUES('contractor',?,'Карточка предприятия','Карточка предприятия.pdf','existing-card.pdf',?,'application/pdf',?,'uploaded')");
    $q->execute([$reuseContractorId,$relative,filesize($existingPath)]);
    $library=new RouteExecutorExistingEntityService($pdo,7);
    $detail=$library->detail('contractor',$reuseContractorId);
    if (!in_array('predef_doc[company_card]',array_column($detail['files'],'field'),true)) throw new RuntimeException('Existing standard file did not appear in contractor form.');
    $snapshot=$library->canonicalize(['controls'=>[['formId'=>'lab-contractor-form','name'=>'name','index'=>0,'value'=>'Подмена','label'=>'Наименование']],
        'selected_entities'=>['contractor'=>$reuseContractorId],'removed_documents'=>[]]);
    if (($snapshot['controls'][0]['value']??'')==='Подмена') throw new RuntimeException('Saved card trusted edited read-only data.');
    $linked=$library->documentsForSnapshot($snapshot,[]);
    if (count($linked)!==1 || file_get_contents($linked[0]['tmp_name'])!=='%PDF-1.4 existing card') throw new RuntimeException('Existing file was not linked to review package.');
    $snapshot['removed_documents']=[(int)$detail['files'][0]['id']];
    if ($library->documentsForSnapshot($snapshot,[])!==[]) throw new RuntimeException('Removed existing file remained in review package.');
    $originalContractor=$pdo->query('SELECT * FROM contractors WHERE id='.$reuseContractorId)->fetch(PDO::FETCH_ASSOC);
    $originalDocument=$pdo->query('SELECT * FROM documents WHERE id='.(int)$detail['files'][0]['id'])->fetch(PDO::FETCH_ASSOC);
    $unchanged=$payload('7701234594','А704ВС77');
    $unchanged['existing']=['contractor'=>$reuseContractorId];
    $unchanged['contractor']['name']='Не заменять существующую карточку';
    RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$unchanged,[],101,'company_owner',null,true);
    if ($pdo->query('SELECT * FROM contractors WHERE id='.$reuseContractorId)->fetch(PDO::FETCH_ASSOC)!==$originalContractor
        || $pdo->query('SELECT * FROM documents WHERE id='.(int)$detail['files'][0]['id'])->fetch(PDO::FETCH_ASSOC)!==$originalDocument
        || file_get_contents($existingPath)!=='%PDF-1.4 existing card') throw new RuntimeException('Reusing an entity changed its data or unrelated existing documents.');
    $countBeforeDenied=(int)$pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn();
    AccessRuntime::setCurrent(EffectiveAccess::member(12,7,['company.route_executors'=>true],['contractor'=>'none','driver'=>'all','vehicle_unit'=>'all']));
    try {
        try { RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$unchanged,[],102,'custom_role',null,true); throw new RuntimeException('Document materialization bypassed access to an existing entity.'); }
        catch (DomainException $expected) {}
    } finally { AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11,7,['company.route_executors'])); }
    if ((int)$pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn()!==$countBeforeDenied) throw new RuntimeException('Denied document materialization wrote partial data.');
} finally { @unlink($existingPath); }
$absentPost=$payload('7701234593','А703ВС77');
$absentPost['vehicle']['units']['primary']['vin']='';
$absentPost['vehicle']['units']['primary']['vin_absent']='1';
$absentCrew=RouteExecutorCombinedCreateService::create($pdo,$central,$config,7,$absentPost,[],101,'company_owner');
$absentRow=$pdo->query('SELECT v.vin,v.vin_absent FROM crews c JOIN vehicle_units v ON v.id=c.vehicle_id WHERE c.id='.(int)$absentCrew)->fetch(PDO::FETCH_ASSOC);
if (!$absentRow || $absentRow['vin']!==null || (int)$absentRow['vin_absent']!==1) throw new RuntimeException('Confirmed absent VIN was not persisted as NULL with the separate flag.');
echo "ROUTE_EXECUTOR_COMBINED_TRANSACTION=PASS\n";
