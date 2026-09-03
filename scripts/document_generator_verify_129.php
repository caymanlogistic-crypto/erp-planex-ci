<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR,"CLI/CGI only.\n"); exit(1); }
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[1]??'')),'/');
if($root===''||!is_file($root.'/.env')||!is_file($root.'/bootstrap/app.php'))throw new RuntimeException('PLANEX_ROOT invalid');
$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
$centralCfg=$config['database'];
$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$tableExists=static function(PDO $pdo,string $table):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);return(int)$s->fetchColumn()===1;};
$columnExists=static function(PDO $pdo,string $table,string $column):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->execute([$table,$column]);return(int)$s->fetchColumn()===1;};
$indexExists=static function(PDO $pdo,string $table,string $index):bool{$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? AND NON_UNIQUE=0');$s->execute([$table,$index]);return(int)$s->fetchColumn()>0;};
$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[])throw new RuntimeException('No active tenant databases found');
$snapshot=['central'=>['db'=>(string)$central->query('SELECT DATABASE()')->fetchColumn(),'active_companies'=>count($companies)],'tenants'=>[]];
foreach($companies as$company){
    $cfg=companyDatabaseConfig($config,$company);$pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier'])throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    foreach(['schema_migrations','contractors','generated_documents']as$t)if(!$tableExists($pdo,$t))throw new RuntimeException('Tenant prerequisite table missing for company '.$company['id'].': '.$t);
    $journal=static function(PDO $pdo,string $name):int{$s=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$s->execute([$name]);return(int)$s->fetchColumn();};
    $seqTable=$tableExists($pdo,'document_number_sequences');$seq=null;if($seqTable){$s=$pdo->prepare('SELECT prefix,next_number FROM document_number_sequences WHERE sequence_key=?');$s->execute(['vehicle_lease_crew_contract']);$seq=$s->fetch(PDO::FETCH_ASSOC)?:null;}
    $duplicate=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT document_type,document_number FROM generated_documents WHERE document_number IS NOT NULL GROUP BY document_type,document_number HAVING COUNT(*)>1) d")->fetchColumn();
    $range=(int)$pdo->query("SELECT COUNT(*) FROM generated_documents WHERE document_type='vehicle_lease_crew' AND document_number REGEXP '^П [0-9]+$' AND CAST(SUBSTRING(document_number,3) AS UNSIGNED)>=105")->fetchColumn();
    $snapshot['tenants'][]=[
        'company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],
        'migration_128'=>$journal($pdo,'128_create_waste_trips.sql'),'migration_129'=>$journal($pdo,'129_document_generator_auto_number_and_bank_inn.sql'),
        'contractors'=>(int)$pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn(),'generated_documents'=>(int)$pdo->query('SELECT COUNT(*) FROM generated_documents')->fetchColumn(),
        'bank_inn_column'=>$columnExists($pdo,'contractors','bank_inn')?1:0,'sequence_table'=>$seqTable?1:0,'sequence_row'=>$seq?1:0,
        'sequence_prefix'=>(string)($seq['prefix']??''),'sequence_next'=>(int)($seq['next_number']??0),
        'unique_number_index'=>$indexExists($pdo,'generated_documents','uk_generated_documents_type_number')?1:0,
        'duplicate_document_numbers'=>$duplicate,'auto_range_rows'=>$range,
    ];
}
echo 'DOCGEN_129_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\nDOCGEN_129_READ_ONLY_OK\n";
