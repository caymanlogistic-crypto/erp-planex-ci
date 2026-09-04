<?php

use App\Core\Database;
use App\Service\AccessControlService;
use App\Service\EntityAssignmentService;
requireRole(['company_owner', 'senior_logist', 'logist']);

if (!AccessControlService::isEffectiveSystemOwner()) {
    jsonResponse([
        'success' => false,
        'error' => 'forbidden',
        'message' => 'Изменять закрепления может только Руководитель.',
    ], 403);
    return;
}

$companyId = (int) (getSessionCompanyId() ?? 0);
$payload = array_replace($_POST, requestJsonBody());
$entityType = trim((string) ($payload['entity_type'] ?? ''));
$entityId = (int) ($payload['entity_id'] ?? 0);
$membershipIds = $payload['membership_ids'] ?? [];
if (!is_array($membershipIds)) {
    $membershipIds = [$membershipIds];
}

// Never allow the legacy transfer/cascade request to rewrite creator history.
if (isset($payload['new_logist_id'], $payload['entity_ids']) || isset($payload['cascade'])) {
    jsonResponse([
        'success' => false,
        'error' => 'legacy_assignment_payload_retired',
        'message' => 'Старый механизм переназначения отключён. Обновите страницу.',
    ], 410);
    return;
}

if ($companyId <= 0 || !EntityAssignmentService::isSupported($entityType) || $entityId <= 0) {
    jsonResponse([
        'success' => false,
        'error' => 'invalid_assignment_target',
        'message' => 'Некорректные параметры закрепления.',
    ], 422);
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
    $exists = $localPdo->prepare("SELECT 1 FROM `{$table}` WHERE id = ? AND deleted_at IS NULL AND status <> 'archived' LIMIT 1");
    $exists->execute([$entityId]);
    if (!$exists->fetchColumn()) {
        throw new RuntimeException('Объект не найден.');
    }

    $normalized = array_values(array_unique(array_filter(array_map('intval', $membershipIds))));
    if ($normalized !== []) {
        $marks = implode(',', array_fill(0, count($normalized), '?'));
        $members = $central->prepare(
            "SELECT COUNT(*) FROM company_memberships
             WHERE company_id = ? AND id IN ({$marks})"
        );
        $members->execute(array_merge([$companyId], $normalized));
        if ((int) $members->fetchColumn() !== count($normalized)) {
            throw new RuntimeException('Выбрано членство другой компании или несуществующий сотрудник.');
        }
    }

    EntityAssignmentService::replace(
        $localPdo,
        $entityType,
        $entityId,
        $normalized,
        (int) AccessControlService::effectiveMembershipId()
    );

    jsonResponse(['success'=>true,'membership_ids'=>$normalized]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'error' => 'assignment_update_failed',
        'message' => $e->getMessage(),
    ], 422);
}
