<?php

declare(strict_types=1);

$root=rtrim((string)(getenv('PLANEX_ROOT')?:''),'/');
$runId=trim((string)(getenv('QA_RUN_ID')?:''));
$companyId=(int)(getenv('QA_COMPANY_ID')?:0);
$ownerMembership=(int)(getenv('QA_OWNER_MEMBERSHIP_ID')?:0);
$ownerUser=(int)(getenv('QA_OWNER_TENANT_USER_ID')?:0);
$dynamicMembership=(int)(getenv('QA_DYNAMIC_MEMBERSHIP_ID')?:0);
if($root===''||!preg_match('/^[0-9]{1,20}$/D',$runId)||$companyId<=0||$ownerMembership<=0||$ownerUser<=0||$dynamicMembership<=0||!is_file($root.'/.env')){fwrite(STDERR,"invalid route QA fixture environment\n");exit(1);}
$stateFile='/tmp/feo_route_executor_fixture_'.$runId.'.json';
$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/entrypoint_dependencies.php';
$central=(new App\Core\Database($config['database']))->connection();
$companyStmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");$companyStmt->execute([$companyId]);$company=$companyStmt->fetch(PDO::FETCH_ASSOC)?:null;
if(!$company){fwrite(STDERR,"QA company unavailable\n");exit(1);}
$tenant=(new App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
$mode=(string)($argv[1]??'');
function routeQaPrefix(string $runId):string{return 'QA Route '.$runId.' ';}
function routeQaInn(string $runId,int $n):string{return '96'.str_pad((string)(((int)$runId+$n)%10000000000),10,'0',STR_PAD_LEFT);}
function routeQaPhone(string $runId,int $n):string{return '+7996'.str_pad((string)(((int)$runId+$n)%10000000),7,'0',STR_PAD_LEFT);}
function routeQaPlate(string $runId,int $n):string{return 'RQ'.str_pad((string)(((int)$runId+$n)%100000000000),10,'0',STR_PAD_LEFT);}
function saveRouteState(string $path,array $state):void{if(file_put_contents($path,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX)===false||!chmod($path,0600))throw new RuntimeException('route QA state write failed');}
function cleanupRouteQa(PDO $tenant,string $stateFile,string $runId):void{
    $state=is_file($stateFile)?json_decode((string)file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR):[];
    $crews=array_values(array_filter(array_map('intval',(array)($state['crew_ids']??[]))));
    $dvbs=array_values(array_filter(array_map('intval',(array)($state['dvb_ids']??[]))));
    $sets=array_values(array_filter(array_map('intval',(array)($state['set_ids']??[]))));
    $contractors=array_values(array_filter(array_map('intval',(array)($state['contractor_ids']??[]))));
    $drivers=array_values(array_filter(array_map('intval',(array)($state['driver_ids']??[]))));
    $units=array_values(array_filter(array_map('intval',(array)($state['unit_ids']??[]))));
    $tenant->beginTransaction();
    try{
        foreach($crews as $id){$q=$tenant->prepare("DELETE FROM entity_responsibilities WHERE entity_type='route_executor' AND entity_id=?");$q->execute([$id]);$q=$tenant->prepare('DELETE FROM crew_drivers WHERE crew_id=?');$q->execute([$id]);$q=$tenant->prepare('DELETE FROM crews WHERE id=?');$q->execute([$id]);}
        foreach([['contractor',$contractors],['driver',$drivers],['vehicle_unit',$units]] as [$type,$ids]){foreach($ids as $id){$q=$tenant->prepare('DELETE FROM entity_assignments WHERE entity_type=? AND entity_id=?');$q->execute([$type,$id]);$q=$tenant->prepare('DELETE FROM entity_responsibilities WHERE entity_type=? AND entity_id=?');$q->execute([$type,$id]);}}
        foreach($dvbs as $id){$q=$tenant->prepare('DELETE FROM driver_vehicle_blocks WHERE id=?');$q->execute([$id]);}
        foreach($sets as $id){$q=$tenant->prepare('DELETE FROM vehicle_sets WHERE id=?');$q->execute([$id]);}
        foreach($drivers as $id){$q=$tenant->prepare('DELETE FROM drivers WHERE id=? AND full_name LIKE ?');$q->execute([$id,routeQaPrefix($runId).'%']);}
        foreach($units as $id){$q=$tenant->prepare('DELETE FROM vehicle_units WHERE id=? AND plate_number LIKE ?');$q->execute([$id,'RQ%']);}
        foreach($contractors as $id){$q=$tenant->prepare('DELETE FROM contractors WHERE id=? AND name LIKE ?');$q->execute([$id,routeQaPrefix($runId).'%']);}
        $tenant->commit();
    }catch(Throwable $e){if($tenant->inTransaction())$tenant->rollBack();throw $e;}
    $assertMissing=static function(PDO $pdo,string $table,string $column,array $ids,string $label):void{
        foreach($ids as $id){$q=$pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}`=?");$q->execute([(int)$id]);if((int)$q->fetchColumn()!==0)throw new RuntimeException('route QA residue in '.$label.' id='.(int)$id);}
    };
    $assertMissing($tenant,'crews','id',$crews,'crews');
    $assertMissing($tenant,'crew_drivers','crew_id',$crews,'crew_drivers');
    $assertMissing($tenant,'driver_vehicle_blocks','id',$dvbs,'driver_vehicle_blocks');
    $assertMissing($tenant,'vehicle_sets','id',$sets,'vehicle_sets');
    $assertMissing($tenant,'vehicle_units','id',$units,'vehicle_units');
    $assertMissing($tenant,'drivers','id',$drivers,'drivers');
    $assertMissing($tenant,'contractors','id',$contractors,'contractors');
    foreach($crews as $id){$q=$tenant->prepare("SELECT COUNT(*) FROM entity_responsibilities WHERE entity_type='route_executor' AND entity_id=?");$q->execute([$id]);if((int)$q->fetchColumn()!==0)throw new RuntimeException('route QA responsibility residue id='.$id);}
    foreach([['contractor',$contractors],['driver',$drivers],['vehicle_unit',$units]] as [$type,$ids]){foreach($ids as $id){$q=$tenant->prepare('SELECT COUNT(*) FROM entity_assignments WHERE entity_type=? AND entity_id=?');$q->execute([$type,$id]);if((int)$q->fetchColumn()!==0)throw new RuntimeException('route QA assignment residue '.$type.' id='.$id);}}
    foreach([['crews','comments'],['contractors','name'],['drivers','full_name']] as [$table,$field]){$q=$tenant->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$field}` LIKE ?");$q->execute([routeQaPrefix($runId).'%']);if((int)$q->fetchColumn()!==0)throw new RuntimeException('route QA namespace residue in '.$table);}
    if(is_file($stateFile)&&!unlink($stateFile))throw new RuntimeException('route QA state cleanup failed');
    echo "ROUTE_EXECUTOR_QA_RESIDUE=0\n";
}
if($mode==='cleanup'){cleanupRouteQa($tenant,$stateFile,$runId);exit(0);}
if($mode!=='create'){fwrite(STDERR,"unknown route QA mode\n");exit(1);}
if(is_file($stateFile)){fwrite(STDERR,"route QA state exists\n");exit(1);}
$state=['crew_ids'=>[],'dvb_ids'=>[],'set_ids'=>[],'contractor_ids'=>[],'driver_ids'=>[],'unit_ids'=>[]];
$tenant->beginTransaction();
try{
    $insertContractor=$tenant->prepare("INSERT INTO contractors(name,inn,status,created_by_user_id,created_by_membership_id,created_by_role) VALUES(?,?,'active',?,?,?)");
    $insertDriver=$tenant->prepare("INSERT INTO drivers(full_name,phone,status,created_by_user_id,created_by_membership_id,created_by_role) VALUES(?,?,'active',?,?,?)");
    $insertUnit=$tenant->prepare("INSERT INTO vehicle_units(plate_number,brand,model,unit_type,status,created_by_user_id,created_by_membership_id,created_by_role) VALUES(?,?,?,'truck','active',?,?,?)");
    $insertSet=$tenant->prepare("INSERT INTO vehicle_sets(set_type,primary_vehicle_unit_id,secondary_vehicle_unit_id,status,comments,created_by_user_id,created_by_membership_id,created_by_role) VALUES('coupling',?,?,'active',?,?,?,?)");
    $insertDvb=$tenant->prepare("INSERT INTO driver_vehicle_blocks(driver_id,vehicle_set_id,status,comments,created_by_user_id,created_by_role) VALUES(?,?,'active',?,?,?)");
    $insertCrew=$tenant->prepare("INSERT INTO crews(contractor_id,vehicle_id,driver_id,driver_vehicle_block_id,status,comments,created_by_user_id,created_by_role) VALUES(?,?,?,?, 'active',?,?,?)");
    $insertCrewDriver=$tenant->prepare('INSERT INTO crew_drivers(crew_id,driver_id,position) VALUES(?,?,?)');
    $manual=$tenant->prepare("INSERT INTO entity_assignments(entity_type,entity_id,membership_id,assigned_by_membership_id,assignment_kind) VALUES(?,?,?,?,'manual')");
    $resp=$tenant->prepare("INSERT INTO entity_responsibilities(entity_type,entity_id,membership_id,assigned_by_membership_id) VALUES(?,?,?,?)");
    $makeComponent=function(string $label,int $offset,bool $accessible,bool $twoDrivers=false)use($insertContractor,$insertDriver,$insertUnit,$insertSet,$insertDvb,$insertCrew,$insertCrewDriver,$manual,$resp,$tenant,$runId,$ownerUser,$ownerMembership,$dynamicMembership,&$state){
        $name=routeQaPrefix($runId).$label;
        $insertContractor->execute([$name.' Contractor',routeQaInn($runId,$offset),$ownerUser,$ownerMembership,'company_owner']);$contractor=(int)$tenant->lastInsertId();$state['contractor_ids'][]=$contractor;
        $driverIds=[];$driverCount=$twoDrivers?2:1;
        for($i=0;$i<$driverCount;$i++){$insertDriver->execute([$name.' Driver '.($i+1),routeQaPhone($runId,$offset+$i),$ownerUser,$ownerMembership,'company_owner']);$id=(int)$tenant->lastInsertId();$driverIds[]=$id;$state['driver_ids'][]=$id;}
        $unitIds=[];$unitCount=$twoDrivers?2:1;
        for($i=0;$i<$unitCount;$i++){$insertUnit->execute([routeQaPlate($runId,$offset+$i),'QA Route',$label.' '.($i+1),$ownerUser,$ownerMembership,'company_owner']);$id=(int)$tenant->lastInsertId();$unitIds[]=$id;$state['unit_ids'][]=$id;}
        $insertSet->execute([$unitIds[0],$unitIds[1]??null,$name.' Vehicle Set',$ownerUser,$ownerMembership,'company_owner']);$set=(int)$tenant->lastInsertId();$state['set_ids'][]=$set;
        $insertDvb->execute([$driverIds[0],$set,$name.' DVB',$ownerUser,'company_owner']);$dvb=(int)$tenant->lastInsertId();$state['dvb_ids'][]=$dvb;
        $insertCrew->execute([$contractor,$unitIds[0],$driverIds[0],$dvb,$name,$ownerUser,'company_owner']);$crew=(int)$tenant->lastInsertId();$state['crew_ids'][]=$crew;
        foreach($driverIds as $i=>$driver)$insertCrewDriver->execute([$crew,$driver,$i+1]);
        if($accessible){$manual->execute(['contractor',$contractor,$dynamicMembership,$ownerMembership]);foreach($driverIds as $id)$manual->execute(['driver',$id,$dynamicMembership,$ownerMembership]);foreach($unitIds as $id)$manual->execute(['vehicle_unit',$id,$dynamicMembership,$ownerMembership]);}
        return ['crew_id'=>$crew,'contractor_id'=>$contractor,'driver_ids'=>$driverIds,'unit_ids'=>$unitIds,'set_id'=>$set];
    };
    $component=$makeComponent('Component',10,true,true);
    $direct=$makeComponent('Direct',30,false,false);$resp->execute(['route_executor',$direct['crew_id'],$dynamicMembership,$ownerMembership]);
    $hidden=$makeComponent('Hidden',50,false,false);
    $state['component']=$component;$state['direct']=$direct;$state['hidden']=$hidden;
    $tenant->commit();saveRouteState($stateFile,$state);
    echo 'QA_ROUTE_COMPONENT_CREW_ID='.$component['crew_id'].PHP_EOL;
    echo 'QA_ROUTE_DIRECT_CREW_ID='.$direct['crew_id'].PHP_EOL;
    echo 'QA_ROUTE_HIDDEN_CREW_ID='.$hidden['crew_id'].PHP_EOL;
    echo "QA_ROUTE_FIXTURES_READY=YES\n";
}catch(Throwable $e){if($tenant->inTransaction())$tenant->rollBack();try{cleanupRouteQa($tenant,$stateFile,$runId);}catch(Throwable){}fwrite(STDERR,'route QA create failed: '.$e->getMessage()."\n");exit(1);}
