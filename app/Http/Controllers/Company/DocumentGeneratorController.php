<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\AccessControlService;
use App\Service\DocumentGeneratorService;
use App\Service\DocumentService;
use App\Service\DocumentTemplateService;
use App\Service\UserSyncService;
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
            $mode = $this->generatorMode($_POST);
            if ($this->isClientPackageMode($mode)) {
                $prepared = DocumentGeneratorService::prepareClientPackage(
                    $localPdo,
                    $company,
                    $companyId,
                    $userId,
                    $role,
                    (int)($_POST['client_id'] ?? 0),
                    (int)($_POST['trip_id'] ?? 0),
                    (string)($_POST['client_contract_date'] ?? ''),
                    (string)($_POST['client_invoice_date'] ?? ''),
                    (string)($_POST['client_act_date'] ?? ''),
                    null,
                    $mode === 'client_package_upd' ? 'upd' : 'act',
                    (string)($_POST['client_contract_number'] ?? '')
                );
                if (!($prepared['ok'] ?? false)) {
                    $this->renderGenerator(true, 'Формирование комплекта заблокировано: исправьте ошибки проверки.');
                    return;
                }
                $overwriteExisting = (string)($_POST['overwrite_existing'] ?? '') === '1';
                $didOverwrite = $overwriteExisting && ($prepared['package_exists'] ?? false);
                $ids = DocumentGeneratorService::generateClientPackage($localPdo, $companyId, $userId, $role, $prepared, $overwriteExisting);
                $lastName = $mode === 'client_package_upd' ? 'УПД' : 'акт';
                $_SESSION['document_generator_flash'] = $didOverwrite
                    ? 'Комплект клиенту перезаписан: договор, счёт и ' . $lastName . ' обновлены в истории.'
                    : 'Комплект клиенту сформирован: договор, счёт и ' . $lastName . ' сохранены в истории.';
                redirect_to('/company/document-generator/history?created_package=' . implode(',', array_map('intval', array_values($ids))));
            }

            $prepared = DocumentGeneratorService::prepareFromTrip(
                $localPdo,
                $company,
                $companyId,
                $userId,
                $role,
                (int)($_POST['template_id'] ?? 0),
                (int)($_POST['trip_id'] ?? 0),
                $this->paramsFromRequest($_POST)
            );
            if (!($prepared['ok'] ?? false)) {
                $this->renderGenerator(true, 'Формирование заблокировано: исправьте ошибки проверки.');
                return;
            }
            $overwriteExisting = (string)($_POST['overwrite_existing'] ?? '') === '1';
            $didOverwrite = $overwriteExisting && ($prepared['contract_exists'] ?? false);
            $documentId = DocumentGeneratorService::generate($localPdo, $companyId, $userId, $role, $prepared, $overwriteExisting);
            $_SESSION['document_generator_flash'] = $didOverwrite
                ? 'Договор перезаписан и обновлён в истории.'
                : 'Документ сформирован и сохранён в истории.';
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
        $formError = $_SESSION['document_generator_history_error'] ?? null;
        unset($_SESSION['document_generator_history_error']);
        $flash = $_SESSION['document_generator_flash'] ?? null;
        unset($_SESSION['document_generator_flash']);
        $csrfToken = $this->csrfToken();
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
            $this->sendDocx($path, DocumentGeneratorService::generatedDownloadName($row));
        } catch (Throwable $e) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
        }
    }

    public function downloadHistoryArchive(): void
    {
        $temporaryPath = null;
        try {
            $this->assertCsrf();
            [, $localPdo, $companyId, $userId, $role] = $this->companyContext();
            $this->requireSchema($localPdo);
            $requestedIds = $_POST['document_ids'] ?? [];
            if (!is_array($requestedIds)) throw new RuntimeException('Выберите документы для скачивания.');
            $archive = DocumentGeneratorService::createHistoryArchive($localPdo, $companyId, $userId, $role, $requestedIds);
            $temporaryPath = $archive['path'];
            header('Content-Type: application/zip');
            header('Content-Length: ' . (string)filesize($temporaryPath));
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('Документы-' . date('Y-m-d-His') . '.zip'));
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($temporaryPath);
        } catch (Throwable $e) {
            $_SESSION['document_generator_history_error'] = 'Не удалось скачать архив: ' . $e->getMessage();
            redirect_to('/company/document-generator/history');
        } finally {
            if ($temporaryPath !== null) @unlink($temporaryPath);
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
        $trips = [];
        $clients = [];
        $preview = null;
        $formError = $forcedError;
        $old = $this->formState($_POST);
        $canGenerateClientPackage = false;
        try {
            [$company, $localPdo, $companyId, $userId, $role] = $this->companyContext();
            $this->requireSchema($localPdo);
            DocumentGeneratorService::seedDefaultTemplates($localPdo, $companyId, 0, 'system');
            $allTemplates = DocumentGeneratorService::listTemplates($localPdo, true);
            $templates = array_values(array_filter($allTemplates, static fn(array $row): bool => in_array((string)($row['document_type'] ?? ''), ['vehicle_lease_crew','carrier_sublease_crew','waybill_driver_assignment'], true)));
            $canGenerateClientPackage = DocumentGeneratorService::canGenerateClientPackage($role);
            $trips = DocumentGeneratorService::listDocumentTrips($localPdo, $canGenerateClientPackage);
            if ($canGenerateClientPackage) $clients = DocumentGeneratorService::listDocumentClients($localPdo, $userId, $role);

            if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['from'])) {
                $generated = DocumentGeneratorService::getAccessibleGenerated($localPdo, (int)$_GET['from'], $userId, $role);
                if ($generated) {
                    $seed = DocumentGeneratorService::copySeedFromGenerated($generated);
                    $snapshot = json_decode((string)($generated['snapshot_json'] ?? ''), true);
                    $snapshot = is_array($snapshot) ? $snapshot : [];
                    $docType = (string)($generated['document_type'] ?? '');
                    $updPackageType = in_array($docType, ['client_upd_contract','client_upd_invoice','client_upd_status2'], true);
                    $packageType = $updPackageType || in_array($docType, ['client_sublease_crew','client_invoice','client_act'], true);
                    $old = $this->formState([
                        'generator_mode' => $packageType ? ($updPackageType ? 'client_package_upd' : 'client_package') : 'carrier',
                        'template_id' => $seed['template_id'] ?? 0,
                        'trip_id' => (int)($snapshot['trip']['id'] ?? 0),
                        'client_id' => (int)($snapshot['client']['id'] ?? 0),
                        'client_contract_date' => (string)($snapshot['parameters']['contract_date'] ?? $generated['document_date'] ?? ''),
                        'client_invoice_date' => (string)($snapshot['parameters']['invoice_date'] ?? $generated['document_date'] ?? ''),
                        'client_act_date' => (string)($snapshot['parameters']['act_date'] ?? $generated['document_date'] ?? ''),
                        'client_contract_number' => (string)($snapshot['parameters']['contract_number'] ?? ''),
                        ...($seed['params'] ?? []),
                    ]);
                }
            }
            if ((int)$old['template_id'] <= 0 && $templates !== []) $old['template_id'] = (int)$templates[0]['id'];
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                if (trim((string)$old['waybill_date']) === '') $old['waybill_date'] = date('Y-m-d');
                if ($this->isClientPackageMode($old['generator_mode'])) {
                    foreach ($trips as $trip) {
                        if ((int)$trip['id'] !== (int)$old['trip_id']) continue;
                        if ($old['client_contract_number'] === '') $old['client_contract_number'] = date('y') . '-' . (int)$trip['business_id'];
                        foreach ((array)($trip['_package_dates'] ?? []) as $key => $value) {
                            $stateKey = 'client_' . $key;
                            if (isset($old[$stateKey]) && $old[$stateKey] === '') $old[$stateKey] = $value;
                        }
                        break;
                    }
                } elseif ((int)$old['trip_id'] > 0) {
                    foreach ($trips as $trip) {
                        if ((int)$trip['id'] !== (int)$old['trip_id']) continue;
                        foreach ((array)($trip['_carrier_contract_defaults'] ?? []) as $key => $value) {
                            if (isset($old[$key]) && $old[$key] === '') $old[$key] = $value;
                        }
                        break;
                    }
                }
                if (trim((string)$old['contract_date']) === '' && (int)$old['trip_id'] <= 0) $old['contract_date'] = date('Y-m-d');
            }
            if ($doPreview) {
                if ($this->isClientPackageMode($old['generator_mode'])) {
                    $preview = DocumentGeneratorService::prepareClientPackage(
                        $localPdo,
                        $company,
                        $companyId,
                        $userId,
                        $role,
                        (int)$old['client_id'],
                        (int)$old['trip_id'],
                        (string)$old['client_contract_date'],
                        (string)$old['client_invoice_date'],
                        (string)$old['client_act_date'],
                        null,
                        $old['generator_mode'] === 'client_package_upd' ? 'upd' : 'act',
                        (string)$old['client_contract_number']
                    );
                } else {
                    $preview = DocumentGeneratorService::prepareFromTrip(
                        $localPdo,
                        $company,
                        $companyId,
                        $userId,
                        $role,
                        (int)$old['template_id'],
                        (int)$old['trip_id'],
                        $this->paramsFromRequest($old)
                    );
                }
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
        $membershipId = AccessControlService::effectiveMembershipId();
        if (($membershipId ?? 0) > 0) {
            $userId = (new UserSyncService($this->config))->resolveMembershipTenantUserId(
                $pdo,
                $localPdo,
                $companyId,
                (int)$membershipId
            );
        } else {
            $userId = DocumentGeneratorService::resolveActorTenantUserId(
                $localPdo,
                null,
                (int)($_SESSION['user_id'] ?? 0)
            );
        }
        return [$company, $localPdo, $companyId, $userId, (string)($_SESSION['role_code'] ?? '')];
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
            'payment_terms' => (string)($source['payment_terms'] ?? ''),
            'executor_bank_inn' => (string)($source['executor_bank_inn'] ?? ''),
            'waybill_number' => (string)($source['waybill_number'] ?? ''),
            'waybill_date' => (string)($source['waybill_date'] ?? ''),
        ];
    }

    private function formState(array $source): array
    {
        $params = $this->paramsFromRequest($source);
        return [
            'generator_mode' => $this->generatorMode($source),
            'template_id' => (int)($source['template_id'] ?? 0),
            'trip_id' => (int)($source['trip_id'] ?? 0),
            'client_id' => (int)($source['client_id'] ?? 0),
            'client_contract_date' => (string)($source['client_contract_date'] ?? ''),
            'client_invoice_date' => (string)($source['client_invoice_date'] ?? ''),
            'client_act_date' => (string)($source['client_act_date'] ?? ''),
            'client_contract_number' => (string)($source['client_contract_number'] ?? ''),
            ...$params,
        ];
    }

    private function generatorMode(array $source): string
    {
        $mode = (string)($source['generator_mode'] ?? '');
        return $this->isClientPackageMode($mode) ? $mode : 'carrier';
    }

    private function isClientPackageMode(string $mode): bool
    {
        return in_array($mode, ['client_package','client_package_upd'], true);
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
