<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\DocumentGeneratorService;
use App\Service\DocumentService;
use App\Service\DocumentTemplateService;
use PDO;
use RuntimeException;
use Throwable;

final class DocumentGeneratorController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {}

    public function index(): void
    {
        $this->renderGenerator(false);
    }

    public function preview(): void
    {
        try {
            $this->assertCsrf();
            $this->renderGenerator(true);
        } catch (Throwable $e) {
            $this->renderGenerator(false, 'Проверка формы не выполнена: ' . $e->getMessage());
        }
    }

    public function generate(): void
    {
        try {
            $this->assertCsrf();
            [$company, $localPdo, $companyId, $userId, $role] = $this->companyContext();
            $this->requireSchema($localPdo);
            DocumentGeneratorService::seedDefaultTemplates($localPdo, $companyId, 0, 'system');
            $prepared = DocumentGeneratorService::prepare(
                $localPdo,
                $company,
                $companyId,
                $userId,
                $role,
                (int)($_POST['template_id'] ?? 0),
                (int)($_POST['crew_id'] ?? 0),
                (string)($_POST['request_input'] ?? ''),
                $this->paramsFromRequest($_POST)
            );
            if (!($prepared['ok'] ?? false)) {
                $this->renderGenerator(true, 'Формирование заблокировано: исправьте ошибки проверки.');
                return;
            }
            $documentId = DocumentGeneratorService::generate($localPdo, $companyId, $userId, $role, $prepared);
            $_SESSION['document_generator_flash'] = 'Документ сформирован и сохранён в истории.';
            redirect_to('/company/document-generator/history?created=' . $documentId);
        } catch (Throwable $e) {
            $this->renderGenerator(true, 'Не удалось сформировать документ: ' . $e->getMessage());
        }
    }

    public function history(): void
    {
        $pageTitle = 'История документов';
        $pageContext = 'Генератор договоров';
        $history = [];
        $formError = null;
        $flash = $_SESSION['document_generator_flash'] ?? null;
        unset($_SESSION['document_generator_flash']);
        try {
            [$company, $localPdo, , $userId, $role] = $this->companyContext();
            $this->requireSchema($localPdo);
            $history = DocumentGeneratorService::listHistory($localPdo, 200, $userId, $role);
            foreach ($history as &$row) {
                $snapshot = json_decode((string)($row['snapshot_json'] ?? ''), true);
                $snapshot = is_array($snapshot) ? $snapshot : [];
                $row['_executor_name'] = (string)($snapshot['executor']['name'] ?? '');
                $row['_driver_name'] = (string)($snapshot['driver']['full_name'] ?? '');
                $row['_vehicle'] = trim((string)($snapshot['vehicle']['model'] ?? '') . ' ' . (string)($snapshot['vehicle']['plate'] ?? ''));
                $row['_template_name'] = (string)($snapshot['template']['name'] ?? $row['template_name'] ?? $row['document_type'] ?? '');
                $row['_template_version_no'] = (int)($snapshot['template']['version_no'] ?? $row['version_no'] ?? 0);
            }
            unset($row);
        } catch (Throwable $e) {
            $company = $company ?? null;
            $formError = $e->getMessage();
        }
        ob_start();
        require base_path('app/View/pages/company_document_generator_history.php');
        $content = ob_get_clean();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }

    public function downloadGenerated(string $id): void
    {
        try {
            [, $localPdo, $companyId, $userId, $role] = $this->companyContext();
            $this->requireSchema($localPdo);
            $row = DocumentGeneratorService::getAccessibleGenerated($localPdo, (int)$id, $userId, $role);
            if (!$row) throw new RuntimeException('Сформированный документ не найден.');
            $path = DocumentService::resolveStoredPath($companyId, (string)$row['stored_path']);
            if ($path === null) throw new RuntimeException('Файл документа недоступен.');
            $actual = hash_file('sha256', $path) ?: '';
            if ($actual === '' || !hash_equals((string)$row['sha256'], $actual)) {
                throw new RuntimeException('Контрольная сумма документа не совпадает. Скачивание заблокировано.');
            }
            $this->sendDocx($path, (string)$row['original_name']);
        } catch (Throwable $e) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
        }
    }

    public function templates(): void
    {
        $pageTitle = 'Шаблоны документов';
        $pageContext = 'Генератор договоров';
        $templates = [];
        $versionsByTemplate = [];
        $formError = null;
        $flash = $_SESSION['document_template_flash'] ?? null;
        unset($_SESSION['document_template_flash']);
        try {
            [$company, $localPdo, $companyId] = $this->companyContext();
            $this->requireSchema($localPdo);
            DocumentGeneratorService::seedDefaultTemplates($localPdo, $companyId, 0, 'system');
            $templates = DocumentGeneratorService::listTemplates($localPdo, false);
            foreach ($templates as $template) {
                $versionsByTemplate[(int)$template['id']] = DocumentGeneratorService::listTemplateVersions($localPdo, (int)$template['id']);
            }
        } catch (Throwable $e) {
            $company = $company ?? null;
            $formError = $e->getMessage();
        }
        $documentTypes = DocumentTemplateService::documentTypes();
        $placeholders = DocumentTemplateService::placeholders();
        $csrfToken = $this->csrfToken();
        ob_start();
        require base_path('app/View/pages/company_document_generator_templates.php');
        $content = ob_get_clean();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }

    public function uploadTemplate(): void
    {
        try {
            $this->assertCsrf();
            [, $localPdo, $companyId, $userId, $role] = $this->companyContext();
            $this->requireSchema($localPdo);
            $templateId = (int)($_POST['template_id'] ?? 0);
            $documentType = trim((string)($_POST['document_type'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($templateId > 0) {
                $current = DocumentGeneratorService::getTemplateVersion($localPdo, $templateId, null, false);
                if (!$current) throw new RuntimeException('Шаблон для новой версии не найден.');
                $documentType = (string)$current['document_type'];
                if ($name === '') $name = (string)$current['template_name'];
            }
            if (!isset($_FILES['template_file']) || !is_array($_FILES['template_file'])) {
                throw new RuntimeException('Выберите DOCX-файл шаблона.');
            }
            DocumentGeneratorService::createTemplateVersionFromUpload(
                $localPdo,
                $companyId,
                $templateId > 0 ? $templateId : null,
                $documentType,
                $name,
                $_FILES['template_file'],
                $userId,
                $role
            );
            $_SESSION['document_template_flash'] = $templateId > 0 ? 'Новая версия шаблона загружена и активирована.' : 'Шаблон добавлен.';
        } catch (Throwable $e) {
            $_SESSION['document_template_flash'] = 'Ошибка: ' . $e->getMessage();
        }
        redirect_to('/company/document-generator/templates');
    }

    public function toggleTemplate(string $id): void
    {
        try {
            $this->assertCsrf();
            [, $localPdo, , $userId, $role] = $this->companyContext();
            $this->requireSchema($localPdo);
            $active = (string)($_POST['active'] ?? '') === '1';
            DocumentGeneratorService::setTemplateActive($localPdo, (int)$id, $active, $userId, $role);
            $_SESSION['document_template_flash'] = $active ? 'Шаблон активирован.' : 'Шаблон деактивирован.';
        } catch (Throwable $e) {
            $_SESSION['document_template_flash'] = 'Ошибка: ' . $e->getMessage();
        }
        redirect_to('/company/document-generator/templates');
    }

    public function downloadTemplateVersion(string $templateId, string $versionId): void
    {
        try {
            [, $localPdo, $companyId] = $this->companyContext();
            $this->requireSchema($localPdo);
            $row = DocumentGeneratorService::getTemplateVersion($localPdo, (int)$templateId, (int)$versionId, false);
            if (!$row) throw new RuntimeException('Версия шаблона не найдена.');
            $path = DocumentService::resolveStoredPath($companyId, (string)$row['stored_path']);
            if ($path === null) throw new RuntimeException('Файл версии шаблона недоступен.');
            $actual = hash_file('sha256', $path) ?: '';
            if ($actual === '' || !hash_equals((string)$row['sha256'], $actual)) {
                throw new RuntimeException('Контрольная сумма шаблона не совпадает. Скачивание заблокировано.');
            }
            $this->sendDocx($path, (string)$row['original_name']);
        } catch (Throwable $e) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
        }
    }

    private function renderGenerator(bool $doPreview, ?string $forcedError = null): void
    {
        $pageTitle = 'Создать документ';
        $pageContext = 'Генератор договоров';
        $templates = [];
        $executors = [];
        $preview = null;
        $formError = $forcedError;
        $old = $this->formState($_POST);
        try {
            [$company, $localPdo, $companyId, $userId, $role] = $this->companyContext();
            $this->requireSchema($localPdo);
            DocumentGeneratorService::seedDefaultTemplates($localPdo, $companyId, 0, 'system');
            $templates = DocumentGeneratorService::listTemplates($localPdo, true);
            $executors = DocumentGeneratorService::listAccessibleExecutors($localPdo, $userId, $role);

            if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['from'])) {
                $generated = DocumentGeneratorService::getAccessibleGenerated($localPdo, (int)$_GET['from'], $userId, $role);
                if ($generated) {
                    $seed = DocumentGeneratorService::copySeedFromGenerated($generated);
                    $old = $this->formState([
                        'template_id' => $seed['template_id'] ?? 0,
                        'crew_id' => $seed['crew_id'] ?? 0,
                        'request_input' => $seed['request_input'] ?? '',
                        ...($seed['params'] ?? []),
                    ]);
                }
            }
            if ((int)$old['template_id'] <= 0 && $templates !== []) $old['template_id'] = (int)$templates[0]['id'];
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                if (trim((string)$old['contract_date']) === '') $old['contract_date'] = date('Y-m-d');
                if (trim((string)$old['waybill_date']) === '') $old['waybill_date'] = date('Y-m-d');
            }
            if ($doPreview) {
                $preview = DocumentGeneratorService::prepare(
                    $localPdo,
                    $company,
                    $companyId,
                    $userId,
                    $role,
                    (int)$old['template_id'],
                    (int)$old['crew_id'],
                    (string)$old['request_input'],
                    $this->paramsFromRequest($old)
                );
            }
        } catch (Throwable $e) {
            $company = $company ?? null;
            $formError = $formError ?? $e->getMessage();
        }
        $documentTypes = DocumentTemplateService::documentTypes();
        $csrfToken = $this->csrfToken();
        ob_start();
        require base_path('app/View/pages/company_document_generator.php');
        $content = ob_get_clean();
        $config = $this->config;
        $db = $this->db;
        require base_path('app/View/layouts/main.php');
    }

    private function companyContext(): array
    {
        $companyId = (int)(getSessionCompanyId() ?? 0);
        if ($companyId <= 0) throw new RuntimeException('Компания не выбрана.');
        $pdo = $this->db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id=?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$company) throw new RuntimeException('Компания не найдена.');
        if (($company['status'] ?? '') !== 'active') throw new RuntimeException('Генератор договоров недоступен для неактивной компании.');
        $localDb = new Database(companyDatabaseConfig($this->config, $company));
        $localPdo = $localDb->connection();
        return [$company, $localPdo, $companyId, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['role_code'] ?? '')];
    }

    private function requireSchema(PDO $pdo): void
    {
        if (!DocumentGeneratorService::schemaReady($pdo)) {
            throw new RuntimeException('Схема генератора договоров ещё не установлена. Требуется штатная tenant migration.');
        }
    }

    private function csrfToken(): string
    {
        $key = 'document_generator_csrf';
        $token = (string)($_SESSION[$key] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[$key] = $token;
        }
        return $token;
    }

    private function assertCsrf(): void
    {
        $expected = (string)($_SESSION['document_generator_csrf'] ?? '');
        $actual = (string)($_POST['_csrf'] ?? '');
        if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            throw new RuntimeException('Сессия формы устарела. Обновите страницу и повторите действие.');
        }
    }

    private function paramsFromRequest(array $source): array
    {
        return [
            'contract_number' => (string)($source['contract_number'] ?? ''),
            'contract_date' => (string)($source['contract_date'] ?? ''),
            'start_date' => (string)($source['start_date'] ?? ''),
            'start_time' => (string)($source['start_time'] ?? ''),
            'finish_date' => (string)($source['finish_date'] ?? ''),
            'price' => (string)($source['price'] ?? ''),
            'vat_mode' => (string)($source['vat_mode'] ?? 'без НДС'),
            'executor_bank_inn' => (string)($source['executor_bank_inn'] ?? ''),
            'waybill_number' => (string)($source['waybill_number'] ?? ''),
            'waybill_date' => (string)($source['waybill_date'] ?? ''),
        ];
    }

    private function formState(array $source): array
    {
        $params = $this->paramsFromRequest($source);
        return [
            'template_id' => (int)($source['template_id'] ?? 0),
            'crew_id' => (int)($source['crew_id'] ?? 0),
            'request_input' => (string)($source['request_input'] ?? ''),
            ...$params,
        ];
    }

    private function sendDocx(string $path, string $name): never
    {
        $safe = DocumentService::safeDownloadName($name);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Length: ' . (string)filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($safe));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
