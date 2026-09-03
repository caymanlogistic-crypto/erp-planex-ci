<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR,"CLI/CGI only.\n"); exit(1); }
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[1]??'')),'/');
if($root===''||!is_file($root.'/.env')||!is_file($root.'/bootstrap/app.php'))throw new RuntimeException('PLANEX_ROOT invalid');
$migrationPath=(string)(getenv('PLANEX_TENANT_MIGRATION_PATH')?:($argv[2]??($root.'/database/migrations-local/129_document_generator_auto_number_and_bank_inn.sql')));
if(!is_file($migrationPath))throw new RuntimeException('Document generator migration 129 file missing');
$sql=file_get_contents($migrationPath);if(!is_string($sql)||trim($sql)==='')throw new RuntimeException('Document generator migration 129 file empty');
$migrationName='129_document_generator_auto_number_and_bank_inn.sql';$checksum=hash('sha256',$sql);
$config=require $root.'/bootstrap/app.php';require_once $root.'/app/Support/crypto_helper.php';require_once $root.'/app/Support/company_database.php';
$centralCfg=$config['database'];$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$tableExists=static function(PDO $pdo,string $table):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);return(int)$s->fetchColumn()===1;};
$columnExists=static function(PDO $pdo,string $table,string $column):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->execute([$table,$column]);return(int)$s->fetchColumn()===1;};
$indexExists=static function(PDO $pdo,string $table,string $index):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? AND NON_UNIQUE=0');$s->execute([$table,$index]);return(int)$s->fetchColumn()>0;};
$split=static function(string $source):array{$out=[];$buf='';$quote=null;$line=false;$block=false;$len=strlen($source);for($i=0;$i<$len;$i++){$ch=$source[$i];$next=$i+1<$len?$source[$i+1]:'';if($line){if($ch==="\n"){$line=false;$buf.=$ch;}continue;}if($block){if($ch==='*'&&$next==='/'){$block=false;$i++;}continue;}if($quote!==null){$buf.=$ch;if($ch==='\\'&&$quote!=='`'&&$i+1<$len){$buf.=$source[++$i];continue;}if($ch===$quote){if($next===$quote){$buf.=$next;$i++;}else{$quote=null;}}continue;}if($ch==='-'&&$next==='-'&&($i===0||ctype_space($source[$i-1]))){$line=true;$i++;continue;}if($ch==='#'){$line=true;continue;}if($ch==='/'&&$next==='*'){$block=true;$i++;continue;}if($ch==="'"||$ch==='"'||$ch==='`'){$quote=$ch;$buf.=$ch;continue;}if($ch===';'){$x=trim($buf);if($x!=='')$out[]=$x;$buf='';continue;}$buf.=$ch;}$tail=trim($buf);if($tail!=='')$out[]=$tail;return$out;};
$statements=$split($sql);if(count($statements)<10)throw new RuntimeException('Document generator migration 129 statement split failed');
$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);if($companies===[])throw new RuntimeException('No active tenant databases found');
$applied=0;$existing=0;
foreach($companies as$company){
    $cfg=companyDatabaseConfig($config,$company);$pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    foreach(['schema_migrations','contractors','generated_documents']as$t)if(!$tableExists($pdo,$t))throw new RuntimeException('Tenant prerequisite table missing for company '.$company['id'].': '.$t);
    $p=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$p->execute(['128_create_waste_trips.sql']);if((int)$p->fetchColumn()!==1)throw new RuntimeException('Migration 128 journal prerequisite missing for company '.$company['id']);
    $lockName='planex_docgen_129_'.substr(hash('sha256',(string)$company['db_identifier']),0,20);$lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Tenant lock failed for company '.$company['id']);
    try{
        $journal=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$journal->execute([$migrationName]);$stored=$journal->fetchColumn();
        if($stored!==false){if(!hash_equals((string)$stored,$checksum))throw new RuntimeException('Migration 129 checksum mismatch for company '.$company['id']);$state='existing';$existing++;}
        else{
            $newer=(int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED)>129")->fetchColumn();if($newer>0)throw new RuntimeException('Newer tenant migration exists for company '.$company['id']);
            $range=(int)$pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_type='vehicle_lease_crew' AND document_number REGEXP '^П [0-9]+$' AND CAST(SUBSTRING(document_number,3) AS UNSIGNED)>=105")->fetchColumn();
            if($range>0)throw new RuntimeException('AUTO NUMBER RANGE COLLISION for company '.$company['id'].': existing vehicle_lease_crew number П N with N >= 105');
            $duplicates=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT document_type,document_number FROM generated_documents WHERE document_number IS NOT NULL GROUP BY document_type,document_number HAVING COUNT(*)>1) d")->fetchColumn();
            if($duplicates>0)throw new RuntimeException('DUPLICATE DOCUMENT NUMBER COLLISION for company '.$company['id']);
            if($columnExists($pdo,'contractors','bank_inn')||$tableExists($pdo,'document_number_sequences')||$indexExists($pdo,'generated_documents','uk_generated_documents_type_number'))throw new RuntimeException('Unjournaled migration 129 schema exists for company '.$company['id']);
            foreach($statements as$statement)$pdo->exec($statement);
            $ins=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$ins->execute([$migrationName,$checksum]);$state='applied';$applied++;
        }
        if(!$columnExists($pdo,'contractors','bank_inn'))throw new RuntimeException('Migration 129 bank_inn missing for company '.$company['id']);
        if(!$tableExists($pdo,'document_number_sequences'))throw new RuntimeException('Migration 129 sequence table missing for company '.$company['id']);
        if(!$indexExists($pdo,'generated_documents','uk_generated_documents_type_number'))throw new RuntimeException('Migration 129 unique index missing for company '.$company['id']);
        $s=$pdo->prepare('SELECT prefix,next_number FROM document_number_sequences WHERE sequence_key=?');$s->execute(['vehicle_lease_crew_contract']);$seq=$s->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$seq||(string)$seq['prefix']!=='П '||(int)$seq['next_number']<105)throw new RuntimeException('Migration 129 sequence row invalid for company '.$company['id']);
        printf("DOCGEN_129_COMPANY_OK id=%d db=%s state=%s next=%d\n",(int)$company['id'],$company['db_identifier'],$state,(int)$seq['next_number']);
    }finally{$r=$pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$lockName]);}
}
printf("DOCGEN_129_OK tenants=%d applied=%d existing=%d checksum=%s\n",count($companies),$applied,$existing,$checksum);
