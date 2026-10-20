<?php
declare(strict_types=1);

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Service\RouteExecutorReviewCardService;
use App\Service\RouteExecutorReviewMaterializationService;

$config=require dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/entrypoint_dependencies.php';
require_once BASE_PATH.'/app/Service/RouteExecutorReviewPackage.php';
require_once BASE_PATH.'/app/Service/RouteExecutorReviewCardService.php';
require_once BASE_PATH.'/app/Service/RouteExecutorReviewMaterializationService.php';
$db=(string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV')!=='test' || $db==='' || !in_array($db,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) throw new RuntimeException('Guarded disposable test database required');
$connection=['host'=>getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>getenv('PLANEX_SCHEMA_DB_PORT')?:3306,'database'=>$db,'username'=>getenv('PLANEX_SCHEMA_DB_USER'),'password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD')];
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$connection['host'],$connection['port'],$db),$connection['username'],$connection['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11,7,['company.route_executors']));
$cards=new RouteExecutorReviewCardService($pdo,7);
$materializer=new RouteExecutorReviewMaterializationService($pdo,new Database($connection),$config,7);
if (PHP_SAPI==='cli') {
    if (($argv[1]??'')==='init') {
        cleanCompanyPoolDatabase($pdo);
        applyLocalMigrations($pdo);
        $pdo->exec('CREATE TABLE company_roles(id INT PRIMARY KEY,company_id INT NOT NULL)');
        $pdo->exec('CREATE TABLE user_accounts(id INT PRIMARY KEY,full_name VARCHAR(255) NOT NULL,login VARCHAR(100) NOT NULL,status VARCHAR(20) NOT NULL)');
        $pdo->exec('CREATE TABLE company_memberships(id INT PRIMARY KEY,company_id INT NOT NULL,account_id INT NOT NULL,role_id INT NOT NULL,status VARCHAR(20) NOT NULL)');
        $pdo->exec("INSERT INTO company_roles VALUES(1,7); INSERT INTO user_accounts VALUES(1,'Создатель','owner','active'),(2,'Логист','logist','active'); INSERT INTO company_memberships VALUES(11,7,1,1,'active'),(12,7,2,1,'active')");
        echo "DOCUMENT_ATTACHMENT_FIXTURE_READY\n";
    } elseif (($argv[1]??'')==='cleanup') {
        $paths=$pdo->query('SELECT relative_path FROM documents')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pdo->query('SELECT files_json FROM route_executor_review_cards')->fetchAll(PDO::FETCH_COLUMN) as $json) foreach (json_decode($json,true) as $file) $paths[]=$file['path'];
        foreach ($paths as $path) {
            if (!str_starts_with($path,'companies/7/')) throw new RuntimeException('Unexpected fixture storage path');
            $absolute=storage_path($path);
            if (is_file($absolute)) unlink($absolute);
            if (is_dir(dirname($absolute)) && count(scandir(dirname($absolute)))===2) rmdir(dirname($absolute));
        }
        echo "DOCUMENT_ATTACHMENT_FIXTURE_CLEAN=PASS\n";
    } else throw new RuntimeException('Unknown fixture mode');
    return;
}
if (parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/public/assets/js/route-executor-review-workflow.js') {
    header('Content-Type: text/javascript; charset=utf-8');readfile(BASE_PATH.'/public/assets/js/route-executor-review-workflow.js');return;
}
header('Content-Type: application/json; charset=utf-8');
try {
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if ($path==='/') { header('Content-Type: text/html; charset=utf-8');echo '<table data-route-executors-grid><tbody></tbody></table>';return; }
    if (str_ends_with($path,'/review/status')) $result=['ok'=>true,'stage'=>'new','card'=>$cards->load((string)($_GET['card_id']??''))];
    elseif (str_ends_with($path,'/review/config') && isset($_GET['file_card'])) {
        $file=$cards->file($cards->load((string)$_GET['file_card']),(string)$_GET['file_id']);
        header('Content-Type: '.$file['mime']);readfile($file['absolute_path']);return;
    } elseif (str_ends_with($path,'/review/config')) $result=['ok'=>true,'trips'=>[]];
    elseif ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save') {
        $id=(string)$_POST['card_id'];
        $revision=$cards->save($id,101,(int)$_POST['revision'],json_decode($_POST['snapshot'],true,512,JSON_THROW_ON_ERROR),$_FILES['attachments']??[],(array)($_POST['attachment_keys']??[]));
        $card=$cards->load($id);
        $result=['ok'=>true,'revision'=>$revision,'stored'=>$card['files'],'snapshot'=>$card['snapshot']];
    } elseif ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='materialize') {
        $id=(string)$_POST['card_id'];
        $pdo->prepare("INSERT INTO route_executor_review_sends(card_id,actor_account_id,stage,final_mail_status) VALUES(?,101,'final','accepted')")->execute([$id]);
        $materializer->validateBeforeFinalSend($cards->load($id),101,'company_owner');
        $crew=$materializer->materialize($id,101,'company_owner');
        $documents=$pdo->query('SELECT d.*,t.code,t.name type_name FROM documents d JOIN document_types t ON t.id=d.document_type_id ORDER BY d.id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($documents as &$document) $document['bytes']=base64_encode((string)file_get_contents(storage_path($document['relative_path'])));
        unset($document);
        $result=['ok'=>true,'crew_id'=>$crew,'documents'=>$documents,'active_cards'=>count($cards->listing()),
            'units'=>$pdo->query('SELECT id,plate_number FROM vehicle_units ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)];
    } else $result=['ok'=>true];
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $error) { http_response_code(422);echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_UNICODE); }
