<?php
declare(strict_types=1);
// Test-only router. It is outside public/, requires explicit test mode and a loopback client.
if (PHP_SAPI!=='cli-server' || getenv('APP_ENV')!=='test' || getenv('PLANEX_DOCUMENT_TEST_SERVER')!=='1' || !in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)) {http_response_code(404);exit;}
$name=(string)getenv('PLANEX_TEST_FIXED_DB');
if ($name==='' || !in_array($name,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) {http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (str_starts_with($path,'/assets/')) {
    $public=realpath(dirname(__DIR__,2).'/public');$file=realpath($public.$path);
    if (!$file || !str_starts_with($file,$public.DIRECTORY_SEPARATOR) || !is_file($file)) {http_response_code(404);exit;}
    header('Content-Type: '.(str_ends_with($file,'.css')?'text/css':(str_ends_with($file,'.js')?'text/javascript':'application/octet-stream')));readfile($file);exit;
}
session_start();
define('ERP_MAX_FILE_SIZE',20*1024*1024);
define('ERP_MAX_TOTAL_UPLOAD_SIZE',80*1024*1024);
$config=require dirname(__DIR__,2).'/bootstrap/app.php';
$config['database']=['host'=>getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>getenv('PLANEX_SCHEMA_DB_PORT'),'database'=>$name,'username'=>getenv('PLANEX_SCHEMA_DB_USER'),'password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD')];
require_once BASE_PATH.'/app/Support/entrypoint_dependencies.php';
require_once BASE_PATH.'/app/Service/TripDocumentWorker.php';
$db=new App\Core\Database($config['database']);
if ($path==='/_fixture/actor') {$_SESSION['fixture_actor']=(int)($_GET['member']??11);header('Location: /company/documents/'.($_SESSION['fixture_actor']===79?'collection':'request'));exit;}
$actor=(int)($_SESSION['fixture_actor']??11);
$_SESSION['user_id']=$actor===79?2:1;$_SESSION['company_id']=1;$_SESSION['membership_id']=$actor;$_SESSION['role_code']='logist';$_SESSION['full_name']=$actor===79?'Тестовый Помощник':'Тестовый Логист';$_SESSION['auth_type']='company_user';
$pageRegistry=new App\Security\PageRegistry();
$pages=$pageRegistry->pageKeys();
$access=$actor===79?App\Security\EffectiveAccess::member(79,1,array_fill_keys(['company.documents.collection','company.contractors','company.drivers','company.vehicle_sets','company.route_executors'],true),['contractor'=>'all','driver'=>'all','vehicle_unit'=>'all'],'logist'):App\Security\EffectiveAccess::systemOwner(11,1,$pages);
App\Security\AccessRuntime::setCurrent($access);
class BrowserSyntheticDocumentMail extends App\Service\DocumentMailTransport {
    public function send(#[SensitiveParameter] array $credentials,array $message):string {return (string)($_GET['outcome']??'accepted');}
    public function poll(#[SensitiveParameter] array $credentials,?array $cursor,callable $consume):array {return $cursor??['uidvalidity'=>42,'uid'=>100];}
}
if ($path==='/_fixture/tick') {
    $pdo=$db->connection();$service=new App\Service\TripDocumentCollectionService($pdo,$pdo,1);
    (new App\Service\TripDocumentWorker($service,new BrowserSyntheticDocumentMail()))->run();header('Location: /company/documents/collection');exit;
}
if ($path==='/_fixture/reply') {
    $pdo=$db->connection();$service=new App\Service\TripDocumentCollectionService($pdo,$pdo,1);$id=(int)$pdo->query('SELECT id FROM trip_document_requests ORDER BY id DESC LIMIT 1')->fetchColumn();
    (new App\Service\TripDocumentWorker($service,new BrowserSyntheticDocumentMail()))->ingest([$id],['message_id'=>'<browser.synthetic.reply@example.test>','from'=>'carrier@example.test','subject'=>'Документы по рейсу','text'=>'Прикладываю два документа. Остальные пришлю позже.','attachments'=>[['name'=>'Паспорт_тестовый_длинное_имя_документа.pdf','bytes'=>"%PDF-1.4\nSynthetic passport\n%%EOF"],['name'=>'СТС_машины.pdf','bytes'=>"%PDF-1.4\nSynthetic STS\n%%EOF"]]]);
    header('Location: /company/documents/collection/'.$id);exit;
}
if ($path==='/_fixture/package') {
    require_once __DIR__.'/trip_document_synthetic_cards.php';$pdo=$db->connection();
    if(($_GET['stage']??'')==='minimum') {
        completeDocumentCards($pdo);
        foreach(['passport','driver_license'] as $code)addSyntheticDocument($pdo,'driver',$code);
        addSyntheticDocument($pdo,'vehicle_unit','sts');
        foreach([5=>'spugov',6=>'gluhova',7=>'ashibanov'] as $id=>$login){
            $pdo->prepare("INSERT IGNORE INTO user_accounts(id,full_name,login,normalized_login,password_hash,email) VALUES(?,?,?,?, 'unused',?)")->execute([$id,$login,$login,$login,$login.'@example.test']);
            $pdo->prepare('INSERT IGNORE INTO company_memberships(id,company_id,account_id,role_id) VALUES(?,1,?,2)')->execute([80+$id,$id]);
        }
    } else addSyntheticDocument($pdo,'vehicle_unit','photo');
    header('Location: /company/documents/collection/1');exit;
}
startCsrfFormInjection();verifyCsrfRequest();
$router=new App\Http\Router($pageRegistry);
require BASE_PATH.'/app/Http/Routes/company_trip_documents.php';
foreach (['company_contractors','company_drivers','company_vehicle_sets','company_route_executors'] as $routes) require BASE_PATH.'/app/Http/Routes/'.$routes.'.php';
// The fixture uses the real controller + services and real DB, with explicit page gate.
$page=match(true) {
    str_starts_with($path,'/company/contractors')=>'company.contractors',
    str_starts_with($path,'/company/drivers')=>'company.drivers',
    str_starts_with($path,'/company/vehicle-sets')=>'company.vehicle_sets',
    str_starts_with($path,'/company/route-executors')=>'company.route_executors',
    str_starts_with($path,'/company/documents/request')=>'company.documents.request',
    default=>'company.documents.collection'
};
if (!$access->canAccessPage($page)) {http_response_code(403);echo 'Нет доступа';exit;}
$router->dispatch($_SERVER['REQUEST_METHOD'],$path);
