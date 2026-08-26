<?php

require_once base_path('app/Service/FeoXlsxParser.php');
require_once base_path('app/Service/FeoRequestService.php');
require_once base_path('app/Service/FeoRequestInteractionService.php');
require_once base_path('app/Service/FeoPlacementInputParser.php');
require_once base_path('app/Service/FeoPlacementService.php');
require_once base_path('app/Support/feo_freshness_navigation.php');
require_once base_path('app/Http/Controllers/Company/FeoRequestsController.php');
require_once base_path('app/Http/Controllers/Company/FeoPlacementController.php');

registerFeoFreshnessNavigation($config, $db);

$feoController = new \App\Http\Controllers\Company\FeoRequestsController($config, $db);
$router->get('/company/feo/requests', [$feoController, 'index']);
$router->get('/company/feo/requests/data', [$feoController, 'data']);
$router->get('/company/feo/requests/{id}/json', [$feoController, 'detail']);
$router->post('/company/feo/requests/{id}/update', [$feoController, 'update']);
$router->post('/company/feo/requests/import/analyze', [$feoController, 'analyzeImport']);
$router->post('/company/feo/requests/import/commit', [$feoController, 'commitImport']);
$router->post('/company/feo/requests/import/rollback', [$feoController, 'rollbackImport']);
$router->post('/company/feo/requests/import/restore', [$feoController, 'restoreImport']);
$router->post('/company/feo/requests/import/delete', [$feoController, 'deleteImport']);

$feoPlacementController = new \App\Http\Controllers\Company\FeoPlacementController($config, $db);
$router->get('/company/feo/requests/map-placement', [$feoPlacementController, 'index']);
$router->post('/company/feo/requests/map-placement/analyze', static function () use ($feoPlacementController, $config, $db): void {
    requireRole(['company_owner','senior_logist','logist']);
    try {
        $sourceMode = (string)($_POST['source_mode'] ?? 'manual');
        $blockType = (string)($_POST['block_type'] ?? '');
        if (!in_array($sourceMode, ['manual','file'], true)) throw new \RuntimeException('Выберите способ заполнения блока.');
        if (!in_array($blockType, ['addition','exclusion'], true)) throw new \RuntimeException('Выберите тип блока.');

        $parser = new \App\Service\FeoPlacementInputParser();
        if ($sourceMode === 'manual') {
            $parsed = $parser->parseManual((string)($_POST['request_ids'] ?? ''));
        } else {
            if (!isset($_FILES['source_file']) || !is_array($_FILES['source_file']) || ($_FILES['source_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Выберите файл XLS или XLSX.');
            }
            $file = $_FILES['source_file'];
            $tmp = (string)($file['tmp_name'] ?? '');
            $name = basename((string)($file['name'] ?? ''));
            $size = (int)($file['size'] ?? 0);
            if (!is_uploaded_file($tmp) || $size <= 0 || $size > ERP_MAX_FILE_SIZE) throw new \RuntimeException('Файл не прошёл проверку или превышает 20 МБ.');
            if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['xls','xlsx'], true)) throw new \RuntimeException('Допустимы только файлы XLS и XLSX.');
            $parsed = $parser->parseFile($tmp, $name);
        }
        if (!empty($parsed['duplicates'])) {
            throw new \RuntimeException('Заявки повторяются внутри блока: ' . implode(', ', array_slice($parsed['duplicates'], 0, 50)) . (count($parsed['duplicates']) > 50 ? ' и ещё ' . (count($parsed['duplicates']) - 50) : '') . '. Уберите повторы и повторите проверку.');
        }

        $requests = new \App\Service\FeoRequestService($config, $db);
        $companyId = $requests->getCompanyId();
        if ($companyId <= 0) throw new \RuntimeException('Компания не выбрана.');
        $company = $requests->loadCompany($companyId);
        if (!$company || ($company['status'] ?? '') !== 'active') throw new \RuntimeException('Компания недоступна.');
        $pdo = $requests->localPdo($company);
        $placement = new \App\Service\FeoPlacementService();
        if (!$placement->moduleReady($pdo)) throw new \RuntimeException('Модуль «Размещение на карте» ещё не инициализирован.');
        $preview = $placement->previewCreate($pdo, $parsed['ids'], $blockType);
        if (!empty($preview['repeat_ids'])) {
            $reason = $blockType === 'addition' ? 'уже находятся в актуальном составе' : 'уже отсутствуют в актуальном составе';
            throw new \RuntimeException('Повторная операция запрещена: заявки ' . implode(', ', array_slice($preview['repeat_ids'], 0, 50)) . (count($preview['repeat_ids']) > 50 ? ' и ещё ' . (count($preview['repeat_ids']) - 50) : '') . ' ' . $reason . '.');
        }
    } catch (\Throwable $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        return;
    }
    $feoPlacementController->analyze();
});
$router->post('/company/feo/requests/map-placement/commit', [$feoPlacementController, 'commitCreate']);
$router->get('/company/feo/requests/map-placement/current', [$feoPlacementController, 'current']);
$router->get('/company/feo/requests/map-placement/blocks/{id}/json', [$feoPlacementController, 'detail']);
$router->get('/company/feo/requests/map-placement/blocks/{id}/download', [$feoPlacementController, 'download']);
$router->post('/company/feo/requests/map-placement/blocks/{id}/preview-status', [$feoPlacementController, 'previewStatus']);
$router->post('/company/feo/requests/map-placement/status/commit', [$feoPlacementController, 'commitStatus']);
$router->post('/company/feo/requests/map-placement/reconcile/preview', [$feoPlacementController, 'previewReconcile']);
$router->post('/company/feo/requests/map-placement/reconcile/commit', [$feoPlacementController, 'commitReconcile']);
