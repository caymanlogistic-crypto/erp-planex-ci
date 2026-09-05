<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FeoRequestInteractionService;
use App\Service\FeoRequestService;
use App\Service\FeoXlsxParser;

final class FeoRequestsController
{
    private FeoRequestService $service;
    private FeoRequestInteractionService $interaction;
    private FeoXlsxParser $parser;

    public function __construct(private readonly array $config, private readonly Database $db)
    {
        $this->service = new FeoRequestService($config, $db);
        $this->interaction = new FeoRequestInteractionService();
        $this->parser = new FeoXlsxParser();
    }

    public function index(): void
    {
        $pageTitle = 'Заявки ФЭО';
        $pageContext = 'Заявки ФЭО › Все заявки';
        $company = null;
        $dbError = null;
        $imports = [];
        $freshness = ['is_problem'=>true,'has_active_import'=>false,'latest_at'=>null,'latest_filename'=>null,'age_days'=>null];
        $successFlash = $_SESSION['flash_success'] ?? null;
        $errorFlash = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        try {
            [$company, $pdo] = $this->companyContext();
            if (!$this->service->moduleReady($pdo)) throw new \RuntimeException('Модуль ФЭО ещё не инициализирован в базе компании.');
            $imports = $this->service->listImports($pdo);
            $freshness = $this->service->freshnessState($pdo);
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        $config = $this->config;
        $db = $this->db;
        ob_start();
        require base_path('app/View/pages/company_feo_requests.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function data(): void
    {
        try {
            [, $pdo] = $this->companyContext();
            if (!$this->service->moduleReady($pdo)) throw new \RuntimeException('Модуль ФЭО не инициализирован.');
            $rawIdFilter = (string)($_GET['filter_zayavki'] ?? '');
            $requestIds = $this->interaction->extractRequestIds($rawIdFilter);
            if (trim($rawIdFilter) !== '' && $requestIds === []) {
                jsonResponse([
                    'success'=>true,
                    'rows'=>[],
                    'total'=>0,
                    'total_mass_kg'=>0,
                    'offset'=>max(0, (int)($_GET['offset'] ?? 0)),
                    'limit'=>max(20, min(100, (int)($_GET['limit'] ?? 80))),
                    'has_more'=>false,
                    'requested_count'=>0,
                    'found_ids'=>[],
                    'missing_ids'=>[],
                    'filter_error'=>'Во введённом тексте не найдено шестизначных номеров заявок.',
                ]);
                return;
            }
            $contentSearch = (string)($_GET['search_text'] ?? '');
            $result = $this->service->listRequestsBatch(
                $pdo,
                implode(' ', $requestIds),
                $contentSearch,
                (int)($_GET['offset'] ?? 0),
                (int)($_GET['limit'] ?? 80)
            );
            $result['total_mass_kg'] = $this->interaction->filteredMassKg($pdo, $requestIds, $contentSearch);
            jsonResponse(['success'=>true] + $result);
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function detail(string $id): void
    {
        try {
            [, $pdo] = $this->companyContext();
            if (!$this->service->moduleReady($pdo)) throw new \RuntimeException('Модуль ФЭО не инициализирован.');
            $row = $this->service->request($pdo, (int)$id);
            if (!$row) { jsonResponse(['success'=>false,'message'=>'Заявка ФЭО не найдена.'], 404); return; }
            jsonResponse(['success'=>true] + $this->detailPayload($row));
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 500);
        }
    }

    public function update(string $id): void
    {
        try {
            [, $pdo] = $this->companyContext();
            if (!$this->service->moduleReady($pdo)) throw new \RuntimeException('Модуль ФЭО не инициализирован.');
            $row = $this->interaction->updateRequest($pdo, (int)$id, $_POST);
            jsonResponse(['success'=>true,'message'=>'Заявка ФЭО сохранена.'] + $this->detailPayload($row));
        } catch (\RuntimeException $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>'Не удалось сохранить заявку ФЭО.'], 500);
        }
    }

    public function analyzeImport(): void
    {
        try {
            [$company, $pdo] = $this->companyContext();
            if (!$this->service->moduleReady($pdo)) throw new \RuntimeException('Модуль ФЭО не инициализирован.');
            if (!isset($_FILES['feo_xlsx']) || !is_array($_FILES['feo_xlsx']) || ($_FILES['feo_xlsx']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Выберите XLSX-файл ФЭО.');
            }
            $file = $_FILES['feo_xlsx'];
            $tmp = (string)($file['tmp_name'] ?? '');
            $name = basename((string)($file['name'] ?? 'feo.xlsx'));
            $size = (int)($file['size'] ?? 0);
            if (!is_uploaded_file($tmp) || $size <= 0 || $size > ERP_MAX_FILE_SIZE) throw new \RuntimeException('Файл не прошёл проверку или превышает 20 МБ.');
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') throw new \RuntimeException('Допустим только формат XLSX.');

            $overwriteExisting = !empty($_POST['overwrite_existing']);
            $rows = $this->parser->parse($tmp);
            $hash = hash_file('sha256', $tmp);
            if (!is_string($hash) || $hash === '') throw new \RuntimeException('Не удалось вычислить контрольную сумму файла.');
            $prepared = $this->interaction->prepareImportRows($pdo, $rows, $overwriteExisting);
            $stats = $this->service->analyze($pdo, $prepared['rows'], $hash);
            $stats['skipped_existing_count'] = (int)$prepared['skipped_existing_count'];
            $stats['unchanged_count'] = max(0, (int)$stats['unchanged_count'] - $stats['skipped_existing_count']);

            $companyId = (int)$company['id'];
            $dir = $this->previewDir($companyId);
            $this->cleanupPreviews($dir);
            $token = bin2hex(random_bytes(24));
            $target = $dir . DIRECTORY_SEPARATOR . $token . '.xlsx';
            if (!move_uploaded_file($tmp, $target)) throw new \RuntimeException('Не удалось сохранить временную копию файла для подтверждения.');
            @chmod($target, 0600);
            $_SESSION['feo_import_previews'][$token] = [
                'company_id'=>$companyId,
                'path'=>$target,
                'filename'=>$name,
                'hash'=>$hash,
                'created_at'=>time(),
                'overwrite_existing'=>$overwriteExisting,
            ];
            jsonResponse(['success'=>true,'token'=>$token,'filename'=>$name,'overwrite_existing'=>$overwriteExisting,'stats'=>$stats]);
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function commitImport(): void
    {
        try {
            [$company, $pdo] = $this->companyContext();
            if (!$this->service->moduleReady($pdo)) throw new \RuntimeException('Модуль ФЭО не инициализирован.');
            $token = trim((string)($_POST['preview_token'] ?? ''));
            $preview = $_SESSION['feo_import_previews'][$token] ?? null;
            if (!is_array($preview) || !hash_equals((string)($preview['company_id'] ?? ''), (string)$company['id'])) throw new \RuntimeException('Предварительный анализ устарел. Загрузите файл повторно.');
            if ((int)($preview['created_at'] ?? 0) < time() - 7200) throw new \RuntimeException('Предварительный анализ устарел. Загрузите файл повторно.');
            $path = (string)($preview['path'] ?? '');
            if (!is_file($path) || !is_readable($path)) throw new \RuntimeException('Временный файл импорта не найден.');
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals((string)$preview['hash'], $hash)) throw new \RuntimeException('Контрольная сумма файла изменилась.');

            $overwriteExisting = !empty($preview['overwrite_existing']);
            $rows = $this->parser->parse($path);
            $prepared = $this->interaction->prepareImportRows($pdo, $rows, $overwriteExisting);
            $result = $this->service->applyImport($pdo, $prepared['rows'], (string)$preview['filename'], $hash, $_SESSION);
            $result['skipped_existing_count'] = (int)$prepared['skipped_existing_count'];
            $result['unchanged_count'] = max(0, (int)$result['unchanged_count'] - $result['skipped_existing_count']);
            @unlink($path);
            unset($_SESSION['feo_import_previews'][$token]);
            $_SESSION['flash_success'] = sprintf(
                'ФЭО: загрузка #%d применена. Новых: %d, обновлено: %d, не перезаписано существующих: %d, без изменений: %d, пропало относительно файла: %d.',
                (int)$result['import_id'],
                (int)$result['new_count'],
                (int)$result['updated_count'],
                (int)$result['skipped_existing_count'],
                (int)$result['unchanged_count'],
                (int)$result['missing_count']
            );
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Импорт ФЭО не выполнен: ' . $e->getMessage();
        }
        redirect_to('/company/feo/requests');
    }

    public function rollbackImport(): void
    {
        try {
            [, $pdo] = $this->companyContext();
            if (!$this->service->moduleReady($pdo)) throw new \RuntimeException('Модуль ФЭО не инициализирован.');
            $importId = (int)($_POST['import_id'] ?? 0);
            $result = $this->service->rollbackLatest($pdo, $importId, $_SESSION);
            $_SESSION['flash_success'] = sprintf('Загрузка ФЭО #%d откатана. Удалено новых заявок: %d, восстановлено: %d. При необходимости её можно вернуть из истории загрузок.', $importId, (int)$result['deleted'], (int)$result['restored']);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Откат ФЭО не выполнен: ' . $e->getMessage();
        }
        redirect_to('/company/feo/requests');
    }

    public function restoreImport(): void
    {
        try {
            [, $pdo] = $this->companyContext();
            if (!$this->service->moduleReady($pdo)) throw new \RuntimeException('Модуль ФЭО не инициализирован.');
            $importId = (int)($_POST['import_id'] ?? 0);
            $result = $this->service->restoreRolledBackImport($pdo, $importId, $_SESSION);
            $_SESSION['flash_success'] = sprintf('Загрузка ФЭО #%d возвращена. Восстановлено новых заявок: %d, повторно применено обновлений: %d.', $importId, (int)$result['inserted'], (int)$result['updated']);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Возврат загрузки ФЭО не выполнен: ' . $e->getMessage();
        }
        redirect_to('/company/feo/requests');
    }

    public function deleteImport(): void
    {
        try {
            [, $pdo] = $this->companyContext();
            if (!$this->service->moduleReady($pdo)) throw new \RuntimeException('Модуль ФЭО не инициализирован.');
            $importId = (int)($_POST['import_id'] ?? 0);
            $result = $this->service->deleteLatestImport($pdo, $importId, $_SESSION);
            $_SESSION['flash_success'] = !empty($result['was_applied'])
                ? sprintf('Загрузка ФЭО #%d полностью удалена. Её изменения отменены: удалено новых заявок %d, восстановлено %d.', $importId, (int)$result['deleted'], (int)$result['restored'])
                : sprintf('Откатанная загрузка ФЭО #%d полностью удалена из истории вместе с журналом изменений.', $importId);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Удаление загрузки ФЭО не выполнено: ' . $e->getMessage();
        }
        redirect_to('/company/feo/requests');
    }

    private function detailPayload(array $row): array
    {
        $values = [];
        foreach (FeoXlsxParser::fieldLabels() as $field => $label) {
            $values[] = [
                'field'=>$field,
                'label'=>$label,
                'value'=>$row[$field] ?? null,
                'editable'=>$field !== 'zayavka_id',
            ];
        }
        return ['id'=>(int)$row['id'],'zayavka_id'=>$row['zayavka_id'],'fields'=>$values];
    }

    private function companyContext(): array
    {
        $companyId = $this->service->getCompanyId();
        if ($companyId <= 0) throw new \RuntimeException('Компания не выбрана.');
        $company = $this->service->loadCompany($companyId);
        if (!$company) throw new \RuntimeException('Компания не найдена.');
        if (($company['status'] ?? '') !== 'active') throw new \RuntimeException('Компания недоступна.');
        return [$company, $this->service->localPdo($company)];
    }

    private function previewDir(int $companyId): string
    {
        $dir = storage_path('tmp/feo-imports/' . $companyId);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Не удалось создать временный каталог импорта ФЭО.');
        return $dir;
    }

    private function cleanupPreviews(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.xlsx') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 7200) @unlink($file);
        }
        if (!isset($_SESSION['feo_import_previews']) || !is_array($_SESSION['feo_import_previews'])) {
            $_SESSION['feo_import_previews'] = [];
            return;
        }
        foreach ($_SESSION['feo_import_previews'] as $token => $preview) {
            if (!is_array($preview) || (int)($preview['created_at'] ?? 0) < time() - 7200) unset($_SESSION['feo_import_previews'][$token]);
        }
    }
}
