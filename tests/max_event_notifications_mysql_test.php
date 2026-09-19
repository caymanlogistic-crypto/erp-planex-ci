<?php
declare(strict_types=1);

use App\Service\MaxApiClient;
use App\Service\MaxMessagingService;
use App\Service\MaxEventCatalog;
use App\Service\MaxEventNotificationService;
use App\Service\FeoPlacementService;

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/crypto_helper.php';
require_once BASE_PATH . '/app/Support/company_database.php';
foreach (['MaxApiClient','MaxMessagingService','MaxEventCatalog','MaxEventNotificationService','FeoPlacementService'] as $class) require_once BASE_PATH . '/app/Service/' . $class . '.php';
$db = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($db === '' || !in_array($db, $allow, true) || getenv('APP_ENV') !== 'test') throw new RuntimeException('Guarded fixed test DB required.');
$pdo = new PDO('mysql:host=' . getenv('PLANEX_SCHEMA_DB_HOST') . ';port=' . (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306) . ';dbname=' . $db . ';charset=utf8mb4', getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$ok = static function(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$rejects = static function(callable $fn) use ($ok): void { $caught=false; try{$fn();}catch(RuntimeException){$caught=true;} $ok($caught,'Expected validation rejection'); };
cleanCompanyPoolDatabase($pdo);
putenv('APP_ENCRYPTION_KEY=max-event-local-test-only');
try {
    $events = new MaxEventNotificationService($pdo);
    $ok(!$events->ready() && $events->recentDeliveries() === [], 'Pre-migration rollout must be safe');
    foreach (['133_create_max_integration.sql','136_create_max_event_notifications.sql'] as $migration) $pdo->exec(file_get_contents(BASE_PATH . '/database/migrations-local/' . $migration));
    $sends=0; $fail=false;
    $messaging = new MaxMessagingService($pdo, static function(string $token) use (&$sends, &$fail, $pdo, $ok): MaxApiClient {
        return new MaxApiClient($token, static function(string $method,string $url,?array $body) use (&$sends,&$fail,$pdo,$ok): array {
            if (str_contains($url,'/chats/')) return ['type'=>'chat','status'=>'active','title'=>'Test'];
            $ok(!$pdo->inTransaction(), 'External API called inside business transaction');
            $sends++;
            if ($fail) throw new RuntimeException('Simulated MAX timeout');
            return ['message'=>['body'=>['mid'=>'event-'.$sends]]];
        });
    });
    $bot=$messaging->saveBot(['name'=>'Test','token'=>'local','is_active'=>1]);
    $chat=$messaging->saveChat(['bot_id'=>$bot,'chat_id'=>'-9001','name'=>'Test','is_active'=>1]);
    $chat2=$messaging->saveChat(['bot_id'=>$bot,'chat_id'=>'-9002','name'=>'Other','is_active'=>1]);
    $events = new MaxEventNotificationService($pdo,$messaging);
    $key='feo.placement.block_created'; $values=MaxEventCatalog::example($key);
    $legacyDefault="Создан блок ФЭО №{{block_id}}: {{block_name}}\nТип: {{block_type}}\nЗаявок: {{request_count}}, масса: {{mass_kg}} кг\nОтветственный: {{author}}\nКомпания: {{company}}\n{{created_at}}";
    $ok(MaxEventCatalog::currentTemplate($key,$legacyDefault)===MaxEventCatalog::event($key)['template'],'Legacy default must upgrade to current main template');
    $ok(MaxEventCatalog::currentTemplate($key,'CUSTOM {{block_id}}')==='CUSTOM {{block_id}}','Custom template must remain custom');
    $rule=['event_key'=>$key,'is_enabled'=>1,'chat_config_id'=>$chat,'message_template'=>'Блок {{block_id}} {{author}}','message_format'=>'plain','notify'=>1];
    $ok((int)$events->rules()[0]['is_enabled']===0,'Events must be opt-in');
    $rejects(fn()=>$events->saveRule(array_replace($rule,['event_key'=>'unknown']),77));
    $rejects(fn()=>$events->saveRule(array_replace($rule,['chat_config_id'=>999999]),77));
    $rejects(fn()=>$events->saveRule(array_replace($rule,['message_template'=>'{{unknown}}']),77));
    $rejects(fn()=>$events->saveRule(array_replace($rule,['message_template'=>str_repeat('x',4001)]),77));
    $ok(MaxEventCatalog::render($key,'{{author}}','html',array_replace($values,['author'=>'<b>A&B</b>']))==='&lt;b&gt;A&amp;B&lt;/b&gt;','HTML variables must be escaped');
    $ok(MaxEventCatalog::render($key,'{{author}}','markdown',array_replace($values,['author'=>'*A*']))==='\\*A\\*','Markdown variables must be escaped');
    $events->saveRule($rule,77);
    $pdo->exec("CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec(file_get_contents(BASE_PATH . '/database/migrations-local/123_create_feo_map_placement.sql'));
    $pdo->exec("INSERT INTO feo_requests VALUES('10001',1.5)");
    $placement=new FeoPlacementService();
    foreach (['addition','exclusion'] as $type) {
        $preview=$placement->previewCreate($pdo,['10001'],$type);
        $deliveryId=null;
        $created=$placement->createBlock($pdo,['ids'=>['10001'],'block_type'=>$type,'created_by_user_id'=>77],$preview['state_hash'],function(array $row) use ($events,$key,$values,&$deliveryId): void {
            $deliveryId=$events->enqueue($key,(string)$row['block_id'],array_replace($values,['block_id'=>(string)$row['block_id']]),77);
        });
        $ok($deliveryId>0,'Both block types must enqueue');
        $before=$sends;
        $sent=$events->dispatch($deliveryId);
        $ok($sent['status']==='sent' && $sends===$before+1,'New block notification failed');
        $events->dispatch($deliveryId,true);
        $ok($sends===$before+1,'Sent delivery duplicated');
        $pdo->beginTransaction();
        $same=$events->enqueue($key,(string)$created['block_id'],$values,77); $pdo->commit();
        $ok($same===$deliveryId,'Duplicate event must retain original delivery');
    }
    $beforeBlocks=(int)$pdo->query('SELECT COUNT(*) FROM feo_placement_blocks')->fetchColumn();
    $beforeDeliveries=(int)$pdo->query('SELECT COUNT(*) FROM max_event_deliveries')->fetchColumn();
    $preview=$placement->previewCreate($pdo,['10001'],'addition');
    $rejects(fn()=>$placement->createBlock($pdo,['ids'=>['10001'],'block_type'=>'addition'],$preview['state_hash'],function(array $row) use ($events,$key,$values):void {
        $events->enqueue($key,(string)$row['block_id'],$values,77); throw new RuntimeException('Rollback fixture');
    }));
    $ok((int)$pdo->query('SELECT COUNT(*) FROM feo_placement_blocks')->fetchColumn()===$beforeBlocks && (int)$pdo->query('SELECT COUNT(*) FROM max_event_deliveries')->fetchColumn()===$beforeDeliveries,'Rollback must remove block and event together');
    $enqueue=static function(string $entity) use ($pdo,$events,$key,$values):?int { $pdo->beginTransaction();$id=$events->enqueue($key,$entity,$values,77);$pdo->commit();return $id; };
    $pending=$enqueue('failure'); $fail=true;
    $ok($events->dispatch($pending)['status']==='failed','Transport failure must be durable');
    $before=$sends; $events->dispatch($pending); $ok($sends===$before,'Failed delivery must not retry automatically');
    $fail=false;
    $ok($events->dispatch($pending,true)['status']==='sent','Explicit retry failed');
    $snapshot=$enqueue('snapshot');
    $events->saveRule(array_replace($rule,['chat_config_id'=>$chat2,'message_template'=>'Changed']),77);
    $row=$events->dispatch($snapshot);
    $ok((int)$row['chat_config_id']===$chat && str_starts_with($row['message_text'],'Блок'),'Config edits must not redirect old events');
    $events->saveRule($rule,77);
    $pending=$enqueue('disabled'); $events->saveRule(array_replace($rule,['is_enabled'=>0]),77);
    $before=$sends; $ok($events->dispatch($pending)['status']==='failed' && $sends===$before,'Disabled event must stop pending delivery');
    $ok($enqueue('off')===null,'Disabled rule must not enqueue');
    $events->saveRule($rule,77);
    $pending=$enqueue('race');
    $pdo->exec("UPDATE max_event_deliveries SET status='sending',updated_at=NOW() WHERE id=".$pending);
    $before=$sends; $events->dispatch($pending,true); $ok($sends===$before,'Concurrent claim must not be retried');
    $pdo->exec("UPDATE max_event_deliveries SET updated_at=DATE_SUB(NOW(),INTERVAL 6 MINUTE) WHERE id=".$pending);
    $ok($events->dispatch($pending,true)['status']==='sent','Stale claim explicit recovery failed');
    $pending=$enqueue('address');
    $pdo->exec("UPDATE max_chats SET chat_id='-changed' WHERE id=".$chat);
    $before=$sends; $ok($events->dispatch($pending)['status']==='failed' && $sends===$before,'Changed recipient must fail closed');
    echo "MAX_EVENTS_MYSQL=PASS templates routing atomicity idempotency failures retry snapshot\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cleanCompanyPoolDatabase($pdo);
}
