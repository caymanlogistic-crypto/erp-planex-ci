<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\WarehouseStockService;
use App\Service\WarehouseTripCorrectionService;
use App\Service\WarehouseTripRequestReconciliationService;
use App\Service\WasteTripLegacySourceService;
use App\Service\WasteTripService;
use PDO;
use PDOException;
use Throwable;

final class WarehouseStockController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void
    {
        requireRole(['company_owner', 'senior_logist', 'logist']);
        $pageTitle = 'Заявки на складе';
        $pageContext = 'Склады › Заявки на складе';
        $data = ['warehouses'=>[], 'groups'=>[], 'current'=>[], 'issues'=>[], 'issue_count'=>0, 'correction_flights'=>[]];
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

    public function correctionPreview(): void
    {
        requireRole(['company_owner']);
        try {
            $tenant = $this->tenant();
            [, $writeDatabase] = WarehouseTripCorrectionService::writeConnection($tenant);
            $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacy instanceof PDO) throw new \RuntimeException('Старая база складов недоступна.');
            if ((string)$legacy->query('SELECT DATABASE()')->fetchColumn() !== $writeDatabase) throw new \RuntimeException('База записи не совпадает с базой складского журнала.');
            $result = WarehouseTripCorrectionService::preview(
                $legacy, (string)$legacy->query('SELECT DATABASE()')->fetchColumn(),
                (int)($_GET['flight_id'] ?? 0), (string)($_GET['route_type'] ?? ''),
                (int)($_GET['source_warehouse_id'] ?? 0), (int)($_GET['destination_warehouse_id'] ?? 0)
            );
            $this->json($result);
        } catch (Throwable $error) {
            error_log('warehouse correction preview failed: '.get_class($error));
            $this->json(['error'=>$this->publicError($error)], 422);
        }
    }

    public function correctionSubmit(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            $tenant = $this->tenant();
            [$writer, $database] = WarehouseTripCorrectionService::writeConnection($tenant);
            $result = WarehouseTripCorrectionService::apply(
                $writer, $database, (int)($_POST['flight_id'] ?? 0), (string)($_POST['route_type'] ?? ''),
                (int)($_POST['source_warehouse_id'] ?? 0), (int)($_POST['destination_warehouse_id'] ?? 0),
                (string)($_POST['fingerprint'] ?? ''), (int)($_SESSION['user_id'] ?? 0)
            );
            try {
                $flightId = (int)$result['flight_id'];
                if ($flightId >= WasteTripLegacySourceService::MIN_SYNCHRONIZABLE_ID) {
                    $query = $tenant->prepare("SELECT id FROM waste_trips WHERE business_id=? AND source_system IN ('legacy_flights','legacy_sync','legacy_prefill') AND deleted_at IS NULL LIMIT 1");
                    $query->execute([$flightId]);
                    $tripId = (int)$query->fetchColumn();
                    if ($tripId > 0) {
                        $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
                        if (!$legacy instanceof PDO) throw new \RuntimeException('Не удалось обновить зеркало рейса в ERP.');
                        $flight = WasteTripLegacySourceService::fetchFlight($legacy, $flightId);
                        if ($flight === null) throw new \RuntimeException('Исправленный рейс не найден при обновлении ERP.');
                        WasteTripService::syncLegacyOwnedState($tenant, $tripId, WasteTripLegacySourceService::authoritativeState($flight));
                    }
                }
            } catch (Throwable $syncError) {
                error_log('warehouse correction ERP mirror failed flight='.(int)$result['flight_id'].': '.get_class($syncError));
                $result['warning'] = 'Складские движения сохранены, но зеркало рейса в ERP пока не обновилось. Повторите синхронизацию рейсов.';
            }
            $this->json($result);
        } catch (Throwable $error) {
            error_log('warehouse correction apply failed: '.get_class($error));
            $this->json(['error'=>$this->publicError($error)], 422);
        }
    }

    public function cancellationPreview(): void
    {
        requireRole(['company_owner']);
        try {
            $tenant = $this->tenant();
            [, $database] = WarehouseTripCorrectionService::writeConnection($tenant);
            $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacy instanceof PDO || (string)$legacy->query('SELECT DATABASE()')->fetchColumn() !== $database) {
                throw new \RuntimeException('База записи не совпадает с базой складского журнала.');
            }
            $this->json(WarehouseTripCorrectionService::cancellationPreview($legacy, $database, (int)($_GET['flight_id'] ?? 0)));
        } catch (Throwable $error) {
            error_log('warehouse cancellation preview failed: '.get_class($error));
            $this->json(['error'=>$this->publicError($error)], 422);
        }
    }

    public function cancellationSubmit(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            $tenant = $this->tenant();
            [$writer, $database] = WarehouseTripCorrectionService::writeConnection($tenant);
            $this->json(WarehouseTripCorrectionService::cancel($writer, $database, (int)($_POST['flight_id'] ?? 0),
                (string)($_POST['fingerprint'] ?? ''), (int)($_SESSION['user_id'] ?? 0)));
        } catch (Throwable $error) {
            error_log('warehouse cancellation failed: '.get_class($error));
            $this->json(['error'=>$this->publicError($error)], 422);
        }
    }

    public function requestReconciliationPreview(): void
    {
        requireRole(['company_owner']);
        try {
            $tenant = $this->tenant();
            [, $database] = WarehouseTripCorrectionService::writeConnection($tenant);
            $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacy instanceof PDO || (string)$legacy->query('SELECT DATABASE()')->fetchColumn() !== $database) {
                throw new \RuntimeException('База записи не совпадает с базой складского журнала.');
            }
            $result = WarehouseTripRequestReconciliationService::preview($legacy, $tenant, $database,
                (int)($_GET['flight_id'] ?? 0), (int)($_GET['warehouse_id'] ?? 0), (string)($_GET['direction'] ?? ''));
            unset($result['expected'], $result['pairs'], $result['all_movements']);
            $this->json($result);
        } catch (Throwable $error) {
            error_log('warehouse request reconciliation preview failed: '.get_class($error));
            $this->json(['error'=>$this->publicError($error)], 422);
        }
    }

    public function requestReconciliationSubmit(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            $tenant = $this->tenant();
            [$writer, $database] = WarehouseTripCorrectionService::writeConnection($tenant);
            $result = WarehouseTripRequestReconciliationService::apply($writer, $tenant, $database,
                (int)($_POST['flight_id'] ?? 0), (int)($_POST['warehouse_id'] ?? 0),
                (string)($_POST['direction'] ?? ''), (string)($_POST['fingerprint'] ?? ''),
                (int)($_SESSION['user_id'] ?? 0));
            $this->json($result);
        } catch (Throwable $error) {
            error_log('warehouse request reconciliation failed: '.get_class($error));
            $this->json(['error'=>$this->publicError($error)], 422);
        }
    }

    public function excludeIssue(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            $flightId = (int)($_POST['flight_id'] ?? 0);
            $hash = (string)($_POST['message_hash'] ?? '');
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($flightId <= 0 || $userId <= 0 || !preg_match('/^[0-9a-f]{64}$/D', $hash)) {
                throw new \RuntimeException('Некорректные данные исключения.');
            }
            $tenant = $this->tenant();
            $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacy instanceof PDO) throw new \RuntimeException('Складской журнал недоступен.');
            $data = WarehouseStockService::load($legacy, $tenant);
            $issue = $data['issues'][$flightId] ?? null;
            $message = null;
            foreach ($issue['messages'] ?? [] as $candidate) {
                if (hash_equals(WarehouseStockService::messageHash($candidate), $hash)) $message = $candidate;
            }
            if ($message === null) throw new \RuntimeException('Расхождение изменилось. Обновите страницу и проверьте его заново.');
            $warehouseName = (string)($data['warehouses'][$issue['warehouse_id']] ?? '');
            $stmt = $tenant->prepare('INSERT INTO warehouse_stock_issue_exceptions
                (flight_id,message_hash,message_text,trip_name,warehouse_name,approved_by_user_id,approved_at)
                VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP(6))
                ON DUPLICATE KEY UPDATE message_text=VALUES(message_text),trip_name=VALUES(trip_name),
                    warehouse_name=VALUES(warehouse_name),approved_by_user_id=VALUES(approved_by_user_id),
                    approved_at=CURRENT_TIMESTAMP(6),revoked_by_user_id=NULL,revoked_at=NULL');
            $stmt->execute([$flightId,$hash,$message,(string)$issue['name'],$warehouseName,$userId]);
            $this->json(['ok'=>true]);
        } catch (Throwable $error) {
            error_log('warehouse issue exclusion failed: '.get_class($error));
            $this->json(['error'=>$this->publicError($error)], 422);
        }
    }

    public function restoreIssue(): void
    {
        requireRole(['company_owner']);
        verifyCsrfRequest();
        try {
            $id = (int)($_POST['exception_id'] ?? 0);
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($id <= 0 || $userId <= 0) throw new \RuntimeException('Некорректное исключение.');
            $stmt = $this->tenant()->prepare('UPDATE warehouse_stock_issue_exceptions
                SET revoked_by_user_id=?,revoked_at=CURRENT_TIMESTAMP(6) WHERE id=? AND revoked_at IS NULL');
            $stmt->execute([$userId,$id]);
            if ($stmt->rowCount() !== 1) throw new \RuntimeException('Исключение уже возвращено в ошибки. Обновите страницу.');
            $this->json(['ok'=>true]);
        } catch (Throwable $error) {
            error_log('warehouse issue restore failed: '.get_class($error));
            $this->json(['error'=>$this->publicError($error)], 422);
        }
    }

    private function tenant(): PDO
    {
        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) throw new \RuntimeException('Компания не выбрана.');
        $stmt = $this->db->connection()->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) throw new \RuntimeException('Компания недоступна.');
        return (new Database(companyDatabaseConfig($this->config, $company)))->connection();
    }

    private function publicError(Throwable $error): string
    {
        return $error instanceof \RuntimeException && !$error instanceof PDOException
            ? $error->getMessage() : 'Результат операции не подтверждён. Обновите страницу и проверьте движения рейса перед повтором.';
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
