<?php
declare(strict_types=1);

use App\Service\MaxApiClient;
use App\Service\MaxEventCatalog;
use App\Service\MaxEventNotificationService;
use App\Service\MaxMessagingService;

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/crypto_helper.php';
require_once BASE_PATH . '/app/Support/company_database.php';
foreach (['MaxApiClient','MaxMessagingService','MaxEventCatalog','MaxEventNotificationService'] as $class) {
    require_once BASE_PATH . '/app/Service/' . $class . '.php';
}
$db=trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow=array_filter(array_map('trim',explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if($db===''||!in_array($db,$allow,true)||getenv('APP_ENV')!=='test') throw new RuntimeException('Guarded fixed test DB required.');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$db.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};
cleanCompanyPoolDatabase($pdo);
putenv('APP_ENCRYPTION_KEY=max-feo-delivery-test');
try{
    foreach(['133_create_max_integration.sql','136_create_max_event_notifications.sql'] as $migration){
        $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/'.$migration));
    }
    $sent=[];
    $failNeedle=null;
    $messaging=new MaxMessagingService($pdo,static function(string $token) use (&$sent,&$failNeedle):MaxApiClient{
        return new MaxApiClient($token,static function(string $method,string $url,?array $body) use (&$sent,&$failNeedle):array{
            if(str_contains($url,'/members?user_ids=123456')) return ['members'=>[['user_id'=>123456,'first_name'=>'Иван','last_name'=>'Иванов']]];
            if(str_contains($url,'/members?user_ids=654321')) return ['members'=>[]];
            if(str_contains($url,'/members?user_ids=999999')) throw new RuntimeException('simulated member lookup failure');
            if(str_contains($url,'/chats/')) return ['type'=>'chat','status'=>'active','title'=>'QA'];
            $text=(string)($body['text']??'');
            $sent[]=['text'=>$text,'format'=>$body['format']??'plain'];
            if($failNeedle!==null&&str_contains($text,$failNeedle)) throw new RuntimeException('simulated supplemental failure');
            return ['message'=>['body'=>['mid'=>'m'.count($sent)]]];
        });
    });
    $bot=$messaging->saveBot(['name'=>'QA','token'=>'token','is_active'=>1]);
    $chat=$messaging->saveChat(['bot_id'=>$bot,'chat_id'=>'-901','name'=>'QA','is_active'=>1]);
    $events=new MaxEventNotificationService($pdo,$messaging);
    $key='feo.placement.block_created';
    $events->saveRule([
        'event_key'=>$key,'is_enabled'=>1,'chat_config_id'=>$chat,
        'message_template'=>MaxEventCatalog::event($key)['template'],
        'message_format'=>'plain','notify'=>1
    ],77);
    $values=MaxEventCatalog::example($key);
    $values['action_title']='ДОБАВЛЕНО НА КАРТУ';
    $values['request_count']='4';
    $values['mass_kg']='45 402';
    $values['request_ids']='123456, 123457, 456789, 876676';
    $additionText=MaxEventCatalog::render($key,MaxEventCatalog::event($key)['template'],'plain',$values);
    $ok($additionText==="✅ **ДОБАВЛЕНО НА КАРТУ**\n**КОЛИЧЕСТВО:** 4 заявки\n**ОБЩАЯ МАССА:** 45 402 кг\n123456, 123457, 456789, 876676",'addition main format with request IDs');
    $exclusionValues=$values;
    $exclusionValues['action_title']='СНЯТИЕ ЗАЯВОК';
    $exclusionText=MaxEventCatalog::render($key,MaxEventCatalog::event($key)['template'],'plain',$exclusionValues);
    $ok($exclusionText==="❌ **СНЯТО С КАРТЫ**\n**КОЛИЧЕСТВО:** 4 заявки\n**ОБЩАЯ МАССА:** 45 402 кг\n123456, 123457, 456789, 876676",'exclusion main format with request IDs');

    $pdo->beginTransaction();
    $main=$events->enqueue($key,'42',$values,77);
    $supp=$events->enqueuePrepared($key,'b42:nearby:r7:p1','[ERP Alias](max://user/123456) nearby','markdown',77);
    $same=$events->enqueuePrepared($key,'b42:nearby:r7:p1','DIFFERENT','markdown',77);
    $pdo->commit();
    $ok($main>0&&$supp>0&&$same===$supp,'prepared delivery idempotency');

    $before=count($sent);
    $blocked=$events->dispatch($supp,true);
    $ok($blocked['status']==='failed'&&count($sent)===$before,'supplemental must not precede main');
    $ok(str_contains((string)$blocked['error_message'],'Основное уведомление'),'dependency diagnostic');

    $mainRow=$events->dispatch($main);
    $ok($mainRow['status']==='sent','main send failed');
    $dependent=$events->dispatchBlockSupplementals(42,true);
    $ok(count($dependent)===1&&$dependent[0]['status']==='sent','supplemental retry after main');
    $ok(count($sent)===$before+2,'main+supplemental send count');
    $ok(str_starts_with($sent[$before]['text'],'✅ **ДОБАВЛЕНО НА КАРТУ**'),'main must be first');
    $ok(str_contains($sent[$before+1]['text'],'[Иван Иванов](max://user/123456)'),'supplemental must use current MAX profile name');
    $ok(!str_contains($sent[$before+1]['text'],'ERP Alias'),'ERP display name must not survive real mention dispatch');
    $ok($sent[$before+1]['format']==='markdown','mention must use markdown');

    // A configured manager who is absent from the selected chat must not block the group message.
    $pdo->beginTransaction();
    $main43=$events->enqueue($key,'43',$values,77);
    $absent=$events->enqueuePrepared($key,'b43:same_point:r8:p1',"‼️ [Менеджер](max://user/654321) · **ДОБАВИТЬ В РЕЙС** ‼️\n**#253 Новороссийск**",'markdown',77);
    $pdo->commit();
    $events->dispatch($main43);
    $absentRow=$events->dispatch($absent,true);
    $ok($absentRow['status']==='sent','absent chat member must fall back to a group message');
    $absentText=(string)$sent[array_key_last($sent)]['text'];
    $ok(!str_contains($absentText,'max://user/')&&!str_contains($absentText,'Менеджер'),'fallback group message must not retain a fake mention');
    $ok(!str_contains($absentText,' · ')&&str_contains($absentText,'**ДОБАВИТЬ В РЕЙС**'),'fallback must remove the mention separator and preserve business text');
    $ok((string)$pdo->query("SELECT message_text FROM max_event_deliveries WHERE id=".$absent)->fetchColumn()===$absentText,'fallback text must be persisted exactly as sent');

    // API/transport failures remain failures and must not be silently downgraded.
    $pdo->beginTransaction();
    $main45=$events->enqueue($key,'45',$values,77);
    $lookupFailure=$events->enqueuePrepared($key,'b45:nearby:r11:p1','[Менеджер](max://user/999999) · lookup failure','markdown',77);
    $pdo->commit();
    $events->dispatch($main45);
    $sentBeforeLookupFailure=count($sent);
    $lookupFailureRow=$events->dispatch($lookupFailure,true);
    $ok($lookupFailureRow['status']==='failed'&&count($sent)===$sentBeforeLookupFailure,'member lookup transport failure must remain failed');
    $ok(str_contains((string)$lookupFailureRow['error_message'],'simulated member lookup failure'),'member lookup failure diagnostic missing');

    // Missing MAX ID or other recipient diagnostics are durable and never fake-send.
    $pdo->beginTransaction();
    $diag=$events->enqueuePrepared($key,'b42:same_point:r8:p1','','markdown',77,'Не заполнен MAX ID ответственного менеджера Иван Иванов.');
    $pdo->commit();
    $sentBefore=count($sent);
    $diagRow=$events->dispatch($diag,true);
    $ok($diagRow['status']==='failed'&&count($sent)===$sentBefore,'diagnostic delivery must not send');

    // One supplemental may fail while the next one succeeds.
    $pdo->beginTransaction();
    $main44=$events->enqueue($key,'44',$values,77);
    $failId=$events->enqueuePrepared($key,'b44:nearby:r9:p1','FAIL supplemental','markdown',77);
    $okId=$events->enqueuePrepared($key,'b44:nearby:r10:p1','OK supplemental','markdown',77);
    $pdo->commit();
    $events->dispatch($main44);
    $failNeedle='FAIL';
    $rows=$events->dispatchBlockSupplementals(44,true);
    $failNeedle=null;
    $statuses=array_column($rows,'status');
    $ok($statuses===['failed','sent'],'independent supplemental failure handling');
    $ok((int)$pdo->query("SELECT attempts FROM max_event_deliveries WHERE id=".$failId)->fetchColumn()===1,'failed supplemental attempted once');
    $ok((int)$pdo->query("SELECT attempts FROM max_event_deliveries WHERE id=".$okId)->fetchColumn()===1,'successful supplemental attempted once');

    $transferKey='feo.transfer.created';
    $events->saveRule(['event_key'=>$transferKey,'is_enabled'=>1,'chat_config_id'=>$chat,
        'message_template'=>MaxEventCatalog::event($transferKey)['template'],
        'message_format'=>'plain','notify'=>1],77);
    $pdo->beginTransaction();
    $transferMain=$events->enqueue($transferKey,'2000',MaxEventCatalog::example($transferKey),77);
    $transferPart=$events->enqueuePrepared($transferKey,'b2000:main:p1','ЗАЯВКИ: **310501**','markdown',77);
    $transferWarning=$events->enqueuePrepared($transferKey,'b2000:route_warning:r0:p1','ПЕРЕМЕЩЕННЫЕ ЗАЯВКИ: #376','markdown',77);
    $pdo->commit();
    $before=count($sent);
    $blocked=$events->dispatch($transferWarning,true);
    $ok($blocked['status']==='failed'&&count($sent)===$before,'Route warning waits for root transfer message');
    $ok($events->dispatch($transferMain)['status']==='sent','Root transfer message sends first');
    $blocked=$events->dispatch($transferWarning,true);
    $ok($blocked['status']==='failed'&&str_contains((string)$blocked['error_message'],'Части основного уведомления')&&count($sent)===$before+1,'Route warning waits for every main continuation');
    $ok($events->dispatch($transferPart)['status']==='sent','Transfer continuation sends second');
    $ok($events->dispatch($transferWarning,true)['status']==='sent'&&count($sent)===$before+3,'Route warning sends after all main parts');
    $ok($sent[$before]['format']==='markdown','Approved transfer main uses Markdown despite old plain setting');

    echo "FEO_PLACEMENT_MAX_DELIVERY_MYSQL=PASS\n";
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    cleanCompanyPoolDatabase($pdo);
}
