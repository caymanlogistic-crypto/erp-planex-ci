<?php

use App\Core\Database;
use App\Service\AccessControlService;
use App\Service\EntityResponsibilityService;

requireRole('company_owner');
verifyCsrfRequest();

if (!AccessControlService::isEffectiveSystemOwner()) {
    http_response_code(403);
    echo 'Доступ запрещён.';
    return;
}

$companyId = (int)(getSessionCompanyId() ?? 0);
$entityType = trim((string)($_POST['entity_type'] ?? ''));
$entityIds = $_POST['entity_ids'] ?? [];
if (!is_array($entityIds)) $entityIds = [$entityIds];
$newLogistMembershipId = filter_var($_POST['new_logist_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$operation = (string)($_POST['operation'] ?? 'transfer');
$cascade = (string)($_POST['cascade'] ?? '0') === '1';
$tab = in_array($entityType, ['route_executor','contractor','driver','vehicle_set'], true) ? $entityType : 'route_executor';

if ($companyId <= 0 || !EntityResponsibilityService::isSupported($entityType)
    || ($operation !== 'reconcile' && ($newLogistMembershipId === false || $newLogistMembershipId === null))
    || ($operation === 'reconcile' && $entityType !== 'route_executor')) {
    redirect_to('/company/responsible-assignments?tab=' . rawurlencode($tab) . '&msg=error', 303);
}

try {
    $central = $db->connection();
    $companyStmt = $central->prepare("SELECT * FROM companies WHERE id=? AND status='active' LIMIT 1");
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) throw new RuntimeException('company not found');

    $tenant = (new Database(companyDatabaseConfig($config, $company)))->connection();
    applyLocalMigrations($tenant);

    $actorMembershipId = (int)(AccessControlService::effectiveMembershipId() ?? 0);
    if ($operation === 'reconcile') {
        $_SESSION['responsibility_reconcile_result'] = EntityResponsibilityService::reconcileRouteExecutorLinks($tenant, $actorMembershipId);
        redirect_to('/company/responsible-assignments?tab=route_executor&preview=1&msg=reconciled', 303);
    }
    $targetMembershipId = EntityResponsibilityService::eligibleRecipientMembershipId(
        $central,
        $tenant,
        $companyId,
        (int)$newLogistMembershipId
    );
    if ($targetMembershipId === null) throw new RuntimeException('responsibility recipient is not eligible');
    if ($entityType === 'route_executor') {
        $_SESSION['responsibility_reconcile_result'] = EntityResponsibilityService::transferRouteExecutorCascade($tenant, $entityIds, $targetMembershipId, $actorMembershipId);
    } else {
        EntityResponsibilityService::transfer($tenant, $entityType, $entityIds, $targetMembershipId, $actorMembershipId);
    }

    if ($cascade && in_array($entityType, ['contractor','driver','vehicle_set'], true)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $entityIds), static fn(int $id): bool => $id > 0)));
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            if ($entityType === 'contractor') {
                $sql = "SELECT id FROM crews WHERE contractor_id IN ({$marks}) AND status <> 'archived'";
            } elseif ($entityType === 'driver') {
                $sql = "SELECT c.id FROM crews c JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id WHERE dvb.driver_id IN ({$marks}) AND c.status <> 'archived'";
            } else {
                $sql = "SELECT c.id FROM crews c JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id WHERE dvb.vehicle_set_id IN ({$marks}) AND c.status <> 'archived'";
            }
            $related = $tenant->prepare($sql);
            $related->execute($ids);
            $crewIds = array_map('intval', $related->fetchAll(PDO::FETCH_COLUMN));
            if ($crewIds !== []) {
                EntityResponsibilityService::transfer($tenant, 'route_executor', $crewIds, $targetMembershipId, $actorMembershipId);
            }
        }
    }

    redirect_to('/company/responsible-assignments?tab=' . rawurlencode($tab) . '&msg=assigned', 303);
} catch (Throwable $e) {
    error_log('Responsibility transfer failed: ' . get_class($e) . ': ' . $e->getMessage());
    redirect_to('/company/responsible-assignments?tab=' . rawurlencode($tab) . '&msg=error', 303);
}
