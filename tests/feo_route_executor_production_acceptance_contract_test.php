<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/feo_route_executor_production_acceptance.yml');
$script=(string)file_get_contents($root.'/.github/feo-route-executor/production_acceptance.js');
$fixture=(string)file_get_contents($root.'/.github/feo-route-executor/qa_route_fixture.php');
$probe=(string)file_get_contents($root.'/.github/feo-route-executor/production_probe.php');
$identity=(string)file_get_contents($root.'/.github/roles-access/qa_identity.php');

$ok=static function(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};

$ok(str_contains($workflow,'workflow_dispatch:')&&str_contains($workflow,'source_sha:'),'acceptance must retain manual exact-SHA trigger');
$ok(str_contains($workflow,'qa/feo-route-executor-agent')&&str_contains($workflow,'test "$REF_NAME" = "$QA_TRIGGER_BRANCH"'),'dedicated QA push trigger must fail closed');
$ok(str_contains($workflow,'ref: ${{ env.SOURCE_SHA }}')&&str_contains($workflow,'git ls-remote origin refs/heads/${CANONICAL_BRANCH}'),'acceptance must bind to exact current canonical SHA');
$ok(str_contains($workflow,'P07_erpv2_deployed_commit.txt'),'acceptance must require exact deployed SHA marker');
$ok(str_contains($workflow,'.github/roles-access/qa_identity.php')&&str_contains($identity,'QA_IDENTITY_CREATED=YES'),'acceptance must reuse proven Roles & Access QA identity lifecycle');
$ok(!str_contains($workflow,'create-legacy-owner')&&!str_contains($script,'QA_LEGACY_SESSION_ID'),'acceptance must not create a parallel auth mechanism');
$ok(str_contains($script,"page.locator('input[name=\"login\"]')")&&str_contains($script,"page.locator('input[name=\"password\"]')"),'browser proof must use real login form');
$ok(!str_contains($script,'page.route(')&&!str_contains($script,'route.fulfill('),'browser proof must not intercept or fake production responses');

foreach([
    'FEO_CHILD_BADGES=PASS','FEO_PARENT_BADGE_SUM=PASS','FEO_ZERO_BADGE_HIDDEN=PASS',
    'ROUTE_EXECUTOR_ALL=PASS','ROUTE_EXECUTOR_ONLY_OWN=PASS','ROUTE_EXECUTOR_DIRECT_RESPONSIBILITY=PASS',
    'ROUTE_EXECUTOR_NONE=PASS','ROUTE_EXECUTOR_PERSONAL_OVERRIDE=PASS','ROUTE_EXECUTOR_DYNAMIC_ROLE=PASS','ROUTE_EXECUTOR_OWNER_ALL=PASS',
    'CROSS_COMPANY_LEAKAGE=NO'
] as $marker){$ok(str_contains($script,$marker),"browser acceptance missing {$marker}");}
$ok(str_contains($script,'/company/roles-access/roles/${Number(role.id)}/save')&&str_contains($script,'/company/roles-access/memberships/${Number(m.id)}/save'),'role and personal override proof must use real Roles & Access POSTs');
$ok(str_contains($script,"[data-route-executor-id]")&&str_contains($script,'foreignIds.includes(routeIds.component)'),'company isolation must inspect rendered route-executor IDs after switch');
$ok(str_contains($script,"'company.feo.requests':'/company/feo/requests'")&&str_contains($script,"'company.feo.costs':'/company/feo/requests/costs'"),'all FEO child routes must be asserted from rendered navigation');
$ok(str_contains($script,"expected<=0?''")&&str_contains($script,"parentLabel=sum<=0?''"),'zero child badge and parent sum behavior must be asserted');

