<?php
declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\CargoBalanceStatisticsService;
use PDO;
use Throwable;

final class CargoBalanceStatisticsController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void
    {
        $pageTitle = 'Забор и сдача груза';
        $pageContext = 'Статистика › Забор и сдача груза';
        $statistics = null;
        $dbError = null;
        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) {
            $dbError = 'Компания не выбрана.';
        } else {
            try {
                $central = $this->db->connection();
                $query = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
                $query->execute([$companyId]);
                $company = $query->fetch(PDO::FETCH_ASSOC);
                if (!$company) {
                    $dbError = 'Компания недоступна.';
                } else {
                    $local = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
                    if (!CargoBalanceStatisticsService::schemaReady($local)) {
                        $dbError = 'Раздел ожидает обновления схемы заявок и рейсов.';
                    } else {
                        $statistics = CargoBalanceStatisticsService::dashboard($local);
                        $pageContext .= ' › Компания: '.(string)$company['name'];
                    }
                }
            } catch (Throwable $error) {
                error_log('cargo balance statistics failed: '.$error->getMessage());
                $dbError = 'Не удалось загрузить баланс заявок.';
            }
        }
        ob_start();
        require base_path('app/View/pages/company_cargo_balance_statistics.php');
        $content = (string)ob_get_clean();
        $pageStylesheets = ['/assets/css/manager-statistics.css', '/assets/css/cargo-balance-statistics.css'];
        $pageScripts = ['/assets/js/cargo-balance-statistics.js'];
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }
}
