<?php

declare(strict_types=1);

$root=rtrim((string)(getenv('PLANEX_ROOT')?:''),'/');
$companyId=(int)(getenv('QA_COMPANY_ID')?:0);
if($root===''||!is_file($root.'/.env')){fwrite(STDERR,"invalid production probe environment\n");exit(1);}
$config=require $root.'/bootstrap/app.php';
require_once $root.'/app/Support/entrypoint_dependencies.php';
$centralDb=new App\Core\Database($config['database']);$central=$centralDb->connection();

function probeTenant(array $config,array $company):PDO{return (new App\Core\Database(companyDatabaseConfig($config,$company)))->connection();}
function visibleVehicleSetCount(PDO $tenant):int{
    $membershipId=App\Service\AccessControlService::effectiveMembershipId();
    $scope=App\Service\AccessControlService::effectiveDataScope('vehicle_unit');
    $rows=$tenant->query("SELECT id,primary_vehicle_unit_id,secondary_vehicle_unit_id,created_by_membership_id FROM vehicle_sets WHERE deleted_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $count=0;
    foreach($rows as $row){
        $direct=false;
        if($scope==='all')$direct=true;
        elseif($scope==='own'&&$membershipId&&((int)($row['created_by_membership_id']??0)===$membershipId||App\Service\LegacyAccessProjectionService::accessLevel($tenant,'vehicle_set',(int)$row['id'],$membershipId)!==null))$direct=true;
        if($scope==='none'&&!$direct)continue;
        $visible=$direct;
        if(!$visible){foreach(['primary_vehicle_unit_id','secondary_vehicle_unit_id'] as $field){$id=(int)($row[$field]??0);if($id>0&&App\Service\RegulatedDataScopeService::canAccess($tenant,'vehicle_unit','vehicle_units',$id)){$visible=true;break;}}}
        if($visible)$count++;
    }
    return $count;
}

if($companyId>0){
    $q=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");$q->execute([$companyId]);$company=$q->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$company)throw new RuntimeException('FEO QA company unavailable');
    $counters=App\Service\NavigationCounterService::forCompany($config,$centralDb,$companyId,$company);
    $feo=[
        'company.feo.requests'=>(int)($counters['feo_freshness']??0),
        'company.feo.map_placement'=>(int)($counters['feo_placement_problem']??0),
        'company.feo.map'=>(int)($counters['feo_map_problem']??0),
        'company.feo.utilizers'=>(int)($counters['feo_utilizer_problem']??0),
        'company.feo.costs'=>(int)($counters['feo_cost_problem']??0),
    ];
    echo 'FEO_COUNTERS_B64='.base64_encode(json_encode($feo,JSON_THROW_ON_ERROR)).PHP_EOL;
}

$account=$central->prepare("SELECT id,full_name,status FROM user_accounts WHERE TRIM(full_name)=? AND status='active' ORDER BY id");
$account->execute(['Сидоров Михаил']);$accounts=$account->fetchAll(PDO::FETCH_ASSOC);
if(count($accounts)!==1)throw new RuntimeException('Sidorov active global account is not unique: '.count($accounts));
$accountId=(int)$accounts[0]['id'];
$members=$central->prepare("SELECT m.id AS membership_id,m.company_id,c.* FROM company_memberships m JOIN companies c ON c.id=m.company_id WHERE m.account_id=? AND m.status='active' AND c.status='active' ORDER BY m.company_id,m.id");
$members->execute([$accountId]);$rows=$members->fetchAll(PDO::FETCH_ASSOC);
if(count($rows)!==1)throw new RuntimeException('Sidorov active company membership is not unique: '.count($rows));
$company=$rows[0];
$membershipId=(int)$company['membership_id'];
$cid=(int)$company['company_id'];
$registry=new App\Security\PageRegistry();$resolver=new App\Security\EffectiveAccessResolver($registry,new App\Security\PdoAccessStateProvider($central));
$access=$resolver->resolve(new App\Security\AccessRequestContext($accountId,$cid));
if(!$access->isActive())throw new RuntimeException('Sidorov effective access is inactive');
if(!$access->canAccessPage('company.route_executors'))throw new RuntimeException('Sidorov route-executor page access changed');
App\Security\AccessRuntime::setCurrent($access);
try{
    $tenant=probeTenant($config,$company);
    $contractorService=new App\Service\ContractorService($config,$centralDb);
    $driverService=new App\Service\DriverService($config,$centralDb);
    $counts=[
        'contractors'=>count($contractorService->listContractors($tenant,false,0)),
        'drivers'=>count($driverService->listDrivers($tenant,false,0)),
        'vehicle_sets'=>visibleVehicleSetCount($tenant),
        'route_executors'=>0,
    ];
    $crewRows=$tenant->query("SELECT id AS crew_id FROM crews WHERE status<>'archived' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $counts['route_executors']=count(App\Service\RouteExecutorService::filterVisibleCrews($tenant,$crewRows));
} finally {
    App\Security\AccessRuntime::clear();
}
echo 'SIDOROV_ACCOUNT_ID='.$accountId.PHP_EOL;
echo 'SIDOROV_MEMBERSHIP_ID='.$membershipId.PHP_EOL;
echo 'SIDOROV_COMPANY_ID='.$cid.PHP_EOL;
echo 'SIDOROV_COUNTS_B64='.base64_encode(json_encode($counts,JSON_THROW_ON_ERROR)).PHP_EOL;
if($counts['contractors']!==0)throw new RuntimeException('Sidorov contractor visibility changed: '.$counts['contractors']);
if($counts['drivers']!==0)throw new RuntimeException('Sidorov driver visibility changed: '.$counts['drivers']);
if($counts['vehicle_sets']!==0)throw new RuntimeException('Sidorov vehicle-set visibility changed: '.$counts['vehicle_sets']);
if($counts['route_executors']!==0)throw new RuntimeException('Sidorov route-executor visibility is not empty: '.$counts['route_executors']);
echo "SIDOROV_CONTRACTORS_CORRECT=PASS\n";
echo "SIDOROV_DRIVERS_CORRECT=PASS\n";
echo "SIDOROV_VEHICLE_SETS_CORRECT=PASS\n";
echo "SIDOROV_ROUTE_EXECUTORS_EXPECTED_EMPTY=PASS\n";
