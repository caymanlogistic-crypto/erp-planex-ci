<?php
/** @var ContractorService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
$pageTitle = 'Добавить экипаж перевозчику';
$pageContext = 'Перевозчики › Компания';

$companyId = $service->getCompanyId();
$contractorId = (int)$id;
$drivers = [];
$vehicleSets = [];
$driverVehicleBlocks = [];

if ($companyId <= 0) {
    $company = null;
    $contractor = null;
    $success = false;
    $errors = [];
    $old = [];
    $formError = null;
    $dbError = null;

    ob_start();
    require base_path('app/View/pages/company_contractor_add_crew.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
    return;
}

try {
    $company = $service->loadCompany($companyId);

    if (!$company) {
        $company = null;
        $contractor = null;
        $success = false;
        $errors = [];
        $old = [];
        $formError = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractor_add_crew.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageContext = 'Перевозчики › Компания: ' . $company['name'];

    if ($company['status'] !== 'active') {
        $contractor = null;
        $success = false;
        $errors = [];
        $old = [];
        $formError = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_contractor_add_crew.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    $localPdo->query('SELECT 1 FROM contractors LIMIT 0');
    $localPdo->query('SELECT 1 FROM drivers LIMIT 0');
    $localPdo->query('SELECT 1 FROM vehicle_sets LIMIT 0');
    $localPdo->query('SELECT 1 FROM driver_vehicle_blocks LIMIT 0');
    $localPdo->query('SELECT 1 FROM crews LIMIT 0');

    $contractorStmt = $localPdo->prepare('SELECT * FROM contractors WHERE id = ?');
    $contractorStmt->execute([$contractorId]);
    $contractor = $contractorStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$contractor) {
        $dbError = null;
        $success = false;
        $errors = [];
        $old = [];
        $formError = null;

        ob_start();
        require base_path('app/View/pages/company_contractor_add_crew.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
    $userId = (int)$_SESSION['user_id'];

    try {
        if ($isLogist) {
            $driversStmt = $localPdo->prepare(
                "SELECT id, full_name, phone FROM drivers WHERE status IN ('active', 'archived') AND (created_by_user_id = ? OR id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'driver' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL)) ORDER BY full_name"
            );
            $driversStmt->execute([$userId, $userId]);
            $drivers = $driversStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $driversStmt = $localPdo->query(
                "SELECT id, full_name, phone FROM drivers WHERE status IN ('active', 'archived') ORDER BY full_name"
            );
            $drivers = $driversStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (\Exception $e) {
        $drivers = [];
    }

    try {
        if ($isLogist) {
            $vehicleSetsStmt = $localPdo->prepare(
                "SELECT vs.id, vs.set_type,
                        vu1.plate_number AS primary_plate,
                        vu2.plate_number AS secondary_plate
                   FROM vehicle_sets vs
                   JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                   LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                  WHERE vs.status IN ('active', 'archived')
                    AND (vs.created_by_user_id = ? OR vs.id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'vehicle_set' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL))
                  ORDER BY vs.id DESC"
            );
            $vehicleSetsStmt->execute([$userId, $userId]);
            $vehicleSets = $vehicleSetsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $vehicleSetsStmt = $localPdo->query(
                "SELECT vs.id, vs.set_type,
                        vu1.plate_number AS primary_plate,
                        vu2.plate_number AS secondary_plate
                   FROM vehicle_sets vs
                   JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                   LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                  WHERE vs.status IN ('active', 'archived')
                  ORDER BY vs.id DESC"
            );
            $vehicleSets = $vehicleSetsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (\Exception $e) {
        $vehicleSets = [];
    }

    try {
        if ($isLogist) {
            $dvbStmt = $localPdo->prepare(
                "SELECT dvb.id, d.full_name AS driver_name, d.id AS driver_id,
                        vs.id AS vehicle_set_id, vs.set_type,
                        vu1.plate_number AS primary_plate,
                        vu2.plate_number AS secondary_plate
                   FROM driver_vehicle_blocks dvb
                   JOIN drivers d ON dvb.driver_id = d.id
                   JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
                   JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                   LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                  WHERE dvb.status = 'active'
                    AND dvb.id NOT IN (
                        SELECT driver_vehicle_block_id FROM crews
                         WHERE contractor_id = ? AND status != 'archived'
                    )
                    AND (dvb.created_by_user_id = ? OR dvb.id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'driver_vehicle_block' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL))
                  ORDER BY d.full_name"
            );
            $dvbStmt->execute([$contractorId, $userId, $userId]);
            $driverVehicleBlocks = $dvbStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            $dvbStmt = $localPdo->prepare(
                "SELECT dvb.id, d.full_name AS driver_name, d.id AS driver_id,
                        vs.id AS vehicle_set_id, vs.set_type,
                        vu1.plate_number AS primary_plate,
                        vu2.plate_number AS secondary_plate
                   FROM driver_vehicle_blocks dvb
                   JOIN drivers d ON dvb.driver_id = d.id
                   JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
                   JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                   LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                  WHERE dvb.status = 'active'
                    AND dvb.id NOT IN (
                        SELECT driver_vehicle_block_id FROM crews
                         WHERE contractor_id = ? AND status != 'archived'
                    )
                  ORDER BY d.full_name"
            );
            $dvbStmt->execute([$contractorId]);
            $driverVehicleBlocks = $dvbStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (\Exception $e) {
        $driverVehicleBlocks = [];
    }

    $success = false;
    $errors = [];
    $old = [];
    $formError = null;
    $dbError = null;
} catch (\Exception $e) {
    $company = null;
    $contractor = null;
    $success = false;
    $errors = [];
    $old = [];
    $formError = 'Ошибка загрузки данных: ' . $e->getMessage();
    $dbError = null;
}

ob_start();
require base_path('app/View/pages/company_contractor_add_crew.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
