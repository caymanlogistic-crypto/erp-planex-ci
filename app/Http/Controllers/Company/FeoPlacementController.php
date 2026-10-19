<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Service\FeoLegacyParityService;
use App\Service\FeoPlacementAuthorService;
use App\Service\FeoPlacementInputParser;
use App\Service\FeoPlacementService;
use App\Service\FeoPlacementRouteNotificationService;
use App\Service\FeoRequestService;
use App\Service\MaxEventNotificationService;

final class FeoPlacementController
{
    private FeoPlacementService $placement;
    private FeoPlacementInputParser $parser;
    private FeoRequestService $requests;

    public function __construct(private readonly array $config, private readonly Database $db)
    {
        $this->placement = new FeoPlacementService();
        $this->parser = new FeoPlacementInputParser();
        $this->requests = new FeoRequestService($config, $db);
    }

    public function index(): void
    {
        $pageStylesheets[] = '/assets/css/registry-components.css';
        $pageScripts[] = '/assets/js/registry-components.js';
        $pageTitle = 'Размещение на карте';
        $pageContext = 'Заявки ФЭО › Размещение на карте';
        $company = null;
        $dbError = null;
        $blocks = [];
        $current = ['request_count'=>0,'total_mass_kg'=>0,'missing_request_count'=>0];
        $consistency = ['is_consistent'=>true,'expected_count'=>0,'actual_count'=>0];
        $legacyParity = ['available'=>false,'source_error'=>true,'mismatch_count'=>0,'message'=>'Сверка со старой базой недоступна.'];
        $successFlash = $_SESSION['flash_success'] ?? null;
        $errorFlash = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        try {
            [$company,$pdo] = $this->companyContext();
            if (!$this->placement->moduleReady($pdo)) throw new \RuntimeException('Модуль «Размещение на карте» ещё не инициализирован в базе компании.');
            $state = $this->placement->pageState($pdo);
            $blocks = (new FeoPlacementAuthorService($this->db->connection()))->decorate($pdo, (int)$company['id'], $state['blocks']);
            $current = $state['current'];
            $consistency = $state['consistency'];
            $legacyParity = (new FeoLegacyParityService())->state($pdo);
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        if (is_array($company)) {
            $topbarCrumbs = [
                ['label'=>mb_strtoupper((string)($company['name'] ?? 'Компания')),'url'=>app_url('/company/dashboard')],
                ['label'=>'Заявки ФЭО','url'=>app_url('/company/feo/requests')],
                ['label'=>'Размещение на карте','url'=>null],
            ];
        }
        $config = $this->config;
        $db = $this->db;
        ob_start();
        require base_path('app/View/pages/company_feo_map_placement.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function analyze(): void
    {
        $moved = null;
        try {
            [$company,$pdo] = $this->companyContext();
            if (!$this->placement->moduleReady($pdo)) throw new \RuntimeException('Модуль не инициализирован.');
            $this->cleanupPreviews((int)$company['id']);
            $blockType = (string)($_POST['block_type'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $sourceMode = (string)($_POST['source_mode'] ?? 'manual');
            if (!in_array($sourceMode, ['manual','file'], true)) throw new \RuntimeException('Выберите способ заполнения блока.');

            $sourceType = 'manual';
            $sourceFilename = null;
            $tempPath = null;
            if ($sourceMode === 'manual') {
                $parsed = $this->parser->parseManual((string)($_POST['request_ids'] ?? ''));
            } else {
                if (!isset($_FILES['source_file']) || !is_array($_FILES['source_file']) || ($_FILES['source_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new \RuntimeException('Выберите файл XLS или XLSX.');
                }
                $file = $_FILES['source_file'];
                $tmp = (string)($file['tmp_name'] ?? '');
                $sourceFilename = basename((string)($file['name'] ?? ''));
                $size = (int)($file['size'] ?? 0);
                if (!is_uploaded_file($tmp) || $size <= 0 || $size > ERP_MAX_FILE_SIZE) throw new \RuntimeException('Файл не прошёл проверку или превышает 20 МБ.');
                $ext = strtolower(pathinfo($sourceFilename, PATHINFO_EXTENSION));
                if (!in_array($ext, ['xls','xlsx'], true)) throw new \RuntimeException('Допустимы только файлы XLS и XLSX.');
                $sourceType = $ext;
                $parsed = $this->parser->parseFile($tmp, $sourceFilename);
                $dir = $this->previewDir((int)$company['id']);
                $tempPath = $dir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24)) . '.' . $ext;
                if (!move_uploaded_file($tmp, $tempPath)) throw new \RuntimeException('Не удалось сохранить временную копию файла.');
                @chmod($tempPath, 0600);
                $moved = $tempPath;
            }

            $preview = $this->placement->previewCreate($pdo, $parsed['ids'], $blockType);
            $token = bin2hex(random_bytes(24));
            $_SESSION['feo_placement_previews'][$token] = [
                'kind'=>'create','company_id'=>(int)$company['id'],'created_at'=>time(),
                'name'=>$name,'block_type'=>$blockType,'ids'=>$parsed['ids'],'duplicates'=>$parsed['duplicates'],
                'source_type'=>$sourceType,'source_filename'=>$sourceFilename,'temp_path'=>$tempPath,
                'state_hash'=>$preview['state_hash'],
            ];
            $moved = null;
            jsonResponse(['success'=>true,'token'=>$token,'preview'=>$preview,'duplicates'=>$parsed['duplicates'],'source_filename'=>$sourceFilename]);
        } catch (\Throwable $e) {
            if ($moved && is_file($moved)) @unlink($moved);
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function commitCreate(): void
    {
        $finalPath = null;
        try {
            [$company,$pdo] = $this->companyContext();
            $token = trim((string)($_POST['preview_token'] ?? ''));
            $preview = $this->previewRecord($token, (int)$company['id'], 'create');
            $fileKey = null;
            if (!empty($preview['temp_path'])) {
                $temp = (string)$preview['temp_path'];
                if (!is_file($temp)) throw new \RuntimeException('Временный файл блока не найден. Выполните проверку повторно.');
                $ext = (string)$preview['source_type'];
                $fileKey = bin2hex(random_bytes(24)) . '.' . $ext;
                $dir = $this->storageDir($company);
                $finalPath = $dir . DIRECTORY_SEPARATOR . $fileKey;
                if (!rename($temp, $finalPath)) throw new \RuntimeException('Не удалось сохранить исходный файл блока.');
                @chmod($finalPath, 0600);
            }
            $creator = FeoPlacementAuthorService::creatorFromSession($_SESSION);
            $notifications = new MaxEventNotificationService($pdo);
            $deliveryId = null;
            $supplementalIds = [];
            $notificationPreparationError = null;
            $centralPdo = $this->db->connection();
            $routeNotifications = new FeoPlacementRouteNotificationService();
            $result = $this->placement->createBlock($pdo, [
                'name'=>$preview['name'],'block_type'=>$preview['block_type'],'ids'=>$preview['ids'],
                'source_type'=>$preview['source_type'],'source_filename'=>$preview['source_filename'],'source_file_key'=>$fileKey,
            ] + $creator, (string)$preview['state_hash'], function (array $created) use (
                $notifications, &$deliveryId, &$supplementalIds, &$notificationPreparationError,
                $company, $pdo, $centralPdo, $creator, $preview, $routeNotifications
            ): void {
                try {
                    $author = (new FeoPlacementAuthorService($centralPdo))->decorate($pdo, (int)$company['id'], [$creator])[0];
                    $deliveryId = $notifications->enqueue('feo.placement.block_created', (string)$created['block_id'], [
                        'action_title' => $preview['block_type'] === 'addition' ? 'ДОБАВЛЕНО НА КАРТУ' : 'СНЯТИЕ ЗАЯВОК',
                        'block_id' => (string)$created['block_id'],
                        'block_name' => trim((string)$preview['name']) ?: 'Без названия',
                        'block_type' => $preview['block_type'] === 'addition' ? 'Добавление' : 'Исключение',
                        'request_count' => (string)$created['preview']['selected_count'],
                        'mass_kg' => FeoPlacementRouteNotificationService::massText((float)$created['preview']['selected_mass_kg']),
                        'request_ids' => implode(', ', array_map('strval', (array)$preview['ids'])),
                        'author' => $author['created_by_display_name'],
                        'company' => (string)$company['name'],
                        'created_at' => date('d.m.Y H:i'),
                        'current_count' => (string)$created['current']['request_count'],
                        'current_mass_kg' => FeoPlacementRouteNotificationService::massText((float)$created['current']['total_mass_kg']),
                    ], (int)$creator['created_by_user_id']);
                    if ($deliveryId === null) return;

                    $intents = $routeNotifications->intents(
                        $pdo, $centralPdo, (int)$company['id'], (string)$preview['block_type'], (array)$preview['ids']
                    );
                    foreach ($intents as $intent) {
                        $entityKey = sprintf(
                            'b%d:%s:r%d:p%d',
                            (int)$created['block_id'], (string)$intent['kind'], (int)$intent['route_id'], (int)$intent['part']
                        );
                        $id = $notifications->enqueuePrepared(
                            'feo.placement.block_created', $entityKey, (string)$intent['text'],
                            (string)$intent['format'], (int)$creator['created_by_user_id'],
                            isset($intent['error']) ? (string)$intent['error'] : null
                        );
                        if ($id !== null) $supplementalIds[] = $id;
                    }
                } catch (\Throwable $notificationError) {
                    $notificationPreparationError = $notificationError->getMessage();
                    error_log('MAX FEO preparation: ' . $notificationPreparationError);
                    try {
                        $diagnosticId = $notifications->enqueuePrepared(
                            'feo.placement.block_created',
                            sprintf('b%d:analysis:r0:p1', (int)$created['block_id']),
                            '',
                            'markdown',
                            (int)$creator['created_by_user_id'],
                            'Не удалось подготовить адресные уведомления: ' . mb_substr($notificationPreparationError, 0, 1500)
                        );
                        if ($diagnosticId !== null) $supplementalIds[] = $diagnosticId;
                    } catch (\Throwable $diagnosticError) {
                        error_log('MAX FEO diagnostic: ' . $diagnosticError->getMessage());
                    }
                }
            });
            $finalPath = null; // The source now belongs to the committed block.
            unset($_SESSION['feo_placement_previews'][$token]);
            $_SESSION['flash_success'] = sprintf('Блок #%d применён. Актуальный состав: %d заявок, %s кг.', (int)$result['block_id'], (int)$result['current']['request_count'], $this->massText((float)$result['current']['total_mass_kg']));
            if ($notificationPreparationError !== null) {
                $_SESSION['flash_error'] = 'Блок сохранён. Не удалось подготовить MAX-уведомления; проверьте журнал приложения и настройки MAX.';
            } elseif ($deliveryId !== null) {
                try {
                    $delivery = $notifications->dispatch($deliveryId);
                    if ($delivery['status'] === 'sent') {
                        $supplementalFailed = false;
                        foreach ($supplementalIds as $supplementalId) {
                            $supplemental = $notifications->dispatch($supplementalId);
                            if (($supplemental['status'] ?? '') !== 'sent') $supplementalFailed = true;
                        }
                        if ($supplementalFailed) {
                            $_SESSION['flash_error'] = 'Блок сохранён. Часть адресных уведомлений MAX не доставлена; подробности — в настройках MAX, вкладка «События».';
                        }
                    } else {
                        $notifications->failPending($supplementalIds, 'Основное уведомление блока не доставлено. Сначала повторите его отправку.');
                        $_SESSION['flash_error'] = 'Блок сохранён. Основное уведомление MAX не доставлено; адресные уведомления не отправлялись.';
                    }
                } catch (\Throwable $notificationError) {
                    $notifications->failPending($supplementalIds, 'Основное уведомление блока не доставлено. Сначала повторите его отправку.');
                    error_log('MAX delivery #' . $deliveryId . ': ' . $notificationError->getMessage());
                    $_SESSION['flash_error'] = 'Блок сохранён. Проверьте отправку уведомления в настройках MAX.';
                }
            }
            jsonResponse(['success'=>true] + $result);
        } catch (\Throwable $e) {
            if ($finalPath && is_file($finalPath)) @unlink($finalPath);
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function detail(string $id): void
    {
        try {
            [, $pdo] = $this->companyContext();
            $block = $this->placement->block($pdo, (int)$id);
            if (!$block) { jsonResponse(['success'=>false,'message'=>'Блок не найден.'], 404); return; }
            jsonResponse(['success'=>true,'block'=>$block]);
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function current(): void
    {
        try {
            [, $pdo] = $this->companyContext();
            jsonResponse(['success'=>true,'rows'=>$this->placement->currentRows($pdo, 20000),'summary'=>$this->placement->currentSummary($pdo)]);
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function previewStatus(string $id): void
    {
        try {
            [$company,$pdo] = $this->companyContext();
            $target = (string)($_POST['target_status'] ?? '');
            $preview = $this->placement->previewStatusChange($pdo, (int)$id, $target);
            $token = bin2hex(random_bytes(24));
            $_SESSION['feo_placement_previews'][$token] = [
                'kind'=>'status','company_id'=>(int)$company['id'],'created_at'=>time(),
                'block_id'=>(int)$id,'target_status'=>$target,'state_hash'=>$preview['state_hash'],
            ];
            jsonResponse(['success'=>true,'token'=>$token,'preview'=>$preview]);
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function commitStatus(): void
    {
        try {
            [$company,$pdo] = $this->companyContext();
            $token = trim((string)($_POST['preview_token'] ?? ''));
            $preview = $this->previewRecord($token, (int)$company['id'], 'status');
            $result = $this->placement->changeStatus($pdo, (int)$preview['block_id'], (string)$preview['target_status'], (string)$preview['state_hash']);
            unset($_SESSION['feo_placement_previews'][$token]);
            $_SESSION['flash_success'] = sprintf('Статус блока изменён. Актуальный состав: %d заявок, %s кг.', (int)$result['current']['request_count'], $this->massText((float)$result['current']['total_mass_kg']));
            jsonResponse(['success'=>true] + $result);
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function previewReconcile(): void
    {
        try {
            [$company,$pdo] = $this->companyContext();
            $preview = $this->placement->previewReconcile($pdo);
            if (!empty($preview['is_consistent'])) throw new \RuntimeException('Актуальный состав уже согласован с блоками.');
            $token = bin2hex(random_bytes(24));
            $_SESSION['feo_placement_previews'][$token] = [
                'kind'=>'reconcile','company_id'=>(int)$company['id'],'created_at'=>time(),'state_hash'=>$preview['state_hash'],
            ];
            jsonResponse(['success'=>true,'token'=>$token,'preview'=>$preview]);
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function commitReconcile(): void
    {
        try {
            [$company,$pdo] = $this->companyContext();
            $token = trim((string)($_POST['preview_token'] ?? ''));
            $preview = $this->previewRecord($token, (int)$company['id'], 'reconcile');
            $result = $this->placement->reconcile($pdo, (string)$preview['state_hash']);
            unset($_SESSION['feo_placement_previews'][$token]);
            $_SESSION['flash_success'] = sprintf('Актуальный состав пересчитан: %d заявок, %s кг.', (int)$result['current']['request_count'], $this->massText((float)$result['current']['total_mass_kg']));
            jsonResponse(['success'=>true] + $result);
        } catch (\Throwable $e) {
            jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        }
    }

    public function download(string $id): void
    {
        try {
            [$company,$pdo] = $this->companyContext();
            $block = $this->placement->block($pdo, (int)$id);
            if (!$block || empty($block['source_file_key']) || empty($block['source_filename'])) throw new \RuntimeException('У блока нет сохранённого исходного файла.');
            $key = (string)$block['source_file_key'];
            if ($key !== basename($key)) throw new \RuntimeException('Некорректный путь исходного файла.');
            $path = $this->storageDir($company) . DIRECTORY_SEPARATOR . $key;
            if (!is_file($path) || !is_readable($path)) throw new \RuntimeException('Исходный файл не найден в хранилище.');
            $name = basename((string)$block['source_filename']);
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . (string)filesize($path));
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name));
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;
        } catch (\Throwable $e) {
            http_response_code(404);
            echo e($e->getMessage());
        }
    }

    private function companyContext(): array
    {
        $companyId = $this->requests->getCompanyId();
        if ($companyId <= 0) throw new \RuntimeException('Компания не выбрана.');
        $company = $this->requests->loadCompany($companyId);
        if (!$company) throw new \RuntimeException('Компания не найдена.');
        if (($company['status'] ?? '') !== 'active') throw new \RuntimeException('Компания недоступна.');
        return [$company, $this->requests->localPdo($company)];
    }

    private function previewRecord(string $token, int $companyId, string $kind): array
    {
        $record = $_SESSION['feo_placement_previews'][$token] ?? null;
        if (!is_array($record) || ($record['kind'] ?? '') !== $kind || (int)($record['company_id'] ?? 0) !== $companyId) throw new \RuntimeException('Подтверждение устарело. Выполните проверку ещё раз.');
        if ((int)($record['created_at'] ?? 0) < time() - 7200) throw new \RuntimeException('Подтверждение устарело. Выполните проверку ещё раз.');
        return $record;
    }

    private function previewDir(int $companyId): string
    {
        $dir = storage_path('tmp/feo-placement/' . $companyId);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Не удалось создать временный каталог.');
        return $dir;
    }

    private function storageDir(array $company): string
    {
        $dir = company_storage_path($company['storage_path'] ?? null, (int)$company['id']) . DIRECTORY_SEPARATOR . 'feo-placement';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Не удалось создать каталог файлов размещения ФЭО.');
        return $dir;
    }

    private function cleanupPreviews(int $companyId): void
    {
        $dir = storage_path('tmp/feo-placement/' . $companyId);
        if (is_dir($dir)) foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file) && filemtime($file) < time() - 7200) @unlink($file);
        if (!isset($_SESSION['feo_placement_previews']) || !is_array($_SESSION['feo_placement_previews'])) { $_SESSION['feo_placement_previews'] = []; return; }
        foreach ($_SESSION['feo_placement_previews'] as $token => $record) {
            if (!is_array($record) || (int)($record['created_at'] ?? 0) >= time() - 7200) continue;
            $temp = (string)($record['temp_path'] ?? '');
            if ($temp !== '' && is_file($temp)) @unlink($temp);
            unset($_SESSION['feo_placement_previews'][$token]);
        }
    }

    private function massText(float $kg): string
    {
        return rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.');
    }
}
