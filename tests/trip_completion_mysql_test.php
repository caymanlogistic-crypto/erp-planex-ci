<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/LocalMigrationService.php';
require_once BASE_PATH.'/app/Service/DocumentService.php';
require_once BASE_PATH.'/app/Service/TripCompletionService.php';
use App\Service\LocalMigrationService;
use App\Service\TripCompletionService;
$ok=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$fails=static function(callable $action):void{try{$action();}catch(DomainException){return;}throw new RuntimeException('Expected validation failure');};
$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if($database===''||!in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true))throw new RuntimeException('Guarded disposable schema required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
cleanCompanyPoolDatabase($pdo);$companyId=987654;$stored=[];
try{
    LocalMigrationService::apply($pdo);$service=new TripCompletionService($pdo,$companyId);$ok($service->ready(),'Migration 142 not ready');
    $pdo->exec("INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES(1,101,'Тестовый логист','tester','hash','logist','active');
      INSERT INTO clients(id,name,inn,status) VALUES(1,'Тестовый клиент','7700000000','active');
      INSERT INTO feo_requests(id,zayavka_id,naim_oo_gruzootpravitel,mno_adres_pogruzki,gruzopoluchatel,adres_dostavki_otkhodov,mass_netto) VALUES
        (1,'274405','Отправитель','Архангельская область','Получатель','Воскресенск',350),(2,'275006','Отправитель 2','Приморский район','Получатель','Воскресенск',135);
      INSERT INTO waste_trips(id,business_id,client_id,responsible_user_id,destination_type,status,actual_start_date,actual_end_date,created_by_membership_id) VALUES
        (1,244,1,1,'warehouse','completed','2026-09-01','2026-09-05',101),(2,245,1,1,'warehouse','completed','2026-08-01','2026-08-02',101);
      INSERT INTO waste_trip_requests(id,waste_trip_id,feo_request_id,snapshot_mass_kg,position_no) VALUES(11,1,1,350,1),(12,1,2,135,2),(13,2,2,135,1)");
    $rows=$service->trips('',true);$ok(count($rows)===2&&(int)$rows[0]['business_id']===245&&(int)$rows[1]['business_id']===244,'Trips ordered by descending identifier');
    $trip=$service->trip(1);$ok(array_column($trip['requests'],'zayavka_id')===['275006','274405'],'Requests ordered by descending number');$ok(count($trip['requests'][0]['issues'])===4,'Initial four problems');
    $service->save(1,11,['pickup_interval'=>'01.09.26 09:40 - 10:00','driver_signature_present'=>'1','sender_signature_present'=>'1'],101);
    $service->save(1,12,['pickup_interval'=>'01.09.26 10:40 - 11:00','driver_signature_present'=>'1','sender_signature_present'=>'1'],101);
    $fails(fn()=>$service->save(1,11,['pickup_interval'=>'01.09.26 12:00 - 11:00'],101));
    $fails(fn()=>$service->save(1,11,['pickup_interval'=>'01.09.26 10:00 - xx:00'],101));
    $stmt=$pdo->query('SELECT arrived_at,departed_at FROM waste_trip_completion_items WHERE waste_trip_request_id=11');$saved=$stmt->fetch();$ok(substr((string)$saved['arrived_at'],0,10)===substr((string)$saved['departed_at'],0,10),'Departure reuses arrival date');
    foreach([[11,'274405'],[12,'275006']]as[$requestId,$requestNumber]){
        $tmp=tempnam(sys_get_temp_dir(),'tn_');file_put_contents($tmp,base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2Q=='));
        $id=$service->upload(1,$requestId,['name'=>'scan.jpg','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>filesize($tmp)],101,false);
        $doc=$service->document(1,$id);$ok(str_contains($doc['download_name'],'Рейс_244_Заявка_'.$requestNumber.'_ТН_'),'Download filename identity');$stored[]=$service->absoluteDocumentPath($doc);
    }
    $trip=$service->trip(1);$visible=$service->trips('',true);$ok((int)$trip['complete_count']===2&&count($visible)===1&&(int)$visible[0]['business_id']===245,'Complete trip hidden by default');
    $xlsx=$service->xlsx($trip);$book=new ZipArchive();$ok($book->open($xlsx)===true,'Excel opens as XLSX');$sheet=(string)$book->getFromName('xl/worksheets/sheet1.xml');$styles=(string)$book->getFromName('xl/styles.xml');$book->close();$ok(str_contains($sheet,'274405')&&str_contains($sheet,'Архангельская область')&&str_contains($sheet,'autoFilter'),'Formatted Excel contains route and filter');$ok(str_contains($styles,'FF0F766E')&&str_contains($styles,'FFFFF1F0')&&str_contains($styles,'numFmtId="22"'),'Excel styles missing');$xlsxEvidence=trim((string)getenv('PLANEX_XLSX_EVIDENCE'));if($xlsxEvidence!==''){$ok(copy($xlsx,$xlsxEvidence),'Cannot preserve XLSX evidence');}@unlink($xlsx);
    $zip=$service->package($trip);$ok(is_file($zip)&&filesize($zip)>0,'Trip ZIP created');$package=new ZipArchive();$ok($package->open($zip)===true&&$package->locateName('Рейс_244_сводный_отчет.xlsx')!==false,'Formatted Excel included in trip package');$ok($package->locateName('Рейс_244_Заявка_274405_ТН_01.jpg')!==false,'TN scan included with canonical name');$package->close();@unlink($zip);
    $request11=array_values(array_filter($trip['requests'],static fn(array $request):bool=>(int)$request['id']===11))[0];
    $firstDoc=(int)$request11['documents'][0]['id'];$service->softDelete(1,11,$firstDoc,101);
    $ok(count($service->trips('',true))===2,'Soft-deleted scan reopens work item');
    echo "TRIP_COMPLETION_MYSQL=PASS\n";
}finally{
    foreach($stored as$path)if(is_file($path))@unlink($path);
    cleanCompanyPoolDatabase($pdo);
}
