<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
$centralCfg=$config['database'];
$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$tableExists=static function(PDO $pdo,string $table):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);return(int)$s->fetchColumn()===1;};
$columnExists=static function(PDO $pdo,string $table,string $column):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->execute([$table,$column]);return(int)$s->fetchColumn()===1;};
$indexExists=static function(PDO $pdo,string $table,string $index):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');$s->execute([$table,$index]);return(int)$s->fetchColumn()>0;};
$fkExists=static function(PDO $pdo,string $table,string $constraint):bool{$s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'");$s->execute([$table,$constraint]);return(int)$s->fetchColumn()===1;};
$journal=static function(PDO $pdo,string $name):array{$s=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$s->execute([$name]);$v=$s->fetchColumn();return['present'=>$v!==false?1:0,'checksum'=>$v!==false?(string)$v:''];};
$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[])throw new RuntimeException('No active tenant databases found');
$snapshot=['central'=>['db'=>(string)$central->query('SELECT DATABASE()')->fetchColumn(),'active_companies'=>count($companies)],'tenants'=>[]];
foreach($companies as$company){
    $cfg=companyDatabaseConfig($config,$company);$pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    if(!$tableExists($pdo,'schema_migrations'))throw new RuntimeException('schema_migrations missing for company '.$company['id']);
    $m132=$journal($pdo,'132_split_assignment_and_responsibility.sql');$m133=$journal($pdo,'133_create_max_integration.sql');
    $flags=[];$rows=[];foreach(['max_bots','max_chats','max_message_logs']as$t){$flags[$t]=$tableExists($pdo,$t)?1:0;$rows[$t]=$flags[$t]?(int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn():0;}
    $snapshot['tenants'][]=[
        'company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],
        'migration_132'=>$m132['present'],'migration_133'=>$m133['present'],'migration_133_checksum'=>$m133['checksum'],
        'max_bots_table'=>$flags['max_bots'],'max_chats_table'=>$flags['max_chats'],'max_message_logs_table'=>$flags['max_message_logs'],
        'max_bots_rows'=>$rows['max_bots'],'max_chats_rows'=>$rows['max_chats'],'max_message_logs_rows'=>$rows['max_message_logs'],
        'token_encrypted_column'=>$flags['max_bots']&&$columnExists($pdo,'max_bots','token_encrypted')?1:0,
        'webhook_secret_column'=>$flags['max_bots']&&$columnExists($pdo,'max_bots','webhook_secret_encrypted')?1:0,
        'chat_id_column'=>$flags['max_chats']&&$columnExists($pdo,'max_chats','chat_id')?1:0,
        'message_source_column'=>$flags['max_message_logs']&&$columnExists($pdo,'max_message_logs','source')?1:0,
        'chat_unique'=>$flags['max_chats']&&$indexExists($pdo,'max_chats','uk_max_chat_per_bot')?1:0,
        'chat_bot_fk'=>$flags['max_chats']&&$fkExists($pdo,'max_chats','fk_max_chats_bot')?1:0,
        'log_bot_fk'=>$flags['max_message_logs']&&$fkExists($pdo,'max_message_logs','fk_max_message_logs_bot')?1:0,
        'log_chat_fk'=>$flags['max_message_logs']&&$fkExists($pdo,'max_message_logs','fk_max_message_logs_chat')?1:0,
    ];
}
echo 'MAX_133_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\nMAX_133_READ_ONLY_OK\n";
