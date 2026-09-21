<?php
declare(strict_types=1);

/** Configure only empty settings; never overwrite an owner's choices or send messages. */
function activateFeoTransfers(PDO $pdo, string $providedKey, Closure $probe): array
{
    if (!preg_match('/^[a-zA-Z0-9_-]{20,200}$/D', $providedKey)) throw new RuntimeException('Invalid geocoder key');
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $lock = 'planex_feo_'.substr(hash('sha256', $database), 0, 24);
    $q = $pdo->prepare('SELECT GET_LOCK(?,10)'); $q->execute([$lock]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('FEO lock unavailable');
    $digest = static function() use ($pdo): string {
        $h = hash_init('sha256');
        foreach (['feo_requests','feo_transfer_batches','feo_transfer_items','feo_location_overrides','feo_transfer_outbox','max_event_deliveries','max_message_logs'] as $table) {
            $rows = $pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $encoded = array_map(static fn(array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $rows);
            sort($encoded, SORT_STRING); hash_update($h, $table."\n".implode("\n", $encoded));
        }
        return hash_final($h);
    };
    try {
        $pdo->beginTransaction();
        $before = $digest();
        $saved = $pdo->query('SELECT api_key_encrypted FROM geocoding_settings WHERE id=1 FOR UPDATE')->fetchColumn();
        $key = $saved === false ? $providedKey : cryptoDecrypt((string)$saved);
        $result = $probe($key); // Verify the actual key before storing it; errors roll back.
        if (!isset($result['precision'], $result['latitude'], $result['longitude'])) throw new RuntimeException('Geocoder probe failed');
        if ($saved === false) $pdo->prepare('INSERT INTO geocoding_settings(id,api_key_encrypted) VALUES(1,?)')->execute([cryptoEncrypt($key)]);
        $source = $pdo->query("SELECT r.* FROM max_event_rules r JOIN max_chats c ON c.id=r.chat_config_id JOIN max_bots b ON b.id=c.bot_id WHERE r.event_key='feo.placement.block_created' AND r.is_enabled=1 AND c.is_active=1 AND c.max_status='active' AND b.is_active=1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
        $events = new App\Service\MaxEventNotificationService($pdo);
        $status = [];
        foreach (['feo.transfer.created','feo.transfer.reverted','feo.request.weight_changed'] as $event) {
            $q = $pdo->prepare('SELECT is_enabled,chat_config_id FROM max_event_rules WHERE event_key=? FOR UPDATE'); $q->execute([$event]);
            $existing = $q->fetch(PDO::FETCH_ASSOC);
            if ($existing) { $status[$event] = ['state'=>'preserved','enabled'=>(bool)$existing['is_enabled'],'chat_config_id'=>$existing['chat_config_id']]; continue; }
            if (!$source) { $status[$event] = ['state'=>'needs_max_configuration','enabled'=>false]; continue; }
            $events->saveRule(['event_key'=>$event,'is_enabled'=>1,'chat_config_id'=>$source['chat_config_id'],
                'message_template'=>App\Service\MaxEventCatalog::event($event)['template'],'message_format'=>'plain','notify'=>$source['notify']], null);
            $status[$event] = ['state'=>'copied_from_placement','enabled'=>true,'chat_config_id'=>$source['chat_config_id']];
        }
        if ($digest() !== $before) throw new RuntimeException('Business data changed during activation');
        $pdo->commit();
        return ['key_configured'=>true,'key_action'=>$saved===false?'stored_encrypted':'preserved','geocode_precision'=>$result['precision'],'events'=>$status,'business_data_unchanged'=>true,'messages_sent'=>0];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
if (PHP_SAPI !== 'cli' || getenv('FEO_ACTIVATION_CONFIRM') !== 'CONFIGURE_FEO_TRANSFERS') throw new RuntimeException('Explicit activation confirmation required');
$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$key = trim((string)stream_get_contents(STDIN)); // Secret travels over encrypted stdin, never argv/logs.
$config = require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
foreach (['YandexGeocodingService','MaxEventCatalog','MaxEventNotificationService'] as $class) require_once $root.'/app/Service/'.$class.'.php';
$connect = static fn(array $c): PDO => new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$central = $connect($config['database']);
$companies = $central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$companies) throw new RuntimeException('No active tenants');
foreach ($companies as $company) {
    $pdo = $connect(companyDatabaseConfig($config, $company));
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $company['db_identifier']) throw new RuntimeException('Wrong tenant');
    $q = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?'); $q->execute(['139_create_feo_transfers.sql']);
    if (!hash_equals(hash_file('sha256', $root.'/database/migrations-local/139_create_feo_transfers.sql'), (string)$q->fetchColumn())) throw new RuntimeException('Migration139 missing/checksum mismatch');
    $result = activateFeoTransfers($pdo, $key, static fn(string $k): array => (new App\Service\YandexGeocodingService($k))->geocode('Москва, Красная площадь, 1'));
    echo 'FEO_ACTIVATION_TENANT='.json_encode(['company_id'=>(int)$company['id']]+$result, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}
echo "FEO_ACTIVATION_PASS\n";
