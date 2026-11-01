<?php
/** @var ContractorService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
$pageTitle = 'Создать перевозчика + Водителя + Транспорт';
$pageContext = 'Перевозчики › Компания';

$companyId = $service->getCompanyId();
$contractors = [];
$drivers = [];
$vehicleSets = [];

if ($companyId <= 0) {
    $company = null;
    $success = false;
    $errors = [];
    $old = [];
    $formError = null;

    ob_start();
    require base_path('app/View/pages/company_contractors_create_full.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
    return;
}

try {
    $company = $service->loadCompany($companyId);

    if (!$company) {
        $company = null;
        $success = false;
        $errors = [];
        $old = [];
        $formError = null;

        ob_start();
        require base_path('app/View/pages/company_contractors_create_full.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageContext = 'Перевозчики › Компания: ' . $company['name'];

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    $localPdo->query('SELECT 1 FROM contractors LIMIT 0');

    try {
        $contractorsStmt = $localPdo->query(
            'SELECT id, name, inn FROM contractors WHERE status IN (\'active\', \'archived\') ORDER BY name'
        );
        $contractors = $contractorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Exception $e) {
        $contractors = [];
    }

    try {
        $driversStmt = $localPdo->query(
            'SELECT id, full_name, phone FROM drivers WHERE status IN (\'active\', \'archived\') ORDER BY full_name'
        );
        $drivers = $driversStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Exception $e) {
        $drivers = [];
    }

    try {
        $vehicleSetsStmt = $localPdo->query(
            'SELECT vs.id, vs.set_type,
                    vu1.plate_number AS primary_plate,
                    vu2.plate_number AS secondary_plate
               FROM vehicle_sets vs
               JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
               LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
              WHERE vs.status IN (\'active\', \'archived\')
              ORDER BY vs.id DESC'
        );
        $vehicleSets = $vehicleSetsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Exception $e) {
        $vehicleSets = [];
    }

    $success = false;
    $errors = [];
    $old = [];
    $formError = null;
} catch (\Exception $e) {
    $company = null;
    $success = false;
    $errors = [];
    $old = [];
    $formError = 'Ошибка загрузки данных: ' . $e->getMessage();
}

ob_start();
require base_path('app/View/pages/company_contractors_create_full.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
