<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
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
        requireRole(['company_owner','senior_logist','logist']);

        $pageTitle = 'Работа логистов';
        $pageContext = 'Статистика › Работа логистов';
        $company = null;
        $dbError = null;
        $eligibleManagers = [];
        $filters = WasteTripStartStatisticsService::normalizeFilters($_GET);
        $statistics = [
            'totals'=>['trips'=>0,'requests'=>0,'weight'=>0.0,'active_managers'=>0,'completed'=>0,'cancelled'=>0],
            'previous_totals'=>['trips'=>0,'requests'=>0,'weight'=>0.0,'active_managers'=>0,'completed'=>0,'cancelled'=>0],
            'managers'=>[], 'trend'=>[], 'previous_trend'=>[], 'manager_trends'=>[],
            'period_rows'=>[], 'details'=>[], 'available_years'=>[(int)date('Y')], 'scope'=>'none',
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
                        $statistics = WasteTripStartStatisticsService::dashboard($local, $filters, $eligibleManagers);
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
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }
}
