<?php

require_once base_path('app/Http/Controllers/Company/VehicleSetController.php');

$c = new \App\Http\Controllers\Company\VehicleSetController($config, $db);
$vehicleSetsPage = 'company.vehicle_sets';

if (!function_exists('vehicleSetAccessibleUnitIds')) {
    /** @return array<int, true> */
    function vehicleSetAccessibleUnitIds(PDO $pdo, array $vehicleSet): array
    {
        $allowed = [];
        foreach (['primary_vehicle_unit_id', 'secondary_vehicle_unit_id'] as $field) {
            $unitId = (int) ($vehicleSet[$field] ?? 0);
            if ($unitId > 0 && \App\Service\RegulatedDataScopeService::canAccess($pdo, 'vehicle_unit', 'vehicle_units', $unitId)) {
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
