<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\ClosingDocumentComplianceService;
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
        $config = $this->config;
        $db = $this->db;
        $pageTitle = 'Закрывающие документы';
        $pageContext = 'Финансы › Закрывающие документы';
        $company = null;
        $rows = [];
        $schemaReady = false;
        $dbError = null;
        $successFlash = $_SESSION['closing_documents_success'] ?? null;
        $errorFlash = $_SESSION['closing_documents_error'] ?? null;
        unset($_SESSION['closing_documents_success'], $_SESSION['closing_documents_error']);

        try {
            [$company, $tenantPdo] = $this->tenant();
            $pageContext .= ' › Компания: ' . e((string) $company['name']);
            $schemaReady = ClosingDocumentComplianceService::schemaReady($tenantPdo);
            if ($schemaReady) $rows = ClosingDocumentComplianceService::reportRows($tenantPdo, false);
        } catch (Throwable $e) {
            $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
        }

        ob_start();
        require base_path('app/View/pages/company_finance_closing_documents.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function modalView(string|int $id): void
    {
        $transactionId = (int) $id;
        if ($transactionId <= 0) {
            http_response_code(404);
            echo '<div class="form-alert alert-error">Платёж не найден.</div>';
            return;
        }
        try {
            [, $tenantPdo] = $this->tenant();
            if (!ClosingDocumentComplianceService::schemaReady($tenantPdo)) throw new RuntimeException('Для раздела требуется актуальная tenant schema.');
            $row = null;
            foreach (ClosingDocumentComplianceService::reportRows($tenantPdo, false) as $candidate) {
                if ((int) ($candidate['id'] ?? 0) === $transactionId) { $row = $candidate; break; }
            }
            if ($row === null) {
                http_response_code(404);
                echo '<div class="form-alert alert-error">Платёж не входит в контур закрывающих документов.</div>';
                return;
            }
            $requirementLabels = ClosingDocumentService::requirementLabels();
            require base_path('app/View/pages/company_finance_closing_document_modal.php');
        } catch (Throwable $e) {
            http_response_code(500);
            echo '<div class="form-alert alert-error">' . e($e->getMessage()) . '</div>';
        }
    }

    public function exceptions(): void
    {
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
            if ($schemaReady) $exceptions = ClosingDocumentService::exceptions($tenantPdo);
        } catch (Throwable $e) {
            $dbError = 'Ошибка при загрузке данных: ' . $e->getMessage();
        }

        ob_start();
        require base_path('app/View/pages/company_finance_closing_document_exceptions.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function exceptionCreateForm(): void
    {
        $rule = null;
        require base_path('app/View/pages/company_finance_closing_document_exception_form.php');
    }

    public function exceptionEditForm(): void
    {
        try {
            [, $tenantPdo] = $this->tenant();
            if (!ClosingDocumentService::schemaReady($tenantPdo)) throw new RuntimeException('Для раздела требуется актуальная tenant schema.');
            $rule = ClosingDocumentService::exception($tenantPdo, (int) ($_GET['id'] ?? 0));
            if ($rule === null) {
                http_response_code(404);
                echo '<div class="modal-body"><div class="form-alert alert-error">Правило не найдено.</div></div>';
                return;
            }
            require base_path('app/View/pages/company_finance_closing_document_exception_form.php');
        } catch (Throwable $e) {
            http_response_code(500);
            echo '<div class="modal-body"><div class="form-alert alert-error">' . e($e->getMessage()) . '</div></div>';
        }
    }

    public function saveException(): void
    {
        try {
            [, $tenantPdo] = $this->tenant();
            $id = (int) ($_POST['id'] ?? 0);
            $inn = (string) ($_POST['inn'] ?? '');
            $purposeContains = (string) ($_POST['purpose_contains'] ?? '');
            $comment = (string) ($_POST['comment'] ?? '');
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if ($id > 0) {
                ClosingDocumentService::updateException($tenantPdo, $id, $inn, $purposeContains, $comment, $userId);
                $_SESSION['closing_documents_success'] = 'Правило исключения обновлено';
            } else {
                ClosingDocumentService::addException($tenantPdo, $inn, $purposeContains, $comment, $userId);
                $_SESSION['closing_documents_success'] = 'Правило исключения добавлено';
            }
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->backToExceptions();
    }

    public function removeException(): void
    {
        try {
            [, $tenantPdo] = $this->tenant();
            ClosingDocumentService::removeException($tenantPdo, (int) ($_POST['id'] ?? 0));
            $_SESSION['closing_documents_success'] = 'Правило исключения удалено';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->backToExceptions();
    }

    public function saveRequirementOverrides(): void
    {
        try {
            [, $tenantPdo] = $this->tenant();
            ClosingDocumentComplianceService::saveRequirementOverrides(
                $tenantPdo,
                (int)($_POST['transaction_id'] ?? 0),
                is_array($_POST['manual_collected'] ?? null) ? $_POST['manual_collected'] : [],
                is_array($_POST['inn_exempt'] ?? null) ? $_POST['inn_exempt'] : [],
                (int)($_SESSION['user_id'] ?? 0)
            );
            $_SESSION['closing_documents_success'] = 'Статусы документов обновлены';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->back();
    }

    public function linkDocument(): void
    {
        try {
            [, $tenantPdo] = $this->tenant();
            ClosingDocumentService::linkExistingDocument($tenantPdo, (int) ($_POST['transaction_id'] ?? 0), (int) ($_POST['document_id'] ?? 0), trim((string) ($_POST['requirement_code'] ?? '')), (int) ($_SESSION['user_id'] ?? 0));
            $_SESSION['closing_documents_success'] = 'Существующий документ привязан';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->back();
    }

    public function uploadDocument(): void
    {
        require_once base_path('app/Support/legal_entity_document_upload.php');
        try {
            [$company, $tenantPdo] = $this->tenant();
            ClosingDocumentComplianceService::uploadAndLink(
                $tenantPdo,
                (int) $company['id'],
                (int) ($_POST['transaction_id'] ?? 0),
                trim((string) ($_POST['requirement_code'] ?? '')),
                $_FILES['document_file'] ?? [],
                (int) ($_SESSION['user_id'] ?? 0),
                (string) ($_SESSION['role_code'] ?? 'company_owner'),
                !empty($_POST['is_edo'])
            );
            $_SESSION['closing_documents_success'] = !empty($_POST['is_edo']) ? 'Документ ЭДО загружен и привязан' : 'Документ загружен и привязан';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->back();
    }

    public function updateDocumentEdo(): void
    {
        try {
            [, $tenantPdo] = $this->tenant();
            if (!ClosingDocumentComplianceService::schemaReady($tenantPdo)) throw new RuntimeException('Для раздела требуется актуальная tenant schema.');
            $transactionId = (int)($_POST['transaction_id'] ?? 0);
            $linkId = (int)($_POST['link_id'] ?? 0);
            if ($transactionId <= 0 || $linkId <= 0) throw new RuntimeException('Некорректный документ');

            $row = $this->closingReportRow($tenantPdo, $transactionId);
            if ($row === null || !empty($row['is_exempt'])) throw new RuntimeException('Операция недоступна для редактирования документов');
            $allowed = false;
            foreach (($row['links'] ?? []) as $link) {
                if ((int)($link['id'] ?? 0) === $linkId && empty($link['document_deleted_at'])) { $allowed = true; break; }
            }
            if (!$allowed) throw new RuntimeException('Документ не найден у этого платежа');

            $stmt = $tenantPdo->prepare(
                'UPDATE finance_closing_document_links SET is_edo = ? WHERE id = ? AND bank_transaction_id = ?'
            );
            $stmt->execute([!empty($_POST['is_edo']) ? 1 : 0, $linkId, $transactionId]);
            $_SESSION['closing_documents_success'] = 'Признак ЭДО обновлён';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->back();
    }

    public function downloadArchive(): void
    {
        $tmpPath = null;
        try {
            if (!class_exists(\ZipArchive::class)) throw new RuntimeException('На сервере недоступно создание ZIP-архивов');
            [$company, $tenantPdo] = $this->tenant();
            if (!ClosingDocumentComplianceService::schemaReady($tenantPdo)) throw new RuntimeException('Для раздела требуется актуальная tenant schema.');
            $storagePrefix = 'companies/' . (int)$company['id'] . '/documents/';

            $requested = $_POST['transaction_ids'] ?? [];
            if (!is_array($requested)) $requested = [];
            $requestedIds = [];
            foreach ($requested as $value) {
                $id = (int)$value;
                if ($id > 0) $requestedIds[$id] = true;
                if (count($requestedIds) > 500) throw new RuntimeException('Слишком много операций для одного архива');
            }
            if ($requestedIds === []) throw new RuntimeException('Нет отображаемых операций для выгрузки');

            $rowsById = [];
            $allowedLinkIds = [];
            foreach (ClosingDocumentComplianceService::reportRows($tenantPdo, false) as $row) {
                $transactionId = (int)($row['id'] ?? 0);
                if (!isset($requestedIds[$transactionId])) continue;
                $rowsById[$transactionId] = $row;
                foreach (($row['links'] ?? []) as $link) {
                    $linkId = (int)($link['id'] ?? 0);
                    if ($linkId > 0 && empty($link['document_deleted_at'])) $allowedLinkIds[$linkId] = true;
                }
            }
            if ($rowsById === []) throw new RuntimeException('Выбранные операции недоступны');
            if ($allowedLinkIds === []) throw new RuntimeException('У отображаемых операций нет загруженных документов');

            $linkIds = array_keys($allowedLinkIds);
            $placeholders = implode(',', array_fill(0, count($linkIds), '?'));
            $stmt = $tenantPdo->prepare(
                "SELECT l.id, l.bank_transaction_id, l.requirement_code, d.original_name, d.stored_name, d.relative_path
                 FROM finance_closing_document_links l
                 INNER JOIN documents d ON d.id = l.document_id
                 WHERE l.id IN ($placeholders) AND d.deleted_at IS NULL
                 ORDER BY l.bank_transaction_id, l.id"
            );
            $stmt->execute($linkIds);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $tmpPath = tempnam(sys_get_temp_dir(), 'planex_closing_docs_');
            if ($tmpPath === false) throw new RuntimeException('Не удалось подготовить временный архив');
            $zip = new \ZipArchive();
            if ($zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Не удалось создать ZIP-архив');
            }

            $usedPaths = [];
            $added = 0;
            foreach ($documents as $document) {
                $transactionId = (int)($document['bank_transaction_id'] ?? 0);
                $row = $rowsById[$transactionId] ?? null;
                if (!is_array($row)) continue;
                $relativePath = ltrim(str_replace('\\', '/', (string)($document['relative_path'] ?? '')), '/');
                if ($relativePath === '' || !str_starts_with($relativePath, $storagePrefix) || str_contains($relativePath, '../') || str_contains($relativePath, "\0")) continue;
                $absolutePath = storage_path($relativePath);
                if (!is_file($absolutePath) || !is_readable($absolutePath)) continue;

                $counterparty = $this->archiveSegment((string)($row['counterparty_name'] ?? ''), 'Без наименования');
                $inn = $this->archiveSegment((string)($row['normalized_inn'] ?? ''), '');
                $counterpartyFolder = $counterparty . ($inn !== '' ? ' — ИНН ' . $inn : '');
                $date = !empty($row['operation_date']) ? date('d.m.Y', strtotime((string)$row['operation_date'])) : 'Без даты';
                $number = $this->archiveSegment((string)($row['document_number'] ?: $transactionId), (string)$transactionId);
                $paymentFolder = $this->archiveSegment($date . '_№' . $number, 'Платёж_' . $transactionId);
                $typeName = $this->archiveRequirementName((string)($document['requirement_code'] ?? ''));
                $originalName = $this->archiveSegment((string)($document['original_name'] ?? ''), 'документ');
                $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
                if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
                    $extension = strtolower((string)pathinfo((string)($document['stored_name'] ?? ''), PATHINFO_EXTENSION));
                }
                $originalBase = pathinfo($originalName, PATHINFO_FILENAME);
                $originalBase = $this->archiveSegment($originalBase, 'документ');
                $baseArchivePath = $counterpartyFolder . '/' . $paymentFolder . '/' . $typeName . '__' . $originalBase;
                $archivePath = $baseArchivePath . ($extension !== '' ? '.' . $extension : '');
                $suffix = 2;
                while (isset($usedPaths[$archivePath])) {
                    $archivePath = $baseArchivePath . '_' . $suffix . ($extension !== '' ? '.' . $extension : '');
                    $suffix++;
                }
                $usedPaths[$archivePath] = true;
                if ($zip->addFile($absolutePath, $archivePath)) $added++;
            }
            $zip->close();
            if ($added === 0) throw new RuntimeException('Не найдено доступных файлов для архива');

            $filename = 'closing-documents-' . date('Ymd-His') . '.zip';
            header('Content-Type: application/zip');
            header('Content-Length: ' . (string)filesize($tmpPath));
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Planex-Archive-Files: ' . (string)$added);
            readfile($tmpPath);
            @unlink($tmpPath);
            exit;
        } catch (Throwable $e) {
            if (is_string($tmpPath) && $tmpPath !== '' && is_file($tmpPath)) @unlink($tmpPath);
            http_response_code(422);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $e->getMessage();
        }
    }

    public function unlinkDocument(): void
    {
        try {
            [, $tenantPdo] = $this->tenant();
            ClosingDocumentService::unlinkDocument($tenantPdo, (int) ($_POST['transaction_id'] ?? 0), (int) ($_POST['link_id'] ?? 0));
            $_SESSION['closing_documents_success'] = 'Связь документа удалена';
        } catch (Throwable $e) {
            $_SESSION['closing_documents_error'] = $e->getMessage();
        }
        $this->back();
    }

    private function closingReportRow(PDO $tenantPdo, int $transactionId): ?array
    {
        foreach (ClosingDocumentComplianceService::reportRows($tenantPdo, false) as $row) {
            if ((int)($row['id'] ?? 0) === $transactionId) return $row;
        }
        return null;
    }

    private function archiveRequirementName(string $code): string
    {
        return match ($code) {
            'agreement', 'application' => 'Договор_Заявка',
            'act', 'upd' => 'Акт_УПД',
            'npd_receipt' => 'Чек_НПД',
            default => 'Документ',
        };
    }

    private function archiveSegment(string $value, string $fallback): string
    {
        $value = trim($value);
        $value = preg_replace('/[\\\\\/<>:"|?*\x00-\x1F]+/u', '_', $value) ?? $value;
        $value = preg_replace('/\.{2,}/u', '.', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value, " ._-");
        if ($value === '') return $fallback;
        return mb_substr($value, 0, 120, 'UTF-8');
    }

    private function tenant(): array
    {
        $companyId = (int) (getSessionCompanyId() ?? 0);
        if ($companyId <= 0) throw new RuntimeException('Компания не выбрана');
        $centralPdo = $this->db->connection();
        $stmt = $centralPdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company || (string) ($company['status'] ?? '') !== 'active') throw new RuntimeException('Компания недоступна');
        $tenantDb = new Database(companyDatabaseConfig($this->config, $company));
        return [$company, $tenantDb->connection()];
    }

    private function back(): void
    {
        header('Location: ' . app_url('/company/finance/closing-documents'));
        exit;
    }

    private function backToExceptions(): void
    {
        header('Location: ' . app_url('/company/finance/closing-documents/exceptions'));
        exit;
    }
}
