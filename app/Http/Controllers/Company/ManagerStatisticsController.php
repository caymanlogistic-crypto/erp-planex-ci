<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Service\WasteTripStartStatisticsService;
use PDO;
use Throwable;

final class ManagerStatisticsController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {}

    public function index(): void
    {
        $this->render(false);
    }

    public function personal(): void
    {
        $this->render(true);
    }

    private function render(bool $personalMode): void
    {
        // When the roles/access registry is active, the route's page grant is
        // authoritative. Keep the legacy role gate only for installations
        // where that registry has not been enabled.
        if (!AccessRuntime::current()->isActive()) {
            if ($personalMode) {
                http_response_code(403);
                return;
            }
            requireRole(['company_owner','senior_logist','logist']);
        }

        $pageTitle = $personalMode ? 'Статистика работы' : 'Работа логистов';
        $pageContext = $personalMode ? 'Логисты › Статистика работы' : 'Статистика › Работа логистов';
        $company = null;
        $dbError = null;
        $eligibleManagers = [];
        $filters = $personalMode
            ? WasteTripStartStatisticsService::normalizePersonalFilters($_GET)
            : WasteTripStartStatisticsService::normalizeFilters($_GET);
        $statistics = [
            'totals'=>['trips'=>0,'requests'=>0,'weight'=>0.0,'active_managers'=>0,'completed'=>0,'cancelled'=>0],
            'previous_totals'=>['trips'=>0,'requests'=>0,'weight'=>0.0,'active_managers'=>0,'completed'=>0,'cancelled'=>0],
            'managers'=>[], 'trend'=>[], 'previous_trend'=>[], 'manager_trends'=>[],
            'period_rows'=>[], 'details'=>[],
        ];

        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) {
            $dbError = 'Компания не выбрана.';
        } else {
            try {
                $central = $this->db->connection();
                $stmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
                $stmt->execute([$companyId]);
                $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($company === null) {
                    $dbError = 'Компания недоступна.';
                } else {
                    $local = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
                    if (!WasteTripStartStatisticsService::schemaReady($local)) {
                        $dbError = 'Раздел ожидает обновление схемы статистики (141).';
                    } else {
                        $eligibleManagers = WasteTripStartStatisticsService::eligibleLogists($central, $companyId);
                        $statistics = $personalMode
                            ? WasteTripStartStatisticsService::personalDashboard(
                                $local, $filters, $eligibleManagers, (int)(AccessRuntime::current()->membershipId() ?? 0)
                            )
                            : WasteTripStartStatisticsService::dashboard($local, $filters, $eligibleManagers);
                        $pageContext .= ' › Компания: '.(string)$company['name'];
                    }
                }
            } catch (Throwable $error) {
                error_log('manager statistics failed: '.$error->getMessage());
                $dbError = 'Не удалось загрузить статистику компании.';
            }
        }

        ob_start();
        require base_path('app/View/pages/company_manager_statistics.php');
        $content = (string)ob_get_clean();
        $pageStylesheets = ['/assets/css/manager-statistics.css'];
        $pageScripts = ['/assets/js/manager-statistics.js'];
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }
}
