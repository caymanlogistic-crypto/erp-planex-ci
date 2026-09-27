<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\WarehouseStockService;
use App\Service\WasteTripLegacySourceService;
use PDO;
use Throwable;

final class WarehouseStockController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $pageTitle = 'Заявки на складе';
        $pageContext = 'Склады › Заявки на складе';
        $data = ['warehouses'=>[], 'groups'=>[], 'current'=>[], 'issues'=>[], 'issue_count'=>0];
        $dbError = null;
        try {
            $companyId = (int)(getSessionCompanyId() ?? 0);
            if ($companyId <= 0) throw new \RuntimeException('Компания не выбрана.');
            $stmt = $this->db->connection()->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
            $stmt->execute([$companyId]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$company) throw new \RuntimeException('Компания недоступна.');
            $tenant = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
            $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacy instanceof PDO) throw new \RuntimeException('Подключение к старой базе складов не настроено.');
            $data = WarehouseStockService::load($legacy, $tenant);
        } catch (Throwable $error) {
            error_log('warehouse stock page failed: '.$error->getMessage());
            $dbError = 'Не удалось загрузить складские движения и сверку с ERP. Данные и остатки не показаны.';
        }
        ob_start();
        require base_path('app/View/pages/company_warehouse_stock.php');
        $content = (string)ob_get_clean();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }
}
