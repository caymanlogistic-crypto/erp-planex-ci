<?php

use App\Service\LinearRouteService;
use App\Service\LinearTripDocumentUploadException;
use App\Service\LinearTripEditSaveService;
use App\Service\LinearTripEditTokenService;
use App\Service\LinearTripServiceLineService;

requireRole(['company_owner', 'senior_logist', 'logist']);
header('Content-Type: application/json; charset=utf-8');

$companyId = (int)(getSessionCompanyId() ?? 0);
$routeId = (int)$id;
$sessionUser = ['user_id'=>(int)($_SESSION['user_id'] ?? 0),'role_code'=>(string)($_SESSION['role_code'] ?? '')];
$respond = static function(array $payload, int $status=200): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit; };
if ($companyId<=0 || $routeId<=0 || $sessionUser['user_id']<=0) $respond(['success'=>false,'message'=>'Рейс недоступен.','error_code'=>'invalid_context'],404);

$requestToken=trim((string)($_POST['_linear_trip_save_token'] ?? ''));
if (!LinearTripEditTokenService::consume($routeId,$requestToken)) $respond(['success'=>false,'message'=>'Запрос сохранения устарел или уже был выполнен. Повторно откройте редактирование рейса.','error_code'=>'duplicate_or_expired_request','retry_token'=>LinearTripEditTokenService::issue($routeId)],409);

try {
    $pdo=$db->connection();
    $companyStmt=$pdo->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
    $companyStmt->execute([$companyId]);
    $company=$companyStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($company===null) $respond(['success'=>false,'message'=>'Компания недоступна для работы.','error_code'=>'company_unavailable','retry_token'=>LinearTripEditTokenService::issue($routeId)],404);
    $localPdo=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
    $route=LinearRouteService::fetchRouteById($localPdo,$routeId,$sessionUser['role_code']==='company_owner');
    if ($route===null) $respond(['success'=>false,'message'=>'Рейс не найден.','error_code'=>'route_not_found','retry_token'=>LinearTripEditTokenService::issue($routeId)],404);
    if (!LinearRouteService::canEditRoute($route,$localPdo,$sessionUser)) $respond(['success'=>false,'message'=>'У вас нет права редактировать этот рейс.','error_code'=>'route_access_denied','retry_token'=>LinearTripEditTokenService::issue($routeId)],403);

    $parsed=LinearTripServiceLineService::normalizeSubmitted($localPdo,(array)($_POST['trip_services'] ?? []),$sessionUser,$sessionUser['role_code']==='company_owner');
    if ($parsed['errors']!==[]) $respond(['success'=>false,'message'=>'Проверьте дополнительные услуги.','errors'=>$parsed['errors'],'error_code'=>'validation_failed','retry_token'=>LinearTripEditTokenService::issue($routeId)],422);

    $result=LinearTripEditSaveService::save($localPdo,$companyId,$routeId,$sessionUser,$_POST,$_FILES,$requestToken,LinearRouteService::fetchRouteDocuments($localPdo,$routeId));
    if (!$result['success']) $respond(['success'=>false,'message'=>$result['message'],'errors'=>$result['errors'],'error_code'=>'validation_failed','retry_token'=>LinearTripEditTokenService::issue($routeId)],422);

    LinearTripServiceLineService::replaceForRoute($localPdo,$routeId,$parsed['services'],$sessionUser,[
        'planned_loading_date'=>LinearRouteService::normalizeDate($_POST['planned_loading_date'] ?? ''),
        'planned_unloading_date'=>LinearRouteService::normalizeDate($_POST['planned_unloading_date'] ?? ''),
        'actual_loading_date'=>LinearRouteService::normalizeDate($_POST['actual_loading_date'] ?? ''),
        'actual_unloading_date'=>LinearRouteService::normalizeDate($_POST['actual_unloading_date'] ?? ''),
    ],$sessionUser['role_code']==='company_owner');

    $respond(['success'=>true,'message'=>$result['message'],'route_id'=>$routeId]);
} catch (LinearTripDocumentUploadException $e) {
    $respond(['success'=>false,'message'=>$e->getMessage(),'errors'=>['documents'=>$e->getMessage()],'error_code'=>$e->errorCode,'retry_token'=>LinearTripEditTokenService::issue($routeId)],422);
} catch (Throwable $e) {
    $errorId='SERV-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
    error_log(sprintf('[%s] linear trip save failed company=%d route=%d: %s',$errorId,$companyId,$routeId,$e->getMessage()));
    $respond(['success'=>false,'message'=>'Не удалось сохранить рейс из-за внутренней ошибки.','error_code'=>'internal_save_error','error_id'=>$errorId,'retry_token'=>LinearTripEditTokenService::issue($routeId)],500);
}
