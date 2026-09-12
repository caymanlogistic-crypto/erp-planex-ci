<?php

declare(strict_types=1);

$root=rtrim((string)(getenv('PLANEX_ROOT')?:''),'/');
$runId=trim((string)(getenv('QA_RUN_ID')?:''));
if($root===''||!preg_match('/^[0-9]{1,20}$/D',$runId)||!is_file($root.'/.env')||!is_file($root.'/bootstrap/app.php')){fwrite(STDERR,"invalid MAX QA identity environment\n");exit(1);}
$stateFile='/tmp/max_messaging_acceptance_identity_'.$runId.'.json';
require_once $root.'/app/Support/helpers.php';
require_once $root.'/app/Support/environment.php';
loadEnvFileNonOverwriting($root.'/.env');
require_once $root.'/app/Core/Database.php';
require_once $root.'/app/Support/crypto_helper.php';
require_once $root.'/app/Support/company_database.php';
require_once $root.'/app/Service/IdentityNormalizer.php';
require_once $root.'/app/Service/CompanyRoleService.php';
require_once $root.'/app/Service/CompanyMembershipService.php';
require_once $root.'/app/Service/UserSyncService.php';
require_once $root.'/app/Security/PageRegistry.php';
$config=require $root.'/bootstrap/app.php';
$pdo=(new App\Core\Database($config['database']))->connection();
$mode=(string)($argv[1]??'');
$prefix='qa_max_'.$runId.'_';
$roleName='QA MAX Member '.$runId;
$writeState=static function(array $state)use($stateFile):void{$json=json_encode($state,JSON_THROW_ON_ERROR);if(file_put_contents($stateFile,$json,LOCK_EX)===false||!chmod($stateFile,0600))throw new RuntimeException('MAX QA state write failed');};
$company=static function(PDO $pdo,array $config,int $companyId):array{$s=$pdo->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");$s->execute([$companyId]);$c=$s->fetch(PDO::FETCH_ASSOC);if(!$c)throw new RuntimeException('MAX QA company unavailable');$tenant=(new App\Core\Database(companyDatabaseConfig($config,$c)))->connection();return[$c,$tenant];};

$cleanup=static function()use($pdo,$config,$stateFile,$prefix,$roleName,$company):void{
    $state=is_file($stateFile)?json_decode((string)file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR):[];
    $companyId=(int)($state['company_id']??0);$memberships=array_values(array_filter([(int)($state['member_membership_id']??0),(int)($state['owner_membership_id']??0)]));
    if($companyId>0){
        [, $tenant]=$company($pdo,$config,$companyId);
        if($memberships!==[]){$ph=implode(',',array_fill(0,count($memberships),'?'));$del=$tenant->prepare("DELETE FROM users WHERE membership_id IN ($ph)");$del->execute($memberships);}
        $membershipService=new App\Service\CompanyMembershipService($pdo);
        foreach($memberships as$membershipId){$s=$pdo->prepare('SELECT COUNT(*) FROM company_memberships WHERE id=? AND company_id=?');$s->execute([$membershipId,$companyId]);if((int)$s->fetchColumn()===1)$membershipService->remove($companyId,$membershipId);}
        $roleId=(int)($state['member_role_id']??0);if($roleId>0){$s=$pdo->prepare('SELECT COUNT(*) FROM company_roles WHERE id=? AND company_id=?');$s->execute([$roleId,$companyId]);if((int)$s->fetchColumn()===1)(new App\Service\CompanyRoleService($pdo))->delete($companyId,$roleId);}
        foreach([(int)($state['owner_account_id']??0),(int)($state['member_account_id']??0)]as$accountId)if($accountId>0){$d=$pdo->prepare('DELETE FROM user_accounts WHERE id=? AND login LIKE ?');$d->execute([$accountId,$prefix.'%']);}
        $central=$pdo->prepare('SELECT COUNT(*) FROM user_accounts WHERE login LIKE ?');$central->execute([$prefix.'%']);if((int)$central->fetchColumn()!==0)throw new RuntimeException('MAX QA central account residue');
        $roles=$pdo->prepare('SELECT COUNT(*) FROM company_roles WHERE company_id=? AND normalized_name=?');$roles->execute([$companyId,App\Service\IdentityNormalizer::roleName($roleName)]);if((int)$roles->fetchColumn()!==0)throw new RuntimeException('MAX QA role residue');
        $tenantResidue=$tenant->prepare('SELECT COUNT(*) FROM users WHERE login LIKE ?');$tenantResidue->execute([$prefix.'%']);if((int)$tenantResidue->fetchColumn()!==0)throw new RuntimeException('MAX QA tenant projection residue');
    }
    if(is_file($stateFile)&&!unlink($stateFile))throw new RuntimeException('MAX QA state cleanup failed');
    echo "MAX_QA_ACCOUNT_RESIDUE=0\nMAX_QA_ROLE_RESIDUE=0\nMAX_QA_TENANT_PROJECTION_RESIDUE=0\nMAX_QA_RESIDUE=0\n";
};

if($mode==='cleanup'){$cleanup();exit(0);}if($mode!=='create'){fwrite(STDERR,"unknown MAX QA identity mode\n");exit(1);}if(is_file($stateFile)){fwrite(STDERR,"MAX QA state already exists\n");exit(1);}
$rows=$pdo->query("SELECT c.id,owner_role.id AS owner_role_id,(SELECT COUNT(*) FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id AND a.status='active' JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.company_id=c.id AND m.status='active' AND r.system_key='company_owner') owner_count FROM companies c JOIN company_access_readiness ar ON ar.company_id=c.id AND ar.state='ready' AND ar.verified_at IS NOT NULL AND ar.activated_at IS NOT NULL JOIN company_roles owner_role ON owner_role.company_id=c.id AND owner_role.system_key='company_owner' AND owner_role.is_system=1 WHERE c.status='active' ORDER BY c.id")->fetchAll(PDO::FETCH_ASSOC);
$target=null;$tenant=null;foreach($rows as$row){if((int)$row['owner_count']<1)continue;try{[, $candidate]=$company($pdo,$config,(int)$row['id']);foreach(['users','max_bots','max_chats','max_message_logs']as$t)$candidate->query("SELECT 1 FROM `{$t}` LIMIT 1");$target=$row;$tenant=$candidate;break;}catch(Throwable){continue;}}
if(!$target||!$tenant){fwrite(STDERR,"no ready company with migration 133 for MAX QA\n");exit(1);}
$companyId=(int)$target['id'];$ownerRoleId=(int)$target['owner_role_id'];
$state=['company_id'=>$companyId,'member_role_id'=>0,'owner_account_id'=>0,'owner_membership_id'=>0,'member_account_id'=>0,'member_membership_id'=>0];$writeState($state);
try{
    $roleService=new App\Service\CompanyRoleService($pdo);$membershipService=new App\Service\CompanyMembershipService($pdo);$registry=new App\Security\PageRegistry();
    $role=$roleService->create($companyId,$roleName);$state['member_role_id']=(int)$role['id'];$writeState($state);
    $grantable=$registry->grantablePageKeys();$landing=array_values(array_map(static fn(array$p):string=>(string)$p['key'],array_filter($registry->landingPages(),static fn(array$p):bool=>$registry->isGrantable((string)$p['key']))));
    $pages=array_fill_keys($grantable,false);$pages['company.dashboard']=true;$scopes=array_fill_keys(App\Service\CompanyRoleService::regulatedEntities(),'none');
    $roleService->save($companyId,(int)$role['id'],$roleName,$pages,$scopes,'company.dashboard',$grantable,$landing);
    $ownerLogin=$prefix.'owner_'.bin2hex(random_bytes(4));$ownerPassword=rtrim(strtr(base64_encode(random_bytes(30)),'+/','-_'),'=');
    $owner=$membershipService->add($companyId,$ownerRoleId,'QA MAX Owner '.$runId,$ownerLogin,$ownerPassword,null,null,'active');if(!($owner['account_created']??false))throw new RuntimeException('MAX QA owner account collision');
    $state['owner_account_id']=(int)$owner['account_id'];$state['owner_membership_id']=(int)$owner['membership_id'];$writeState($state);
    $memberLogin=$prefix.'member_'.bin2hex(random_bytes(4));$memberPassword=rtrim(strtr(base64_encode(random_bytes(30)),'+/','-_'),'=');
    $member=$membershipService->add($companyId,(int)$role['id'],'QA MAX Member '.$runId,$memberLogin,$memberPassword,null,null,'active');if(!($member['account_created']??false))throw new RuntimeException('MAX QA member account collision');
    $state['member_account_id']=(int)$member['account_id'];$state['member_membership_id']=(int)$member['membership_id'];$writeState($state);
    $sync=new App\Service\UserSyncService($config);
    foreach([[(int)$owner['account_id'],(int)$owner['membership_id'],$ownerRoleId],[(int)$member['account_id'],(int)$member['membership_id'],(int)$role['id']]]as[$accountId,$membershipId,$roleId]){
        $a=$pdo->prepare('SELECT * FROM user_accounts WHERE id=?');$a->execute([$accountId]);$account=$a->fetch(PDO::FETCH_ASSOC);$m=$pdo->prepare('SELECT * FROM company_memberships WHERE id=?');$m->execute([$membershipId]);$membership=$m->fetch(PDO::FETCH_ASSOC);$r=$pdo->prepare('SELECT * FROM company_roles WHERE id=?');$r->execute([$roleId]);$roleRow=$r->fetch(PDO::FETCH_ASSOC);if(!$account||!$membership||!$roleRow)throw new RuntimeException('MAX QA authority row missing');$sync->syncMembershipProjection($tenant,$account,$membership,$roleRow);
    }
    $credentials=[['role'=>'OWNER','username'=>$ownerLogin,'password'=>$ownerPassword],['role'=>'MEMBER','username'=>$memberLogin,'password'=>$memberPassword]];
    echo 'MAX_QA_CREDENTIALS_B64='.base64_encode(json_encode($credentials,JSON_THROW_ON_ERROR))."\n";
    echo 'MAX_QA_COMPANY_ID='.$companyId."\nMAX_QA_IDENTITY_CREATED=YES\n";
}catch(Throwable$e){try{$cleanup();}catch(Throwable$cleanupError){fwrite(STDERR,'cleanup failure: '.$cleanupError->getMessage()."\n");}throw$e;}
