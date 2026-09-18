<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Support/http_runtime.php';
require_once BASE_PATH.'/app/Core/Database.php';
ob_start();
require __DIR__.'/roles_access_session_auth_mysql_test.php';
try {
    foreach (['FeoXlsxParser','FeoRequestService','FeoPlacementAuthorService','MaxEventCatalog','MaxEventNotificationService'] as $class) require_once BASE_PATH.'/app/Service/'.$class.'.php';
    foreach (['122_create_feo_requests.sql','139_create_feo_transfers.sql'] as $file) $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/'.$file));
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mno_adres_pogruzki,mno_sh,mno_d) VALUES('100001','Исходный адрес','37','55'),('100002','Новый адрес','38','56')");
    $config=['database'=>['host'=>$host,'port'=>(string)$port,'database'=>$dbName,'username'=>$user,'password'=>$password]];
    $db=new App\Core\Database($config['database']);
    $registry=new App\Security\PageRegistry();
    $router=new App\Http\Router($registry);
    require BASE_PATH.'/app/Http/Routes/company_feo_transfers.php';
    $resolver=new App\Security\EffectiveAccessResolver($registry,new App\Security\PdoAccessStateProvider($pdo));
    $_SESSION=['account_id'=>103,'user_id'=>103,'company_id'=>7];
    $_SERVER['HTTP_ACCEPT']='application/json';
    $call=static function(string $method,string $path,array $post=[]) use ($router,$resolver): array {
        $router->enableCompanyAccess($resolver,App\Security\AccessRequestContext::fromSession($_SESSION));
        $_POST=$post; http_response_code(200); ob_start(); $router->dispatch($method,$path); $body=ob_get_clean();
        return ['status'=>http_response_code(),'body'=>json_decode($body,true),'raw'=>$body];
    };
    foreach ([['GET','/company/feo/requests/transfers'],['POST','/company/feo/requests/transfers/preview'],['POST','/company/feo/requests/transfers/create'],['GET','/company/feo/requests/transfers/1/json'],['POST','/company/feo/requests/transfers/1/revert'],['GET','/company/feo/requests/transfers/1/export'],['GET','/company/feo/requests/coordinates'],['POST','/company/feo/requests/coordinates/geocode'],['POST','/company/feo/requests/coordinates/settings']] as [$method,$path]) $ok($call($method,$path)['status']===403,'Direct URL denied: '.$path);
    $pdo->exec("INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES(2,'company.feo.transfers',1)");
    $r=$call('POST','/company/feo/requests/transfers/preview',['mode'=>'request','request_ids'=>'100001','source_request_id'=>'100002']);
    $ok($r['status']===200 && $r['body']['success'],'Role-granted preview');
    $token=$r['body']['preview_token'];
    $pdo->exec("INSERT INTO membership_page_overrides(membership_id,page_key,effect) VALUES(13,'company.feo.transfers','deny')");
    $ok($call('POST','/company/feo/requests/transfers/create',['preview_token'=>$token])['status']===403,'Revocation blocks outstanding preview');
    $pdo->exec("UPDATE membership_page_overrides SET effect='allow' WHERE membership_id=13 AND page_key='company.feo.transfers'");
    $r=$call('POST','/company/feo/requests/transfers/create',['preview_token'=>$token]);
    $ok($r['status']===200 && $r['body']['success'],'Personal allow commit: '.$r['raw']);
    $batch=$r['body']['batch_id'];
    $ok($pdo->query('SELECT created_by_user_id FROM feo_transfer_batches')->fetchColumn()===103,'Account identity recorded');
    $ok($call('GET',"/company/feo/requests/transfers/$batch/json")['body']['success'],'Real detail endpoint');
    $_SESSION['company_id']=99;
    $ok($call('POST','/company/feo/requests/transfers/create',['preview_token'=>$token])['status']===403,'Foreign company fails closed');
    $_SESSION['company_id']=7;
    $ok($call('POST','/company/feo/requests/coordinates/settings',['api_key'=>'abcdefghijklmnopqrstuv'])['status']===403,'Manager cannot change API key');
    $ok($call('POST',"/company/feo/requests/transfers/$batch/revert")['body']['success'],'Real package revert endpoint');
    $pdo->exec("INSERT INTO membership_page_overrides(membership_id,page_key,effect) VALUES(13,'company.feo.coordinates','allow')");
    $pdo->exec("UPDATE membership_page_overrides SET effect='deny' WHERE membership_id=13 AND page_key='company.feo.transfers'");
    $ok($call('POST','/company/feo/requests/transfers/preview')['status']===403,'Coordinate-only user cannot transfer');
    $ok($call('POST','/company/feo/requests/coordinates/geocode',['address'=>''])['status']===422,'Coordinate-only user can reach geocoder validation');
    ob_end_clean(); echo "FEO_TRANSFER_ACCESS_MYSQL=PASS role/personal access direct URL revocation tenant isolation real controller create/revert\n";
} finally { cleanCompanyPoolDatabase($pdo); }
