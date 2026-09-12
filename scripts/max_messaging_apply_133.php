<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$migrationPath = (string)(getenv('PLANEX_TENANT_MIGRATION_PATH') ?: ($argv[2] ?? ($root . '/database/migrations-local/133_create_max_integration.sql')));
if (!is_file($migrationPath)) throw new RuntimeException('MAX migration 133 file missing');
$sql = file_get_contents($migrationPath);
if (!is_string($sql) || trim($sql) === '') throw new RuntimeException('MAX migration 133 file empty');
$migrationName = '133_create_max_integration.sql';
$checksum = hash('sha256', $sql);
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
$centralCfg = $config['database'];
$central = new PDO('mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4', $centralCfg['username'], $centralCfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$tableExists = static function(PDO $pdo, string $table): bool { $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $s->execute([$table]); return (int)$s->fetchColumn()===1; };
$columnExists = static function(PDO $pdo, string $table, string $column): bool { $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'); $s->execute([$table,$column]); return (int)$s->fetchColumn()===1; };
$indexExists = static function(PDO $pdo, string $table, string $index): bool { $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'); $s->execute([$table,$index]); return (int)$s->fetchColumn()>0; };
$fkExists = static function(PDO $pdo, string $table, string $constraint): bool { $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'"); $s->execute([$table,$constraint]); return (int)$s->fetchColumn()===1; };
$split = static function(string $source): array { $out=[];$buf='';$quote=null;$line=false;$block=false;$len=strlen($source);for($i=0;$i<$len;$i++){ $ch=$source[$i];$next=$i+1<$len?$source[$i+1]:''; if($line){if($ch==="\n"){$line=false;$buf.=$ch;}continue;} if($block){if($ch==='*'&&$next==='/'){$block=false;$i++;}continue;} if($quote!==null){$buf.=$ch;if($ch==='\\'&&$quote!=='`'&&$i+1<$len){$buf.=$source[++$i];continue;}if($ch===$quote){if($next===$quote){$buf.=$next;$i++;}else{$quote=null;}}continue;} if($ch==='-'&&$next==='-'&&($i===0||ctype_space($source[$i-1]))){$line=true;$i++;continue;}if($ch==='#'){$line=true;continue;}if($ch==='/'&&$next==='*'){$block=true;$i++;continue;}if($ch==="'"||$ch==='"'||$ch==='`'){$quote=$ch;$buf.=$ch;continue;}if($ch===';'){$x=trim($buf);if($x!=='')$out[]=$x;$buf='';continue;}$buf.=$ch;} $tail=trim($buf);if($tail!=='')$out[]=$tail;return $out; };
$statements = $split($sql);
if (count($statements) !== 3) throw new RuntimeException('MAX migration 133 statement split failed');
$companies = $central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) throw new RuntimeException('No active tenant databases found');
$applied=0;$existing=0;
foreach ($companies as $company) {
    $cfg=companyDatabaseConfig($config,$company);
    $pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== (string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    if (!$tableExists($pdo,'schema_migrations')) throw new RuntimeException('schema_migrations missing for company '.$company['id']);
    $prereq=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$prereq->execute(['132_split_assignment_and_responsibility.sql']);
    if ((int)$prereq->fetchColumn()!==1) throw new RuntimeException('Migration 132 prerequisite missing for company '.$company['id']);
    $lockName='planex_max_133_'.substr(hash('sha256',(string)$company['db_identifier']),0,20);$lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);
    if ((int)$lock->fetchColumn()!==1) throw new RuntimeException('Tenant lock failed for company '.$company['id']);
    try {
        $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$journal->execute([$migrationName]);$stored=$journal->fetchColumn();
        if ($stored!==false) {
            if (!hash_equals((string)$stored,$checksum)) throw new RuntimeException('Migration 133 checksum mismatch for company '.$company['id']);
            $state='existing';$existing++;
        } else {
            $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>133")->fetchColumn();
            if ($newer>0) throw new RuntimeException('Newer tenant migration exists for company '.$company['id']);
            foreach (['max_bots','max_chats','max_message_logs'] as $target) if ($tableExists($pdo,$target)) throw new RuntimeException('Unjournaled MAX 133 schema exists for company '.$company['id'].': '.$target);
            foreach ($statements as $statement) $pdo->exec($statement);
            $ins=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$ins->execute([$migrationName,$checksum]);
            $state='applied';$applied++;
        }
        foreach (['max_bots','max_chats','max_message_logs'] as $target) if (!$tableExists($pdo,$target)) throw new RuntimeException('Migration 133 table missing for company '.$company['id'].': '.$target);
        foreach ([['max_bots','token_encrypted'],['max_bots','webhook_secret_encrypted'],['max_chats','chat_id'],['max_message_logs','source']] as [$table,$column]) if (!$columnExists($pdo,$table,$column)) throw new RuntimeException('Migration 133 column missing for company '.$company['id'].': '.$table.'.'.$column);
        foreach ([['max_bots','idx_max_bots_active'],['max_chats','uk_max_chat_per_bot'],['max_chats','idx_max_chats_active'],['max_message_logs','idx_max_message_logs_created'],['max_message_logs','idx_max_message_logs_status']] as [$table,$index]) if (!$indexExists($pdo,$table,$index)) throw new RuntimeException('Migration 133 index missing for company '.$company['id'].': '.$index);
        foreach ([['max_chats','fk_max_chats_bot'],['max_message_logs','fk_max_message_logs_bot'],['max_message_logs','fk_max_message_logs_chat']] as [$table,$fk]) if (!$fkExists($pdo,$table,$fk)) throw new RuntimeException('Migration 133 foreign key missing for company '.$company['id'].': '.$fk);
        $counts=[];foreach(['max_bots','max_chats','max_message_logs'] as $target)$counts[$target]=(int)$pdo->query("SELECT COUNT(*) FROM `{$target}`")->fetchColumn();
        printf("MAX_133_COMPANY_OK id=%d db=%s state=%s bots=%d chats=%d logs=%d\n",(int)$company['id'],$company['db_identifier'],$state,$counts['max_bots'],$counts['max_chats'],$counts['max_message_logs']);
    } finally { $r=$pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lockName]); }
}
printf("MAX_133_OK tenants=%d applied=%d existing=%d checksum=%s\n",count($companies),$applied,$existing,$checksum);
