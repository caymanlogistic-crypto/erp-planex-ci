<?php

require_once base_path('app/Http/Controllers/Company/VehicleSetController.php');

$c = new \App\Http\Controllers\Company\VehicleSetController($config, $db);
$vehicleSetsPage = 'company.vehicle_sets';

if (!function_exists('vehicleSetAccessibleUnitIds')) {
    function vehicleSetDirectAccessLevel(PDO $pdo, array $vehicleSet): ?string
    {
        $runtime = \App\Security\AccessRuntime::current();
        if (!$runtime->isActive()) {
            $role = (string) ($_SESSION['role_code'] ?? '');
            if (in_array($role, ['superadmin', 'company_owner', 'senior_logist'], true)) {
                return 'edit';
            }
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            if ($role !== 'logist' || $userId <= 0) {
                return null;
            }
            if ((int) ($vehicleSet['created_by_user_id'] ?? 0) === $userId) {
                return 'edit';
            }
            $stmt = $pdo->prepare("SELECT access_level FROM entity_access_grants
                WHERE entity_type='vehicle_set' AND entity_id=? AND granted_to_user_id=?
                  AND access_level IN ('view','edit') AND revoked_at IS NULL LIMIT 1");
            $stmt->execute([(int) ($vehicleSet['id'] ?? 0), $userId]);
            $level = $stmt->fetchColumn();
            return in_array($level, ['view', 'edit'], true) ? (string) $level : null;
        }

        $scope = \App\Service\AccessControlService::effectiveDataScope('vehicle_unit');
        if ($scope === 'all') {
            return 'edit';
        }
        if ($scope !== 'own') {
            return null;
        }
        $membershipId = \App\Service\AccessControlService::effectiveMembershipId();
        if ($membershipId === null || $membershipId <= 0) {
            return null;
        }
        if ((int) ($vehicleSet['created_by_membership_id'] ?? 0) === $membershipId) {
            return 'edit';
        }
        return \App\Service\LegacyAccessProjectionService::accessLevel(
            $pdo,
            'vehicle_set',
            (int) ($vehicleSet['id'] ?? 0),
            $membershipId
        );
    }

    /** @return array<int, true> */
    function vehicleSetAccessibleUnitIds(PDO $pdo, array $vehicleSet): array
    {
        $direct = vehicleSetDirectAccessLevel($pdo, $vehicleSet);
        $active = \App\Security\AccessRuntime::current()->isActive();
        if (!$active && $direct === null) {
            return [];
        }
        $allowed = [];
        foreach (['primary_vehicle_unit_id', 'secondary_vehicle_unit_id'] as $field) {
            $unitId = (int) ($vehicleSet[$field] ?? 0);
            if ($unitId <= 0) {
                continue;
            }
            if ($direct !== null || ($active && \App\Service\RegulatedDataScopeService::canAccess($pdo, 'vehicle_unit', 'vehicle_units', $unitId))) {
                $allowed[$unitId] = true;
            }
        }
        return $allowed;
    }

    function vehicleSetHasAccessibleUnit(PDO $pdo, array $vehicleSet): bool
    {
        return vehicleSetAccessibleUnitIds($pdo, $vehicleSet) !== [];
    }

    function vehicleSetAllUnitsAccessible(PDO $pdo, array $vehicleSet): bool
    {
        $direct = vehicleSetDirectAccessLevel($pdo, $vehicleSet);
        if ($direct !== null) {
            return $direct === 'edit';
        }
        $unitIds = array_values(array_filter([
            (int) ($vehicleSet['primary_vehicle_unit_id'] ?? 0),
            (int) ($vehicleSet['secondary_vehicle_unit_id'] ?? 0),
        ]));
        return $unitIds !== [] && count(vehicleSetAccessibleUnitIds($pdo, $vehicleSet)) === count(array_unique($unitIds));
    }

    function redactVehicleSetUnitColumns(array $vehicleSet, array $allowedUnitIds): array
    {
        $groups = [
            'primary' => 'primary_vehicle_unit_id',
            'secondary' => 'secondary_vehicle_unit_id',
        ];
        foreach ($groups as $prefix => $idField) {
            $unitId = (int) ($vehicleSet[$idField] ?? 0);
            if ($unitId <= 0 || isset($allowedUnitIds[$unitId])) {
                continue;
            }
            foreach (array_keys($vehicleSet) as $key) {
                if (str_starts_with((string) $key, $prefix . '_')) {
                    $vehicleSet[$key] = null;
                }
            }
            foreach ($prefix === 'primary'
                ? ['has_sts', 'has_diagnostic_card_doc', 'has_photo']
                : ['has_sts_sec', 'has_diagnostic_card_doc_sec', 'has_photo_sec'] as $key) {
                $vehicleSet[$key] = null;
            }
        }
        return $vehicleSet;
    }
}

$router->get('/company/vehicle-sets', [$c, 'index'], $vehicleSetsPage);
$router->get('/company/vehicle-sets/create', [$c, 'createForm'], $vehicleSetsPage);
$router->post('/company/vehicle-sets/create', [$c, 'createSubmit'], $vehicleSetsPage);
$router->get('/company/vehicle-sets/{id}', [$c, 'show'], $vehicleSetsPage);
$router->get('/company/vehicle-sets/{id}/modal-view', [$c, 'modalView'], $vehicleSetsPage);
$router->get('/company/vehicle-sets/{id}/modal-edit', [$c, 'modalEditForm'], $vehicleSetsPage);
$router->post('/company/vehicle-sets/{id}/modal-edit', [$c, 'modalEditSubmit'], $vehicleSetsPage);
$router->post('/company/vehicle-sets/{id}/modal-archive', [$c, 'modalArchive'], $vehicleSetsPage);
$router->post('/company/vehicle-sets/{id}/archive', [$c, 'modalArchive'], $vehicleSetsPage);
