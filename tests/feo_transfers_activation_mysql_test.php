<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/crypto_helper.php';
require_once BASE_PATH.'/app/Support/company_database.php';
foreach (['MaxEventCatalog','MaxEventNotificationService'] as $class) require_once BASE_PATH.'/app/Service/'.$class.'.php';
require_once BASE_PATH.'/scripts/feo_transfers_activate.php';
$db = (string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV')!=='test'||!$db||!in_array($db,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) throw new RuntimeException('Guarded test DB required');
$pdo = new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$db.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$ok = static function(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); };
putenv('APP_ENCRYPTION_KEY=activation-test-only');
cleanCompanyPoolDatabase($pdo);
try {
    foreach (['122_create_feo_requests.sql','133_create_max_integration.sql','136_create_max_event_notifications.sql','139_create_feo_transfers.sql'] as $m) $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/'.$m));
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mno_adres_pogruzki,mno_sh,mno_d) VALUES('123456','Keep address','37','55')");
    $original = $pdo->query('SELECT * FROM feo_requests')->fetchAll(PDO::FETCH_ASSOC);
    $probe = static fn(string $key): array => ['precision'=>'exact','latitude'=>'55','longitude'=>'37'];
    $key = str_repeat('a',36); $failed = false;
    try { activateFeoTransfers($pdo,$key,static function(): array { throw new RuntimeException('API unavailable'); }); } catch (RuntimeException) { $failed=true; }
    $ok($failed && (int)$pdo->query('SELECT COUNT(*) FROM geocoding_settings')->fetchColumn()===0,'Failed API must not save settings');
    $result = activateFeoTransfers($pdo,$key,$probe);
    $ok($result['events']['feo.transfer.created']['state']==='needs_max_configuration','No routing guessed without placement rule');
    $stored = (string)$pdo->query('SELECT api_key_encrypted FROM geocoding_settings')->fetchColumn();
    $ok($stored!==$key && cryptoDecrypt($stored)===$key,'Key encrypted');
    $pdo->exec("INSERT INTO max_bots(name,token_encrypted,webhook_secret_encrypted,is_active) VALUES('Synthetic','encrypted','encrypted',1)");
    $bot=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO max_chats(bot_id,chat_id,name,max_status,is_active) VALUES(?,'-9001','Placement','active',1)")->execute([$bot]);
    $chat=(int)$pdo->lastInsertId(); $events=new App\Service\MaxEventNotificationService($pdo);
    $events->saveRule(['event_key'=>'feo.placement.block_created','is_enabled'=>1,'chat_config_id'=>$chat,'message_template'=>App\Service\MaxFeoMessageTemplates::defaults()['main'],'message_format'=>'plain','notify'=>0],null);
    $result=activateFeoTransfers($pdo,str_repeat('b',36),static function(string $actual) use($key,$ok,$probe): array {$ok($actual===$key,'Existing owner key preserved');return $probe($actual);});
    foreach ($result['events'] as $event=>$state) {
        $ok($state['enabled'] && (int)$state['chat_config_id']===$chat,'Copy exact current-company placement routing');
        $s=$pdo->prepare('SELECT * FROM max_event_rules WHERE event_key=?');$s->execute([$event]);$r=$s->fetch(PDO::FETCH_ASSOC);
        $requiredVariable = $event === 'feo.request.weight_changed' ? '{{changes}}'
            : ($event === 'feo.transfer.reverted' ? '{{returned_requests}}' : '{{request_ids}}');
        $ok(str_contains($r['message_template'],$requiredVariable) && (int)$r['notify']===0,'Event template and notify preference preserved');
    }
    $pdo->exec("UPDATE max_event_rules SET is_enabled=0 WHERE event_key='feo.transfer.created'");
    $before=$pdo->query('SELECT * FROM max_event_rules ORDER BY event_key')->fetchAll(PDO::FETCH_ASSOC);
    activateFeoTransfers($pdo,$key,$probe);
    $ok($before===$pdo->query('SELECT * FROM max_event_rules ORDER BY event_key')->fetchAll(PDO::FETCH_ASSOC),'Idempotent; preserve owner event choice');
    $ok($stored===$pdo->query('SELECT api_key_encrypted FROM geocoding_settings')->fetchColumn(),'Existing ciphertext unchanged');
    $ok($original===$pdo->query('SELECT * FROM feo_requests')->fetchAll(PDO::FETCH_ASSOC),'Business rows unchanged');
    foreach (['feo_transfer_batches','feo_transfer_outbox','max_event_deliveries','max_message_logs'] as $t) $ok((int)$pdo->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn()===0,'No synthetic batches/messages');
    echo "FEO_ACTIVATION_MYSQL=PASS encrypted key, failed probe rollback, routing, idempotency, business data\n";
} finally { cleanCompanyPoolDatabase($pdo); }
