<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root.'/.env') || !is_file($root.'/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$seedPath = (string)(getenv('PLANEX_FEO_STAGE1_SEED_PATH') ?: ($argv[2] ?? (__DIR__.'/feo_stage1_utilizer_seed_data.php')));
if (!is_file($seedPath)) throw new RuntimeException('Stage 1 utilizer seed data missing');
$targetCompanyId=(int)(getenv('PLANEX_TARGET_COMPANY_ID') ?: 0);
$targetDbIdentifier=trim((string)getenv('PLANEX_TARGET_DB_IDENTIFIER'));
if ($targetCompanyId!==25 || $targetDbIdentifier!=='spugovxsim_1') throw new RuntimeException('Production seed target must be exact PLANEX company 25 / spugovxsim_1');

$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Service/FeoUtilizerService.php';
$seed=require $seedPath;
$utilizerSeed=is_array($seed['utilizers']??null)?$seed['utilizers']:[];
$aliasSeed=is_array($seed['aliases']??null)?$seed['aliases']:[];
if(count($utilizerSeed)!==8) throw new RuntimeException('Unexpected Stage 1 utilizer cardinality');

$centralCfg=$config['database'];
$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$q=$central->prepare("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE id=? LIMIT 1");
$q->execute([$targetCompanyId]);
$company=$q->fetch(PDO::FETCH_ASSOC);
if(!$company || (string)$company['status']!=='active' || (string)$company['db_identifier']!==$targetDbIdentifier) throw new RuntimeException('Exact PLANEX target company is not active or DB identity changed');
$cfg=companyDatabaseConfig($config,$company);
$pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==$targetDbIdentifier) throw new RuntimeException('Unexpected tenant database');

$required=['feo_requests','feo_placement_current','feo_utilizers','feo_utilizer_aliases','schema_migrations'];
$marks=implode(',',array_fill(0,count($required),'?'));
$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$marks.')');
$q->execute($required);
if((int)$q->fetchColumn()!==count($required)) throw new RuntimeException('Stage 1 schema prerequisites missing');
foreach(['125_create_feo_map_utilizers.sql','126_create_feo_utilizer_aliases.sql'] as $migration){
    $q=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$q->execute([$migration]);
    if((int)$q->fetchColumn()!==1) throw new RuntimeException('Required migration is not journaled: '.$migration);
}

$lockName='planex_feo_stage1_seed_'.substr(hash('sha256',$targetDbIdentifier),0,20);
$q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$lockName]);
if((int)$q->fetchColumn()!==1) throw new RuntimeException('Stage 1 seed lock failed');
$ids=[];$created=0;$existing=0;$createdAliases=0;$existingAliases=0;
$equal=static function(array $row,array $u):bool{
    $same=static fn($a,$b):bool=>abs((float)$a-(float)$b)<0.0000001;
    return (string)$row['full_name']===(string)$u['full_name']
        &&(string)($row['address']??'')===(string)$u['address']
        &&(string)($row['fkko_classification']??'')===(string)$u['fkko_classification']
        &&(int)$row['adr']===(int)$u['adr']
        &&$same($row['latitude'],$u['latitude'])
        &&$same($row['longitude'],$u['longitude'])
        &&strtoupper((string)$row['color_hex'])===strtoupper((string)$u['color_hex'])
        &&(int)$row['is_active']===1
        &&(int)$row['sort_order']===(int)$u['sort_order'];
};
try{
    $pdo->beginTransaction();
    foreach($utilizerSeed as $u){
        $short=(string)$u['short_name'];
        $q=$pdo->prepare('SELECT * FROM feo_utilizers WHERE short_name=? FOR UPDATE');$q->execute([$short]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if($row){
            if(!$equal($row,$u)) throw new RuntimeException('Existing utilizer drift detected for '.$short.'; refusing to overwrite');
            $ids[$short]=(int)$row['id'];$existing++;continue;
        }
        $q=$pdo->prepare('INSERT INTO feo_utilizers (full_name,short_name,address,latitude,longitude,fkko_classification,adr,color_hex,is_active,sort_order,created_by_user_id,updated_by_user_id) VALUES (?,?,?,?,?,?,?,?,1,?,NULL,NULL)');
        $q->execute([$u['full_name'],$short,$u['address'],$u['latitude'],$u['longitude'],$u['fkko_classification'],$u['adr'],strtoupper((string)$u['color_hex']),$u['sort_order']]);
        $ids[$short]=(int)$pdo->lastInsertId();$created++;
    }
    foreach($aliasSeed as $a){
        $short=(string)($a['utilizer']??'');$alias=trim((string)($a['alias']??''));
        if(!isset($ids[$short])||$alias==='') throw new RuntimeException('Invalid alias seed');
        $normalized=App\Service\FeoUtilizerService::normalizeText($alias);
        $q=$pdo->prepare('SELECT utilizer_id,alias FROM feo_utilizer_aliases WHERE normalized_alias=? FOR UPDATE');$q->execute([$normalized]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if($row){
            if((int)$row['utilizer_id']!==$ids[$short]||(string)$row['alias']!==$alias) throw new RuntimeException('Existing alias drift detected for '.$alias);
            $existingAliases++;continue;
        }
        $q=$pdo->prepare('INSERT INTO feo_utilizer_aliases (utilizer_id,alias,normalized_alias) VALUES (?,?,?)');
        $q->execute([$ids[$short],$alias,$normalized]);$createdAliases++;
    }
    $pdo->commit();
    printf("FEO_STAGE1_SEED_OK company_id=%d db=%s utilizers_created=%d utilizers_existing=%d aliases_created=%d aliases_existing=%d\n",$targetCompanyId,$targetDbIdentifier,$created,$existing,$createdAliases,$existingAliases);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
}finally{
    $q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);
}
