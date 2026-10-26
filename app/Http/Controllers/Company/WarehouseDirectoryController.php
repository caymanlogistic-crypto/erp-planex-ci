<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\WarehouseDirectoryService;
use App\Service\WarehouseStockService;
use App\Service\WarehouseTripCorrectionService;
use App\Service\WasteTripLegacySourceService;
use PDO;
use RuntimeException;
use Throwable;

final class WarehouseDirectoryController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $pageTitle = 'Адреса складов';
        $pageContext = 'Склады › Адреса складов';
        $warehouses = [];
        $dbError = null;
        $isOwner = (string)($_SESSION['role_code'] ?? '') === 'company_owner';
        $successFlash = $_SESSION['warehouse_directory_success'] ?? null;
        $errorFlash = $_SESSION['warehouse_directory_error'] ?? null;
        unset($_SESSION['warehouse_directory_success'], $_SESSION['warehouse_directory_error']);
        try {
            [$tenant, $legacy] = $this->connections();
            $warehouses = WarehouseDirectoryService::list($legacy);
            $stock = WarehouseStockService::load($legacy, $tenant)['current'];
            foreach ($warehouses as &$warehouse) {
                $current = $stock[(int)$warehouse['id']] ?? [];
                $warehouse['request_count'] = (int)($current['request_count'] ?? 0);
                $warehouse['weight_kg'] = (int)($current['weight_kg'] ?? 0);
            }
            unset($warehouse);
        } catch (Throwable $error) {
            error_log('warehouse directory page failed: '.get_class($error));
            $dbError = 'Не удалось загрузить адреса и контакты складов. Данные не показаны.';
        }
        ob_start();
        require base_path('app/View/pages/company_warehouse_directory.php');
        $content = (string)ob_get_clean();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }

    public function save(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            [$tenant, $legacy] = $this->connections();
            [$writer, $database] = WarehouseTripCorrectionService::writeConnection($tenant);
            $names = $_POST['contact_name'] ?? [];
            $positions = $_POST['contact_position'] ?? [];
            $phones = $_POST['contact_phone'] ?? [];
            $emails = $_POST['contact_email'] ?? [];
            if (!is_array($names) || !is_array($positions) || !is_array($phones) || !is_array($emails)
                || count($names) !== count($positions) || count($names) !== count($phones) || count($names) !== count($emails)) {
                throw new RuntimeException('Некорректный список контактов.');
            }
            $contacts = [];
            foreach ($names as $index => $name) {
                $contacts[] = ['name'=>$name,'position'=>$positions[$index] ?? '',
                    'phone'=>$phones[$index] ?? '', 'email'=>$emails[$index] ?? ''];
            }
            WarehouseDirectoryService::save($legacy, $writer, $database,
                (int)($_POST['warehouse_id'] ?? 0), (string)($_POST['expected_address'] ?? ''),
                (string)($_POST['expected_contacts_hash'] ?? ''), (string)($_POST['expected_coordinates_hash'] ?? ''),
                (string)($_POST['address'] ?? ''), (string)($_POST['latitude'] ?? ''),
                (string)($_POST['longitude'] ?? ''), $contacts, (int)($_SESSION['user_id'] ?? 0));
            $_SESSION['warehouse_directory_success'] = 'Адрес, координаты и контакты склада сохранены.';
        } catch (Throwable $error) {
            error_log('warehouse directory save failed: '.get_class($error));
            $_SESSION['warehouse_directory_error'] = $error instanceof RuntimeException && !$error instanceof \PDOException
                ? $error->getMessage() : 'Не удалось сохранить склад. Данные не изменены.';
        }
        redirect_to('/company/warehouses/addresses');
    }

    private function connections(): array
    {
        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) throw new RuntimeException('Компания не выбрана.');
        $stmt = $this->db->connection()->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) throw new RuntimeException('Компания недоступна.');
        $tenant = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
        $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
        if (!$legacy instanceof PDO) throw new RuntimeException('Складская база недоступна.');
        return [$tenant, $legacy];
    }
}
