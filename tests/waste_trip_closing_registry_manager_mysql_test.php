<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/LocalMigrationService.php';
require_once BASE_PATH.'/app/Security/EffectiveAccess.php';
require_once BASE_PATH.'/app/Security/AccessRuntime.php';
require_once BASE_PATH.'/app/Service/AccessControlService.php';
require_once BASE_PATH.'/app/Service/EntityResponsibilityService.php';
require_once BASE_PATH.'/app/Service/RegulatedDataScopeService.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingService.php';
require_once BASE_PATH.'/app/Service/WasteTripClosingReportService.php';
foreach (['DateCalculationService','ProductionCalendarService','FinanceReceivedPaidService',
    'WasteTripClosingInvoiceService','FinanceInvoicePaymentTimelineService'] as $service) {
    require_once BASE_PATH.'/app/Service/'.$service.'.php';
}

use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Service\LocalMigrationService;
use App\Service\WasteTripClosingReportService;
use App\Service\WasteTripClosingService;

$database=(string)getenv('PLANEX_TEST_FIXED_DB');
if ($database===''||!in_array($database,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) {
    throw new RuntimeException('Guarded disposable schema required');
}
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$database.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
$GLOBALS['closingRegistryTestPath']='/company/logistics/closing-documents';
function current_app_path(): string { return $GLOBALS['closingRegistryTestPath']; }
cleanCompanyPoolDatabase($pdo);
try {
    LocalMigrationService::apply($pdo);
    $pdo->exec("INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES
        (10,71,'Христофорова Наталья','natalia','hash','membership_role','active'),
        (11,72,'Другой менеджер','other','hash','membership_role','active');
        INSERT INTO clients(id,name,inn,status) VALUES(1,'Клиент','7700000000','active');
        INSERT INTO waste_trips(id,business_id,client_id,responsible_user_id,destination_type,legal_entity_code,status,actual_start_date,actual_end_date,created_by_membership_id,comment)
        VALUES (1,346,1,10,'warehouse','planex','in_progress','2026-10-13',NULL,72,'Ангарск'),
               (2,334,1,10,'warehouse','planex','in_progress','2026-10-13',NULL,72,'Новороссийск'),
               (3,325,1,10,'warehouse','planex','completed','2026-10-10','2026-10-11',72,'Завершённый'),
               (4,344,1,10,'warehouse','planex','planned_route',NULL,NULL,72,'Планируемый'),
               (5,333,1,11,'warehouse','planex','completed','2026-10-10','2026-10-11',71,'Чужой');");
    AccessRuntime::setCurrent(EffectiveAccess::member(71,1,['company.documents.trip_closing_registry'=>true],['waste_trip'=>'none']));
    if(!WasteTripClosingService::ready($pdo))throw new RuntimeException('Closing schema not ready');
    $trips=WasteTripClosingService::trips($pdo);
    $ids=array_map('intval',array_column($trips,'business_id'));
    sort($ids);
    if($ids!==[325,334,346])throw new RuntimeException('Manager registry trips mismatch: '.json_encode($ids));
    if((int)WasteTripClosingService::trip($pdo,1)['business_id']!==346)throw new RuntimeException('Started manager trip must open its document popup');
    try {WasteTripClosingService::trip($pdo,5);throw new RuntimeException('Another manager trip became accessible');}
    catch (DomainException) {}
    $rows=WasteTripClosingReportService::rows($trips);
    foreach($rows as $row) {
        if(in_array((int)$row['number'],[334,346],true)&&$row['section_state']!=='incomplete') {
            throw new RuntimeException('Started trips must not become ready for payment');
        }
    }
    $GLOBALS['closingRegistryTestPath']='/company/trips/closing-documents';
    if(WasteTripClosingService::trips($pdo)!==[])throw new RuntimeException('Old route must keep denied data scope');
    echo "WASTE_TRIP_CLOSING_REGISTRY_MANAGER=PASS\n";
} finally {
    AccessRuntime::clear();
    cleanCompanyPoolDatabase($pdo);
}
