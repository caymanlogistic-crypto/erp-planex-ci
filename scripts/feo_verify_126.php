<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') { fwrite(STDERR, "CLI/CGI only.\n"); exit(1); }
$root=rtrim((string)(getenv('PLANEX_ROOT')?:($argv[1]??'')),'/');
if($root===''||!is_file($root.'/.env')||!is_file($root.'/bootstrap/app.php')) throw new RuntimeException('PLANEX_ROOT invalid');
$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
$centralCfg=$config['database'];
$central=new PDO('mysql:host='.$centralCfg['host'].';port='.($centralCfg['port']??3306).';dbname='.$centralCfg['database'].';charset=utf8mb4',$centralCfg['username'],$centralCfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$companies=$central->query("SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if($companies===[]) throw new RuntimeException('No active tenant databases found');
$snapshot=[];
foreach($companies as $company){
    $cfg=companyDatabaseConfig($config,$company);
    $pdo=new PDO('mysql:host='.$cfg['host'].';port='.($cfg['port']??3306).';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if((string)$pdo->query('SELECT DATABASE()')->fetchColumn()!==(string)$company['db_identifier']) throw new RuntimeException('Unexpected tenant DB for company '.$company['id']);
    $tableExists=static function(PDO $p,string $table):int{$q=$p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return(int)$q->fetchColumn();};
    $countIf=static function(PDO $p,string $table)use($tableExists):int{return$tableExists($p,$table)?(int)$p->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn():0;};
    $migration=static function(PDO $p,string $name)use($tableExists):int{if(!$tableExists($p,'schema_migrations'))return 0;$q=$p->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');$q->execute([$name]);return(int)$q->fetchColumn();};
    $snapshot[]=[
        'company_id'=>(int)$company['id'],'db'=>(string)$company['db_identifier'],
        'feo_requests'=>$countIf($pdo,'feo_requests'),'feo_imports'=>$countIf($pdo,'feo_imports'),'feo_changes'=>$countIf($pdo,'feo_import_changes'),
        'placement_blocks'=>$countIf($pdo,'feo_placement_blocks'),'placement_members'=>$countIf($pdo,'feo_placement_block_requests'),'placement_current'=>$countIf($pdo,'feo_placement_current'),
        'cost_blocks'=>$countIf($pdo,'feo_request_cost_blocks'),'cost_members'=>$countIf($pdo,'feo_request_cost_block_requests'),
        'utilizers'=>$countIf($pdo,'feo_utilizers'),'legacy_rules'=>$countIf($pdo,'feo_utilizer_match_rules'),'legacy_overrides'=>$countIf($pdo,'feo_request_utilizer_overrides'),
        'alias_table'=>$tableExists($pdo,'feo_utilizer_aliases'),'aliases'=>$countIf($pdo,'feo_utilizer_aliases'),
        'migration_125'=>$migration($pdo,'125_create_feo_map_utilizers.sql'),'migration_126'=>$migration($pdo,'126_create_feo_utilizer_aliases.sql'),
    ];
}
echo 'FEO_126_SNAPSHOT='.json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'FEO_126_READ_ONLY_OK'.PHP_EOL;
