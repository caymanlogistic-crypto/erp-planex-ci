<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\LinearRouteService;
use App\Service\WasteTripLegacyImportService;
use App\Service\WasteTripService;
use PDO;
use RuntimeException;
use Throwable;

final class WasteTripController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {
    }

    public function index(): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $context = $this->tenantContext(false);
        $company = $context['company'];
        $localPdo = $context['localPdo'];
        $sessionUser = $context['sessionUser'];
        $pageTitle = 'Перевозка отходов';
        $pageContext = 'Рейсы › Перевозка отходов';
        $dbError = $context['error'];
        $trips = [];
        $clients = [];
        $routeExecutors = [];
        $responsibleUsers = [];
        $utilizers = [];
        $formError = $_SESSION['waste_trip_error'] ?? null;
        $formSuccess = $_SESSION['waste_trip_success'] ?? null;
        $old = $_SESSION['waste_trip_old'] ?? [];
        unset($_SESSION['waste_trip_error'], $_SESSION['waste_trip_success'], $_SESSION['waste_trip_old']);

        if ($company !== null && $localPdo instanceof PDO && $dbError === null) {
            if (!WasteTripService::schemaReady($localPdo)) {
                $dbError = 'Схема модуля перевозки отходов ещё не применена. Требуется canonical migration 128.';
            } else {
                try {
                    $trips = WasteTripService::fetchTrips($localPdo, [
                        'status' => $_GET['status'] ?? '',
                        'q' => $_GET['q'] ?? '',
                    ]);
                    $trips = array_values(array_filter(
                        $trips,
                        static fn(array $trip): bool => WasteTripService::canAccessTrip($localPdo, $trip, $sessionUser)
                    ));
                    [$clients, $routeExecutors, $responsibleUsers, $utilizers] = $this->loadOptions($localPdo, $sessionUser);
                    $pageContext .= ' › Компания: ' . (string) $company['name'];
                } catch (Throwable $e) {
                    error_log('waste trips index failed: ' . $e->getMessage());
                    $dbError = 'Не удалось загрузить реестр перевозки отходов.';
                }
            }
        }

        ob_start();
        require base_path('app/View/pages/company_waste_trips.php');
        $content = (string) ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function createSubmit(): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $context = $this->tenantContext(true);
        $sessionUser = $context['sessionUser'];

        try {
            $localPdo = $this->requireTenantPdo($context);
            $parsed = WasteTripService::parseRequestIds((string) ($_POST['request_ids'] ?? ''));
            $this->assertParsedRequests($parsed);
            $tripId = WasteTripService::create($localPdo, $_POST, $parsed['ids'], $sessionUser);
            $_SESSION['waste_trip_success'] = 'Рейс #' . $tripId . ' создан.';
            header('Location: ' . app_url('/company/trips/departures'));
        } catch (Throwable $e) {
            $_SESSION['waste_trip_error'] = $this->publicError($e, 'Не удалось создать рейс.');
            $_SESSION['waste_trip_old'] = $_POST;
            header('Location: ' . app_url('/company/trips/departures?show_create=1'));
        }
        exit;
    }

    public function previewSelection(): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        header('Content-Type: application/json; charset=utf-8');
        $context = $this->tenantContext(false);
        try {
            $localPdo = $this->requireTenantPdo($context);
            $this->assertSchemaReady($localPdo);
            $parsed = WasteTripService::parseRequestIds((string) ($_POST['request_ids'] ?? ''));
            $this->assertParsedRequests($parsed);
            $preview = WasteTripService::previewSelection($localPdo, $parsed['ids']);
            $preview['success'] = $preview['missing'] === [] && $preview['occupied'] === [] && $preview['found_count'] > 0;
            $preview['blocking'] = [
                'missing' => $preview['missing'],
                'occupied' => $preview['occupied'],
            ];
            echo json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => $this->publicError($e, 'Не удалось проверить заявки.'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }

    public function modalView(string $id): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $context = $this->tenantContext(false);
        $localPdo = $this->requireTenantPdo($context);
        $this->assertSchemaReady($localPdo);
        $trip = WasteTripService::fetchTrip($localPdo, (int) $id);
        if ($trip === null) {
            http_response_code(404);
            echo '<div class="notice warn">Рейс не найден.</div>';
            return;
        }
        if (!WasteTripService::canAccessTrip($localPdo, $trip, $context['sessionUser'])) {
            http_response_code(403);
            echo '<div class="notice warn">У вас нет доступа к этому рейсу.</div>';
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        require base_path('app/View/partials/company_waste_trip_modal_view.php');
    }

    public function modalEditForm(string $id): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $context = $this->tenantContext(false);
        $localPdo = $this->requireTenantPdo($context);
        $this->assertSchemaReady($localPdo);
        $trip = WasteTripService::fetchTrip($localPdo, (int) $id);
        if ($trip === null) {
            http_response_code(404);
            echo '<div class="notice warn">Рейс не найден.</div>';
            return;
        }
        if (!WasteTripService::canAccessTrip($localPdo, $trip, $context['sessionUser'])) {
            http_response_code(403);
            echo '<div class="notice warn">У вас нет доступа к этому рейсу.</div>';
            return;
        }
        [$clients, $routeExecutors, $responsibleUsers, $utilizers] = $this->loadOptions($localPdo, $context['sessionUser']);
        header('Content-Type: text/html; charset=utf-8');
        require base_path('app/View/partials/company_waste_trip_modal_edit.php');
    }

    public function modalEditSubmit(string $id): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $context = $this->tenantContext(true);
        try {
            $localPdo = $this->requireTenantPdo($context);
            $parsed = WasteTripService::parseRequestIds((string) ($_POST['request_ids'] ?? ''));
            $this->assertParsedRequests($parsed);
            WasteTripService::update(
                $localPdo,
                (int) $id,
                $_POST,
                $parsed['ids'],
                (int) ($_POST['lock_version'] ?? 0),
                $context['sessionUser']
            );
            $_SESSION['waste_trip_success'] = 'Рейс #' . (int) $id . ' сохранён.';
        } catch (Throwable $e) {
            $_SESSION['waste_trip_error'] = $this->publicError($e, 'Не удалось сохранить рейс.');
        }
        header('Location: ' . app_url('/company/trips/departures'));
        exit;
    }

    public function cancelSubmit(string $id): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $context = $this->tenantContext(true);
        try {
            $localPdo = $this->requireTenantPdo($context);
            WasteTripService::cancel(
                $localPdo,
                (int) $id,
                (int) ($_POST['lock_version'] ?? 0),
                (string) ($_POST['cancellation_reason'] ?? ''),
                $context['sessionUser']
            );
            $_SESSION['waste_trip_success'] = 'Рейс #' . (int) $id . ' отменён.';
        } catch (Throwable $e) {
            $_SESSION['waste_trip_error'] = $this->publicError($e, 'Не удалось отменить рейс.');
        }
        header('Location: ' . app_url('/company/trips/departures'));
        exit;
    }

    public function importIndex(): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $context = $this->tenantContext(false);
        $company = $context['company'];
        $localPdo = $this->requireTenantPdo($context);
        $this->assertSchemaReady($localPdo);
        $sessionUser = $context['sessionUser'];
        [$clients, $routeExecutors, $responsibleUsers, $utilizers] = $this->loadOptions($localPdo, $sessionUser);
        $pageTitle = 'Импорт старых рейсов';
        $pageContext = 'Рейсы › Перевозка отходов › Импорт из старой системы';
        $legacyCandidates = [];
        $legacyError = null;
        $showImported = (($_GET['show_imported'] ?? '') === '1');
        try {
            $legacyPdo = WasteTripLegacyImportService::connectionFromEnvironment();
            if ($legacyPdo === null) {
                $legacyError = 'Legacy DB не настроена в environment. Импорт недоступен, существующий модуль продолжает работать.';
            } else {
                $legacyCandidates = WasteTripLegacyImportService::listCandidates($legacyPdo, $localPdo, 300, $showImported);
            }
        } catch (Throwable $e) {
            error_log('waste legacy preview failed: ' . $e->getMessage());
            $legacyError = 'Не удалось прочитать legacy DB. Запись в старую систему не выполнялась.';
        }
        $formError = $_SESSION['waste_trip_error'] ?? null;
        $formSuccess = $_SESSION['waste_trip_success'] ?? null;
        unset($_SESSION['waste_trip_error'], $_SESSION['waste_trip_success']);

        ob_start();
        require base_path('app/View/pages/company_waste_trip_import.php');
        $content = (string) ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function importSubmit(string $legacyId): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $context = $this->tenantContext(true);
        try {
            $localPdo = $this->requireTenantPdo($context);
            $legacyPdo = WasteTripLegacyImportService::connectionFromEnvironment();
            if ($legacyPdo === null) {
                throw new RuntimeException('Legacy DB не настроена.');
            }
            $tripId = WasteTripLegacyImportService::import(
                $legacyPdo,
                $localPdo,
                (int) $legacyId,
                $_POST,
                $context['sessionUser']
            );
            $_SESSION['waste_trip_success'] = 'Legacy-рейс #' . (int) $legacyId . ' импортирован как ERP рейс #' . $tripId . '.';
        } catch (Throwable $e) {
            $_SESSION['waste_trip_error'] = 'Legacy #' . (int) $legacyId . ': ' . $this->publicError($e, 'Импорт не выполнен.');
        }
        header('Location: ' . app_url('/company/trips/departures/import'));
        exit;
    }

    private function tenantContext(bool $applyMigrations): array
    {
        $companyId = (int) (getSessionCompanyId() ?? 0);
        $sessionUser = [
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
            'role_code' => (string) ($_SESSION['role_code'] ?? ''),
        ];
        if ($companyId <= 0) {
            return ['company' => null, 'localPdo' => null, 'sessionUser' => $sessionUser, 'error' => 'Компания не выбрана.'];
        }
        try {
            $centralPdo = $this->db->connection();
            $stmt = $centralPdo->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
            $stmt->execute([$companyId]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($company === null) {
                return ['company' => null, 'localPdo' => null, 'sessionUser' => $sessionUser, 'error' => 'Компания недоступна.'];
            }
            $localPdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
            if ($applyMigrations) {
                applyLocalMigrations($localPdo);
            }
            return ['company' => $company, 'localPdo' => $localPdo, 'sessionUser' => $sessionUser, 'error' => null];
        } catch (Throwable $e) {
            error_log('waste tenant context failed: ' . $e->getMessage());
            return ['company' => null, 'localPdo' => null, 'sessionUser' => $sessionUser, 'error' => 'Не удалось подключиться к базе данных компании.'];
        }
    }

    private function requireTenantPdo(array $context): PDO
    {
        if (!($context['localPdo'] ?? null) instanceof PDO) {
            throw new RuntimeException((string) ($context['error'] ?? 'База данных компании недоступна.'));
        }
        return $context['localPdo'];
    }

    private function assertSchemaReady(PDO $pdo): void
    {
        if (!WasteTripService::schemaReady($pdo)) {
            throw new RuntimeException('Схема модуля перевозки отходов не применена.');
        }
    }

    private function assertParsedRequests(array $parsed): void
    {
        if (($parsed['invalid'] ?? []) !== []) {
            throw new RuntimeException('Некорректные номера заявок: ' . implode(', ', $parsed['invalid']) . '.');
        }
        if (($parsed['duplicates'] ?? []) !== []) {
            throw new RuntimeException('В списке повторяются заявки: ' . implode(', ', $parsed['duplicates']) . '.');
        }
    }

    private function publicError(Throwable $e, string $fallback): string
    {
        if ($e instanceof \PDOException) {
            error_log('waste trip database error: ' . $e->getMessage());
            return $fallback;
        }
        if ($e instanceof RuntimeException) {
            return $e->getMessage();
        }
        error_log('waste trip unexpected error: ' . $e->getMessage());
        return $fallback;
    }

    private function loadOptions(PDO $localPdo, array $sessionUser): array
    {
        $clients = LinearRouteService::fetchVisibleClients($localPdo, $sessionUser);
        $routeExecutors = LinearRouteService::fetchVisibleRouteExecutors($localPdo, $sessionUser);
        if ($routeExecutors !== []) {
            $crewIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $routeExecutors)));
            if ($crewIds !== []) {
                $placeholders = implode(',', array_fill(0, count($crewIds), '?'));
                $stmt = $localPdo->prepare(
                    "SELECT cd.crew_id, GROUP_CONCAT(d.full_name ORDER BY cd.position SEPARATOR ' / ') AS driver_names
                       FROM crew_drivers cd JOIN drivers d ON d.id=cd.driver_id
                      WHERE cd.crew_id IN ({$placeholders}) GROUP BY cd.crew_id"
                );
                $stmt->execute($crewIds);
                $map = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $map[(int) $row['crew_id']] = trim((string) $row['driver_names']);
                }
                foreach ($routeExecutors as &$executor) {
                    $crewId = (int) ($executor['id'] ?? 0);
                    if (($map[$crewId] ?? '') !== '') {
                        $executor['driver_name'] = $map[$crewId];
                    }
                }
                unset($executor);
            }
        }
        return [
            $clients,
            $routeExecutors,
            WasteTripService::fetchActiveUsers($localPdo),
            WasteTripService::fetchActiveUtilizers($localPdo),
        ];
    }
}
