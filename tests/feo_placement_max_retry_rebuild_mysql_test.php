<?php
declare(strict_types=1);

use App\Service\FeoMapService;
use App\Service\FeoPlacementRouteNotificationService;
use App\Service\FeoPlacementService;
use App\Service\MaxApiClient;
use App\Service\MaxEventCatalog;
use App\Service\MaxEventNotificationService;
use App\Service\MaxMessagingService;
use App\Service\WasteTripService;

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/crypto_helper.php';
require_once BASE_PATH . '/app/Support/company_database.php';
foreach ([
    'FeoMapService','WasteTripService','FeoPlacementService','FeoPlacementRouteNotificationService',
    'MaxApiClient','MaxMessagingService','MaxEventCatalog','MaxEventNotificationService'
] as $class) require_once BASE_PATH . '/app/Service/' . $class . '.php';

$db=trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow=array_filter(array_map('trim',explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if($db===''||!in_array($db,$allow,true)||getenv('APP_ENV')!=='test') {
    throw new RuntimeException('Guarded fixed test DB required.');
}
$pdo=new PDO(
    'mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$db.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};
cleanCompanyPoolDatabase($pdo);
putenv('APP_ENCRYPTION_KEY=max-feo-rebuild-test');

try {
    $pdo->exec("CREATE TABLE user_accounts(
        id BIGINT UNSIGNED PRIMARY KEY,full_name VARCHAR(255) NOT NULL,max_user_id VARCHAR(32) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE company_memberships(
        id BIGINT UNSIGNED PRIMARY KEY,account_id BIGINT UNSIGNED NOT NULL,company_id INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE users(
        id INT UNSIGNED PRIMARY KEY,membership_id BIGINT UNSIGNED NULL,full_name VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE feo_requests(
        id BIGINT UNSIGNED PRIMARY KEY,zayavka_id VARCHAR(64) NOT NULL UNIQUE,
        mass_netto DECIMAL(18,6) NULL,mno_d VARCHAR(64) NULL,mno_sh VARCHAR(64) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE waste_trips(
        id BIGINT UNSIGNED PRIMARY KEY,business_id BIGINT UNSIGNED NOT NULL,comment VARCHAR(500) NULL,
        status VARCHAR(32) NOT NULL,responsible_user_id INT UNSIGNED NOT NULL,deleted_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE waste_trip_requests(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,waste_trip_id BIGINT UNSIGNED NOT NULL,
        feo_request_id BIGINT UNSIGNED NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/123_create_feo_map_placement.sql'));
    foreach(['133_create_max_integration.sql','136_create_max_event_notifications.sql'] as $migration) {
        $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/'.$migration));
    }

    $pdo->exec("INSERT INTO user_accounts(id,full_name,max_user_id) VALUES(10,'ERP Имя',NULL)");
    $pdo->exec("INSERT INTO company_memberships(id,account_id,company_id) VALUES(20,10,7)");
    $pdo->exec("INSERT INTO users(id,membership_id,full_name) VALUES(30,20,'ERP Имя')");
    $pdo->exec("INSERT INTO waste_trips(id,business_id,comment,status,responsible_user_id)
        VALUES(7,707,'QA route','".WasteTripService::STATUS_IN_PROGRESS."',30)");
    $pdo->exec("INSERT INTO feo_requests(id,zayavka_id,mass_netto,mno_d,mno_sh)
        VALUES(100,'500001',1.000000,'55.75','37.61'),(101,'600001',0.050000,'55.75','37.61')");
    $pdo->exec("INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id) VALUES(7,100)");
    $sent=[];$memberUrls=[];
    $messaging=new MaxMessagingService($pdo,static function(string $token) use (&$sent,&$memberUrls):MaxApiClient {
        return new MaxApiClient($token,static function(string $method,string $url,?array $body) use (&$sent,&$memberUrls):array {
            if(str_contains($url,'/members?user_ids=')){
                $memberUrls[]=$url;
                if(str_contains($url,'user_ids=222222')){
                    return ['members'=>[['user_id'=>222222,'first_name'=>'Мария','last_name'=>'Менеджер']]];
                }
                return ['members'=>[]];
            }
            if(str_contains($url,'/chats/')) return ['type'=>'chat','status'=>'active','title'=>'QA'];
            $sent[]=['text'=>(string)($body['text']??''),'format'=>(string)($body['format']??'plain')];
            return ['message'=>['body'=>['mid'=>'m'.count($sent)]]];
        });
    });
    $bot=$messaging->saveBot(['name'=>'QA','token'=>'token','is_active'=>1]);
    $chat=$messaging->saveChat(['bot_id'=>$bot,'chat_id'=>'-901','name'=>'QA','is_active'=>1]);
    $events=new MaxEventNotificationService($pdo,$messaging);
    $key='feo.placement.block_created';
    $events->saveRule([
        'event_key'=>$key,'chat_config_id'=>$chat,'notify'=>1,
        'message_template'=>MaxEventCatalog::event($key)['template'],'message_format'=>'html','is_enabled'=>0,
    ],77);

    $placement=new FeoPlacementService();
    $routeNotifications=new FeoPlacementRouteNotificationService();
    $preview=$placement->previewCreate($pdo,['600001'],'addition');
    $mainId=null;$suppId=null;$entityKey=null;
    $created=$placement->createBlock(
        $pdo,
        ['ids'=>['600001'],'block_type'=>'addition','created_by_user_id'=>77],
        $preview['state_hash'],
        function(array $row) use($events,$routeNotifications,$pdo,$key,&$mainId,&$suppId,&$entityKey):void {
            $mainId=$events->enqueue($key,(string)$row['block_id'],[
                'action_title'=>'ДОБАВЛЕНО НА КАРТУ','block_id'=>(string)$row['block_id'],
                'block_name'=>'QA','block_type'=>'Добавление','request_count'=>'1','mass_kg'=>'50',
                'request_ids'=>'600001',
                'author'=>'QA','company'=>'QA','created_at'=>'20.09.2026 12:00',
                'current_count'=>'1','current_mass_kg'=>'50',
            ],77);
            $intents=$routeNotifications->intents($pdo,$pdo,7,'addition',['600001']);
            if(count($intents)!==1) throw new RuntimeException('Expected one route intent');
            $intent=$intents[0];
            $entityKey=sprintf('b%d:%s:r%d:p%d',(int)$row['block_id'],$intent['kind'],$intent['route_id'],$intent['part']);
            $suppId=$events->enqueuePrepared(
                $key,$entityKey,(string)$intent['text'],(string)$intent['format'],77,
                isset($intent['error'])?(string)$intent['error']:null
            );
        }
    );
    $ok($mainId>0&&$suppId>0,'main/supplemental delivery IDs');
    $diag=$pdo->query('SELECT * FROM max_event_deliveries WHERE id='.(int)$suppId)->fetch(PDO::FETCH_ASSOC);
    $ok($diag['status']==='pending'&&str_starts_with($diag['message_text'],'‼️ **НЕОБХОДИМО ДОБАВИТЬ В РЕЙС** ‼️')&&!str_contains($diag['message_text'],'max://user/'),'missing MAX ID ready for delivery');
    // Historical rows created before optional addressing must recover even if the ID is still empty.
    $pdo->exec("UPDATE max_event_deliveries SET status='failed',message_text='',error_message='Не заполнен MAX ID' WHERE id=".(int)$suppId);
    $legacy=$events->rebuildFeoSupplementalForRetry((int)$suppId,$pdo,7);
    $ok((int)$legacy['id']===(int)$suppId&&$legacy['status']==='pending'&&$legacy['message_text']===$diag['message_text'],'legacy missing ID retry rebuilds complete unaddressed text');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM max_event_deliveries WHERE event_key='feo.placement.block_created' AND entity_key=".$pdo->quote($entityKey))->fetchColumn()===1,'one logical supplemental row');

    $main=$events->dispatch((int)$mainId);
    $ok($main['status']==='sent','main must send before recovery');
    $ok(count($sent)===1&&str_starts_with($sent[0]['text'],'✅ **ДОБАВЛЕНО НА КАРТУ**'),'main send ordering');

    $pdo->exec("UPDATE user_accounts SET max_user_id='111111' WHERE id=10");
    $pdo->exec("UPDATE user_accounts SET max_user_id='222222' WHERE id=10");
    $pdo->exec("UPDATE max_event_deliveries SET status='sending',updated_at=NOW() WHERE id=".(int)$suppId);
    $activeClaim=$events->rebuildFeoSupplementalForRetry((int)$suppId,$pdo,7);
    $ok($activeClaim['status']==='sending'&&$activeClaim['message_text']===$diag['message_text'],'fresh sending claim must not be rebuilt or reclaimed');
    $pdo->exec("UPDATE max_event_deliveries SET status='failed',updated_at=NOW() WHERE id=".(int)$suppId);
    $rebuilt=$events->rebuildFeoSupplementalForRetry((int)$suppId,$pdo,7);
    $ok((int)$rebuilt['id']===(int)$suppId,'rebuild must keep delivery ID');
    $ok($rebuilt['status']==='pending'&&str_contains($rebuilt['message_text'],'max://user/222222'),'rebuild must use current MAX ID');

    $sentRow=$events->dispatch((int)$suppId,true);
    $ok($sentRow['status']==='sent','rebuilt supplemental must send');
    $ok(count($sent)===2,'exactly main + one supplemental send');
    $ok(str_contains($sent[1]['text'],'[Мария](max://user/222222)'),'MAX profile name must be used in real mention payload');
    $ok(!str_contains($sent[1]['text'],'ERP Имя'),'ERP name must not be used as MAX mention display text');
    $ok(count($memberUrls)===1&&str_contains($memberUrls[0],'user_ids=222222'),'profile lookup must use latest MAX ID');
    $after=$pdo->query('SELECT * FROM max_event_deliveries WHERE id='.(int)$suppId)->fetch(PDO::FETCH_ASSOC);
    $ok((int)$after['attempts']===1,'rebuilt supplemental must have one HTTP attempt');
    $ok(str_contains((string)$after['message_text'],'[Мария](max://user/222222)'),'canonical MAX profile name must be persisted');

    $before=count($sent);
    $again=$events->rebuildFeoSupplementalForRetry((int)$suppId,$pdo,7);
    $ok($again['status']==='sent','sent supplemental must stay terminal during rebuild');
    $again=$events->dispatch((int)$suppId,true);
    $ok($again['status']==='sent'&&count($sent)===$before,'sent supplemental retry must not duplicate');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM max_event_deliveries WHERE event_key='feo.placement.block_created' AND entity_key=".$pdo->quote($entityKey))->fetchColumn()===1,'retry must not create duplicate delivery');

    echo "FEO_PLACEMENT_MAX_RETRY_REBUILD_MYSQL=PASS\n";
    $ownerScenarios = require __DIR__ . '/feo_placement_owner_scenarios.php';
    $ownerScenarios($pdo, false);
    $ownerScenarios($pdo, true);
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    cleanCompanyPoolDatabase($pdo);
}
