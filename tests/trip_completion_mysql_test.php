<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/LocalMigrationService.php';
require_once BASE_PATH.'/app/Service/DocumentService.php';
require_once BASE_PATH.'/app/Service/TripCompletionService.php';
require_once BASE_PATH.'/app/Core/Database.php';
require_once BASE_PATH.'/app/Service/NavigationCounterService.php';
use App\Service\LocalMigrationService;
use App\Service\TripCompletionService;
$ok=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$fails=static function(callable $action):void{try{$action();}catch(DomainException){return;}throw new RuntimeException('Expected validation failure');};
$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if($database===''||!in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true))throw new RuntimeException('Guarded disposable schema required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
cleanCompanyPoolDatabase($pdo);$companyId=987654;$stored=[];
try{
    LocalMigrationService::apply($pdo);$service=new TripCompletionService($pdo,$companyId);$ok($service->ready(),'Migration 150 not ready');
    $pdo->exec("INSERT INTO users(id,membership_id,full_name,login,email,phone,password_hash,role_code,status) VALUES(1,101,'Тестовый логист','tester','manager@example.test','+7 900 000-30-01','hash','logist','active');
      INSERT INTO clients(id,name,inn,status) VALUES(1,'Тестовый клиент','7700000000','active');
      INSERT INTO feo_requests(id,zayavka_id,naim_oo_gruzootpravitel,mno_adres_pogruzki,gruzopoluchatel,adres_dostavki_otkhodov,mass_netto) VALUES
        (1,'274405','Отправитель','Архангельская область','Получатель','Воскресенск',350),(2,'275006','Отправитель 2','Приморский район','Получатель','Воскресенск',135);
      INSERT INTO waste_trips(id,business_id,client_id,responsible_user_id,destination_type,status,actual_start_date,actual_end_date,created_by_membership_id) VALUES
        (1,244,1,1,'warehouse','completed','2026-09-01','2026-09-05',101),(2,245,1,1,'warehouse','in_progress','2026-08-01',NULL,101),(3,246,1,1,'warehouse','in_progress','2026-08-03',NULL,101);
      INSERT INTO waste_trip_requests(id,waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES(11,1,1,350,1),(12,1,2,135,2),(13,2,2,135,1)");
    $pdo->exec("INSERT INTO contractors(id,name,inn) VALUES(1,'Перевозчик','7700000001');
      INSERT INTO contractor_contacts(contractor_id,contact_person,phone,email,is_primary) VALUES(1,'Петров Пётр','+7 900 000-10-01','carrier@example.test',1);
      INSERT INTO drivers(id,full_name,phone) VALUES(1,'Пинчук Антон','+7 900 000-20-01');
      INSERT INTO crews(id,contractor_id,vehicle_id,driver_id) VALUES(1,1,1,1);
      UPDATE waste_trips SET route_executor_id=1 WHERE id=1");
    $rows=$service->trips('',true);$ok(count($rows)===2&&(int)$rows[0]['business_id']===245&&(int)$rows[1]['business_id']===244,'In-progress and completed trips ordered by descending identifier');
    $ok(count($service->trips('',false))===3,'All eligible trips include a trip with no requests');
    $ok($rows[0]['status']==='in_progress'&&$rows[0]['actual_end_date']===null&&$rows[0]['manager_name']==='Тестовый логист','In-progress status, missing end date, and manager visible');
    $trip=$service->trip(1);$ok(array_column($trip['requests'],'zayavka_id')===['275006','274405'],'Requests ordered by descending number');$ok(count($trip['requests'][0]['issues'])===4,'Initial four problems');
    $listRequests=$service->tripListRequests([1,2,3]);
    $ok(array_column($listRequests[1],'zayavka_id')===['275006','274405']&&count($listRequests[2])===1&&!isset($listRequests[3]),'Lightweight list requests preserve order and empty trips');
    $pdo->exec("INSERT INTO waste_trip_completion_items(waste_trip_request_id,tn_datetime_status,recovery_source,data_invented) VALUES(13,'no','invented',1)");
    $legacy=$service->trip(2)['requests'][0];$ok($legacy['recovery_source']==='driver','Legacy invented source renders as driver before backfill');
    $pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/146_remap_trip_completion_invented_source.sql'));
    $legacy=$pdo->query('SELECT recovery_source,data_from_driver,data_from_tracker,data_invented FROM waste_trip_completion_items WHERE waste_trip_request_id=13')->fetch();
    $ok($legacy['recovery_source']==='driver'&&(int)$legacy['data_from_driver']===1&&(int)$legacy['data_invented']===0,'Approved production backfill maps invented to driver');
    $fails(fn()=>$service->save(2,13,['tn_datetime_status'=>'no','recovery_source'=>'invented'],101));
    $ok($trip['manager_name']==='Тестовый логист'&&$trip['manager_phone']==='+7 900 000-30-01'&&$trip['manager_email']==='manager@example.test'&&$trip['driver_phone']==='+7 900 000-20-01','Manager and driver contacts loaded from source records');
    $ok($service->trip(2)['status']==='in_progress','In-progress trip is accessible for request updates');
    $service->save(1,11,['pickup_interval'=>'01.09.26 09:40 - 10:00','driver_signature_status'=>'yes','sender_signature_status'=>'yes','tn_datetime_status'=>'full'],101);
    $service->save(1,12,['pickup_interval'=>'01.09.26 10:40 - 11:00','driver_signature_status'=>'yes','sender_signature_status'=>'yes','tn_datetime_status'=>'no','recovery_source'=>'driver'],101);
    $service->save(2,13,['driver_signature_status'=>'no','sender_signature_status'=>'no','tn_datetime_status'=>'partial','recovery_source'=>'tracker'],101);
    $events=$service->journal(['user'=>'membership:101','kind'=>'marks']);
    $ok($events['total']===3,'Each saved request creates an attributed journal event');
    $beforeCount=$events['total'];
    $service->save(2,13,['driver_signature_status'=>'no','sender_signature_status'=>'no','tn_datetime_status'=>'partial','recovery_source'=>'tracker'],101);
    $ok($service->journal(['user'=>'membership:101','kind'=>'marks'])['total']===$beforeCount,'No-op save must not create a journal event');
    $fails(fn()=>$service->save(1,11,['pickup_interval'=>'01.09.26 12:00 - 11:00'],101));
    $fails(fn()=>$service->save(1,11,['pickup_interval'=>'01.09.26 10:00 - xx:00'],101));
    $stmt=$pdo->query('SELECT arrived_at,departed_at,tn_datetime_status,recovery_source FROM waste_trip_completion_items WHERE waste_trip_request_id=11');$saved=$stmt->fetch();$ok(substr((string)$saved['arrived_at'],0,10)===substr((string)$saved['departed_at'],0,10),'Departure reuses arrival date');$ok($saved['tn_datetime_status']==='full'&&$saved['recovery_source']===null,'Full time needs no recovery source');
    $stmt=$pdo->query('SELECT tn_datetime_missing,tn_datetime_status,recovery_source,data_from_tracker,data_from_driver,data_invented FROM waste_trip_completion_items WHERE waste_trip_request_id=12');$saved=$stmt->fetch();$ok((int)$saved['tn_datetime_missing']===1&&$saved['tn_datetime_status']==='no'&&$saved['recovery_source']==='driver'&&(int)$saved['data_from_tracker']===0&&(int)$saved['data_from_driver']===1&&(int)$saved['data_invented']===0,'Missing time and one recovery source saved');
    $stmt=$pdo->query('SELECT sender_signature_status,driver_signature_status,tn_datetime_status,recovery_source FROM waste_trip_completion_items WHERE waste_trip_request_id=13');$saved=$stmt->fetch();$ok($saved===['sender_signature_status'=>'no','driver_signature_status'=>'no','tn_datetime_status'=>'partial','recovery_source'=>'tracker'],'Explicit no and partial statuses persist');
    foreach([[11,'274405'],[12,'275006']]as[$requestId,$requestNumber]){
        $tmp=tempnam(sys_get_temp_dir(),'tn_');file_put_contents($tmp,base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2Q=='));
        $id=$service->upload(1,$requestId,['name'=>'scan.jpg','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>filesize($tmp)],101,false);
        $doc=$service->document(1,$id);$ok(str_contains($doc['download_name'],'Рейс_244_Заявка_'.$requestNumber.'_ТН_'),'Download filename identity');$stored[]=$service->absoluteDocumentPath($doc);
    }
    $tmp=tempnam(sys_get_temp_dir(),'tn_');file_put_contents($tmp,base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2Q=='));
    $secondId=$service->upload(1,12,['name'=>'second.jpg','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>filesize($tmp)],101,false);$stored[]=$service->absoluteDocumentPath($service->document(1,$secondId));
    $tmp=tempnam(sys_get_temp_dir(),'tn_');file_put_contents($tmp,base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2Q=='));
    try{$fails(fn()=>$service->upload(1,12,['name'=>'third.jpg','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>filesize($tmp)],101,false));}finally{@unlink($tmp);}
    $trip=$service->trip(1);$visible=$service->trips('',true);$ok((int)$trip['complete_count']===2&&count($visible)===1&&(int)$visible[0]['business_id']===245,'Complete trip hidden by default');
    $listRequests=$service->tripListRequests([1]);
    $ok($listRequests[1][0]['document_count']===2&&count($listRequests[1][0]['document_names'])===2&&$listRequests[1][0]['recovery_source']==='driver','Lightweight summaries include scans and normalized form states');
    $completeInput=['pickup_interval'=>'01.09.26 09:40 - 10:00','driver_signature_status'=>'yes','sender_signature_status'=>'yes','tn_datetime_status'=>'full'];
    $service->save(1,11,$completeInput+['tn_absent'=>'1'],101);
    $flagged=$service->trip(1);$flaggedRequest=array_values(array_filter($flagged['requests'],static fn(array $request):bool=>(int)$request['id']===11))[0];
    $ok((int)$flaggedRequest['tn_absent']===1&&in_array('Нет ТН',$flaggedRequest['issues'],true)&&$flagged['complete_count']===1,'Explicit missing TN persists and overrides an uploaded scan');
    $ok(count($service->trips('',true))===2,'Missing TN reopens otherwise complete trip');
    $service->save(1,11,$completeInput,101);
    $trip=$service->trip(1);$ok((int)$trip['complete_count']===2&&count($service->trips('',true))===1,'Clearing missing TN restores trip completion');
    $fails(fn()=>$service->save(1,11,$completeInput+['tn_absent'=>'invalid'],101));
    $xlsx=$service->xlsx($trip);$book=new ZipArchive();$ok($book->open($xlsx)===true,'Excel opens as XLSX');$sheet=(string)$book->getFromName('xl/worksheets/sheet1.xml');$styles=(string)$book->getFromName('xl/styles.xml');$book->close();$ok(str_contains($sheet,'274405')&&str_contains($sheet,'Архангельская область')&&str_contains($sheet,'Восстановлены по трекеру')&&str_contains($sheet,'Внесены со слов вод./мен')&&!str_contains($sheet,'Придуманы')&&str_contains($sheet,'autoFilter ref="A3:Q'),'Formatted Excel contains route, current provenance and filter');$ok(str_contains($styles,'FF0F766E')&&str_contains($styles,'FFFFF1F0')&&str_contains($styles,'numFmtId="164"')&&str_contains($styles,'dd.mm.yy hh:mm'),'Excel styles missing');$xlsxEvidence=trim((string)getenv('PLANEX_XLSX_EVIDENCE'));if($xlsxEvidence!==''){$ok(copy($xlsx,$xlsxEvidence),'Cannot preserve XLSX evidence');}@unlink($xlsx);
    $zip=$service->package($trip);$ok(is_file($zip)&&filesize($zip)>0,'Trip ZIP created');$package=new ZipArchive();$ok($package->open($zip)===true&&$package->locateName('Рейс_244_сводный_отчет.xlsx')!==false,'Formatted Excel included in trip package');$ok($package->locateName('Рейс_244_Заявка_274405_ТН_01.jpg')!==false,'TN scan included with canonical name');$package->close();@unlink($zip);
    $ok($service->reviewProblems($trip)===[],'Complete trip has no review problems');
    $transition=$service->transitionReview(1,0,'submit',101,'tester');
    $ok($transition['status']==='review'&&$transition['version']===1,'Even a complete trip waits for human review');
    $counterConfig=['database'=>['host'=>getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306),
        'database'=>$database,'username'=>getenv('PLANEX_SCHEMA_DB_USER'),'password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD'),'charset'=>'utf8mb4']];
    $badges=App\Service\NavigationCounterService::forCompany($counterConfig,new App\Core\Database($counterConfig['database']),
        $companyId,['id'=>$companyId,'status'=>'active','db_identifier'=>$database]);
    $ok($badges['trip_completion_problem']===2&&$badges['tn_review_pending']===1,
        'Navigation badges must count work trips and review trips, not requests');
    $ok($service->journal(['kind'=>'review'])['total']===1,'Review submission is attributed in the journal');
    $fails(fn()=>$service->save(1,11,$completeInput,101));
    $fails(fn()=>$service->transitionReview(1,1,'archive',101));
    $reviewer=new TripCompletionService($pdo,$companyId,'review');
    $reviewer->save(1,11,$completeInput,101,'checker');
    $archived=$reviewer->transitionReview(1,1,'archive',101,'checker');
    $ok($archived['status']==='archive'&&$archived['version']===2,'Reviewer moves checked trip to archive');
    $fails(fn()=>$reviewer->save(1,11,$completeInput,101));
    $restored=$reviewer->transitionReview(1,2,'restore',101,'checker');
    $ok($restored['status']==='review'&&$restored['version']===3,'Reviewer restores archived trip to review');
    $returned=$reviewer->transitionReview(1,3,'return_work',101,'checker');
    $ok($returned['status']==='work'&&$returned['version']===4,'Reviewer returns checked trip to registrar for corrections');
    $fails(fn()=>$reviewer->transitionReview(1,4,'return_work',101));
    $fails(fn()=>$reviewer->save(1,11,$completeInput,101));
    $service->save(1,11,$completeInput,101,'tester');
    $resubmitted=$service->transitionReview(1,4,'submit',101,'tester');
    $ok($resubmitted['status']==='review'&&$resubmitted['version']===5,'Registrar may submit a corrected trip again');
    $ok($service->journal(['kind'=>'review'])['total']===5,'Review transitions are attributed in the journal');
    $request11=array_values(array_filter($trip['requests'],static fn(array $request):bool=>(int)$request['id']===11))[0];
    $firstDoc=(int)$request11['documents'][0]['id'];$reviewer->softDelete(1,11,$firstDoc,101);
    $ok($service->journal(['kind'=>'scan'])['total']===4,'Upload and deletion are logged');
    $ok(count($service->trips('',true))===2,'Soft-deleted scan reopens incomplete item');
    $pdo->exec('DROP TABLE waste_trip_registration_journal');
    $pdo->exec("DELETE FROM schema_migrations WHERE migration='148_create_registration_journal.sql'");
    $migration=(string)file_get_contents(BASE_PATH.'/database/migrations-local/148_create_registration_journal.sql');
    $pdo->exec($migration);$pdo->exec($migration);
    $initial=$service->journal(['kind'=>'initial']);
    $expected=(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_completion_items')->fetchColumn()+(int)$pdo->query('SELECT COUNT(*) FROM waste_trip_completion_documents')->fetchColumn();
    $ok($initial['total']===$expected && count(array_unique(array_column($initial['rows'],'actor_login')))==1 && $initial['rows'][0]['actor_login']==='enshina','Initial backfill must be complete, idempotent and attributed to enshina');
    echo "TRIP_COMPLETION_MYSQL=PASS\n";
}finally{
    foreach($stored as$path)if(is_file($path))@unlink($path);
    cleanCompanyPoolDatabase($pdo);
}
