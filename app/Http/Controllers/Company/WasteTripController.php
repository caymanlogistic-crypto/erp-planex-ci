<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\LinearRouteService;
use App\Service\WasteTripLegacySourceService;
use App\Service\WasteTripLegacySyncService;
use App\Service\WasteTripService;
use PDO;
use RuntimeException;
use Throwable;

final class WasteTripController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        $context = $this->tenantContext(false);
        $company = $context['company']; $localPdo = $context['localPdo']; $sessionUser = $context['sessionUser'];
        $pageTitle='Перевозка отходов'; $pageContext='Рейсы › Перевозка отходов'; $dbError=$context['error'];
        $trips=$clients=$routeExecutors=$responsibleUsers=$utilizers=[];
        $formError=$_SESSION['waste_trip_error']??null; $formSuccess=$_SESSION['waste_trip_success']??null; $old=$_SESSION['waste_trip_old']??[];
        unset($_SESSION['waste_trip_error'],$_SESSION['waste_trip_success'],$_SESSION['waste_trip_old']);
        if ($company!==null && $localPdo instanceof PDO && $dbError===null) {
            if (!WasteTripService::schemaReady($localPdo)) $dbError='Модуль ожидает завершённый controlled cutover migration 130.';
            else try {
                $trips=WasteTripService::fetchTrips($localPdo,['status'=>$_GET['status']??'','q'=>$_GET['q']??'']);
                $trips=array_values(array_filter($trips,static fn(array $trip):bool=>WasteTripService::canAccessTrip($localPdo,$trip,$sessionUser)));
                [$clients,$routeExecutors,$responsibleUsers,$utilizers]=$this->loadOptions($localPdo,$sessionUser);
                $pageContext.=' › Компания: '.(string)$company['name'];
            } catch(Throwable $e) { error_log('waste trips index failed: '.$e->getMessage()); $dbError='Не удалось загрузить реестр перевозки отходов.'; }
        }
        ob_start(); require base_path('app/View/pages/company_waste_trips.php'); $content=(string)ob_get_clean();
        $config=$this->config; $db=$this->db; require base_path('app/View/layouts/main.php');
    }

    public function createSubmit(): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(true);
        try {
            $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
            $parsed=WasteTripService::parseRequestIds((string)($_POST['request_ids']??'')); $this->assertParsedRequests($parsed);
            $legacy=WasteTripLegacySourceService::connectionFromEnvironment(); if(!$legacy instanceof PDO) throw new RuntimeException('Legacy flights DB не настроена: проверка ID-коллизий недоступна.');
            $businessId=WasteTripService::createIndependent($pdo,$legacy,$_POST,$parsed['ids'],$context['sessionUser']);
            $_SESSION['waste_trip_success']='Рейс #'.$businessId.' создан.';
            header('Location: '.app_url('/company/trips/departures'));
        } catch(Throwable $e) {
            $_SESSION['waste_trip_error']=$this->publicError($e,'Не удалось создать рейс.'); $_SESSION['waste_trip_old']=$_POST;
            header('Location: '.app_url('/company/trips/departures?show_create=1'));
        } exit;
    }

    public function previewSelection(): void
    {
        requireRole(['company_owner','senior_logist','logist']); header('Content-Type: application/json; charset=utf-8'); $context=$this->tenantContext(false);
        try {
            $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
            $parsed=WasteTripService::parseRequestIds((string)($_POST['request_ids']??'')); $this->assertParsedRequests($parsed);
            $preview=WasteTripService::previewSelection($pdo,$parsed['ids']); $preview['success']=$preview['missing']===[]&&$preview['occupied']===[]&&$preview['found_count']>0;
            echo json_encode($preview,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch(Throwable $e) { http_response_code(422); echo json_encode(['success'=>false,'message'=>$this->publicError($e,'Не удалось проверить заявки.')],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    }

    public function legacyIndex(): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(false);
        $company=$context['company']; $localPdo=$this->requireTenantPdo($context); $this->assertSchemaReady($localPdo); $sessionUser=$context['sessionUser'];
        [$clients,$routeExecutors,$responsibleUsers,$utilizers]=$this->loadOptions($localPdo,$sessionUser);
        $pageTitle='Рейсы из старой БД'; $pageContext='Рейсы › Перевозка отходов › Рейсы из старой БД'; $legacyRows=[]; $legacyError=null; $legacyWarning=null;
        try {
            $legacyPdo=WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacyPdo instanceof PDO) $legacyError='Legacy flights DB не настроена.';
            else { $diagnostic=WasteTripService::legacyRangeDiagnostic($legacyPdo); $legacyWarning=$diagnostic['warning']; $legacyRows=WasteTripLegacySourceService::listCatalog($legacyPdo,$localPdo,300); }
        } catch(Throwable $e) { error_log('waste legacy catalog failed: '.$e->getMessage()); $legacyError=$this->publicError($e,'Не удалось прочитать старую БД.'); }
        $formError=$_SESSION['waste_trip_error']??null; $formSuccess=$_SESSION['waste_trip_success']??null; unset($_SESSION['waste_trip_error'],$_SESSION['waste_trip_success']);
        ob_start(); require base_path('app/View/pages/company_waste_trip_legacy.php'); $content=(string)ob_get_clean();
        $config=$this->config; $db=$this->db; require base_path('app/View/layouts/main.php');
    }

    public function materializeSubmit(string $legacyId): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(true); $id=(int)$legacyId;
        try {
            $this->rejectLegacyOwnedPost($_POST); $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
            $legacy=WasteTripLegacySourceService::connectionFromEnvironment(); if (!$legacy instanceof PDO) throw new RuntimeException('Legacy flights DB не настроена.');
            $businessId=WasteTripLegacySyncService::materialize($legacy,$pdo,$id,$_POST,$context['sessionUser']);
            $_SESSION['waste_trip_success']='Legacy-рейс #'.$id.' материализован как рейс #'.$businessId.'.';
        } catch(Throwable $e) { $_SESSION['waste_trip_error']='Legacy #'.$id.': '.$this->publicError($e,'Материализация не выполнена.'); }
        header('Location: '.app_url('/company/trips/departures/legacy')); exit;
    }

    public function modalView(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(false); $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
        $trip=WasteTripService::fetchTrip($pdo,(int)$id); if ($trip===null){http_response_code(404);echo '<div class="notice warn">Рейс не найден.</div>';return;}
        if(!WasteTripService::canAccessTrip($pdo,$trip,$context['sessionUser'])){http_response_code(403);echo '<div class="notice warn">У вас нет доступа к этому рейсу.</div>';return;}
        header('Content-Type: text/html; charset=utf-8'); require base_path('app/View/partials/company_waste_trip_modal_view.php');
    }

    public function modalEditForm(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(false); $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
        $trip=WasteTripService::fetchTrip($pdo,(int)$id); if ($trip===null){http_response_code(404);echo '<div class="notice warn">Рейс не найден.</div>';return;}
        if(!WasteTripService::canAccessTrip($pdo,$trip,$context['sessionUser'])){http_response_code(403);echo '<div class="notice warn">У вас нет доступа к этому рейсу.</div>';return;}
        [$clients,$routeExecutors,$responsibleUsers,$utilizers]=$this->loadOptions($pdo,$context['sessionUser']); header('Content-Type: text/html; charset=utf-8'); require base_path('app/View/partials/company_waste_trip_modal_edit.php');
    }

    public function modalEditSubmit(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(true);
        try {
            $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
            $parsed=['ids'=>[],'invalid'=>[],'duplicates'=>[]];
            if(array_key_exists('request_ids',$_POST)){ $parsed=WasteTripService::parseRequestIds((string)$_POST['request_ids']); $this->assertParsedRequests($parsed); }
            WasteTripService::update($pdo,(int)$id,$_POST,$parsed['ids'],(int)($_POST['lock_version']??0),$context['sessionUser']);
            $_SESSION['waste_trip_success']='Рейс сохранён.';
        } catch(Throwable $e) { $_SESSION['waste_trip_error']=$this->publicError($e,'Не удалось сохранить рейс.'); }
        header('Location: '.app_url('/company/trips/departures')); exit;
    }

    public function cancelSubmit(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(true);
        try { $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo); WasteTripService::cancel($pdo,(int)$id,(int)($_POST['lock_version']??0),(string)($_POST['cancellation_reason']??''),$context['sessionUser']); $_SESSION['waste_trip_success']='Рейс отменён.'; }
        catch(Throwable $e){$_SESSION['waste_trip_error']=$this->publicError($e,'Не удалось отменить рейс.');}
        header('Location: '.app_url('/company/trips/departures')); exit;
    }

    private function tenantContext(bool $applyMigrations): array
    {
        $companyId=(int)(getSessionCompanyId()??0); $sessionUser=['user_id'=>(int)($_SESSION['user_id']??0),'role_code'=>(string)($_SESSION['role_code']??'')];
        if($companyId<=0)return['company'=>null,'localPdo'=>null,'sessionUser'=>$sessionUser,'error'=>'Компания не выбрана.'];
        try {
            $central=$this->db->connection(); $stmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1"); $stmt->execute([$companyId]); $company=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($company===null)return['company'=>null,'localPdo'=>null,'sessionUser'=>$sessionUser,'error'=>'Компания недоступна.'];
            $local=(new Database(companyDatabaseConfig($this->config,$company)))->connection(); if($applyMigrations)applyLocalMigrations($local);
            return['company'=>$company,'localPdo'=>$local,'sessionUser'=>$sessionUser,'error'=>null];
        } catch(Throwable $e){error_log('waste tenant context failed: '.$e->getMessage());return['company'=>null,'localPdo'=>null,'sessionUser'=>$sessionUser,'error'=>'Не удалось подключиться к базе данных компании.'];}
    }

    private function requireTenantPdo(array $context): PDO
    { if(!($context['localPdo']??null) instanceof PDO)throw new RuntimeException((string)($context['error']??'База данных компании недоступна.')); return $context['localPdo']; }
    private function assertSchemaReady(PDO $pdo): void
    { if(!WasteTripService::schemaReady($pdo))throw new RuntimeException('Схема Waste Trips не готова: требуется завершённый controlled cutover migration 130.'); }
    private function assertParsedRequests(array $parsed): void
    { if(($parsed['invalid']??[])!==[])throw new RuntimeException('Некорректные номера заявок: '.implode(', ',$parsed['invalid']).'.'); if(($parsed['duplicates']??[])!==[])throw new RuntimeException('В списке повторяются заявки: '.implode(', ',$parsed['duplicates']).'.'); }
    private function rejectLegacyOwnedPost(array $input): void
    {
        $keys=['business_id','comment','planned_start_mode','planned_start_date','planned_start_date_from','planned_start_date_to','planned_finish_date','actual_start_date','actual_end_date','request_ids'];
        $present=array_values(array_filter($keys,static fn(string $key):bool=>array_key_exists($key,$input)));
        if($present!==[])throw new RuntimeException('Legacy-owned поля нельзя передавать при materialization: '.implode(', ',$present).'.');
    }
    private function publicError(Throwable $e,string $fallback): string
    { if($e instanceof \PDOException){error_log('waste trip database error: '.$e->getMessage());return$fallback;} if($e instanceof RuntimeException)return$e->getMessage(); error_log('waste unexpected: '.$e->getMessage());return$fallback; }
    private function loadOptions(PDO $pdo,array $sessionUser): array
    {
        $clients=LinearRouteService::fetchVisibleClients($pdo,$sessionUser); $executors=LinearRouteService::fetchVisibleRouteExecutors($pdo,$sessionUser);
        if($executors!==[]){$ids=array_values(array_filter(array_map(static fn(array $r):int=>(int)($r['id']??0),$executors)));if($ids!==[]){$m=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT cd.crew_id,GROUP_CONCAT(d.full_name ORDER BY cd.position SEPARATOR ' / ') AS driver_names FROM crew_drivers cd JOIN drivers d ON d.id=cd.driver_id WHERE cd.crew_id IN ({$m}) GROUP BY cd.crew_id");$s->execute($ids);$map=[];foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$map[(int)$r['crew_id']]=trim((string)$r['driver_names']);foreach($executors as&$e){$cid=(int)($e['id']??0);if(($map[$cid]??'')!=='')$e['driver_name']=$map[$cid];}unset($e);}}
        return[$clients,$executors,WasteTripService::fetchActiveUsers($pdo),WasteTripService::fetchActiveUtilizers($pdo)];
    }
}
