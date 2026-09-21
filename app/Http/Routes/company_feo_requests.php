<?php

require_once base_path('app/Service/FeoXlsxParser.php');
require_once base_path('app/Service/FeoRequestService.php');
require_once base_path('app/Service/FeoRequestInteractionService.php');
require_once base_path('app/Service/FeoWeightChangeNotificationService.php');
require_once base_path('app/Service/FeoPlacementInputParser.php');
require_once base_path('app/Service/FeoPlacementService.php');
require_once base_path('app/Service/FeoMapService.php');
require_once base_path('app/Service/WasteTripService.php');
require_once base_path('app/Service/FeoPlacementRouteNotificationService.php');
require_once base_path('app/Service/FeoPlacementAuthorService.php');
require_once base_path('app/Service/FeoLegacyParityService.php');
require_once base_path('app/Http/Controllers/Company/FeoRequestsController.php');
require_once base_path('app/Http/Controllers/Company/FeoPlacementController.php');

// Registry navigation owns FEO links and badges when effective access is active.
// Keep the historical DOM helper only as a feature-off compatibility fallback.
if (!class_exists(\App\Security\AccessRuntime::class) || !\App\Security\AccessRuntime::current()->isActive()) {
    require_once base_path('app/Support/feo_freshness_navigation.php');
    registerFeoFreshnessNavigation($config, $db);
}

$feoController = new \App\Http\Controllers\Company\FeoRequestsController($config, $db);
$feoRequestsPage = 'company.feo.requests';
$feoPlacementPage = 'company.feo.map_placement';
$router->get('/company/feo/requests', [$feoController, 'index'], $feoRequestsPage);
$router->get('/company/feo/requests/data', [$feoController, 'data'], $feoRequestsPage);
$router->get('/company/feo/requests/{id}/json', [$feoController, 'detail'], $feoRequestsPage);
$router->post('/company/feo/requests/{id}/update', [$feoController, 'update'], $feoRequestsPage);
$router->post('/company/feo/requests/import/analyze', [$feoController, 'analyzeImport'], $feoRequestsPage);
$router->post('/company/feo/requests/import/commit', [$feoController, 'commitImport'], $feoRequestsPage);
$router->post('/company/feo/requests/import/rollback', [$feoController, 'rollbackImport'], $feoRequestsPage);
$router->post('/company/feo/requests/import/restore', [$feoController, 'restoreImport'], $feoRequestsPage);
$router->post('/company/feo/requests/import/delete', [$feoController, 'deleteImport'], $feoRequestsPage);

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

$feoPlacementMemberships = static function (\PDO $pdo, array $ids, bool $activeOnly, bool $newestFirst = false): array {
    $ids = array_values(array_unique(array_map('strval', $ids)));
    $out = [];
    foreach ($ids as $id) $out[$id] = [];
    foreach (array_chunk($ids, 800) as $chunk) {
        if ($chunk === []) continue;
        $marks = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT br.zayavka_id,b.id block_id,b.name,b.block_type,b.status,b.created_at
                  FROM feo_placement_block_requests br
                  JOIN feo_placement_blocks b ON b.id=br.block_id
                 WHERE br.zayavka_id IN ({$marks})";
        if ($activeOnly) $sql .= " AND b.status='applied'";
        $sql .= $newestFirst ? ' ORDER BY b.id DESC' : ' ORDER BY b.id ASC';
        $q = $pdo->prepare($sql);
        $q->execute($chunk);
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $requestId = (string)$row['zayavka_id'];
            $out[$requestId][] = [
                'id'=>(int)$row['block_id'],
                'name'=>(string)($row['name'] ?? ''),
                'block_type'=>(string)$row['block_type'],
                'status'=>(string)$row['status'],
                'created_at'=>(string)($row['created_at'] ?? ''),
            ];
        }
    }
    return $out;
};

$feoPlacementPreviewRecord = static function (string $token, int $companyId, string $kind): array {
    $record = $_SESSION['feo_placement_previews'][$token] ?? null;
    if (!is_array($record) || ($record['kind'] ?? '') !== $kind || (int)($record['company_id'] ?? 0) !== $companyId) {
        throw new \RuntimeException('Подтверждение устарело. Выполните операцию ещё раз.');
    }
    if ((int)($record['created_at'] ?? 0) < time() - 7200) {
        throw new \RuntimeException('Подтверждение устарело. Выполните операцию ещё раз.');
    }
    return $record;
};

$feoPlacementController = new \App\Http\Controllers\Company\FeoPlacementController($config, $db);
$router->get('/company/feo/requests/map-placement', [$feoPlacementController, 'index'], $feoPlacementPage);

$router->post('/company/feo/requests/map-placement/validate-manual', static function () use (
    $feoPlacementCompanyContext,
    $feoPlacementRegistryState,
    $feoPlacementMemberships
): void {
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
            'block_type'=>$blockType,
        ]);
    } catch (\Throwable $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
    }
}, $feoPlacementPage);

