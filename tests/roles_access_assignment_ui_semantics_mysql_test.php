<?php

declare(strict_types=1);

use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Service\EntityAssignmentService;
use App\Service\EntityResponsibilityService;
use App\Service\ResponsibleAssignmentService;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
foreach ([
    '/app/Security/AccessStateProvider.php',
    '/app/Security/AccessRequestContext.php',
    '/app/Security/MembershipAccessSnapshot.php',
    '/app/Security/EffectiveAccess.php',
    '/app/Security/AccessRuntime.php',
    '/app/Security/PageRegistry.php',
    '/app/Security/EffectiveAccessResolver.php',
    '/app/Security/PdoAccessStateProvider.php',
    '/app/Service/AccessControlService.php',
    '/app/Service/IdentityNormalizer.php',
    '/app/Service/CompanyRoleService.php',
    '/app/Service/LegacyAccessProjectionService.php',
    '/app/Service/EntityResponsibilityService.php',
    '/app/Service/RegulatedDataScopeService.php',
    '/app/Service/EntityAssignmentService.php',
    '/app/Service/ResponsibleAssignmentService.php',
] as $file) require_once BASE_PATH . $file;

$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'';
$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'';
$dbName=trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow=array_values(array_filter(array_map('trim',explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')))));
if($dbName===''||!in_array($dbName,$allow,true)||(string)getenv('APP_ENV')!=='test') throw new RuntimeException('Guarded fixed test DB required.');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};
$rowById=static function(array $rows,int $id):array{foreach($rows as $r)if((int)$r['id']===$id)return$r;throw new RuntimeException("row {$id} missing");};

cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE companies(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255) NOT NULL,status VARCHAR(20) NOT NULL) ENGINE=InnoDB");
    $pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations/013_create_roles_access.sql'));
    $pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations/014_add_role_default_page.sql'));
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(7,'QA','active'),(8,'Other','active')");
    $h=str_repeat('a',64);$q=$pdo->prepare("INSERT INTO company_access_readiness(company_id,state,before_sha256,after_sha256,comparison_sha256,verified_at,activated_at) VALUES(?,'ready',?,?,?,NOW(),NOW())");$q->execute([7,$h,$h,$h]);$q->execute([8,$h,$h,$h]);
    $pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,system_key,is_system,default_page_key) VALUES
      (1,7,'Руководитель','company_owner','company_owner',1,'company.dashboard'),
      (2,7,'Логист','logist',NULL,0,'company.dashboard'),
      (9,8,'Foreign','foreign',NULL,0,'company.dashboard')");
    foreach([1,2,9] as $rid){foreach(['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'] as $type){$scope=$rid===1?'all':($rid===2?'own':'none');$s=$pdo->prepare('INSERT INTO role_data_scopes(role_id,entity_type,scope) VALUES(?,?,?)');$s->execute([$rid,$type,$scope]);}}
    foreach([[101,'Owner','owner'],[102,'Logist','logist'],[103,'Other','other'],[109,'Foreign','foreign']] as [$id,$name,$login]){$s=$pdo->prepare("INSERT INTO user_accounts(id,full_name,login,normalized_login,password_hash,status) VALUES(?,?,?,?,?,'active')");$s->execute([$id,$name,$login,$login,password_hash('x'.$id,PASSWORD_DEFAULT)]);}
    $pdo->exec("INSERT INTO company_memberships(id,account_id,company_id,role_id,status) VALUES(11,101,7,1,'active'),(12,102,7,2,'active'),(13,103,7,2,'active'),(19,109,8,9,'active')");

    foreach([
      'clients'=>'name VARCHAR(255)',
      'contractors'=>'name VARCHAR(255)',
      'vehicle_units'=>'brand VARCHAR(100),model VARCHAR(100),plate_number VARCHAR(30)',
      'drivers'=>'full_name VARCHAR(255)'
    ] as $table=>$labelCols){$pdo->exec("CREATE TABLE {$table}(id BIGINT UNSIGNED PRIMARY KEY,{$labelCols},status VARCHAR(20) NOT NULL DEFAULT 'active',deleted_at DATETIME NULL,created_by_membership_id BIGINT UNSIGNED NULL,created_by_user_id INT UNSIGNED NULL) ENGINE=InnoDB");}
    $pdo->exec("CREATE TABLE entity_assignments(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type ENUM('client','contractor','vehicle_unit','driver') NOT NULL,entity_id BIGINT UNSIGNED NOT NULL,membership_id BIGINT UNSIGNED NOT NULL,assigned_by_membership_id BIGINT UNSIGNED NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uk_entity_assignment(entity_type,entity_id,membership_id)) ENGINE=InnoDB");
    $pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/132_split_assignment_and_responsibility.sql'));

    $pdo->exec("INSERT INTO contractors(id,name,created_by_membership_id) VALUES(1,'Creator only',12),(2,'Manual only',11),(3,'Creator and manual',12),(4,'No access',11)");
    $pdo->exec("INSERT INTO clients(id,name,created_by_membership_id) VALUES(1,'Client',11)");
    $pdo->exec("INSERT INTO vehicle_units(id,brand,model,plate_number,created_by_membership_id) VALUES(1,'MAN','TGX','A001AA',11)");
    $pdo->exec("INSERT INTO drivers(id,full_name,created_by_membership_id) VALUES(1,'Driver',11)");

    AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11,7,[]));
    EntityAssignmentService::replace($pdo,'contractor',2,[12],11);
    EntityAssignmentService::replace($pdo,'contractor',3,[12],11);

    $payload=ResponsibleAssignmentService::assignmentPayload($pdo,$pdo,7,12);
    $summary=$payload['summaries']['contractor'];$rows=$payload['entities']['contractor'];
    $ok($summary['scope']==='own'&&$summary['scope_source']==='role','ONLY_OWN role scope not visible');
    $ok((int)$summary['accessible_count']===3&&(int)$summary['total_count']===4,'ONLY_OWN accessible count wrong');
    $ok((int)$summary['explicit_assignment_count']===2,'explicit assignment count wrong');
    $r1=$rowById($rows,1);$r2=$rowById($rows,2);$r3=$rowById($rows,3);$r4=$rowById($rows,4);
    $ok($r1['accessible']&&!$r1['explicit_assigned']&&$r1['created_by_selected']&&in_array('creator_ownership',$r1['access_reasons'],true),'creator-only reason wrong');
    $ok($r2['accessible']&&$r2['explicit_assigned']&&!$r2['created_by_selected']&&in_array('explicit_assignment',$r2['access_reasons'],true),'manual-only reason wrong');
    $ok($r3['accessible']&&$r3['explicit_assigned']&&$r3['created_by_selected'],'creator+manual combined state wrong');
    $ok(!$r4['accessible']&&!$r4['explicit_assigned'],'unassigned foreign object leaked');

    // Checkbox semantics: remove only explicit row; creator history is untouched.
    AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11,7,[]));
    $creator2Before=(int)$pdo->query('SELECT created_by_membership_id FROM contractors WHERE id=2')->fetchColumn();
    EntityAssignmentService::replace($pdo,'contractor',2,[],11);
    $creator2After=(int)$pdo->query('SELECT created_by_membership_id FROM contractors WHERE id=2')->fetchColumn();
    $ok($creator2Before===$creator2After&&$creator2After===11,'checkbox changed creator ownership');
    $reload=ResponsibleAssignmentService::assignmentPayload($pdo,$pdo,7,12);
    $s2=$reload['summaries']['contractor'];$rr2=$rowById($reload['entities']['contractor'],2);
    $ok((int)$s2['accessible_count']===2&&(int)$s2['explicit_assignment_count']===1&&!$rr2['accessible'],'checkbox readback/count mismatch');

    // Personal ALL override: all objects visible, explicit count remains independent.
    $pdo->exec("INSERT INTO membership_data_scope_overrides(membership_id,entity_type,scope) VALUES(12,'contractor','all')");
    $all=ResponsibleAssignmentService::assignmentPayload($pdo,$pdo,7,12);$sa=$all['summaries']['contractor'];
    $ok($sa['scope']==='all'&&$sa['scope_source']==='personal_override','personal ALL override not effective/visible');
    $ok((int)$sa['accessible_count']===4&&(int)$sa['explicit_assignment_count']===1,'ALL counts mix accessible and explicit');
    foreach($all['entities']['contractor'] as $r)$ok($r['accessible']&&in_array('scope_all',$r['access_reasons'],true),'ALL row not explained');

    // Personal NONE override: no object visible; stored manual assignment remains visible as fact.
    $pdo->exec("UPDATE membership_data_scope_overrides SET scope='none' WHERE membership_id=12 AND entity_type='contractor'");
    $none=ResponsibleAssignmentService::assignmentPayload($pdo,$pdo,7,12);$sn=$none['summaries']['contractor'];
    $ok($sn['scope']==='none'&&$sn['scope_source']==='personal_override','personal NONE override not effective/visible');
    $ok((int)$sn['accessible_count']===0&&(int)$sn['explicit_assignment_count']===1,'NONE counts wrong');
    $ok($rowById($none['entities']['contractor'],3)['explicit_assigned']===true,'NONE erased explicit assignment fact');

    // Restore role OWN, transfer current base responsibility away from historical creator.
    $pdo->exec("DELETE FROM membership_data_scope_overrides WHERE membership_id=12 AND entity_type='contractor'");
    AccessRuntime::setCurrent(EffectiveAccess::systemOwner(11,7,[]));
    EntityResponsibilityService::transfer($pdo,'contractor',[1],13,11);
    $afterTransfer=ResponsibleAssignmentService::assignmentPayload($pdo,$pdo,7,12);$tr1=$rowById($afterTransfer['entities']['contractor'],1);
    $ok($tr1['created_by_selected']===true&&$tr1['base_responsible']===false&&$tr1['accessible']===false,'creator history/current responsibility not distinguished');
    $newResponsible=ResponsibleAssignmentService::assignmentPayload($pdo,$pdo,7,13);$new1=$rowById($newResponsible['entities']['contractor'],1);
    $ok($new1['base_responsible']===true&&$new1['accessible']===true&&in_array('base_responsibility',$new1['access_reasons'],true),'new base responsibility not visible');

    // Cross-company membership must never be inspectable through current company payload.
    $isolated=false;try{ResponsibleAssignmentService::assignmentPayload($pdo,$pdo,7,19);}catch(DomainException){$isolated=true;}
    $ok($isolated,'cross-company membership leaked into assignment payload');

    echo "ASSIGNMENT_UI_SEMANTICS=PASS\n";
    echo "CREATOR_OWNERSHIP_VISIBLE=PASS\n";
    echo "EXPLICIT_ASSIGNMENT_VISIBLE=PASS\n";
    echo "EFFECTIVE_SCOPE_VISIBLE=PASS\n";
    echo "ACCESSIBLE_COUNT=PASS\n";
    echo "EXPLICIT_ASSIGNMENT_COUNT=PASS\n";
    echo "ASSIGNMENT_RELOAD_READBACK=PASS\n";
    echo "ASSIGNMENT_MULTI_COMPANY_ISOLATION=PASS\n";
    echo "ACL_SEMANTICS_CHANGED=NO\n";
} finally {AccessRuntime::clear();cleanCompanyPoolDatabase($pdo);}
