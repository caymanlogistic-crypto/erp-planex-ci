<?php
/** @var DriverService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
$companyId = $service->getCompanyId();
if ($companyId <= 0) { http_response_code(404); echo '<div class="notice warn">Компания не найдена.</div>'; exit; }
try {
    $company = $service->loadCompany($companyId);
    if (!$company || $company['status'] !== 'active') { http_response_code(404); echo '<div class="notice warn">Компания не найдена или не активна.</div>'; exit; }
    $localPdo = $service->getLocalPdo($company);
    $driver = $service->getDriverById($localPdo, (int)$id);
    if (!$driver) { http_response_code(404); echo '<div class="notice warn">Водитель не найден.</div>'; exit; }
    $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
    if ($isLogist) { $userId = (int)$_SESSION['user_id']; $grantLevel = $service->checkLogistAccess($localPdo, (int)$id, $userId); if ((int)$driver['created_by_user_id'] !== $userId && !$grantLevel) { http_response_code(403); echo '<div class="notice warn">У вас нет доступа к этой записи.</div>'; exit; } }
    $phones = $service->getDriverPhones($localPdo, (int)$id);
    $mainPhone = $service->getMainPhone($localPdo, $phones, $driver);
    $allDocs = $service->getDriverDocuments($localPdo, (int)$id);
    $docsByType = $service->getDocsByType($localPdo, $allDocs);
    $zipAvailable = class_exists('ZipArchive');
    $role = $_SESSION['role_code'] ?? ''; $canEdit = true; $canDelete = true;
    header('Content-Type: text/html; charset=utf-8');
    require base_path('app/View/partials/company_driver_modal_view.php');
    exit;
} catch (\Exception $e) { http_response_code(500); echo '<div class="notice warn">Ошибка загрузки: ' . e($e->getMessage()) . '</div>'; exit; }
