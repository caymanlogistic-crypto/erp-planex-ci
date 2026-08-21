<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\ClosingDocumentService;
use PDO;
use RuntimeException;
use Throwable;

final class ClosingDocumentsController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {
    }

    public function index(): void
    {
        requireRole(['company_owner']);
        $config = $this->config;
        $db = $this->db;
        $pageTitle = 'Закрывающие документы';
        $pageContext = 'Финансы › Закрывающие документы';
        $company = null;
        $rows = [];
        $documentsByInn = [];
        $schemaReady = false;
        $dbError = null;
        $problemOnly = (string) ($_GET['problem'] ?? '') === '1';
        $successFlash = $_SESSION['closing_documents_success'] ?? null;
        $errorFlash = $_SESSION['closing_documents_error'] ?? null;
        unset($_SESSION['closing_documents_success'], $_SESSION['closing_documents_error']);

        try {
            [$company, $tenantPdo] = $this->tenant();
            $pageContext .= ' › Компания: ' . e((string) $company['name']);
            $schemaReady = ClosingDocumentService::schemaReady($tenantPdo);
            if ($schemaReady) {
                $rows = ClosingDocumentService::reportRows($tenantPdo, $problemOnly);
                $seenInn = [];
                foreach ($rows as $row) {
                    $inn = (string) ($row['normalized_inn'] ?? '');
                    if ($inn === '' || !empty($row['is_exempt']) || isset($seenInn[$inn])) {
                        continue;
                    }
                    $seenInn[$inn] = true;
                    $documentsByInn[$inn] = ClosingDocumentService::existingDocumentsForInn($tenantPdo, $inn);
                }
            }
        } catch (Throwable $e) {
            $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
        }

        ob_start();
        require base_path('app/View/pages/company_finance_closing_documents.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function exceptions(): void
    {
        requireRole(['company_owner']);
        $config = $this->config;
        $db = $this->db;
        $pageTitle = 'Исключения по документам';
        $pageContext = 'Финансы › Закрывающие документы › Исключения';
        $company = null;
        $exceptions = [];
        $schemaReady = false;
        $dbError = null;
        $successFlash = $_SESSION['closing_documents_success'] ?? null;
        $errorFlash = $_SESSION['closing_documents_error'] ?? null;
        unset($_SESSION['closing_documents_success'], $_SESSION['closing_documents_error']);

        try {
            [$company, $tenantPdo] = $this->tenant();
            $pageContext .= ' › Компания: ' . e((string) $company['name']);
            $schemaReady = ClosingDocumentService::schemaReady($tenantPdo);
            if ($schemaReady) {
                $exceptions = ClosingDocumentService::exceptions($tenantPdo);
            }
        } catch (Throwable $e) {
            $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
        }

        ob_start();
        require base_path('app/View/pages/company_finance_closing_document_exceptions.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function addException(): void
    {
        requireRole(['company_owner']);
        try {
            [, $tenantPdo] = $this->tenant();
            ClosingDocumentService::addException(
                $tenantPdo,
                (string) ($_POST['inn'] ?? ''),
                (string) ($_POST['comment'] ?? ''),
                (int) ($_SESSION['user_id'] ?? 0)
            );
            $_SESSION['closing_documents_success'] = 'ИНН добавлен в исключения';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->backToExceptions();
    }

    public function removeException(): void
    {
        requireRole(['company_owner']);
        try {
            [, $tenantPdo] = $this->tenant();
            ClosingDocumentService::removeException($tenantPdo, (int) ($_POST['id'] ?? 0));
            $_SESSION['closing_documents_success'] = 'Исключение удалено';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->backToExceptions();
    }

    public function linkDocument(): void
    {
        requireRole(['company_owner']);
        try {
            [, $tenantPdo] = $this->tenant();
            ClosingDocumentService::linkExistingDocument(
                $tenantPdo,
                (int) ($_POST['transaction_id'] ?? 0),
                (int) ($_POST['document_id'] ?? 0),
                trim((string) ($_POST['requirement_code'] ?? '')),
                (int) ($_SESSION['user_id'] ?? 0)
            );
            $_SESSION['closing_documents_success'] = 'Существующий документ привязан';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->back();
    }

    public function uploadDocument(): void
    {
        requireRole(['company_owner']);
        require_once base_path('app/Support/legal_entity_document_upload.php');
        try {
            [$company, $tenantPdo] = $this->tenant();
            ClosingDocumentService::uploadAndLink(
                $tenantPdo,
                (int) $company['id'],
                (int) ($_POST['transaction_id'] ?? 0),
                trim((string) ($_POST['requirement_code'] ?? '')),
                $_FILES['document_file'] ?? [],
                (int) ($_SESSION['user_id'] ?? 0),
                (string) ($_SESSION['role_code'] ?? 'company_owner')
            );
            $_SESSION['closing_documents_success'] = 'Документ загружен и привязан';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->back();
    }

    public function unlinkDocument(): void
    {
        requireRole(['company_owner']);
        try {
            [, $tenantPdo] = $this->tenant();
            ClosingDocumentService::unlinkDocument(
                $tenantPdo,
                (int) ($_POST['transaction_id'] ?? 0),
                (int) ($_POST['link_id'] ?? 0)
            );
            $_SESSION['closing_documents_success'] = 'Связь документа удалена';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->back();
    }

    private function tenant(): array
    {
        $companyId = (int) (getSessionCompanyId() ?? 0);
        if ($companyId <= 0) {
            throw new RuntimeException('Компания не выбрана');
        }
        $centralPdo = $this->db->connection();
        $stmt = $centralPdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company || (string) ($company['status'] ?? '') !== 'active') {
            throw new RuntimeException('Компания недоступна');
        }
        $tenantDb = new Database(companyDatabaseConfig($this->config, $company));
        return [$company, $tenantDb->connection()];
    }

    private function back(): void
    {
        $suffix = (string) ($_POST['problem_only'] ?? '') === '1' ? '?problem=1' : '';
        header('Location: ' . app_url('/company/finance/closing-documents') . $suffix);
        exit;
    }

    private function backToExceptions(): void
    {
        header('Location: ' . app_url('/company/finance/closing-documents/exceptions'));
        exit;
    }
}
