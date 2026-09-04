<?php

use App\Core\Database;
use App\Service\AccessControlService;
use App\Service\EntityAssignmentService;
requireRole(['company_owner', 'senior_logist', 'logist']);

if (!AccessControlService::isEffectiveSystemOwner()) {
    http_response_code(403);
    echo 'Изменять закрепления может только Руководитель.';
    return;
}

$companyId = (int) (getSessionCompanyId() ?? 0);
$entityType = trim((string) ($_POST['entity_type'] ?? ''));
$entityId = (int) ($_POST['entity_id'] ?? 0);
$membershipIds = $_POST['membership_ids'] ?? [];
if (!is_array($membershipIds)) {
    $membershipIds = [$membershipIds];
}

// Never allow the legacy transfer/cascade request to rewrite creator history.
if (isset($_POST['new_logist_id'], $_POST['entity_ids']) || isset($_POST['cascade'])) {
    http_response_code(410);
    echo 'Старый механизм переназначения отключён. Обновите страницу.';
    return;
}

if ($companyId <= 0 || !EntityAssignmentService::isSupported($entityType) || $entityId <= 0) {
    http_response_code(422);
    echo 'Некорректные параметры закрепления.';
    return;
}

try {
    $central = $db->connection();
    $companyStmt = $central->prepare("SELECT * FROM companies WHERE id = ? AND status = 'active'");
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        throw new RuntimeException('Компания не найдена или отключена.');
    }

    $tenant = new Database(companyDatabaseConfig($config, $company));
    $localPdo = $tenant->connection();
    applyLocalMigrations($localPdo);

    $table = ['client'=>'clients','contractor'=>'contractors','vehicle_unit'=>'vehicle_units','driver'=>'drivers'][$entityType];
    $exists = $localPdo->prepare("SELECT 1 FROM `{$table}` WHERE id = ? AND deleted_at IS NULL LIMIT 1");
    $exists->execute([$entityId]);
    if (!$exists->fetchColumn()) {
        throw new RuntimeException('Объект не найден.');
    }

    $normalized = array_values(array_unique(array_filter(array_map('intval', $membershipIds))));
    if ($normalized !== []) {
        $marks = implode(',', array_fill(0, count($normalized), '?'));
        $members = $localPdo->prepare("SELECT COUNT(*) FROM users WHERE membership_id IN ({$marks}) AND status = 'active' AND deleted_at IS NULL");
        $members->execute($normalized);
        if ((int) $members->fetchColumn() !== count($normalized)) {
            throw new RuntimeException('Выбрано неактивное или чужое членство компании.');
        }
    }

    EntityAssignmentService::replace(
        $localPdo,
        $entityType,
        $entityId,
        $normalized,
        (int) AccessControlService::effectiveMembershipId()
    );

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'membership_ids'=>$normalized], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
