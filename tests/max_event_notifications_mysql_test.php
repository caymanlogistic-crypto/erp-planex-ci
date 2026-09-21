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
    $canonicalTemplate=MaxEventCatalog::event($key)['template'];
    $ok(MaxEventCatalog::currentTemplate($key,$legacyDefault)===$canonicalTemplate,'Legacy default must upgrade to current main template');
    $ok(MaxEventCatalog::currentTemplate($key,'CUSTOM {{block_id}}')==='CUSTOM {{block_id}}','Explicit custom text must be preserved by rendering');
    $rule=['event_key'=>$key,'is_enabled'=>0,'chat_config_id'=>$chat,'message_template'=>$canonicalTemplate,'message_format'=>'html','notify'=>1];
    $firstRule=$events->rules()[0];
    $ok((int)$firstRule['is_enabled']===1 && !empty($firstRule['system_required']),'FEO placement event must be system-required');
    $rejects(fn()=>$events->saveRule(array_replace($rule,['event_key'=>'unknown']),77));
    $rejects(fn()=>$events->saveRule(array_replace($rule,['chat_config_id'=>999999]),77));
    $events->saveRule($rule,77);
    $stored=$pdo->query("SELECT is_enabled,message_template,message_format FROM max_event_rules WHERE event_key='feo.placement.block_created'")->fetch(PDO::FETCH_ASSOC);
    $ok((int)$stored['is_enabled']===1,'System FEO event cannot be disabled');
    $ok(\App\Service\MaxFeoMessageTemplates::decode((string)$stored['message_template'])['main']===$canonicalTemplate,'FEO template bundle must persist');
    $ok((string)$stored['message_format']==='markdown','System FEO main format must be markdown');
    $legacyBrowserTemplates=\App\Service\MaxFeoMessageTemplates::defaults();
    $legacyBrowserTemplates['main']="{{action_icon}} **{{action_title}}**\n**КОЛИЧЕСТВО:** {{count_label}}\n**ОБЩАЯ МАССА:** {{mass_kg}} кг";
    $events->saveRule(array_replace($rule,['chat_config_id'=>$chat2,'feo_templates'=>json_encode($legacyBrowserTemplates,JSON_UNESCAPED_UNICODE)]),77);
    $chatOnlyStored=$pdo->query("SELECT chat_config_id,message_template FROM max_event_rules WHERE event_key='feo.placement.block_created'")->fetch(PDO::FETCH_ASSOC);
    $ok((int)$chatOnlyStored['chat_config_id']===$chat2,'Chat-only save from an old page must persist the selected chat');
    $ok(str_ends_with(\App\Service\MaxFeoMessageTemplates::decode((string)$chatOnlyStored['message_template'])['main'],"\n{{request_ids}}"),'Chat-only save from an old page must upgrade the main template');
    $rejects(fn()=>$events->saveRule(array_replace($rule,['chat_config_id'=>$chat,'feo_templates'=>$legacyBrowserTemplates,'feo_templates_version'=>\App\Service\MaxFeoMessageTemplates::version()]),77));
    $ok((int)$pdo->query("SELECT chat_config_id FROM max_event_rules WHERE event_key='feo.placement.block_created'")->fetchColumn()===$chat2,'Invalid current template must not partially change the chat');
    $events->saveRule($rule,77);
    $additionValues=array_replace($values,['action_title'=>'ДОБАВЛЕНО НА КАРТУ','request_count'=>'4','mass_kg'=>'45 402','request_ids'=>'123456, 123457, 456789, 876676']);
    $exclusionValues=array_replace($values,['action_title'=>'СНЯТИЕ ЗАЯВОК','request_count'=>'4','mass_kg'=>'45 402','request_ids'=>'123456, 123457, 456789, 876676']);
    $ok(MaxEventCatalog::render($key,$canonicalTemplate,'html',$additionValues)==="✅ **ДОБАВЛЕНО НА КАРТУ**\n**КОЛИЧЕСТВО:** 4 заявки\n**ОБЩАЯ МАССА:** 45 402 кг\n123456, 123457, 456789, 876676",'Exact default addition main text with request IDs');
    $ok(MaxEventCatalog::render($key,$canonicalTemplate,'markdown',$exclusionValues)==="❌ **СНЯТО С КАРТЫ**\n**КОЛИЧЕСТВО:** 4 заявки\n**ОБЩАЯ МАССА:** 45 402 кг\n123456, 123457, 456789, 876676",'Exact default exclusion main text with request IDs');
    $transferKey='feo.transfer.created';
    $transferValues=MaxEventCatalog::example($transferKey);
    $htmlEscaped=MaxEventCatalog::render($transferKey,'{{author}} {{quality}}','html',array_replace($transferValues,['author'=>'<b>A&B</b>']));
    $markdownEscaped=MaxEventCatalog::render($transferKey,'{{author}} {{quality}}','markdown',array_replace($transferValues,['author'=>'*A*']));
    $ok(str_starts_with($htmlEscaped,'&lt;b&gt;A&amp;B&lt;/b&gt; '),'HTML variables must be escaped');
    $ok(str_starts_with($markdownEscaped,'\\*A\\* '),'Markdown variables must be escaped');
    $pdo->exec("CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec(file_get_contents(BASE_PATH . '/database/migrations-local/123_create_feo_map_placement.sql'));
    $pdo->exec("INSERT INTO feo_requests VALUES('10001',1.5)");
    $placement=new FeoPlacementService();
    foreach (['addition','exclusion'] as $type) {
        $preview=$placement->previewCreate($pdo,['10001'],$type);
        $deliveryId=null;
        $created=$placement->createBlock($pdo,['ids'=>['10001'],'block_type'=>$type,'created_by_user_id'=>77],$preview['state_hash'],function(array $row) use ($events,$key,$values,$type,&$deliveryId): void {
            $deliveryId=$events->enqueue($key,(string)$row['block_id'],array_replace($values,[
                'block_id'=>(string)$row['block_id'],
                'action_title'=>$type==='addition'?'ДОБАВЛЕНО НА КАРТУ':'СНЯТИЕ ЗАЯВОК',
                'request_count'=>'1',
                'mass_kg'=>'1 500',
                'request_ids'=>'10001',
            ]),77);
        });
        $ok($deliveryId>0,'Both block types must enqueue');
        $before=$sends;
        $sent=$events->dispatch($deliveryId);
        $ok($sent['status']==='sent' && $sends===$before+1,'New block notification failed');
        $expectedMain=($type==='addition'?'✅ **ДОБАВЛЕНО НА КАРТУ**':'❌ **СНЯТО С КАРТЫ**')."\n**КОЛИЧЕСТВО:** 1 заявка\n**ОБЩАЯ МАССА:** 1 500 кг\n10001";
        $ok($sent['message_text']===$expectedMain && $sent['message_format']==='markdown','Exact system main payload mismatch');
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
    $events->saveRule(array_replace($rule,['chat_config_id'=>$chat2,'message_template'=>'Changed '.$canonicalTemplate]),77);
    $row=$events->dispatch($snapshot);
    $ok((int)$row['chat_config_id']===$chat && str_starts_with($row['message_text'],'✅ **ДОБАВЛЕНО НА КАРТУ**'),'Config edits must not redirect or rewrite old system events');
    $events->saveRule($rule,77);
    $pending=$enqueue('required-disabled');
    $pdo->exec("UPDATE max_event_rules SET is_enabled=0 WHERE event_key='feo.placement.block_created'");
    $before=$sends;
    $requiredSent=$events->dispatch($pending);
    $ok($requiredSent['status']==='sent' && $sends===$before+1,'System FEO event must ignore legacy disabled flag');
    $off=$enqueue('required-off');
    $ok($off>0,'System FEO event must enqueue even when legacy is_enabled=0');
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
