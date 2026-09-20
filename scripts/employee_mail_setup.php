<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
ini_set('display_errors','0');
try {
    $mode=$argv[1]??'inspect';
    if(!in_array($mode,['inspect','seed','check'],true))throw new RuntimeException('Invalid mode');
    $root=dirname(__DIR__);$sha=(string)getenv('PLANEX_SOURCE_SHA');
    $marker=(string)(getenv('PLANEX_DEPLOY_MARKER')?:'/home/s/spugovxsim/planexp/deploy/P07_erpv2_deployed_commit.txt');
    if(!preg_match('/^[0-9a-f]{40}$/D',$sha)||trim((string)file_get_contents($marker))!==$sha)throw new RuntimeException('Exact deployed marker required');
    if($mode!=='inspect' && getenv('PLANEX_CONFIRM_MAIL')!=='SETUP_SIX_PLANEX_MAIL_PROFILES')throw new RuntimeException('Explicit setup authorization required');
    $config=require $root.'/bootstrap/app.php';
    require_once $root.'/app/Core/Database.php';require_once $root.'/app/Support/company_database.php';
    require_once $root.'/app/Service/EmployeeMailBootstrapService.php';
    $central=(new App\Core\Database($config['database']))->connection();
    $q=$central->prepare("SELECT * FROM companies WHERE inn=? AND status='active'");$q->execute(['5406854242']);$companies=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($companies)!==1)throw new DomainException('Сопоставление компании неоднозначно.');
    $company=$companies[0];
    $service=new App\Service\EmployeeMailBootstrapService($central,(int)$company['id']);
    $before=$service->mapping();
    if($mode==='inspect')$result=$before;
    elseif($mode==='seed'){
        $raw=stream_get_contents(STDIN,16384);$passwords=json_decode($raw,true,32,JSON_THROW_ON_ERROR);unset($raw);
        if(!is_array($passwords))throw new RuntimeException('Invalid input');
        $result=$service->seed($passwords);unset($passwords);
    }else $result=$service->checkAll();
    if($service->mapping()!==$before)throw new RuntimeException('Identity changed during setup');
    echo json_encode(['operation'=>$mode,'result'=>$result,'identity_unchanged'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(DomainException $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
catch(Throwable){fwrite(STDERR,"EMPLOYEE_MAIL_SETUP_FAILED: проверьте предусловия и доступность сервиса.\n");exit(1);}
