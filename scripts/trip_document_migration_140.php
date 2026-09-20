<?php
declare(strict_types=1);

// Production Control Plane only. Default is a read-only snapshot, never runtime DDL.
if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
$root=rtrim((string)getenv('PLANEX_ROOT'),'/');
if (!is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$apply=getenv('DOCUMENT_COLLECTION_MIGRATION_MODE')==='apply';
if ($apply && getenv('DOCUMENT_COLLECTION_MIGRATION_CONFIRM')!=='APPLY_DOCUMENT_COLLECTION_140') throw new RuntimeException('Explicit migration confirmation missing');
$name='140_create_trip_document_collection.sql';
$path=getenv('PLANEX_TENANT_MIGRATION_PATH')?:$root.'/database/migrations-local/'.$name;
$sql=file_get_contents($path); if ($sql===false) throw new RuntimeException('Migration SQL missing');
$checksum=hash('sha256',$sql);
$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
$connect=static fn(array $c):PDO=>new PDO('mysql:host='.$c['host'].';port='.($c['port']??3306).';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$central=$connect($config['database']);
$companies=$central->query("SELECT * FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$companies) throw new RuntimeException('No active tenants');
$targets=['trip_document_requests', 'trip_document_mail', 'trip_document_incoming', 'trip_document_events', 'trip_document_mailboxes', 'trip_document_previews'];
$expectedColumns=[
 'trip_document_requests'=>['id','company_id','request_token','trip_type','trip_id','legal_entity_id','initiator_membership_id','responsible_membership_id','assistant_membership_id','assistant_account_id','responsible_account_id','sender_profile_id','sender_email','responsible_email','contact_name','contact_email','contact_phone','promised_at','next_check_at','next_action','response_received_at','package_stage','version','created_at','updated_at'],
 'trip_document_mail'=>['id','request_id','operation_key','kind','message_id','actor_membership_id','sender_profile_id','snapshot_json','snapshot_hash','status','error_message','attempts','attempted_at','accepted_at','created_at'],
 'trip_document_incoming'=>['id','request_id','candidate_request_ids','identity_hash','message_id','sender','subject','body_text','attachments_json','received_at','reviewed_at'],
 'trip_document_events'=>['id','request_id','event_key','action','description','context_json','actor_membership_id','max_delivery_id','max_error','created_at'],
 'trip_document_mailboxes'=>['profile_id','account_id','legal_entity_id','email','uidvalidity','last_uid','last_checked_at','error_message'],
 'trip_document_previews'=>['token','membership_id','request_id','kind','input_json','snapshot_json','snapshot_hash','expires_at','consumed_mail_id'],
];
$exists=static function(PDO $p,string $table):bool {$q=$p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return (int)$q->fetchColumn()===1;};
$snapshot=['tenants'=>[]];
foreach ($companies as $company) {
    $pdo=$connect(companyDatabaseConfig($config,$company));
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database!==$company['db_identifier']) throw new RuntimeException('Wrong tenant');
    $locks=['planex_migrate_'.substr(hash('sha256',$database),0,32),'planex_documents_'.substr(hash('sha256',$database),0,32)]; $held=[];
    try {
        foreach ($locks as $lock) {$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Migration/document lock unavailable');$held[]=$lock;}
        $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $journal->execute(['139_create_feo_transfers.sql']); if($journal->fetchColumn()===false || !$exists($pdo,'feo_requests'))throw new RuntimeException('Migration 139 prerequisite missing');
        $journal->execute([$name]); $stored=$journal->fetchColumn();
        if($stored!==false && !hash_equals((string)$stored,$checksum))throw new RuntimeException('Migration 140 checksum mismatch');
        $flags=array_map(fn(string $table):bool=>$exists($pdo,$table),$targets);
        if($stored===false && in_array(true,$flags,true))throw new RuntimeException('Unjournaled document collection schema');
        if($stored!==false && in_array(false,$flags,true))throw new RuntimeException('Incomplete document collection schema');
        if($apply && $stored===false) {
            if((int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE CAST(LEFT(migration,3) AS UNSIGNED)>140")->fetchColumn()>0)throw new RuntimeException('Newer migration exists');
            $withoutComments=preg_replace('/^\s*--[^\n]*$/m','',$sql);
            $statements=array_values(array_filter(array_map('trim',explode(';',(string)$withoutComments))));
            if(count($statements)!==6)throw new RuntimeException('Unexpected migration statement count');
            foreach($statements as $index=>$statement) {
                if(!preg_match('/^CREATE TABLE IF NOT EXISTS ([a-z_]+)\s*\(/',$statement,$match) || $match[1]!==$targets[$index])throw new RuntimeException('Migration statement outside allowed scope');
                $pdo->exec($statement);
            }
            $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)')->execute([$name,$checksum]); $stored=$checksum;
        }
        $row=['company_id'=>(int)$company['id'],'migration_140'=>$stored!==false?1:0,'checksum'=>$stored!==false?$stored:null,'tables'=>[]];
        foreach($targets as $table) {
            $present=$exists($pdo,$table);
            $row['tables'][$table]=['present'=>$present,'rows'=>$present?(int)$pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn():0];
            if($stored!==false) {
                $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION'); $q->execute([$table]);
                if($q->fetchAll(PDO::FETCH_COLUMN)!==$expectedColumns[$table])throw new RuntimeException('Migration 140 column mismatch');
            }
        }
        $hash=hash_init('sha256'); $count=0;
        $q=$pdo->query('SELECT * FROM waste_trips ORDER BY id');
        while($request=$q->fetch(PDO::FETCH_ASSOC)) {hash_update($hash,json_encode($request,JSON_THROW_ON_ERROR)."\n");$count++;}
        $row['trip_rows']=$count; $row['trip_sha256']=hash_final($hash);
        $snapshot['tenants'][]=$row;
    } finally {foreach(array_reverse($held) as $lock)$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}
echo 'DOCUMENT_140_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
echo $apply?"DOCUMENT_140_APPLY_OK\n":"DOCUMENT_140_READ_ONLY_OK\n";
