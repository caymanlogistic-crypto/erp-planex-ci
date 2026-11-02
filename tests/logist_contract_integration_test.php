<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/company_database.php';
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require_once $path;
    }
});

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $action, string $message): void {
    try { $action(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
}

$schema = (string)getenv('PLANEX_TEST_FIXED_DB');
check($schema !== '' && in_array($schema, explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')), true), 'disposable schema required');
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1', getenv('PLANEX_SCHEMA_DB_PORT') ?: '33316', $schema);
$pdo = new PDO($dsn, (string)getenv('PLANEX_SCHEMA_DB_USER'), (string)getenv('PLANEX_SCHEMA_DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
cleanCompanyPoolDatabase($pdo);
\App\Service\LocalMigrationService::apply($pdo);
check(\App\Service\LogistContractService::schemaReady($pdo), 'migration 164 missing');

$_SESSION = ['role_code'=>'logist','user_id'=>101];
$companyId = 900000 + (int)(abs(crc32($schema)) % 90000);
$scopes = array_fill_keys(['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'],'all');
$scopes['waste_trip'] = 'own';
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::member(1,$companyId,['company.logistics.contracts'=>true],$scopes,'logist'));
$pdo->exec("INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES(101,1,'Карина Г.','karina','hash','logist','active'),(102,2,'Руководитель','owner','hash','company_owner','active')");
$pdo->exec("INSERT INTO clients(id,name,inn,status) VALUES(1,'ООО Клиент','7700000000','active')");
$pdo->exec("INSERT INTO contractors(id,name,inn,kpp,ogrn,contractor_type,legal_address,physical_address,bank_account,bank_name,bank_bik,bank_corr_account,director_full_name,director_position,created_by_user_id,status) VALUES(1,'ООО Исполнитель','7701234567','770101001','1027700000001','legal','Москва','Москва','40702810900000000001','Банк','044525225','30101810400000000225','Петров Петр Петрович','Директор',101,'active')");
$pdo->exec("INSERT INTO drivers(id,full_name,phone,passport_number,passport_issued_by,passport_issue_date,license_number,license_issue_date,created_by_user_id,status) VALUES(1,'Иванов Иван Иванович','+70000000000','4510 123456','ОВД','2020-01-02','77 11 123456','2021-02-03',101,'active')");
$pdo->exec("INSERT INTO vehicle_units(id,unit_type,brand,model,plate_number,vin,created_by_user_id,status) VALUES(1,'truck','КАМАЗ','5490','А123АА77','XTCVIN00000000001',101,'active')");
$pdo->exec("INSERT INTO vehicle_sets(id,primary_vehicle_unit_id,set_type,created_by_user_id,status) VALUES(1,1,'single',101,'active')");
$pdo->exec("INSERT INTO driver_vehicle_blocks(id,driver_id,vehicle_set_id,created_by_user_id,status) VALUES(1,1,1,101,'active')");
$pdo->exec("INSERT INTO crews(id,contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,created_by_user_id,status) VALUES(1,1,1,1,1,101,'active')");
$pdo->exec("INSERT INTO crew_drivers(crew_id,driver_id,position) VALUES(1,1,1)");
$pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto,naim_oo_gruzootpravitel,mno_adres_pogruzki,adres_vygruzki_otkhodov) VALUES('300001',1.0,'Отправитель','Москва, погрузка','Воскресенск')");
$requestId = (int)$pdo->query("SELECT id FROM feo_requests WHERE zayavka_id='300001'")->fetchColumn();
$pdo->exec("INSERT INTO waste_trips(id,business_id,client_id,route_executor_id,responsible_user_id,destination_type,status,planned_start_mode,planned_start_date,comment,created_by_user_id,created_by_role) VALUES(1,463,1,NULL,101,'warehouse','searching_executor','exact','2026-11-03','Кемерово — Воскресенск',101,'logist')");
$pdo->exec("INSERT INTO waste_trips(id,business_id,client_id,route_executor_id,responsible_user_id,destination_type,status,planned_start_mode,planned_start_date,comment,created_by_user_id,created_by_membership_id,created_by_role) VALUES(2,464,1,NULL,102,'warehouse','searching_executor','exact','2026-11-03','Чужой рейс',101,1,'logist')");
$pdo->prepare('INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES(1,?,1000,1)')->execute([$requestId]);

\App\Service\GoogleSheetsRequestContactService::setDefaultFetcherForTests(static fn(string $url): string => "id,c1,c2,c3,c4,c5,c6,c7,c8,contacts\n300001,,,,,,,,,Контакт\n");
\App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo, $companyId, 0, 'system');
$company = ['id'=>$companyId,'name'=>'ООО «ПЛАНЭКС»','inn'=>'5005061234','kpp'=>'500501001','ogrn'=>'1205000000001','legal_address'=>'Воскресенск','physical_address'=>'Воскресенск','bank_name'=>'Банк ПЛАНЭКС','bank_account'=>'40702810000000000001','bank_corr_account'=>'30101810000000000001','bank_bik'=>'044525001','contact_phone'=>'','contact_email'=>'','director_full_name'=>'Карина Артуровна','director_position'=>'Генеральный директор'];
$input = ['trip_id'=>1,'route_executor_id'=>1,'contract_type'=>'lease','start_date'=>'2026-11-03','finish_date'=>'2026-11-10','price'=>'185000','vat_mode'=>'Без НДС'];
$actor = ['user_id'=>101,'role_code'=>'logist'];
check(array_column(\App\Service\LogistContractService::trips($pdo,$actor),'id') === [1], 'Logist sees only assigned trips, even when creator scope includes another trip');
rejects(static fn() => \App\Service\LogistContractService::create($pdo,$company,$companyId,101,'logist',$actor,array_replace($input,['trip_id'=>2])), 'Logist cannot contract another assigned manager trip');
$first = \App\Service\LogistContractService::create($pdo,$company,$companyId,101,'logist',$actor,$input);
check((int)$first['version_no'] === 1 && $first['status'] === 'pending', 'first immutable version');
check(($first['snapshot']['company']['name'] ?? '') === 'ООО «ПЛАНЭКС»'
    && ($first['snapshot']['executor']['name'] ?? '') === 'ООО Исполнитель', 'Planex contracts with the carrier');
$firstPath = \App\Service\LogistContractService::pdfPath($first,$companyId);
check(file_get_contents($firstPath,false,null,0,5) === '%PDF-', 'first signed PDF');
$firstHash = hash_file('sha256',$firstPath);
$second = \App\Service\LogistContractService::create($pdo,$company,$companyId,101,'logist',$actor,array_replace($input,['price'=>'190000']));
check((int)$second['version_no'] === 2 && (int)$second['id'] !== (int)$first['id'], 'new version appended');
check(hash_file('sha256',$firstPath) === $firstHash, 'first PDF not overwritten');
check(\App\Service\LogistContractService::pendingCount($pdo) === 1, 'only latest pending counts');
rejects(static fn() => \App\Service\LogistContractService::review($pdo,(int)$first['id'],102,'approved',''), 'superseded version approved');
\App\Service\LogistContractService::review($pdo,(int)$second['id'],102,'rejected','Уточнить ставку');
check(\App\Service\LogistContractService::pendingCount($pdo) === 0, 'review clears attention');
rejects(static fn() => \App\Service\LogistContractService::get($pdo,(int)$second['id'],102,false), 'another logist can read contract');
rejects(static fn() => \App\Service\LogistContractService::create($pdo,$company,$companyId,101,'logist',$actor,array_replace($input,['route_executor_id'=>2])), 'unlisted executor accepted');
echo "LOGIST_CONTRACT_IMMUTABLE_PDF_REVIEW=PASS\n";
