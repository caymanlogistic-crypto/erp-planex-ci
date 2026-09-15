<?php
declare(strict_types=1);

// Manual-only Production Control Plane entry point. Default mode is read-only.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$root = rtrim((string)getenv('PLANEX_ROOT'), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$apply = (string)getenv('MAX_EVENTS_MIGRATION_MODE') === 'apply';
if ($apply && getenv('MAX_EVENTS_MIGRATION_CONFIRM') !== 'APPLY_MAX_EVENTS_136') throw new RuntimeException('Explicit migration confirmation missing');
$name = '136_create_max_event_notifications.sql';
$path = (string)(getenv('PLANEX_TENANT_MIGRATION_PATH') ?: $root . '/database/migrations-local/' . $name);
$sql = file_get_contents($path);
if ($sql === false) throw new RuntimeException('Migration SQL missing');
$checksum = hash('sha256', $sql);
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
$connect = static fn(array $c): PDO => new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$central = $connect($config['database']);
$companies = $central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$companies) throw new RuntimeException('No active tenants');
$exists = static function(PDO $pdo,string $table):bool { $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return (int)$q->fetchColumn()===1; };
$targets=['max_event_rules','max_event_deliveries'];
$snapshot=['tenants'=>[]];
foreach ($companies as $company) {
    $pdo=$connect(companyDatabaseConfig($config,$company));
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $company['db_identifier']) throw new RuntimeException('Wrong tenant');
    $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
    $journal->execute(['133_create_max_integration.sql']);
    if ($journal->fetchColumn()===false || !$exists($pdo,'max_chats')) throw new RuntimeException('MAX 133 prerequisite missing');
    $lockName='planex_migrate_'.substr(hash('sha256',$company['db_identifier']),0,32);
    $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Tenant migration lock unavailable');
    try {
        $journal->execute([$name]);$stored=$journal->fetchColumn();
        if ($stored!==false && !hash_equals((string)$stored,$checksum)) throw new RuntimeException('Migration 136 checksum mismatch');
        $flags=array_map(fn(string $table):bool=>$exists($pdo,$table),$targets);
        if ($stored===false && in_array(true,$flags,true)) throw new RuntimeException('Unjournaled MAX events schema');
        if ($stored!==false && in_array(false,$flags,true)) throw new RuntimeException('Incomplete MAX events schema');
        if ($apply && $stored===false) {
            if ((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>136")->fetchColumn()>0) throw new RuntimeException('Newer migration exists');
            $statements=array_values(array_filter(array_map('trim',explode(';',$sql))));
            if(count($statements)!==2) throw new RuntimeException('Unexpected migration statement count');
            foreach($statements as $statement) {
                if (!preg_match('/^CREATE TABLE IF NOT EXISTS `max_event_(rules|deliveries)`/', $statement)) throw new RuntimeException('Migration statement outside allowed scope');
                $pdo->exec($statement);
            }
            $insert=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$insert->execute([$name,$checksum]);
            $stored=$checksum;
        }
        $row=['company_id'=>(int)$company['id'],'migration_136'=>$stored!==false?1:0,'checksum'=>$stored!==false?$stored:null,'tables'=>[]];
        foreach($targets as $table) {
            $present=$exists($pdo,$table);
            $row['tables'][$table]=['present'=>$present,'rows'=>$present?(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn():0];
        }
        if($stored!==false) {
            foreach(['max_event_rules'=>['event_key','is_enabled','chat_config_id','message_template','message_format','notify','updated_by_user_id','updated_at'], 'max_event_deliveries'=>['id','event_key','entity_key','chat_config_id','bot_id','external_chat_id','message_text','message_format','notify','initiated_by_user_id','status','attempts','error_message','max_message_id','created_at','updated_at']] as $table=>$columns) {
                $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
                if(array_diff($columns,$q->fetchAll(PDO::FETCH_COLUMN))) throw new RuntimeException('Migration 136 columns incomplete');
            }
            $indexes=$pdo->query("SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_event_deliveries' AND INDEX_NAME='uk_max_event_entity' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
            if(array_column($indexes,'COLUMN_NAME')!==['event_key','entity_key'] || array_sum(array_column($indexes,'NON_UNIQUE'))!==0) throw new RuntimeException('Event uniqueness missing');
            $fk=$pdo->query("SELECT REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='max_event_rules' AND CONSTRAINT_NAME='fk_max_event_rule_chat'")->fetch(PDO::FETCH_ASSOC);
            if(!$fk || $fk['REFERENCED_TABLE_NAME']!=='max_chats' || $fk['REFERENCED_COLUMN_NAME']!=='id') throw new RuntimeException('Event chat FK missing');
        }
        $snapshot['tenants'][]=$row;
    } finally { $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]); }
}
echo 'MAX_136_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
echo $apply ? "MAX_136_APPLY_OK\n" : "MAX_136_READ_ONLY_OK\n";