foreach(['crew_drivers','driver_vehicle_blocks','vehicle_sets','vehicle_units','drivers','contractors'] as $table){$ok(str_contains($fixture,$table),"fixture lifecycle missing {$table}");}
$ok(str_contains($fixture,"'route_executor',\$direct['crew_id']")&&str_contains($fixture,'entity_responsibilities'),'direct route-executor responsibility fixture missing');
$ok(str_contains($fixture,"'contractor',\$contractor,\$dynamicMembership")&&str_contains($fixture,"'driver',\$id,\$dynamicMembership")&&str_contains($fixture,"'vehicle_unit',\$id,\$dynamicMembership"),'constituent manual-assignment fixture missing');
$ok(str_contains($fixture,'$driverCount=$twoDrivers?2:1')&&str_contains($fixture,"\$component=\$makeComponent('Component',10,true,true)"),'multi-driver production fixture must be explicit');
$ok(str_contains($fixture,"DELETE FROM entity_responsibilities WHERE entity_type='route_executor'")&&str_contains($fixture,'DELETE FROM crew_drivers')&&str_contains($fixture,'DELETE FROM entity_assignments'),'fixture cleanup must remove route responsibility, crew drivers and constituent assignments before entities');
$ok(str_contains($fixture,"ROUTE_EXECUTOR_QA_RESIDUE=0"),'fixture cleanup must publish zero residue');
$ok(!str_contains($fixture,"plate_number LIKE 'RQ%'"),'cleanup must not use a global QA plate prefix that can collide with parallel runs');

$ok(str_contains($probe,"TRIM(full_name)=?")&&str_contains($probe,"['Сидоров Михаил']"),'Sidorov probe must identify the real account read-only');
$ok(str_contains($probe,"if(count(\$accounts)!==1)")&&str_contains($probe,"if(count(\$rows)!==1)"),'Sidorov account and active membership must both be unambiguous');
$ok(str_contains($probe,'EffectiveAccessResolver')&&str_contains($probe,'AccessRequestContext($accountId,$cid)')&&str_contains($probe,'AccessRuntime::setCurrent($access)'),'Sidorov probe must resolve canonical effective access for the exact account/company');
$ok(str_contains($probe,'ContractorService')&&str_contains($probe,'DriverService')&&str_contains($probe,'RegulatedDataScopeService::canAccess')&&str_contains($probe,'RouteExecutorService::filterVisibleCrews'),'Sidorov counts must use canonical scope services');
foreach(['SIDOROV_ACCOUNT_ID=','SIDOROV_MEMBERSHIP_ID=','SIDOROV_COMPANY_ID=','SIDOROV_COUNTS_B64=','SIDOROV_CONTRACTORS_CORRECT=PASS','SIDOROV_DRIVERS_CORRECT=PASS','SIDOROV_VEHICLE_SETS_CORRECT=PASS','SIDOROV_ROUTE_EXECUTORS_EXPECTED_EMPTY=PASS'] as $marker){$ok(str_contains($probe,$marker),"Sidorov factual evidence missing {$marker}");}
$ok(!preg_match('/(?:UPDATE|DELETE|INSERT)[^\n]*Сидоров/iu',$probe),'Sidorov probe must remain read-only for the real user');

$routeCleanupPos=strpos($workflow,'- name: Cleanup route-executor fixtures');
$identityCleanupPos=strpos($workflow,'- name: Cleanup temporary QA identities');
$ok($routeCleanupPos!==false&&$identityCleanupPos!==false&&$routeCleanupPos<$identityCleanupPos,'route fixtures must be cleaned before temporary identities');
$ok(str_contains($workflow,"if: \${{ always() && env.QA_COMPANY_A_ID != '' }}")&&str_contains($workflow,"ROUTE_EXECUTOR_QA_RESIDUE=0"),'route fixture cleanup must be fail-safe and residue gated');
$ok(str_contains($workflow,'QA_IDENTITY_RESIDUE=0')&&str_contains($workflow,'MULTI_COMPANY_QA_RESIDUE=0'),'identity cleanup must remain residue gated');
foreach(['SIDOROV_CONTRACTORS_CORRECT=PASS','SIDOROV_DRIVERS_CORRECT=PASS','SIDOROV_VEHICLE_SETS_CORRECT=PASS','SIDOROV_ROUTE_EXECUTORS_EXPECTED_EMPTY=PASS','QA_RESIDUE=0'] as $marker){$ok(str_contains($workflow,$marker),"workflow evidence gate missing {$marker}");}
$ok(str_contains($workflow,'erpv2/feo-route-executor-functional'),'successful acceptance must publish an exact-SHA commit status');

echo "FEO_ROUTE_EXECUTOR_PRODUCTION_ACCEPTANCE_CONTRACT=PASS\n";
