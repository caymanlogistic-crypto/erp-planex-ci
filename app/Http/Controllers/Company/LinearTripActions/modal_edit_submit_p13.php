<?php

use App\Service\LinearRouteService;
use App\Service\LinearTripDocumentUploadException;
use App\Service\LinearTripEditSaveService;
use App\Service\LinearTripEditTokenService;
use App\Service\LinearTripServiceLineService;

requireRole(['company_owner', 'senior_logist', 'logist']);
header('Content-Type: application/json; charset=utf-8');

$companyId = (int) (getSessionCompanyId() ?? 0);
$routeId = (int) $id;
$sessionUser = [
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'role_code' => (string) ($_SESSION['role_code'] ?? ''),
];

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
};

if ($companyId <= 0 || $routeId <= 0 || $sessionUser['user_id'] <= 0) {
    $respond(['success' => false, 'message' => 'Рейс недоступен.', 'error_code' => 'invalid_context'], 404);
}

$requestToken = trim((string) ($_POST['_linear_trip_save_token'] ?? ''));
if (!LinearTripEditTokenService::consume($routeId, $requestToken)) {
    $respond([
        'success' => false,
        'message' => 'Запрос сохранения устарел или уже был выполнен. Повторно откройте редактирование рейса.',
        'error_code' => 'duplicate_or_expired_request',
        'retry_token' => LinearTripEditTokenService::issue($routeId),
    ], 409);
}

try {
    $pdo = $db->connection();
    $companyStmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND status = ? LIMIT 1');
    $companyStmt->execute([$companyId, 'active']);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($company === null) {
        $respond([
            'success' => false,
            'message' => 'Компания недоступна для работы.',
            'error_code' => 'company_unavailable',
            'retry_token' => LinearTripEditTokenService::issue($routeId),
        ], 404);
    }

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();

    // P13: normal save requests must never run migrations or schema workarounds.
    $route = LinearRouteService::fetchRouteById($localPdo, $routeId, $sessionUser['role_code'] === 'company_owner');
    if ($route === null) {
        $respond([
            'success' => false,
            'message' => 'Рейс не найден.',
            'error_code' => 'route_not_found',
            'retry_token' => LinearTripEditTokenService::issue($routeId),
        ], 404);
    }
    if (!LinearRouteService::canEditRoute($route, $localPdo, $sessionUser)) {
        $respond([
            'success' => false,
            'message' => 'У вас нет права редактировать этот рейс.',
            'error_code' => 'route_access_denied',
            'retry_token' => LinearTripEditTokenService::issue($routeId),
        ], 403);
    }

    $includeFinance = $sessionUser['role_code'] === 'company_owner';
    $serviceParse = LinearTripServiceLineService::normalizeSubmitted(
        $localPdo,
        (array) ($_POST['trip_services'] ?? []),
        $sessionUser,
        $includeFinance
    );
    if ($serviceParse['errors'] !== []) {
        $respond([
            'success' => false,
            'message' => 'Проверьте дополнительные услуги.',
            'errors' => $serviceParse['errors'],
            'error_code' => 'validation_failed',
            'retry_token' => LinearTripEditTokenService::issue($routeId),
        ], 422);
    }

    $serviceSchemaReady = LinearTripServiceLineService::schemaReady($localPdo);
    $existingServices = $serviceSchemaReady
        ? (LinearTripServiceLineService::fetchByRouteIds($localPdo, [$routeId], $includeFinance)[$routeId] ?? [])
        : [];
    $serviceMutationNeeded = $serviceParse['services'] !== [] || $existingServices !== [];

    // A route without service rows must remain editable even if an older tenant
    // has not received the optional service-line schema yet. Conversely, never
    // accept submitted services if the schema is unavailable: fail before the
    // main route save so we cannot report an error after partially committing it.
    if ($serviceParse['services'] !== [] && !$serviceSchemaReady) {
        $respond([
            'success' => false,
            'message' => 'Дополнительные услуги временно недоступны для этой компании. Основные данные рейса не изменены.',
            'errors' => ['trip_services' => 'Схема дополнительных услуг рейса недоступна.'],
            'error_code' => 'service_schema_unavailable',
            'retry_token' => LinearTripEditTokenService::issue($routeId),
        ], 503);
    }

    $result = LinearTripEditSaveService::save(
        $localPdo,
        $companyId,
        $routeId,
        $sessionUser,
        $_POST,
        $_FILES,
        $requestToken,
        LinearRouteService::fetchRouteDocuments($localPdo, $routeId)
    );

    if (!$result['success']) {
        $respond([
            'success' => false,
            'message' => $result['message'],
            'errors' => $result['errors'],
            'error_code' => 'validation_failed',
            'retry_token' => LinearTripEditTokenService::issue($routeId),
        ], 422);
    }

    if ($serviceMutationNeeded) {
        LinearTripServiceLineService::replaceForRoute(
            $localPdo,
            $routeId,
            $serviceParse['services'],
            $sessionUser,
            [
                'planned_loading_date' => LinearRouteService::normalizeDate($_POST['planned_loading_date'] ?? ''),
                'planned_unloading_date' => LinearRouteService::normalizeDate($_POST['planned_unloading_date'] ?? ''),
                'actual_loading_date' => LinearRouteService::normalizeDate($_POST['actual_loading_date'] ?? ''),
                'actual_unloading_date' => LinearRouteService::normalizeDate($_POST['actual_unloading_date'] ?? ''),
            ],
            $includeFinance
        );
    }

    $respond(['success' => true, 'message' => $result['message'], 'route_id' => $routeId]);
} catch (LinearTripDocumentUploadException $e) {
    $respond([
        'success' => false,
        'message' => $e->getMessage(),
        'errors' => ['documents' => $e->getMessage()],
        'error_code' => $e->errorCode,
        'retry_token' => LinearTripEditTokenService::issue($routeId),
    ], 422);
} catch (Throwable $e) {
    $errorId = 'P15-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    $logLine = json_encode([
        'timestamp' => date(DATE_ATOM),
        'error_id' => $errorId,
        'company_id' => $companyId,
        'route_id' => $routeId,
        'user_id' => $sessionUser['user_id'],
        'exception' => get_class($e),
        'code' => (string) $e->getCode(),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => array_slice($e->getTrace(), 0, 8),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $logDirectory = storage_path('logs');
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0750, true);
    }
    @file_put_contents(
        $logDirectory . DIRECTORY_SEPARATOR . 'P15_linear_trip_save_errors.log',
        $logLine . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    error_log(sprintf(
        '[P15] linear trip save failed error_id=%s company=%d route=%d user=%d exception=%s message=%s',
        $errorId,
        $companyId,
        $routeId,
        $sessionUser['user_id'],
        get_class($e),
        $e->getMessage()
    ));

    $respond([
        'success' => false,
        'message' => 'Не удалось сохранить рейс из-за внутренней ошибки. Изменения не применены.',
        'error_code' => 'internal_save_error',
        'error_id' => $errorId,
        'retry_token' => LinearTripEditTokenService::issue($routeId),
    ], 500);
}