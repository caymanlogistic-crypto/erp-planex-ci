<?php

declare(strict_types=1);

$root = rtrim((string)(getenv('PLANEX_ROOT') ?: ''), '/');
$runId = trim((string)(getenv('QA_RUN_ID') ?: ''));
if ($root === '' || !preg_match('/^[0-9]{1,20}$/D', $runId) || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "invalid QA identity environment\n"); exit(1);
}
$stateFile = '/tmp/roles_access_func_identity_' . $runId . '.json';
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/environment.php';
loadEnvFileNonOverwriting($root . '/.env');
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/IdentityNormalizer.php';
require_once $root . '/app/Service/CompanyRoleService.php';
require_once $root . '/app/Service/CompanyMembershipService.php';
require_once $root . '/app/Service/EntityResponsibilityService.php';
$config = require $root . '/bootstrap/app.php';
$pdo = (new App\Core\Database($config['database']))->connection();
$mode = (string)($argv[1] ?? '');

function qaIdentityPrefix(string $runId): string { return 'qa_func_' . $runId . '_'; }
function qaRoleName(string $runId): string { return 'QA Functional Role ' . $runId; }
function qaContractorName(string $runId): string { return 'QA Functional Contractor ' . $runId; }
function qaContractorInn(string $runId): string { return '99' . str_pad((string)(((int)$runId) % 10000000000), 10, '0', STR_PAD_LEFT); }

function writeQaState(string $stateFile, array $state): void
{
    $json = json_encode($state, JSON_THROW_ON_ERROR);
    if (file_put_contents($stateFile, $json, LOCK_EX) === false || !chmod($stateFile, 0600)) {
        throw new RuntimeException('QA state persistence failed');
    }
}

