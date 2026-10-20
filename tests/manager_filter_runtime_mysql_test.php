<?php
declare(strict_types=1);

// Real startup dependencies, real migrated disposable DB, no fixture autoloader.
$config=require dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/entrypoint_dependencies.php';
$router=new class {
    public function get(string $path,array $handler,?string $page=null): void {}
    public function post(string $path,array $handler,?string $page=null): void {}
};
$db=new \App\Core\Database($config['database']);
require BASE_PATH.'/app/Http/Routes/company_linear_trips.php';
require BASE_PATH.'/app/Http/Routes/company_trip_closing.php';

use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Service\EntityResponsibilityService;
use App\Service\LocalMigrationService;
use App\Service\WasteTripService;
use App\Service\WasteTripClosingService;
use App\Service\WasteTripClosingReportService;

$schema=(string)getenv('PLANEX_TEST_FIXED_DB');
if($schema===''||!in_array($schema,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true))throw new RuntimeException('Disposable schema required');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$schema.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$check=static function(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);};
cleanCompanyPoolDatabase($pdo);
try {
    LocalMigrationService::apply($pdo);
    $pdo->exec("INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES
        (10,71,'Гладких Карина Артуровна','karina','hash','logist','active'),
        (11,72,'GTP-Бухгалтерия','gtp','hash','logist','active');
        INSERT INTO clients(id,name,inn,status) VALUES(1,'Тестовый клиент','7700000000','active');
        INSERT INTO waste_trips(id,business_id,client_id,responsible_user_id,destination_type,legal_entity_code,status,actual_start_date,actual_end_date,created_by_membership_id,comment) VALUES
        (1,501,1,10,'warehouse','planex','completed','2026-10-10','2026-10-11',71,'Рейс Карины'),
        (2,502,1,11,'warehouse','planex','completed','2026-10-10','2026-10-11',72,'Рейс бухгалтерии');
        CREATE TABLE user_accounts(id INT PRIMARY KEY,full_name VARCHAR(255),login VARCHAR(255),status VARCHAR(20));
        CREATE TABLE company_roles(id INT PRIMARY KEY,company_id INT);
        CREATE TABLE company_memberships(id INT PRIMARY KEY,account_id INT,company_id INT,role_id INT,status VARCHAR(20));
        INSERT INTO user_accounts VALUES(1,'Гладких Карина Артуровна','karina','active'),(2,'GTP-Бухгалтерия','gtp','active'),(3,'','fallback.login','active'),(4,'Кузнецова <Анна>','escape','active');
        INSERT INTO company_roles VALUES(1,1);
        INSERT INTO company_memberships VALUES(71,1,1,1,'active'),(72,2,1,1,'active'),(73,3,1,1,'active'),(74,4,1,1,'active');");
    $_SESSION=['account_id'=>1,'user_id'=>1,'company_id'=>1,'role_code'=>'company_owner'];
    AccessRuntime::setCurrent(EffectiveAccess::systemOwner(1,1,array_column(\App\Security\PageRegistry::companyPages(),'key')));
    $before=$pdo->query('SELECT id,full_name FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $registryManagers=WasteTripService::fetchRegistryManagers($pdo);
    $check(count($registryManagers)===2,'Actual manager source options');
    $trips=WasteTripService::fetchTrips($pdo,['manager'=>10]);
    $check(array_map('intval',array_column($trips,'id'))===[1],'Manager value still filters real trip IDs');
    $check(WasteTripService::fetchTrips($pdo,['manager'=>10,'q'=>'Рейс бухгалтерии'])===[],'Manager and search remain conjunctive');
    $company=['name'=>'Тест','status'=>'active'];$dbError=$formSuccess=$formError=null;$old=$clients=$routeExecutors=$utilizers=[];$isFinanceRealm=false;$selectedManager=10;
    $responsibleUsers=EntityResponsibilityService::eligibleRecipients($pdo,$pdo,1);
    $pageTitle='Выезды';$pageContext='Выезды';$topbarCrumbs=[];
    ob_start();require BASE_PATH.'/app/View/pages/company_waste_trips.php';$content=(string)ob_get_clean();
    ob_start();require BASE_PATH.'/app/View/layouts/main.php';$departures=(string)ob_get_clean();
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$departures);$xp=new DOMXPath($dom);
    $check($xp->query('//select[@name="manager"]/option[@value="10" and @selected]')->item(0)?->textContent==='Карина Г.','Real source selected short label');
    $check($xp->query('//select[@name="manager"]/option[@value="11"]')->item(0)?->textContent==='GTP-Бухгалтерия','Single-token source preserved');
    $assertShortOption=static function(string $html,string $optionQuery): void {
        $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);$xpath=new DOMXPath($dom);
        if($xpath->query($optionQuery)->item(0)?->textContent!=='Карина Г.')throw new RuntimeException('Full-name manager option regression');
    };
    $assertRejectsFullName=static function(string $html,string $optionQuery)use($assertShortOption,$check): void {
        $assertShortOption($html,$optionQuery);
        $mutated=str_replace('>Карина Г.</option>','>Гладких Карина Артуровна</option>',$html);
        $rejected=false;try{$assertShortOption($mutated,$optionQuery);}catch(RuntimeException){$rejected=true;}
        $check($rejected,'Rendered old full-name label must fail the option contract');
    };
    $assertRejectsFullName($departures,'//select[@name="manager"]/option[@value="10"]');
    $check($xp->query('//select[@name="responsible_membership_id"]/option[@value="71"]')->item(0)?->textContent==='Гладких Карина Артуровна','Full-name form data preserved');
    $trips=WasteTripClosingService::trips($pdo);$reportRows=WasteTripClosingReportService::rows($trips);
    $schemaReady=$canFilterManagers=$canFilterLeader=true;$canCreateInvoice=false;$success=$flashError=$error=null;$viewStateKey='runtime-manager-filter';$completionQuarters=[];
    $pageTitle='Закрывающие документы';$pageContext=$pageTitle;
    ob_start();require BASE_PATH.'/app/View/pages/company_waste_trip_closing_registry.php';$content=(string)ob_get_clean();
    ob_start();require BASE_PATH.'/app/View/layouts/main.php';$closing=(string)ob_get_clean();
    $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$closing);$xp=new DOMXPath($dom);
    foreach([71=>'Карина Г.',72=>'GTP-Бухгалтерия',73=>'fallback.login',74=>'<Анна> К.'] as $id=>$label)$check($xp->query('//select[@data-closing-manager]/option[@value="'.$id.'"]')->item(0)?->textContent===$label,'Real central membership label '.$id);
    $assertRejectsFullName($closing,'//select[@data-closing-manager]/option[@value="71"]');
    $check(!str_contains($closing,'><Анна> К.</option>'),'Labels escape HTML');
    $check($before===$pdo->query('SELECT id,full_name FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Rendering never rewrites full names');
    $check(str_contains($departures,'/assets/js/table-reference-select.js')&&str_contains($closing,'/assets/js/table-reference-select.js'),'Actual layout includes enhanced select');
    echo "MANAGER_FILTER_RUNTIME_MYSQL=PASS\n";
} finally {
    AccessRuntime::clear();
    cleanCompanyPoolDatabase($pdo);
}
