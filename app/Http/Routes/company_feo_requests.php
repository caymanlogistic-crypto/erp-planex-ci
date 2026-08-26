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

$feoPlacementCompanyContext = static function () use ($config, $db): array {
    $requests = new \App\Service\FeoRequestService($config, $db);
    $companyId = $requests->getCompanyId();
    if ($companyId <= 0) throw new \RuntimeException('Компания не выбрана.');
    $company = $requests->loadCompany($companyId);
    if (!$company || ($company['status'] ?? '') !== 'active') throw new \RuntimeException('Компания недоступна.');
    $pdo = $requests->localPdo($company);
    $placement = new \App\Service\FeoPlacementService();
    if (!$placement->moduleReady($pdo)) throw new \RuntimeException('Модуль «Размещение на карте» ещё не инициализирован.');
    return [$company, $pdo, $placement];
};

$feoPlacementRegistryState = static function (\PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_map('strval', $ids)));
    $exists = [];
    $current = [];
    foreach (array_chunk($ids, 800) as $chunk) {
        if ($chunk === []) continue;
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $q = $pdo->prepare("SELECT zayavka_id FROM feo_requests WHERE zayavka_id IN ({$marks})");
        $q->execute($chunk);
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) $exists[(string)$id] = true;

        $q = $pdo->prepare("SELECT zayavka_id,source_block_id FROM feo_placement_current WHERE zayavka_id IN ({$marks})");
        $q->execute($chunk);
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) $current[(string)$row['zayavka_id']] = (int)$row['source_block_id'];
    }
    return ['exists'=>$exists, 'current'=>$current];
};

$feoPlacementMemberships = static function (\PDO $pdo, array $ids, bool $activeOnly): array {
    $ids = array_values(array_unique(array_map('strval', $ids)));
    $out = [];
    foreach ($ids as $id) $out[$id] = [];
    foreach (array_chunk($ids, 800) as $chunk) {
        if ($chunk === []) continue;
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT br.zayavka_id,b.id block_id,b.name,b.block_type,b.status
                  FROM feo_placement_block_requests br
                  JOIN feo_placement_blocks b ON b.id=br.block_id
                 WHERE br.zayavka_id IN ({$marks})";
        if ($activeOnly) $sql .= " AND b.status='applied'";
        $sql .= ' ORDER BY b.id ASC';
        $q = $pdo->prepare($sql);
        $q->execute($chunk);
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $requestId = (string)$row['zayavka_id'];
            $out[$requestId][] = [
                'id'=>(int)$row['block_id'],
                'name'=>(string)($row['name'] ?? ''),
                'block_type'=>(string)$row['block_type'],
                'status'=>(string)$row['status'],
            ];
        }
    }
    return $out;
};

$feoPlacementRepeatMessage = static function (array $repeatIds, array $memberships, string $blockType): string {
    $parts = [];
    foreach ($repeatIds as $requestId) {
        $blocks = $memberships[$requestId] ?? [];
        if ($blocks !== []) {
            $blockLabels = [];
            foreach ($blocks as $block) {
                $name = trim((string)($block['name'] ?? ''));
                $type = ($block['block_type'] ?? '') === 'addition' ? 'Добавление' : 'Исключение';
                $blockLabels[] = '#' . (int)$block['id'] . ($name !== '' ? ' «' . $name . '»' : '') . ' (' . $type . ')';
            }
            $parts[] = 'заявка ' . $requestId . ' — активные блоки: ' . implode(', ', $blockLabels);
        } else {
            $parts[] = 'заявка ' . $requestId . ($blockType === 'addition'
                ? ' уже находится в актуальном составе'
                : ' отсутствует в актуальном составе');
        }
    }
    return 'Повторная операция запрещена: ' . implode('; ', $parts) . '.';
};

$feoPlacementController = new \App\Http\Controllers\Company\FeoPlacementController($config, $db);
$router->get('/company/feo/requests/map-placement', [$feoPlacementController, 'index']);

$router->post('/company/feo/requests/map-placement/validate-manual', static function () use (
    $feoPlacementCompanyContext,
    $feoPlacementRegistryState,
    $feoPlacementMemberships
): void {
    requireRole(['company_owner','senior_logist','logist']);
    try {
        $blockType = (string)($_POST['block_type'] ?? '');
        if (!in_array($blockType, ['addition','exclusion'], true)) throw new \RuntimeException('Выберите тип блока.');
        $parser = new \App\Service\FeoPlacementInputParser();
        $parsed = $parser->parseManual((string)($_POST['request_ids'] ?? ''));
        [, $pdo] = $feoPlacementCompanyContext();
        $state = $feoPlacementRegistryState($pdo, $parsed['ids']);
        $missing = [];
        $repeat = [];
        foreach ($parsed['ids'] as $id) {
            if (!isset($state['exists'][$id])) $missing[] = $id;
            $isCurrent = isset($state['current'][$id]);
            if (($blockType === 'addition' && $isCurrent) || ($blockType === 'exclusion' && !$isCurrent)) $repeat[] = $id;
        }
        $memberships = $feoPlacementMemberships($pdo, $repeat, true);
        jsonResponse([
            'success'=>true,
            'valid'=>$parsed['duplicates'] === [] && $missing === [] && $repeat === [],
            'ids'=>$parsed['ids'],
            'duplicates'=>$parsed['duplicates'],
            'missing_ids'=>$missing,
            'repeat_ids'=>$repeat,
            'repeat_blocks'=>$memberships,
        ]);
    } catch (\Throwable $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
    }
});

$router->get('/company/feo/requests/map-placement/search-blocks', static function () use (
    $feoPlacementCompanyContext,
    $feoPlacementRegistryState,
    $feoPlacementMemberships
): void {
    requireRole(['company_owner','senior_logist','logist']);
    try {
        $parser = new \App\Service\FeoPlacementInputParser();
        $parsed = $parser->parseManual((string)($_GET['request_ids'] ?? ''));
        [, $pdo] = $feoPlacementCompanyContext();
        $state = $feoPlacementRegistryState($pdo, $parsed['ids']);
        $memberships = $feoPlacementMemberships($pdo, $parsed['ids'], false);
        $results = [];
        foreach ($parsed['ids'] as $id) {
            $results[] = [
                'zayavka_id'=>$id,
                'exists_in_feo'=>isset($state['exists'][$id]),
                'is_current'=>isset($state['current'][$id]),
                'current_source_block_id'=>$state['current'][$id] ?? null,
                'blocks'=>$memberships[$id] ?? [],
            ];
        }
        jsonResponse(['success'=>true,'duplicates'=>$parsed['duplicates'],'results'=>$results]);
    } catch (\Throwable $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
    }
});

$router->post('/company/feo/requests/map-placement/analyze', static function () use (
    $feoPlacementController,
    $feoPlacementCompanyContext,
    $feoPlacementMemberships,
    $feoPlacementRepeatMessage
): void {
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

        [, $pdo, $placement] = $feoPlacementCompanyContext();
        $preview = $placement->previewCreate($pdo, $parsed['ids'], $blockType);
        if (!empty($preview['repeat_ids'])) {
            $memberships = $feoPlacementMemberships($pdo, $preview['repeat_ids'], true);
            throw new \RuntimeException($feoPlacementRepeatMessage($preview['repeat_ids'], $memberships, $blockType));
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