function activeCompany(PDO $pdo, int $companyId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function tenantFor(PDO $pdo, array $config, int $companyId): PDO
{
    $company = activeCompany($pdo, $companyId);
    if (!$company) throw new RuntimeException('QA company unavailable');
    return (new App\Core\Database(companyDatabaseConfig($config, $company)))->connection();
}

function restoreQaResponsibilityFixture(PDO $pdo, array $config, ?array $state): void
{
    $fixture = is_array($state) ? ($state['responsibility_fixture'] ?? null) : null;
    if (!is_array($fixture)) return;
    $companyId = (int)($state['company_id'] ?? 0);
    $entityType = (string)($fixture['entity_type'] ?? '');
    $entityId = (int)($fixture['entity_id'] ?? 0);
    if ($companyId <= 0 || !App\Service\EntityResponsibilityService::isSupported($entityType) || $entityId <= 0) {
        throw new RuntimeException('invalid QA responsibility cleanup state');
    }
    $tenant = tenantFor($pdo, $config, $companyId);
    $original = $fixture['original'] ?? null;
    $tenant->beginTransaction();
    try {
        $lock = $tenant->prepare('SELECT membership_id,assigned_by_membership_id,created_at,updated_at FROM entity_responsibilities WHERE entity_type=? AND entity_id=? FOR UPDATE');
        $lock->execute([$entityType, $entityId]);
        $lock->fetch(PDO::FETCH_ASSOC);
        if (is_array($original)) {
            $restore = $tenant->prepare(
                'INSERT INTO entity_responsibilities (entity_type,entity_id,membership_id,assigned_by_membership_id,created_at,updated_at) VALUES (?,?,?,?,?,?) '
                . 'ON DUPLICATE KEY UPDATE membership_id=VALUES(membership_id),assigned_by_membership_id=VALUES(assigned_by_membership_id),created_at=VALUES(created_at),updated_at=VALUES(updated_at)'
            );
            $restore->execute([$entityType,$entityId,(int)$original['membership_id'],$original['assigned_by_membership_id'] !== null ? (int)$original['assigned_by_membership_id'] : null,(string)$original['created_at'],(string)$original['updated_at']]);
        } else {
            $delete = $tenant->prepare('DELETE FROM entity_responsibilities WHERE entity_type=? AND entity_id=?');
            $delete->execute([$entityType, $entityId]);
        }
        $tenant->commit();
    } catch (Throwable $e) {
        if ($tenant->inTransaction()) $tenant->rollBack();
        throw $e;
    }
    $check = $tenant->prepare('SELECT COUNT(*) FROM entity_responsibilities WHERE entity_type=? AND entity_id=?');
    $check->execute([$entityType,$entityId]);
    if ($original === null && (int)$check->fetchColumn() !== 0) throw new RuntimeException('QA responsibility residue detected');
}

function cleanupQaContractorFixture(PDO $pdo, array $config, ?array $state, string $runId): void
{
    $companyId = (int)($state['company_id'] ?? 0);
    if ($companyId <= 0) return;
    $tenant = tenantFor($pdo, $config, $companyId);
    $name = qaContractorName($runId);
    $inn = qaContractorInn($runId);
    $stmt = $tenant->prepare('SELECT id,name,inn FROM contractors WHERE name=? OR inn=? ORDER BY id LIMIT 2');
    $stmt->execute([$name,$inn]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) throw new RuntimeException('ambiguous QA contractor namespace');
    if ($rows !== []) {
        $id=(int)$rows[0]['id'];
        if ((string)$rows[0]['name'] !== $name || (string)$rows[0]['inn'] !== $inn) throw new RuntimeException('QA contractor namespace collision');
        $r=$tenant->prepare("SELECT COUNT(*) FROM entity_responsibilities WHERE entity_type='contractor' AND entity_id=?");
        $r->execute([$id]);
        if ((int)$r->fetchColumn() !== 0) throw new RuntimeException('QA contractor still has responsibility residue');
        $a=$tenant->prepare("SELECT COUNT(*) FROM entity_assignments WHERE entity_type='contractor' AND entity_id=?");
        $a->execute([$id]);
        if ((int)$a->fetchColumn() !== 0) throw new RuntimeException('QA contractor unexpectedly has assignment residue');
        $del=$tenant->prepare('DELETE FROM contractors WHERE id=? AND name=? AND inn=?');
        $del->execute([$id,$name,$inn]);
        if ($del->rowCount() !== 1) throw new RuntimeException('QA contractor cleanup mismatch');
    }
    $verify=$tenant->prepare('SELECT COUNT(*) FROM contractors WHERE name=? OR inn=?');
    $verify->execute([$name,$inn]);
    if ((int)$verify->fetchColumn() !== 0) throw new RuntimeException('QA contractor residue detected');
}

/** @return list<array<string,mixed>> */
function qaAccounts(PDO $pdo, string $runId): array
{
    $prefix=qaIdentityPrefix($runId);
    $stmt=$pdo->prepare('SELECT id,login,normalized_login,full_name FROM user_accounts WHERE LEFT(normalized_login,?)=? ORDER BY id');
    $stmt->execute([strlen($prefix),$prefix]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cleanupQaTenantProjections(PDO $pdo, array $config, string $runId, array $accounts, int $targetCompanyId): void
{
    foreach ($accounts as $account) {
        $m=$pdo->prepare('SELECT m.id,m.company_id,r.system_key,r.is_system FROM company_memberships m JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.account_id=? ORDER BY m.id');
        $m->execute([(int)$account['id']]);
        foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $membership) {
            $companyId=(int)$membership['company_id'];
            $membershipId=(int)$membership['id'];
            if ($companyId !== $targetCompanyId) continue;
            $company=activeCompany($pdo,$companyId);
            if (!$company) continue;
            $tenant=(new App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
            $row=$tenant->prepare('SELECT id,login,full_name,role_code,status FROM users WHERE membership_id=? LIMIT 2');
            $row->execute([$membershipId]);
            $rows=$row->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)>1) throw new RuntimeException('duplicate QA tenant projection');
            if ($rows===[]) continue;
            $u=$rows[0];
            if (!str_starts_with((string)$u['login'],qaIdentityPrefix($runId)) || !str_starts_with((string)$u['full_name'],'QA Functional ')) {
                throw new RuntimeException('unexpected row at QA tenant membership projection');
            }
            $responsibility=$tenant->prepare('SELECT COUNT(*) FROM entity_responsibilities WHERE membership_id=? OR assigned_by_membership_id=?');
            $responsibility->execute([$membershipId,$membershipId]);
            if ((int)$responsibility->fetchColumn()!==0) throw new RuntimeException('QA tenant projection still referenced by responsibility');
            $assignment=$tenant->prepare('SELECT COUNT(*) FROM entity_assignments WHERE membership_id=? OR assigned_by_membership_id=?');
            $assignment->execute([$membershipId,$membershipId]);
            if ((int)$assignment->fetchColumn()!==0) throw new RuntimeException('QA tenant projection unexpectedly referenced by assignment');
            $del=$tenant->prepare('DELETE FROM users WHERE id=? AND membership_id=? AND login=?');
            $del->execute([(int)$u['id'],$membershipId,(string)$u['login']]);
            if ($del->rowCount()!==1) throw new RuntimeException('QA tenant projection cleanup mismatch');
        }
    }
}

function cleanupQaIdentity(PDO $pdo, array $config, string $stateFile, string $runId): void
{
    $state=is_file($stateFile) ? json_decode((string)file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR) : [];
    restoreQaResponsibilityFixture($pdo,$config,$state);
    cleanupQaContractorFixture($pdo,$config,$state,$runId);

    $accounts=qaAccounts($pdo,$runId);
    cleanupQaTenantProjections($pdo,$config,$runId,$accounts,(int)($state['company_id']??0));

    // Remove non-owner memberships first, then temporary owners. Every owner company
    // was selected with an existing independent active owner, so fail-closed owner
    // quorum remains intact throughout cleanup.
    $membershipService=new App\Service\CompanyMembershipService($pdo);
    $membershipRows=[];
    foreach ($accounts as $account) {
        if (!str_starts_with((string)$account['full_name'],'QA Functional ')) throw new RuntimeException('unexpected QA account shape');
        $stmt=$pdo->prepare('SELECT m.id,m.company_id,r.system_key FROM company_memberships m JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.account_id=? ORDER BY m.id');
        $stmt->execute([(int)$account['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $membershipRows[]=$row;
    }
    usort($membershipRows,static fn(array $a,array $b): int => (($a['system_key']??null)===App\Service\CompanyRoleService::OWNER_SYSTEM_KEY ? 1:0) <=> (($b['system_key']??null)===App\Service\CompanyRoleService::OWNER_SYSTEM_KEY ? 1:0));
    foreach ($membershipRows as $m) $membershipService->remove((int)$m['company_id'],(int)$m['id']);

    foreach ($accounts as $account) {
        $del=$pdo->prepare('DELETE FROM user_accounts WHERE id=? AND normalized_login=? AND NOT EXISTS (SELECT 1 FROM company_memberships WHERE account_id=user_accounts.id)');
        $del->execute([(int)$account['id'],(string)$account['normalized_login']]);
        if ($del->rowCount()!==1) throw new RuntimeException('QA account cleanup mismatch');
    }

    $companyId=(int)($state['company_id']??0);
    $roleId=(int)($state['dynamic_role_id']??0);
    if ($companyId>0) {
        $normalized=App\Service\IdentityNormalizer::roleName(qaRoleName($runId));
        $role=$pdo->prepare('SELECT id,name,is_system,system_key FROM company_roles WHERE company_id=? AND normalized_name=? LIMIT 2');
        $role->execute([$companyId,$normalized]);
        $rows=$role->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows)>1) throw new RuntimeException('ambiguous QA role namespace');
        if ($rows!==[]) {
            $r=$rows[0];
            if ((int)$r['is_system']!==0 || $r['system_key']!==null || (string)$r['name']!==qaRoleName($runId) || ($roleId>0 && (int)$r['id']!==$roleId)) throw new RuntimeException('unexpected QA role shape');
            (new App\Service\CompanyRoleService($pdo))->delete($companyId,(int)$r['id']);
        }
    }

    if (qaAccounts($pdo,$runId)!==[]) throw new RuntimeException('QA account namespace residue');
    if ($companyId>0) {
        $roleCheck=$pdo->prepare('SELECT COUNT(*) FROM company_roles WHERE company_id=? AND normalized_name=?');
        $roleCheck->execute([$companyId,App\Service\IdentityNormalizer::roleName(qaRoleName($runId))]);
        if ((int)$roleCheck->fetchColumn()!==0) throw new RuntimeException('QA role residue');
        $tenant=tenantFor($pdo,$config,$companyId);
        $projection=$tenant->prepare('SELECT COUNT(*) FROM users WHERE login LIKE ?');
        $projection->execute([qaIdentityPrefix($runId).'%']);
        if ((int)$projection->fetchColumn()!==0) throw new RuntimeException('QA tenant projection residue');
    }
    if (is_file($stateFile) && !unlink($stateFile)) throw new RuntimeException('QA state cleanup failed');
    echo "QA_RESPONSIBILITY_FIXTURE_RESIDUE=0\n";
    echo "QA_CONTRACTOR_FIXTURE_RESIDUE=0\n";
    echo "QA_TENANT_PROJECTION_RESIDUE=0\n";
    echo "QA_DYNAMIC_RECIPIENT_RESIDUE=0\n";
    echo "QA_TEMP_ROLE_RESIDUE=0\n";
    echo "QA_IDENTITY_RESIDUE=0\n";
}

if ($mode==='cleanup') { cleanupQaIdentity($pdo,$config,$stateFile,$runId); exit(0); }
if ($mode!=='create') { fwrite(STDERR,"unknown QA identity mode\n"); exit(1); }
if (is_file($stateFile)) { fwrite(STDERR,"QA state already exists\n"); exit(1); }

$companies=$pdo->query(
    "SELECT c.id,c.name,owner_role.id AS owner_role_id,
            (SELECT COUNT(*) FROM company_memberships om JOIN user_accounts oa ON oa.id=om.account_id AND oa.status='active' JOIN company_roles rr ON rr.id=om.role_id AND rr.company_id=om.company_id WHERE om.company_id=c.id AND om.status='active' AND rr.system_key='company_owner') AS owner_count
       FROM companies c
       JOIN company_access_readiness ar ON ar.company_id=c.id AND ar.state='ready' AND ar.verified_at IS NOT NULL AND ar.activated_at IS NOT NULL
       JOIN company_roles owner_role ON owner_role.company_id=c.id AND owner_role.system_key='company_owner' AND owner_role.is_system=1
      WHERE c.status='active' ORDER BY c.id"
)->fetchAll(PDO::FETCH_ASSOC);
$target=null;
$foreignCompany=null;
foreach ($companies as $candidate) {
    if ((int)$candidate['owner_count']<=0) continue;
    $company=activeCompany($pdo,(int)$candidate['id']);
    if (!$company) continue;
    try {
        $tenant=(new App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
        foreach (['users','contractors','entity_responsibilities','entity_assignments'] as $table) $tenant->query("SELECT 1 FROM `{$table}` LIMIT 1");
    } catch (Throwable) { continue; }
    $foreign=$pdo->prepare(
        "SELECT c.id,c.name,r.id AS owner_role_id,
                (SELECT COUNT(*) FROM company_memberships om JOIN user_accounts oa ON oa.id=om.account_id AND oa.status='active' JOIN company_roles rr ON rr.id=om.role_id AND rr.company_id=om.company_id WHERE om.company_id=c.id AND om.status='active' AND rr.system_key='company_owner') AS owner_count
           FROM companies c JOIN company_roles r ON r.company_id=c.id AND r.system_key='company_owner' AND r.is_system=1
          WHERE c.status='active' AND c.id<>? ORDER BY c.id"
    );
    $foreign->execute([(int)$candidate['id']]);
    foreach ($foreign->fetchAll(PDO::FETCH_ASSOC) as $foreignRow) {
        if ((int)$foreignRow['owner_count']>0) { $foreignCompany=$foreignRow; break; }
    }
    if ($foreignCompany) { $target=['row'=>$candidate,'company'=>$company,'tenant'=>$tenant]; break; }
}
if (!$target || !$foreignCompany) { fwrite(STDERR,"no two active QA companies with independent owners\n"); exit(1); }

$companyId=(int)$target['row']['id'];
$ownerRoleId=(int)$target['row']['owner_role_id'];
$foreignCompanyId=(int)$foreignCompany['id'];
$foreignOwnerRoleId=(int)$foreignCompany['owner_role_id'];
$prefix=qaIdentityPrefix($runId);
$roleName=qaRoleName($runId);
$state=[
    'company_id'=>$companyId,
    'foreign_company_id'=>$foreignCompanyId,
    'dynamic_role_id'=>0,
    'responsibility_fixture'=>null,
    'qa_contractor_fixture'=>['id'=>0,'name'=>qaContractorName($runId),'inn'=>qaContractorInn($runId)],
];
writeQaState($stateFile,$state);

$membershipService=new App\Service\CompanyMembershipService($pdo);
try {
    $role=(new App\Service\CompanyRoleService($pdo))->create($companyId,$roleName);
    $state['dynamic_role_id']=(int)$role['id']; writeQaState($stateFile,$state);

    $ownerLogin=$prefix.'owner_'.bin2hex(random_bytes(4));
    $ownerPassword=rtrim(strtr(base64_encode(random_bytes(30)),'+/','-_'),'=');
    $owner=$membershipService->add($companyId,$ownerRoleId,'QA Functional Owner '.$runId,$ownerLogin,$ownerPassword,null,null,'active');
    if (!($owner['account_created']??false)) throw new RuntimeException('QA owner account collision');
    $state['owner']=['account_id'=>(int)$owner['account_id'],'membership_id'=>(int)$owner['membership_id'],'login'=>$ownerLogin]; writeQaState($stateFile,$state);

    $dynamicLogin=$prefix.'dynamic_'.bin2hex(random_bytes(4));
    $dynamicPassword=rtrim(strtr(base64_encode(random_bytes(30)),'+/','-_'),'=');
    $dynamic=$membershipService->add($companyId,(int)$role['id'],'QA Functional Dynamic '.$runId,$dynamicLogin,$dynamicPassword,null,null,'active');
    if (!($dynamic['account_created']??false)) throw new RuntimeException('QA dynamic account collision');
    $state['dynamic']=['account_id'=>(int)$dynamic['account_id'],'membership_id'=>(int)$dynamic['membership_id'],'login'=>$dynamicLogin]; writeQaState($stateFile,$state);

    $foreignLogin=$prefix.'foreign_'.bin2hex(random_bytes(4));
    $foreignPassword=rtrim(strtr(base64_encode(random_bytes(30)),'+/','-_'),'=');
    $foreign=$membershipService->add($foreignCompanyId,$foreignOwnerRoleId,'QA Functional Foreign '.$runId,$foreignLogin,$foreignPassword,null,null,'active');
    if (!($foreign['account_created']??false)) throw new RuntimeException('QA foreign account collision');
    $state['foreign']=['account_id'=>(int)$foreign['account_id'],'membership_id'=>(int)$foreign['membership_id'],'login'=>$foreignLogin]; writeQaState($stateFile,$state);

    $tenant=$target['tenant'];
    $projection=$tenant->prepare('INSERT INTO users (membership_id,full_name,login,password_hash,role_code,status) VALUES (?,?,?,?,?,\'active\')');
    $projection->execute([(int)$owner['membership_id'],'QA Functional Owner '.$runId,$ownerLogin,password_hash($ownerPassword,PASSWORD_DEFAULT),'company_owner']);
    $ownerTenantUserId=(int)$tenant->lastInsertId();
    if ($ownerTenantUserId<=0) throw new RuntimeException('QA owner tenant projection failed');
    $projection->execute([(int)$dynamic['membership_id'],'QA Functional Dynamic '.$runId,$dynamicLogin,password_hash($dynamicPassword,PASSWORD_DEFAULT),'membership_role']);
    $dynamicTenantUserId=(int)$tenant->lastInsertId();
    if ($dynamicTenantUserId<=0) throw new RuntimeException('QA dynamic tenant projection failed');
    $state['owner']['tenant_user_id']=$ownerTenantUserId;
    $state['dynamic']['tenant_user_id']=$dynamicTenantUserId;
    writeQaState($stateFile,$state);

    $recipients=App\Service\EntityResponsibilityService::eligibleRecipients($pdo,$tenant,$companyId);
    $byMembership=[];
    foreach ($recipients as $recipient) $byMembership[(int)$recipient['membership_id']]=$recipient;
    if ((int)($byMembership[(int)$owner['membership_id']]['id']??0)!==$ownerTenantUserId) throw new RuntimeException('QA owner is not eligible projected recipient');
    if ((int)($byMembership[(int)$dynamic['membership_id']]['id']??0)!==$dynamicTenantUserId) throw new RuntimeException('QA dynamic is not eligible projected recipient');

    $contractorName=qaContractorName($runId); $contractorInn=qaContractorInn($runId);
    $collision=$tenant->prepare('SELECT COUNT(*) FROM contractors WHERE name=? OR inn=?');
    $collision->execute([$contractorName,$contractorInn]);
    if ((int)$collision->fetchColumn()!==0) throw new RuntimeException('QA contractor namespace collision');
    $insert=$tenant->prepare("INSERT INTO contractors (name,inn,status) VALUES (?,?,'active')");
    $insert->execute([$contractorName,$contractorInn]);
    $entityId=(int)$tenant->lastInsertId();
    if ($entityId<=0) throw new RuntimeException('QA contractor insert failed');
    $state['qa_contractor_fixture']['id']=$entityId;
    $state['responsibility_fixture']=[
        'entity_type'=>'contractor','entity_id'=>$entityId,
        'baseline_membership_id'=>(int)$owner['membership_id'],
        'dynamic_membership_id'=>(int)$dynamic['membership_id'],
        'original'=>null,
    ];
    writeQaState($stateFile,$state);
    $fixture=$tenant->prepare('INSERT INTO entity_responsibilities(entity_type,entity_id,membership_id,assigned_by_membership_id) VALUES(?,?,?,?)');
    $fixture->execute(['contractor',$entityId,(int)$owner['membership_id'],(int)$owner['membership_id']]);
    if (App\Service\EntityResponsibilityService::effectiveBaseMembershipId($tenant,'contractor',$entityId)!==(int)$owner['membership_id']) throw new RuntimeException('QA responsibility baseline readback failed');

    $payload=base64_encode(json_encode([
        ['role'=>'OWNER','username'=>$ownerLogin,'password'=>$ownerPassword],
        ['role'=>'DYNAMIC','username'=>$dynamicLogin,'password'=>$dynamicPassword],
    ],JSON_THROW_ON_ERROR));
    echo 'QA_CREDENTIALS_B64='.$payload.PHP_EOL;
    echo 'QA_FOREIGN_MEMBERSHIP_ID='.(int)$foreign['membership_id'].PHP_EOL;
    echo 'QA_DYNAMIC_MEMBERSHIP_ID='.(int)$dynamic['membership_id'].PHP_EOL;
    echo 'QA_DYNAMIC_TENANT_USER_ID='.$dynamicTenantUserId.PHP_EOL;
    echo 'QA_DYNAMIC_RECIPIENT_READY=YES'.PHP_EOL;
    echo 'QA_TENANT_PROJECTIONS_READY=YES'.PHP_EOL;
    echo 'QA_CONTRACTOR_FIXTURE_READY=YES'.PHP_EOL;
    echo 'QA_RESPONSIBILITY_FIXTURE_READY=YES'.PHP_EOL;
    echo 'QA_IDENTITY_CREATED=YES'.PHP_EOL;
} catch (Throwable $e) {
    try { cleanupQaIdentity($pdo,$config,$stateFile,$runId); }
    catch (Throwable $cleanupError) { fwrite(STDERR,'QA create cleanup failed: '.$cleanupError->getMessage()."\n"); }
    fwrite(STDERR,'QA identity/fixture create failed: '.$e->getMessage()."\n"); exit(1);
}
