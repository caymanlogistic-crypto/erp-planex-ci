<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit(1);}
$config=require dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Core/Database.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/TripDocumentWorker.php';
// Uses the application's PHP timezone, like the existing card and trip controllers.
$options=getopt('',['company:','check']);
$companyId=filter_var($options['company']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$companyId) {fwrite(STDERR,"Usage: php scripts/trip_document_worker.php --company=<id> [--check]\n");exit(2);}
try {
    $central=(new App\Core\Database($config['database']))->connection();
    $q=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active'");$q->execute([$companyId]);$company=$q->fetch(PDO::FETCH_ASSOC);
    if (!$company) throw new RuntimeException('Company unavailable');
    $tenant=(new App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
    $service=new App\Service\TripDocumentCollectionService($central,$tenant,$companyId);
    if (!$service->ready()) throw new RuntimeException('Document schema is not ready');
    if(abs(strtotime((string)$tenant->query('SELECT NOW()')->fetchColumn())-time())>60)throw new RuntimeException('Application and database timezone mismatch');
    if (isset($options['check'])) {echo "DOCUMENT_WORKER_READY=YES\n";exit(0);}
    $result=(new App\Service\TripDocumentWorker($service))->run();
    $dir=storage_path('runtime');if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Runtime directory unavailable');
    if(file_put_contents($dir.'/trip_documents_'.$companyId.'.json',json_encode(['checked_at'=>date(DATE_ATOM),'result'=>$result],JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Heartbeat unavailable');
    // No credentials, messages, addresses or document content in scheduler logs.
    echo json_encode(['company_id'=>$companyId,'result'=>$result],JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {fwrite(STDERR,"DOCUMENT_WORKER_FAILED: inspect mail and MAX status in ERP.\n");exit(1);}
