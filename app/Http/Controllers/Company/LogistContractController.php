<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\AccessControlService;
use App\Service\DocumentGeneratorService;
use App\Service\LogistContractService;
use App\Service\UserSyncService;
use App\Service\WasteTripLegacySourceService;
use App\Service\WasteTripSynchronizationService;
use PDO;
use RuntimeException;
use Throwable;

final class LogistContractController
{
    public function __construct(private readonly array $config, private readonly Database $db) {}

    public function index(): void { $this->page(false); }
    public function ownerIndex(): void { $this->page(true); }

    private function page(bool $owner): void
    {
        $this->allow($owner);
        $pageTitle = 'Договоры логистов';
        $pageContext = $owner ? 'Генератор договоров' : 'Логисты';
        $rows = [];
        $formError = null;
        try {
            [, $pdo, , $userId] = $this->context();
            $this->requireSchema($pdo);
            $rows = LogistContractService::list($pdo, $userId, $owner);
        } catch (Throwable $error) {
            $formError = $error->getMessage();
        }
        $csrfToken = csrfToken();
        $pageStylesheets = ['/assets/css/logist-contracts.css'];
        $pageScripts = ['/assets/js/registry-components.js','/assets/js/logist-contracts.js'];
        ob_start();
        require base_path('app/View/pages/company_logist_contracts.php');
        $content = ob_get_clean();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }

    public function prepare(): void
    {
        try {
            $this->allow(false);
            verifyCsrfRequest();
            [$company, $pdo, $companyId, $userId, $role, $central] = $this->context();
            $this->requireSchema($pdo);
            $legacy = WasteTripLegacySourceService::connectionFromEnvironment();
            if (!$legacy) throw new RuntimeException('Источник рейсов недоступен. Договор не формируется до синхронизации.');
            $result = WasteTripSynchronizationService::runForContractPreparation(
                $legacy, $pdo, $central, $companyId, ['user_id'=>$userId,'role_code'=>$role]
            );
            if ((int)$result['failed'] > 0) throw new RuntimeException('Не все рейсы синхронизированы. Проверьте журнал синхронизации рейсов.');
            DocumentGeneratorService::seedDefaultTemplates($pdo, $companyId, 0, 'system');
            $actor = ['user_id'=>$userId,'role_code'=>$role];
            $trips = LogistContractService::trips($pdo, $actor);
            $executors = LogistContractService::executors($pdo, $userId, $role);
            $_SESSION['logist_contract_prepared_at'] = time();
            $this->json(['ok'=>true,'trips'=>$trips,'executors'=>$executors,'sync'=>$result]);
        } catch (Throwable $error) {
            $this->json(['ok'=>false,'message'=>$error->getMessage()], 422);
        }
    }

    public function create(): void
    {
        try {
            $this->allow(false);
            verifyCsrfRequest();
            if (time() - (int)($_SESSION['logist_contract_prepared_at'] ?? 0) > 600) {
                throw new RuntimeException('Данные рейсов устарели. Откройте форму заново для синхронизации.');
            }
            [$company, $pdo, $companyId, $userId, $role] = $this->context();
            $this->requireSchema($pdo);
            $row = LogistContractService::create($pdo, $company, $companyId, $userId, $role, ['user_id'=>$userId,'role_code'=>$role], $_POST);
            unset($_SESSION['logist_contract_prepared_at']);
            $this->json(['ok'=>true,'row'=>$row,'download_url'=>app_url('/company/logistics/contracts/' . (int)$row['id'] . '/download')]);
        } catch (Throwable $error) {
            $this->json(['ok'=>false,'message'=>$error->getMessage()], 422);
        }
    }

    public function detail(string $id): void
    {
        try {
            $this->allow(false);
            [, $pdo, , $userId, $role] = $this->context();
            $owner = $role === 'company_owner' || AccessControlService::isEffectiveSystemOwner();
            $row = LogistContractService::get($pdo, (int)$id, $userId, $owner);
            $this->json(['ok'=>true,'row'=>$row,'versions'=>LogistContractService::versions($pdo,$row,$userId,$owner)]);
        } catch (Throwable $error) {
            $this->json(['ok'=>false,'message'=>$error->getMessage()], 404);
        }
    }

    public function download(string $id): void
    {
        try {
            $this->allow(false);
            [, $pdo, $companyId, $userId, $role] = $this->context();
            $owner = $role === 'company_owner' || AccessControlService::isEffectiveSystemOwner();
            $row = LogistContractService::get($pdo, (int)$id, $userId, $owner);
            $path = LogistContractService::pdfPath($row, $companyId);
            header('Content-Type: application/pdf');
            header('Content-Length: ' . filesize($path));
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode((string)$row['pdf_name']));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
        } catch (Throwable $error) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $error->getMessage();
        }
    }

    public function review(string $id): void
    {
        try {
            $this->allow(true);
            verifyCsrfRequest();
            [, $pdo, , $userId] = $this->context();
            LogistContractService::review($pdo, (int)$id, $userId, (string)($_POST['decision'] ?? ''), (string)($_POST['reason'] ?? ''));
            $this->json(['ok'=>true]);
        } catch (Throwable $error) {
            $this->json(['ok'=>false,'message'=>$error->getMessage()], 422);
        }
    }

    private function context(): array
    {
        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) throw new RuntimeException('Компания не выбрана.');
        $central = $this->db->connection();
        $q = $central->prepare('SELECT * FROM companies WHERE id=?');
        $q->execute([$companyId]);
        $company = $q->fetch(PDO::FETCH_ASSOC);
        if (!$company || (string)$company['status'] !== 'active') throw new RuntimeException('Компания недоступна.');
        $pdo = (new Database(companyDatabaseConfig($this->config, $company)))->connection();
        $membership = AccessControlService::effectiveMembershipId();
        $userId = $membership
            ? (new UserSyncService($this->config))->resolveMembershipTenantUserId($central, $pdo, $companyId, $membership)
            : DocumentGeneratorService::resolveActorTenantUserId($pdo, null, (int)($_SESSION['user_id'] ?? 0));
        return [$company,$pdo,$companyId,$userId,(string)($_SESSION['role_code'] ?? ''),$central];
    }

    private function requireSchema(PDO $pdo): void
    {
        if (!LogistContractService::schemaReady($pdo) || !DocumentGeneratorService::runtimeSchemaReady($pdo)) {
            throw new RuntimeException('Схема договоров логистов не установлена.');
        }
    }

    private function allow(bool $owner): void
    {
        if ($owner) requireRole(['company_owner']);
        else requireRole(['company_owner','senior_logist','logist']);
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
