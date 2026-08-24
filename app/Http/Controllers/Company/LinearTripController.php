<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FinanceObligationService;
use App\Service\LinearRouteService;
use App\Service\LinearTripRequestNormalizer;
use App\Service\LinearTripServiceFinanceService;
use App\Service\LinearTripServiceLineService;
use PDO;
use Throwable;

final class LinearTripController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {
    }

    public function index(): void
    {
        $this->syncFinanceObligations();
        $config = $this->config;
        $db = $this->db;

        ob_start();
        require base_path('app/Http/Controllers/Company/LinearTripActions/index.php');
        $html = (string) ob_get_clean();

        ob_start();
        require base_path('app/View/partials/company_linear_trip_services_bootstrap.php');
        require base_path('app/View/partials/company_linear_trip_services_identity.php');
        $serviceUi = (string) ob_get_clean();

        echo str_contains($html, '</body>')
            ? str_replace('</body>', $serviceUi . '</body>', $html)
            : $html . $serviceUi;
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

    public function modalView(string $id): void
    {
        $this->syncFinanceObligations();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/LinearTripActions/modal_view.php');
    }

    public function modalEditForm(string $id): void
    {
        $this->syncFinanceObligations();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/LinearTripActions/modal_edit_form.php');
    }

    public function modalEditSubmit(string $id): void
    {
        $this->normalizeSubmittedExecutorCarrier();
        $this->normalizeOptionalCargo();
        $this->ensureLegacyPaymentDueCompatibility();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/LinearTripActions/modal_edit_submit.php');
    }

    public function modalDelete(string $id): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/LinearTripActions/modal_delete.php');
    }

    public function cargoTypes(): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/LinearTripActions/cargo_types.php');
    }

    public function departuresPlaceholder(): void
    {
        $config = $this->config;
        $db = $this->db;
        require base_path('app/Http/Controllers/Company/LinearTripActions/departures_placeholder.php');
    }

    public function serviceLines(): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        header('Content-Type: application/json; charset=utf-8');

        $companyId = (int) (getSessionCompanyId() ?? 0);
        $routeIds = array_values(array_unique(array_filter(array_map(
            'intval',
            preg_split('/\s*,\s*/', (string) ($_GET['route_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []
        ))));
        if ($companyId <= 0 || $routeIds === []) {
            echo json_encode(['success' => true, 'services_by_route' => []], JSON_UNESCAPED_UNICODE);
            return;
        }

        try {
            $company = $this->activeCompany($companyId);
            if ($company === null) {
                throw new \RuntimeException('Компания недоступна.');
            }
            $localPdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
            $sessionUser = [
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'role_code' => (string) ($_SESSION['role_code'] ?? ''),
            ];

            $allowedRouteIds = [];
            foreach ($routeIds as $routeId) {
                $route = LinearRouteService::fetchRouteById(
                    $localPdo,
                    $routeId,
                    $sessionUser['role_code'] === 'company_owner'
                );
                if ($route !== null && LinearRouteService::canViewRoute($route, $localPdo, $sessionUser)) {
                    $allowedRouteIds[] = $routeId;
                }
            }

            $services = LinearTripServiceLineService::fetchByRouteIds(
                $localPdo,
                $allowedRouteIds,
                $sessionUser['role_code'] === 'company_owner'
            );
            echo json_encode(
                ['success' => true, 'services_by_route' => $services],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (Throwable) {
            http_response_code(500);
            echo json_encode(
                ['success' => false, 'message' => 'Не удалось загрузить услуги рейса.'],
                JSON_UNESCAPED_UNICODE
            );
        }
    }

    private function prepareAdditionalServicesForCreate(): void
    {
        $companyId = (int) (getSessionCompanyId() ?? 0);
        if ($companyId <= 0) {
            return;
        }

        try {
            $company = $this->activeCompany($companyId);
            if ($company === null) {
                return;
            }
            $localPdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
            $sessionUser = [
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'role_code' => (string) ($_SESSION['role_code'] ?? ''),
            ];
            $includeFinance = $sessionUser['role_code'] === 'company_owner';
            $parsed = LinearTripServiceLineService::normalizeSubmitted(
                $localPdo,
                (array) ($_POST['trip_services'] ?? []),
                $sessionUser,
                $includeFinance
            );

            if ($parsed['errors'] !== []) {
                $_SESSION['linear_trip_form_error'] = 'Форма содержит ошибки. Проверьте дополнительные услуги.';
                $_SESSION['linear_trip_validation_errors'] = $parsed['errors'];
                $_SESSION['linear_trip_old'] = $_POST;
                header('Location: ' . app_url('/company/trips/linear?show_create=1'));
                exit;
            }

            $services = $parsed['services'];
            if ($services === []) {
                return;
            }

            $dates = [
                'planned_loading_date' => LinearRouteService::normalizeDate($_POST['planned_loading_date'] ?? ''),
                'planned_unloading_date' => LinearRouteService::normalizeDate($_POST['planned_unloading_date'] ?? ''),
                'actual_loading_date' => LinearRouteService::normalizeDate($_POST['actual_loading_date'] ?? ''),
                'actual_unloading_date' => LinearRouteService::normalizeDate($_POST['actual_unloading_date'] ?? ''),
            ];

            register_shutdown_function(function () use (
                $company,
                $sessionUser,
                $services,
                $dates,
                $includeFinance
            ): void {
                $successMessage = (string) ($_SESSION['linear_trip_form_success'] ?? '');
                if (!preg_match('/Рейс #(\d+)/u', $successMessage, $matches)) {
                    return;
                }

                try {
                    $localPdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
                    LinearTripServiceLineService::replaceForRoute(
                        $localPdo,
                        (int) $matches[1],
                        $services,
                        $sessionUser,
                        $dates,
                        $includeFinance
                    );
                } catch (Throwable $e) {
                    error_log('linear trip additional services create failed: ' . $e->getMessage());
                    $_SESSION['linear_trip_form_error'] = 'Рейс создан, но дополнительные услуги не удалось сохранить. Откройте рейс и повторите сохранение.';
                }
            });
        } catch (Throwable $e) {
            error_log('linear trip services validation failed: ' . $e->getMessage());
        }
    }

    private function syncFinanceObligations(): void
    {
        $companyId = (int) (getSessionCompanyId() ?? 0);
        if ($companyId <= 0) {
            return;
        }

        try {
            $company = $this->activeCompany($companyId);
            if ($company === null) {
                return;
            }
            $localPdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
            applyLocalMigrations($localPdo);
            FinanceObligationService::syncAllLinearRoutes($localPdo);

            if (LinearTripServiceLineService::schemaReady($localPdo)) {
                $routeIds = $localPdo->query(
                    'SELECT DISTINCT linear_route_id FROM linear_route_services WHERE deleted_at IS NULL ORDER BY linear_route_id'
                )->fetchAll(PDO::FETCH_COLUMN);
                foreach ($routeIds as $routeId) {
                    LinearTripServiceFinanceService::syncRoute($localPdo, (int) $routeId);
                }
            }
        } catch (Throwable) {
            // The trip page remains available if finance infrastructure is temporarily unavailable.
        }
    }

    private function activeCompany(int $companyId): ?array
    {
        $centralPdo = $this->db->connection();
        $stmt = $centralPdo->prepare('SELECT * FROM companies WHERE id=? AND status=\'active\' LIMIT 1');
        $stmt->execute([$companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function normalizeSubmittedExecutorCarrier(): void
    {
        $companyId = (int) (getSessionCompanyId() ?? 0);
        $sessionUser = [
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'role_code' => (string) ($_SESSION['role_code'] ?? ''),
        ];

        $_POST = LinearTripRequestNormalizer::deriveCarrierFromExecutor(
            $this->config,
            $this->db,
            $companyId,
            $sessionUser,
            $_POST
        );
    }

    private function normalizeOptionalCargo(): void
    {
        if (trim((string) ($_POST['cargo_type_name'] ?? '')) === '') {
            $_POST['cargo_type_name'] = '—';
        }
    }

    private function ensureLegacyPaymentDueCompatibility(): void
    {
        $companyId = (int) (getSessionCompanyId() ?? 0);
        if ($companyId <= 0) {
            return;
        }

        $company = $this->activeCompany($companyId);
        if ($company === null) {
            return;
        }

        $localPdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
        $columnStmt = $localPdo->prepare(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='payment_due_type' LIMIT 1"
        );
        foreach (['linear_route_financial_terms', 'linear_route_payments'] as $table) {
            $columnStmt->execute([$table]);
            if ($columnStmt->fetchColumn() === 'NO') {
                $localPdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `payment_due_type` VARCHAR(100) NULL DEFAULT NULL");
            }
        }
    }
}
