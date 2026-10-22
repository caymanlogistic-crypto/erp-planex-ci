<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\AccessControlService;
use App\Service\EntityResponsibilityService;
use App\Service\LinearRouteService;
use App\Service\WasteTripIncidentService;
use App\Service\WasteTripSynchronizationService;
use App\Service\WasteTripLegacySourceService;
use App\Service\WasteTripCustomerPaymentService;
use App\Service\WasteTripGeneralPaymentService;
use App\Service\WasteTripCarrierPaymentService;
use App\Service\WasteTripService;
use App\Service\WasteTripParityService;
use App\Service\UserSyncService;
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
        $company = $context['company']; $localPdo = $context['localPdo']; $sessionUser = $context['sessionUser']; $central=$context['central']; $companyId=(int)$context['companyId'];
        $currentMembershipId=AccessControlService::effectiveMembershipId()??0;
        $isFinanceRealm=(string)($sessionUser['role_code']??'')==='company_owner';
        $pageTitle='Перевозка отходов'; $pageContext='Рейсы › Перевозка отходов'; $dbError=$context['error'];
        $trips=$clients=$routeExecutors=$responsibleUsers=$utilizers=$registryManagers=[];
        $tripStatusCounts=[];
        $tripParity=null;
        $syncResult=$_SESSION['waste_trip_sync_result']??null; unset($_SESSION['waste_trip_sync_result']);
        $formError=$_SESSION['waste_trip_error']??null; $formSuccess=$_SESSION['waste_trip_success']??null; $old=$_SESSION['waste_trip_old']??[];
        unset($_SESSION['waste_trip_error'],$_SESSION['waste_trip_success'],$_SESSION['waste_trip_old']);
        if ($company!==null && $localPdo instanceof PDO && $dbError===null) {
            if (!WasteTripService::schemaReady($localPdo)) $dbError='Модуль ожидает обновление схемы рейсов (158).';
            else try {
                $registryManagers=WasteTripService::fetchRegistryManagers($localPdo);
                $requestedManager=max(0,(int)($_GET['manager']??0));
                $availableManagerIds=array_map(static fn(array $row):int=>(int)$row['id'],$registryManagers);
                $selectedManager=in_array($requestedManager,$availableManagerIds,true)?$requestedManager:0;
                $filters=['status'=>$_GET['status']??'','company'=>$_GET['company']??'','manager'=>$selectedManager,'q'=>$_GET['q']??'','sort'=>$_GET['sort']??'status'];
                $trips=WasteTripService::fetchTrips($localPdo,$filters,$isFinanceRealm);
                $trips=array_values(array_filter($trips,static fn(array $trip):bool=>WasteTripService::canAccessTrip($localPdo,$trip,$sessionUser)));
                $statusFilterActive=trim((string)($_GET['status']??''))!=='';
                $statusTrips=$statusFilterActive ? WasteTripService::fetchTrips($localPdo,array_replace($filters,['status'=>'']),false) : $trips;
                if($statusFilterActive)$statusTrips=array_values(array_filter($statusTrips,static fn(array $trip):bool=>WasteTripService::canAccessTrip($localPdo,$trip,$sessionUser)));
                $tripStatusCounts['']=count($statusTrips);
                foreach($statusTrips as $statusTrip){$status=WasteTripService::displayStatus((string)($statusTrip['status']??''));$tripStatusCounts[$status]=($tripStatusCounts[$status]??0)+1;}
                $tripParity=WasteTripParityService::state($localPdo);
                [$clients,$routeExecutors,$responsibleUsers,$utilizers]=$this->loadOptions($localPdo,$sessionUser,$central,$companyId);
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
            $input=$this->resolveResponsibleInput($context,$_POST);
            $generalPayments=$this->generalPaymentsForSession($_POST,$context['sessionUser']);
            $customerPayments=$this->customerPaymentsForSession($_POST,$context['sessionUser']);
            $carrierPayments=!empty($_POST['no_markup'])?null:$this->carrierPaymentsForSession($_POST,$context['sessionUser']);
            $legacy=WasteTripLegacySourceService::connectionFromEnvironment(); if(!$legacy instanceof PDO) throw new RuntimeException('Legacy flights DB не настроена: проверка ID-коллизий недоступна.');
            $businessId=WasteTripService::createIndependent($pdo,$legacy,$input,$parsed['ids'],$context['sessionUser'],$customerPayments,$carrierPayments,$generalPayments);
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

    public function synchronizeSubmit(): void
    {
        requireRole(['company_owner']);
        $context = $this->tenantContext(false);
        try {
            $pdo = $this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
            $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacy instanceof PDO) throw new RuntimeException('Подключение к старой БД не настроено.');
            $result = WasteTripSynchronizationService::run($legacy,$pdo,$context['central'],(int)$context['companyId'],$context['sessionUser']);
            $_SESSION['waste_trip_sync_result'] = $result;
        } catch (Throwable $error) {
            $_SESSION['waste_trip_error'] = $this->publicError($error,'Синхронизация не выполнена.');
        }
        header('Location: '.app_url('/company/trips/departures')); exit;
    }

    public function modalView(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(false); $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
        $isFinanceRealm=(string)($context['sessionUser']['role_code']??'')==='company_owner';$trip=WasteTripService::fetchTrip($pdo,(int)$id,$isFinanceRealm); if ($trip===null){http_response_code(404);echo '<div class="notice warn">Рейс не найден.</div>';return;}
        if(!WasteTripService::canAccessTrip($pdo,$trip,$context['sessionUser'])){http_response_code(403);echo '<div class="notice warn">У вас нет доступа к этому рейсу.</div>';return;}
        header('Content-Type: text/html; charset=utf-8'); require base_path('app/View/partials/company_waste_trip_modal_view.php');
    }

    public function modalEditForm(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(false); $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
        $isFinanceRealm=(string)($context['sessionUser']['role_code']??'')==='company_owner';$trip=WasteTripService::fetchTrip($pdo,(int)$id,$isFinanceRealm); if ($trip===null){http_response_code(404);echo '<div class="notice warn">Рейс не найден.</div>';return;}
        if(!WasteTripService::canAccessTrip($pdo,$trip,$context['sessionUser'])){http_response_code(403);echo '<div class="notice warn">У вас нет доступа к этому рейсу.</div>';return;}
        [$clients,$routeExecutors,$responsibleUsers,$utilizers]=$this->loadOptions($pdo,$context['sessionUser'],$context['central'],(int)$context['companyId']);
        $editOld=[];$editOldId=(int)($_SESSION['waste_trip_edit_id']??0);if($editOldId===(int)$id&&is_array($_SESSION['waste_trip_edit_old']??null)){$editOld=$_SESSION['waste_trip_edit_old'];unset($_SESSION['waste_trip_edit_id'],$_SESSION['waste_trip_edit_old']);}
        header('Content-Type: text/html; charset=utf-8'); require base_path('app/View/partials/company_waste_trip_modal_edit.php');
    }

    public function modalEditSubmit(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(true);
        try {
            $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
            $parsed=['ids'=>[],'invalid'=>[],'duplicates'=>[]];
            if(array_key_exists('request_ids',$_POST)){ $parsed=WasteTripService::parseRequestIds((string)$_POST['request_ids']); $this->assertParsedRequests($parsed); }
            $input=$this->resolveResponsibleInput($context,$_POST);
            $generalPayments=$this->generalPaymentsForSession($_POST,$context['sessionUser']);
            $customerPayments=$this->customerPaymentsForSession($_POST,$context['sessionUser']);
            $carrierPayments=!empty($_POST['no_markup'])?null:(array_key_exists('carrier_payments',$_POST)?$this->carrierPaymentsForSession($_POST,$context['sessionUser']):null);
            WasteTripService::update($pdo,(int)$id,$input,$parsed['ids'],(int)($_POST['lock_version']??0),$context['sessionUser'],$customerPayments,$carrierPayments,$generalPayments);
            $_SESSION['waste_trip_success']='Рейс сохранён.';
            $redirect='/company/trips/departures?open_trip='.(int)$id;
        } catch(Throwable $e) { $_SESSION['waste_trip_error']=$this->publicError($e,'Не удалось сохранить рейс.');$_SESSION['waste_trip_edit_old']=$_POST;$_SESSION['waste_trip_edit_id']=(int)$id;$redirect='/company/trips/departures?open_edit='.(int)$id; }
        header('Location: '.app_url($redirect)); exit;
    }

    public function cancelSubmit(string $id): void
    {
        requireRole(['company_owner','senior_logist','logist']); $context=$this->tenantContext(true);
        try { $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo); WasteTripService::cancel($pdo,(int)$id,(int)($_POST['lock_version']??0),(string)($_POST['cancellation_reason']??''),$context['sessionUser']); $_SESSION['waste_trip_success']='Рейс отменён.';$redirect='/company/trips/departures?open_trip='.(int)$id; }
        catch(Throwable $e){$_SESSION['waste_trip_error']=$this->publicError($e,'Не удалось отменить рейс.');$redirect='/company/trips/departures?open_trip='.(int)$id;}
        header('Location: '.app_url($redirect)); exit;
    }

    public function deleteSubmit(string $id): void
    {
        requireRole(['company_owner']); $context=$this->tenantContext(true);
        try {
            $pdo=$this->requireTenantPdo($context); $this->assertSchemaReady($pdo);
            WasteTripService::delete($pdo,(int)$id,(int)($_POST['lock_version']??0),$context['sessionUser']);
            $_SESSION['waste_trip_success']='Рейс удалён из реестра.';
        } catch(Throwable $e) { $_SESSION['waste_trip_error']=$this->publicError($e,'Не удалось удалить рейс.'); }
        header('Location: '.app_url('/company/trips/departures')); exit;
    }

    private function tenantContext(bool $applyMigrations): array
    {
        $companyId=(int)(getSessionCompanyId()??0); $sessionUser=['user_id'=>(int)($_SESSION['user_id']??0),'role_code'=>(string)($_SESSION['role_code']??'')];
        if($companyId<=0)return['company'=>null,'localPdo'=>null,'central'=>null,'companyId'=>0,'sessionUser'=>$sessionUser,'error'=>'Компания не выбрана.'];
        try {
            $central=$this->db->connection(); $stmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1"); $stmt->execute([$companyId]); $company=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($company===null)return['company'=>null,'localPdo'=>null,'central'=>$central,'companyId'=>$companyId,'sessionUser'=>$sessionUser,'error'=>'Компания недоступна.'];
            $local=(new Database(companyDatabaseConfig($this->config,$company)))->connection(); if($applyMigrations)applyLocalMigrations($local);
            return['company'=>$company,'localPdo'=>$local,'central'=>$central,'companyId'=>$companyId,'sessionUser'=>$sessionUser,'error'=>null];
        } catch(Throwable $e){error_log('waste tenant context failed: '.$e->getMessage());return['company'=>null,'localPdo'=>null,'central'=>null,'companyId'=>$companyId,'sessionUser'=>$sessionUser,'error'=>'Не удалось подключиться к базе данных компании.'];}
    }

    private function requireTenantPdo(array $context): PDO
    { if(!($context['localPdo']??null) instanceof PDO)throw new RuntimeException((string)($context['error']??'База данных компании недоступна.')); return $context['localPdo']; }
    private function assertSchemaReady(PDO $pdo): void
    { if(!WasteTripService::schemaReady($pdo))throw new RuntimeException('Схема рейсов не готова: требуется обновление 158.'); }
    private function assertParsedRequests(array $parsed): void
    { if(($parsed['invalid']??[])!==[])throw new RuntimeException('Некорректные номера заявок: '.implode(', ',$parsed['invalid']).'.'); if(($parsed['duplicates']??[])!==[])throw new RuntimeException('В списке повторяются заявки: '.implode(', ',$parsed['duplicates']).'.'); }
    private function publicError(Throwable $e,string $fallback,array $context=[]): string
    {
        if($e instanceof RuntimeException&&!$e instanceof \PDOException)return$e->getMessage();
        $incident=WasteTripIncidentService::record($e,$context);
        return $fallback.' Код ошибки: '.$incident.'.';
    }
    private function loadOptions(PDO $pdo,array $sessionUser,PDO $central,int $companyId): array
    {
        $clients=LinearRouteService::fetchVisibleClients($pdo,$sessionUser); $executors=LinearRouteService::fetchVisibleRouteExecutors($pdo,$sessionUser);
        if($executors!==[]){$ids=array_values(array_filter(array_map(static fn(array $r):int=>(int)($r['id']??0),$executors)));if($ids!==[]){$m=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT cd.crew_id,GROUP_CONCAT(d.full_name ORDER BY cd.position SEPARATOR ' / ') AS driver_names FROM crew_drivers cd JOIN drivers d ON d.id=cd.driver_id WHERE cd.crew_id IN ({$m}) GROUP BY cd.crew_id");$s->execute($ids);$map=[];foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$map[(int)$r['crew_id']]=trim((string)$r['driver_names']);foreach($executors as&$e){$cid=(int)($e['id']??0);if(($map[$cid]??'')!=='')$e['driver_name']=$map[$cid];}unset($e);}}
        $responsibleUsers=EntityResponsibilityService::eligibleRecipients($central,$pdo,$companyId);
        return[$clients,$executors,$responsibleUsers,WasteTripService::fetchActiveUtilizers($pdo)];
    }

    private function customerPaymentsForSession(array $post,array $sessionUser): ?array
    {
        if((string)($sessionUser['role_code']??'')!=='company_owner')return null;
        return WasteTripCustomerPaymentService::parseRows((array)($post['customer_payments']??[]),false,true);
    }


    private function generalPaymentsForSession(array $post,array $sessionUser): ?array
    {
        if((string)($sessionUser['role_code']??'')!=='company_owner')return null;
        if(!array_key_exists('general_payments',$post))return null;
        return WasteTripGeneralPaymentService::parseRows((array)($post['general_payments']??[]),false,true);
    }

    private function carrierPaymentsForSession(array $post,array $sessionUser): ?array
    {
        if((string)($sessionUser['role_code']??'')!=='company_owner')return null;
        $rows=WasteTripCarrierPaymentService::parseRows((array)($post['carrier_payments']??[]),false);
        if($rows!==[]&&(int)($post['route_executor_id']??0)<=0)throw new RuntimeException('Для ставки перевозчика выберите исполнителя рейса.');
        return $rows;
    }

    private function resolveResponsibleInput(array $context,array $input): array
    {
        $membershipId=(int)($input['responsible_membership_id']??0);
        if($membershipId<=0){
            if(AccessControlService::effectiveMembershipId()!==null)throw new RuntimeException('Выберите менеджера.');
            return $input;
        }
        $pdo=$this->requireTenantPdo($context);$central=$context['central']??null;$companyId=(int)($context['companyId']??0);
        if(!$central instanceof PDO)throw new RuntimeException('Центральный каталог пользователей недоступен.');
        $input['responsible_user_id']=(new UserSyncService($this->config))->resolveMembershipTenantUserId($central,$pdo,$companyId,$membershipId);
        return $input;
    }
}