$router->get('/company/feo/requests/map-placement/search-blocks', static function () use (
    $feoPlacementCompanyContext,
    $feoPlacementRegistryState,
    $feoPlacementMemberships
): void {
    try {
        $parser = new \App\Service\FeoRequestInteractionService();
        $ids = $parser->extractRequestIds((string)($_GET['request_ids'] ?? ''));
        if ($ids === []) throw new \RuntimeException('Не найдено самостоятельных шестизначных номеров заявок.');
        [, $pdo] = $feoPlacementCompanyContext();
        $state = $feoPlacementRegistryState($pdo, $ids);
        $memberships = $feoPlacementMemberships($pdo, $ids, false, true);
        $results = [];
        foreach ($ids as $id) {
            $results[] = [
                'zayavka_id'=>$id,
                'exists_in_feo'=>isset($state['exists'][$id]),
                'is_current'=>isset($state['current'][$id]),
                'current_source_block_id'=>$state['current'][$id] ?? null,
                'blocks'=>$memberships[$id] ?? [],
            ];
        }
        jsonResponse(['success'=>true,'duplicates'=>[],'results'=>$results]);
    } catch (\Throwable $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
    }
}, $feoPlacementPage);

$router->post('/company/feo/requests/map-placement/analyze', static function () use (
    $feoPlacementController,
    $feoPlacementCompanyContext,
    $feoPlacementMemberships
): void {
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
            jsonResponse([
                'success'=>false,
                'code'=>'repeat_operation',
                'message'=>'Повторная операция запрещена.',
                'repeat_ids'=>$preview['repeat_ids'],
                'repeat_blocks'=>$memberships,
                'block_type'=>$blockType,
            ], 422);
            return;
        }
    } catch (\Throwable $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
        return;
    }
    $feoPlacementController->analyze();
}, $feoPlacementPage);
$router->post('/company/feo/requests/map-placement/commit', [$feoPlacementController, 'commitCreate'], $feoPlacementPage);
$router->get('/company/feo/requests/map-placement/current', [$feoPlacementController, 'current'], $feoPlacementPage);
$router->get('/company/feo/requests/map-placement/blocks/{id}/json', [$feoPlacementController, 'detail'], $feoPlacementPage);
$router->get('/company/feo/requests/map-placement/blocks/{id}/download', [$feoPlacementController, 'download'], $feoPlacementPage);
$router->post('/company/feo/requests/map-placement/blocks/{id}/preview-status', [$feoPlacementController, 'previewStatus'], $feoPlacementPage);
$router->post('/company/feo/requests/map-placement/status/commit', [$feoPlacementController, 'commitStatus'], $feoPlacementPage);

$router->post('/company/feo/requests/map-placement/blocks/{id}/preview-delete', static function (string $id) use ($feoPlacementCompanyContext): void {
    try {
        [$company, $pdo, $placement] = $feoPlacementCompanyContext();
        $preview = $placement->previewDelete($pdo, (int)$id);
        $token = bin2hex(random_bytes(24));
        $_SESSION['feo_placement_previews'][$token] = [
            'kind'=>'delete',
            'company_id'=>(int)$company['id'],
            'created_at'=>time(),
            'block_id'=>(int)$id,
            'state_hash'=>(string)$preview['state_hash'],
        ];
        jsonResponse(['success'=>true,'token'=>$token,'preview'=>$preview]);
    } catch (\Throwable $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
    }
}, $feoPlacementPage);

$router->post('/company/feo/requests/map-placement/delete/commit', static function () use (
    $feoPlacementCompanyContext,
    $feoPlacementPreviewRecord
): void {
    try {
        [$company, $pdo, $placement] = $feoPlacementCompanyContext();
        $token = trim((string)($_POST['preview_token'] ?? ''));
        $record = $feoPlacementPreviewRecord($token, (int)$company['id'], 'delete');
        $deletePassword = trim((string)($_POST['delete_password'] ?? ''));
        if (!hash_equals('7550055', $deletePassword)) throw new \RuntimeException('Неверный пароль удаления.');
        $result = $placement->deleteBlock($pdo, (int)$record['block_id'], (string)$record['state_hash']);
        unset($_SESSION['feo_placement_previews'][$token]);

        $deleted = $result['deleted_block'] ?? [];
        $fileWarning = null;
        $fileKey = trim((string)($deleted['source_file_key'] ?? ''));
        if ($fileKey !== '') {
            if ($fileKey !== basename($fileKey)) {
                $fileWarning = 'Блок удалён, но путь исходного файла оказался некорректным.';
            } else {
                $dir = company_storage_path($company['storage_path'] ?? null, (int)$company['id']) . DIRECTORY_SEPARATOR . 'feo-placement';
                $path = $dir . DIRECTORY_SEPARATOR . $fileKey;
                if (is_file($path) && !@unlink($path)) $fileWarning = 'Блок удалён, но исходный файл не удалось удалить из хранилища.';
            }
        }

        $_SESSION['flash_success'] = sprintf(
            'Блок #%d удалён. Актуальный состав: %d заявок, %s кг.',
            (int)($deleted['block_id'] ?? 0),
            (int)($result['current']['request_count'] ?? 0),
            rtrim(rtrim(number_format((float)($result['current']['total_mass_kg'] ?? 0), 3, '.', ''), '0'), '.')
        );
        if ($fileWarning !== null) $_SESSION['flash_error'] = $fileWarning;
        jsonResponse(['success'=>true,'file_warning'=>$fileWarning] + $result);
    } catch (\Throwable $e) {
        jsonResponse(['success'=>false,'message'=>$e->getMessage()], 422);
    }
}, $feoPlacementPage);

$router->post('/company/feo/requests/map-placement/reconcile/preview', [$feoPlacementController, 'previewReconcile'], $feoPlacementPage);
$router->post('/company/feo/requests/map-placement/reconcile/commit', [$feoPlacementController, 'commitReconcile'], $feoPlacementPage);
require_once base_path('app/Http/Routes/company_feo_transfers.php');
