<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FinanceObligationService;
use App\Service\LinearTripRequestNormalizer;
use App\Service\LinearTripServiceLineService;

final class LinearTripController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void
    {
        $this->syncFinanceObligations();
        $config = $this->config;
        $db = $this->db;
        ob_start();
        require base_path('app/Http/Controllers/Company/LinearTripActions/index.php');
        $html = (string)ob_get_clean();
        ob_start();
        require base_path('app/View/partials/company_linear_trip_services_bootstrap.php');
        $addon = (string)ob_get_clean();
        echo str_contains($html, '</body>') ? str_replace('</body>', $addon . '</body>', $html) : $html . $addon;
    }

    public function createSubmit(): void
    {
        $this->normalizeSubmittedExecutorCarrier();
        $this->normalizeOptionalCargo();
        $this->ensureLegacyPaymentDueCompatibility();
        $this->prepareAdditionalServicesForCreate();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/LinearTripActions/create_submit.php');
    }

    public function modalView(string $id): void { $this->syncFinanceObligations(); $config=$this->config; $db=$this->db; require base_path('app/Http/Controllers/Company/LinearTripActions/modal_view.php'); }
    public function modalEditForm(string $id): void { $this->syncFinanceObligations(); $config=$this->config; $db=$this->db; require base_path('app/Http/Controllers/Company/LinearTripActions/modal_edit_form.php'); }
    public function modalEditSubmit(string $id): void { $this->normalizeSubmittedExecutorCarrier(); $this->normalizeOptionalCargo(); $this->ensureLegacyPaymentDueCompatibility(); $config=$this->config; $db=$this->db; require base_path('app/Http/Controllers/Company/LinearTripActions/modal_edit_submit.php'); }
    public function modalDelete(string $id): void { $config=$this->config; $db=$this->db; require base_path('app/Http/Controllers/Company/LinearTripActions/modal_delete.php'); }
    public function cargoTypes(): void { $config=$this->config; $db=$this->db; require base_path('app/Http/Controllers/Company/LinearTripActions/cargo_types.php'); }
    public function departuresPlaceholder(): void { $config=$this->config; $db=$this->db; require base_path('app/Http/Controllers/Company/LinearTripActions/departures_placeholder.php'); }

    public function serviceLines(): void
    {
        requireRole(['company_owner','senior_logist','logist']);
        header('Content-Type: application/json; charset=utf-8');
        $companyId = (int)(getSessionCompanyId() ?? 0);
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', (string)($_GET['route_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []))));
        if ($companyId <= 0 || $ids === []) { echo json_encode(['success'=>true,'services_by_route'=>[]], JSON_UNESCAPED_UNICODE); return; }
        try {
            $central = $this->db->connection();
            $stmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
            $stmt->execute([$companyId]);
            $company = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$company) throw new \RuntimeException('Компания недоступна.');
            $pdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
            $user = ['user_id'=>(int)($_SESSION['user_id'] ?? 0),'role_code'=>(string)($_SESSION['role_code'] ?? '')];
            $allowed = [];
            foreach ($ids as $routeId) {
                $route = \App\Service\LinearRouteService::fetchRouteById($pdo, $routeId, $user['role_code']==='company_owner');
                if ($route && \App\Service\LinearRouteService::canViewRoute($route,$pdo,$user)) $allowed[]=$routeId;
            }
            $map = LinearTripServiceLineService::fetchByRouteIds($pdo,$allowed,$user['role_code']==='company_owner');
            echo json_encode(['success'=>true,'services_by_route'=>$map], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success'=>false,'message'=>'Не удалось загрузить услуги рейса.'], JSON_UNESCAPED_UNICODE);
        }
    }

    private function prepareAdditionalServicesForCreate(): void
    {
        $companyId=(int)(getSessionCompanyId() ?? 0);
        if ($companyId<=0) return;
        try {
            $central=$this->db->connection();
            $stmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
            $stmt->execute([$companyId]);
            $company=$stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$company) return;
            $pdo=(new Database(companyDatabaseConfig($this->config,$company)))->connection();
            $user=['user_id'=>(int)($_SESSION['user_id'] ?? 0),'role_code'=>(string)($_SESSION['role_code'] ?? '')];
            $parsed=LinearTripServiceLineService::normalizeSubmitted($pdo,(array)($_POST['trip_services'] ?? []),$user,$user['role_code']==='company_owner');
            if ($parsed['errors'] !== []) {
                $_SESSION['linear_trip_form_error']='Форма содержит ошибки. Проверьте дополнительные услуги.';
                $_SESSION['linear_trip_validation_errors']=$parsed['errors'];
                $_SESSION['linear_trip_old']=$_POST;
                header('Location: '.app_url('/company/trips/linear?show_create=1')); exit;
            }
            $services=$parsed['services'];
            if ($services===[]) return;
            register_shutdown_function(function() use ($companyId,$company,$user,$services): void {
                $success=(string)($_SESSION['linear_trip_form_success'] ?? '');
                if (!preg_match('/Рейс #(\d+)/u',$success,$m)) return;
                try {
                    $pdo=(new Database(companyDatabaseConfig($this->config,$company)))->connection();
                    $routeId=(int)$m[1];
                    LinearTripServiceLineService::replaceForRoute($pdo,$routeId,$services,$user,[
                        'planned_loading_date'=>\App\Service\LinearRouteService::normalizeDate($_POST['planned_loading_date'] ?? ''),
                        'planned_unloading_date'=>\App\Service\LinearRouteService::normalizeDate($_POST['planned_unloading_date'] ?? ''),
                        'actual_loading_date'=>\App\Service\LinearRouteService::normalizeDate($_POST['actual_loading_date'] ?? ''),
                        'actual_unloading_date'=>\App\Service\LinearRouteService::normalizeDate($_POST['actual_unloading_date'] ?? ''),
                    ],$user['role_code']==='company_owner');
                } catch (\Throwable $e) {
                    error_log('linear trip additional services create failed: '.$e->getMessage());
                    $_SESSION['linear_trip_form_error']='Рейс создан, но дополнительные услуги не удалось сохранить. Откройте рейс и повторите сохранение.';
                }
            });
        } catch (\Throwable $e) { error_log('linear trip services validation failed: '.$e->getMessage()); }
    }

    private function syncFinanceObligations(): void
    {
        $companyId=(int)(getSessionCompanyId() ?? 0); if($companyId<=0)return;
        try{$central=$this->db->connection();$stmt=$central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");$stmt->execute([$companyId]);$company=$stmt->fetch(\PDO::FETCH_ASSOC);if(!$company)return;$localPdo=(new Database(companyDatabaseConfig($this->config,$company)))->connection();applyLocalMigrations($localPdo);FinanceObligationService::syncAllLinearRoutes($localPdo);}catch(\Throwable){}
    }

    private function normalizeSubmittedExecutorCarrier(): void
    {
        $companyId=(int)(getSessionCompanyId() ?? 0);$sessionUser=['user_id'=>(int)($_SESSION['user_id'] ?? 0),'role_code'=>(string)($_SESSION['role_code'] ?? '')];
        $_POST=LinearTripRequestNormalizer::deriveCarrierFromExecutor($this->config,$this->db,$companyId,$sessionUser,$_POST);
    }
    private function normalizeOptionalCargo(): void { if(trim((string)($_POST['cargo_type_name'] ?? ''))==='')$_POST['cargo_type_name']='—'; }
    private function ensureLegacyPaymentDueCompatibility(): void
    {
        $companyId=(int)(getSessionCompanyId() ?? 0);if($companyId<=0)return;$centralPdo=$this->db->connection();$companyStmt=$centralPdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');$companyStmt->execute([$companyId]);$company=$companyStmt->fetch(\PDO::FETCH_ASSOC);if(!$company||($company['status']??'')!=='active')return;$localPdo=(new Database(companyDatabaseConfig($this->config,$company)))->connection();$columnStmt=$localPdo->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='payment_due_type' LIMIT 1");foreach(['linear_route_financial_terms','linear_route_payments'] as $table){$columnStmt->execute([$table]);if($columnStmt->fetchColumn()==='NO')$localPdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `payment_due_type` VARCHAR(100) NULL DEFAULT NULL");}
    }
}
