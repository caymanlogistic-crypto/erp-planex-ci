<?php
/** @var ContractorService $service */
requireRole(['company_owner', 'senior_logist', 'logist']);
$pageTitle = 'Создать перевозчика + Водителя + Транспорт';
$pageContext = 'Перевозчики › Компания';

$companyId = $service->getCompanyId();
$errors = [];
$old = $_POST;
$formError = null;
$success = false;
$createdContractor = null;
$createdDriver = null;
$createdVehicleSet = null;
$createdBlock = null;
$createdCrew = null;
$contractors = [];
$drivers = [];
$vehicleSets = [];

if ($companyId <= 0) {
    $company = null;
    $formError = 'Компания не найдена';

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
        $formError = 'Компания не найдена';

        ob_start();
        require base_path('app/View/pages/company_contractors_create_full.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $pageContext = 'Перевозчики › Компания: ' . $company['name'];

    if ($company['status'] !== 'active') {
        $formError = 'Создание недоступно';

        ob_start();
        require base_path('app/View/pages/company_contractors_create_full.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $localDbConfig = companyDatabaseConfig($config, $company);
    $localDb = new \App\Core\Database($localDbConfig);
    $localPdo = $localDb->connection();
    applyLocalMigrations($localPdo);

    $requiredTables = [
        'contractors' => 'database/migrations-local/003_create_company_contractors.sql',
        'drivers'     => 'database/migrations-local/004_create_company_drivers.sql',
        'vehicle_units' => 'database/migrations-local/005_create_company_vehicles.sql',
        'vehicle_sets' => 'database/migrations-local/017_create_vehicle_sets.sql',
        'driver_vehicle_blocks' => 'database/migrations-local/018_create_driver_vehicle_blocks.sql',
        'crews'       => 'database/migrations-local/006_create_company_crews.sql',
    ];

    foreach ($requiredTables as $table => $migrationFile) {
        try {
            $localPdo->query("SELECT 1 FROM `$table` LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path($migrationFile));
            if ($migrationSql !== false && trim($migrationSql) !== '') {
                $localPdo->exec($migrationSql);
            }
        }
    }

    try {
        $localPdo->query("SELECT 1 FROM vehicle_units LIMIT 1")->fetch();
    } catch (\Exception $e) {
        try {
            $localPdo->exec("RENAME TABLE vehicles TO vehicle_units");
        } catch (\Exception $renameEx) {
        }
    }

    $contractorMode = trim($_POST['contractor_mode'] ?? 'existing');
    $driverMode = trim($_POST['driver_mode'] ?? 'existing');
    $vehicleMode = trim($_POST['vehicle_mode'] ?? 'existing');

    $contractors = [];
    $drivers = [];
    $vehicleSets = [];
    try {
        $contractorsStmt = $localPdo->query(
            'SELECT id, name, inn FROM contractors WHERE status IN (\'active\', \'archived\') ORDER BY name'
        );
        $contractors = $contractorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Exception $e) {}
    try {
        $driversStmt = $localPdo->query(
            'SELECT id, full_name, phone FROM drivers WHERE status IN (\'active\', \'archived\') ORDER BY full_name'
        );
        $drivers = $driversStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Exception $e) {}
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
    } catch (\Exception $e) {}

    $name = trim($_POST['name'] ?? '');
    $inn = trim($_POST['inn'] ?? '');
    $kpp = trim($_POST['kpp'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $contractorIdExisting = trim($_POST['contractor_id'] ?? '');

    $driverFullName = trim($_POST['driver_full_name'] ?? '');
    $driverPhone = trim($_POST['driver_phone'] ?? '');
    $driverIdExisting = trim($_POST['driver_id'] ?? '');

    $plateNumber = trim($_POST['plate_number'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $setType = trim($_POST['set_type'] ?? 'single');
    $secondaryPlateNumber = trim($_POST['secondary_plate_number'] ?? '');
    $vehicleSetIdExisting = trim($_POST['vehicle_set_id'] ?? '');

    if ($contractorMode === 'existing') {
        if ($contractorIdExisting === '') {
            $errors['contractor_id'] = 'Выберите перевозчика';
        } else {
            $checkStmt = $localPdo->prepare('SELECT id, name, inn FROM contractors WHERE id = ? AND deleted_at IS NULL');
            $checkStmt->execute([(int)$contractorIdExisting]);
            $existingContractorRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingContractorRow) {
                $errors['contractor_id'] = 'Перевозчик не найден';
            }
        }
    } else {
        if ($name === '') {
            $errors['name'] = 'Обязательное поле';
        }
        if ($inn === '') {
            $errors['inn'] = 'Обязательное поле';
        } else {
            $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM contractors WHERE inn = ? AND deleted_at IS NULL');
            $checkStmt->execute([$inn]);
            if ($checkStmt->fetchColumn() > 0) {
                $errors['inn'] = 'ИНН уже используется в этой компании';
            }
        }
    }

    if ($driverMode === 'existing') {
        if ($driverIdExisting === '') {
            $errors['driver_id'] = 'Выберите водителя';
        } else {
            $checkStmt = $localPdo->prepare('SELECT id, full_name FROM drivers WHERE id = ? AND deleted_at IS NULL');
            $checkStmt->execute([(int)$driverIdExisting]);
            if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
                $errors['driver_id'] = 'Водитель не найден';
            }
        }
    } else {
        if ($driverFullName === '') {
            $errors['driver_full_name'] = 'Обязательное поле';
        }
    }

    if ($vehicleMode === 'existing') {
        if ($vehicleSetIdExisting === '') {
            $errors['vehicle_set_id'] = 'Выберите транспорт';
        } else {
            $checkStmt = $localPdo->prepare('SELECT id FROM vehicle_sets WHERE id = ? AND deleted_at IS NULL');
            $checkStmt->execute([(int)$vehicleSetIdExisting]);
            if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
                $errors['vehicle_set_id'] = 'Транспорт не найден';
            }
        }
    } else {
        if ($plateNumber === '') {
            $errors['plate_number'] = 'Обязательное поле';
        } else {
            $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM vehicle_units WHERE plate_number = ?');
            $checkStmt->execute([$plateNumber]);
            if ($checkStmt->fetchColumn() > 0) {
                $errors['plate_number'] = 'Госномер уже используется в этой компании';
            }
        }
        if (($setType === 'coupling' || $setType === 'road_train') && $secondaryPlateNumber === '') {
            $errors['secondary_plate_number'] = 'Обязательно для сцепки и автопоезда';
        }
        if ($secondaryPlateNumber !== '' && empty($errors['plate_number'])) {
            $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM vehicle_units WHERE plate_number = ?');
            $checkStmt->execute([$secondaryPlateNumber]);
            if ($checkStmt->fetchColumn() > 0) {
                $errors['secondary_plate_number'] = 'Госномер уже используется в этой компании';
            }
        }
    }

    if (!empty($errors)) {
        ob_start();
        require base_path('app/View/pages/company_contractors_create_full.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    $localPdo->beginTransaction();
    try {
        $uid = (int)$_SESSION['user_id'];
        $role = $_SESSION['role_code'];
        $membershipId = \App\Service\AccessControlService::effectiveMembershipId();
        if ($contractorMode !== 'existing') \App\Service\RegulatedDataScopeService::assertCanCreate('contractor');
        if ($driverMode !== 'existing') \App\Service\RegulatedDataScopeService::assertCanCreate('driver');
        if ($vehicleMode !== 'existing') \App\Service\RegulatedDataScopeService::assertCanCreate('vehicle_unit');

        $contractorId = null;
        $contractorNameForSuccess = '';
        $contractorInnForSuccess = '';
        if ($contractorMode === 'existing') {
            $contractorId = (int)$contractorIdExisting;
            $contractorNameForSuccess = $existingContractorRow['name'] ?? '';
            $contractorInnForSuccess = $existingContractorRow['inn'] ?? '';
        } else {
            $insertContractor = $localPdo->prepare(
                'INSERT INTO contractors (name, inn, kpp, status, created_by_user_id, created_by_membership_id, created_by_role)
                 VALUES (:name, :inn, :kpp, :status, :uid, :membership_id, :role)'
            );
            $insertContractor->execute([
                ':name'   => $name,
                ':inn'    => $inn,
                ':kpp'    => $kpp !== '' ? $kpp : null,
                ':status' => 'active',
                ':uid'    => $uid,
                ':membership_id' => $membershipId,
                ':role'   => $role,
            ]);
            $contractorId = (int)$localPdo->lastInsertId();
            \App\Service\EntityAssignmentService::autoAssignCreator($localPdo, 'contractor', $contractorId, $membershipId);
            ContractorContactService::replaceForContractor(
                $localPdo,
                $contractorId,
                $phone !== '' ? [[
                    'contact_person'      => null,
                    'phone'               => $phone,
                    'email'               => null,
                    'comment'             => null,
                    'is_primary'          => 1,
                    'is_document_email'   => 0,
                ]] : [],
                $uid,
                (string) ($role ?? '')
            );
            $contractorNameForSuccess = $name;
            $contractorInnForSuccess = $inn;
        }

        $driverId = null;
        $driverNameForSuccess = '';
        if ($driverMode === 'existing') {
            $driverId = (int)$driverIdExisting;
            $drvStmt = $localPdo->prepare('SELECT full_name FROM drivers WHERE id = ?');
            $drvStmt->execute([$driverId]);
            $drvRow = $drvStmt->fetch(PDO::FETCH_ASSOC);
            $driverNameForSuccess = $drvRow['full_name'] ?? '';
        } else {
            $insertDriver = $localPdo->prepare(
                'INSERT INTO drivers (full_name, phone, status, created_by_user_id, created_by_membership_id, created_by_role)
                 VALUES (:full_name, :phone, :status, :uid, :membership_id, :role)'
            );
            $insertDriver->execute([
                ':full_name' => $driverFullName,
                ':phone'     => $driverPhone !== '' ? $driverPhone : '',
                ':status'    => 'active',
                ':uid'       => $uid,
                ':membership_id' => $membershipId,
                ':role'      => $role,
            ]);
            $driverId = (int)$localPdo->lastInsertId();
            \App\Service\EntityAssignmentService::autoAssignCreator($localPdo, 'driver', $driverId, $membershipId);
            $driverNameForSuccess = $driverFullName;
        }

        $vehicleSetId = null;
        $vehiclePrimaryPlateForSuccess = '';
        $vehicleSecondaryPlateForSuccess = null;
        if ($vehicleMode === 'existing') {
            $vehicleSetId = (int)$vehicleSetIdExisting;
            $vsStmt = $localPdo->prepare(
                'SELECT vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate
                   FROM vehicle_sets vs
                   JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                   LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                  WHERE vs.id = ?'
            );
            $vsStmt->execute([$vehicleSetId]);
            $vsRow = $vsStmt->fetch(PDO::FETCH_ASSOC);
            $vehiclePrimaryPlateForSuccess = $vsRow['primary_plate'] ?? '';
            $vehicleSecondaryPlateForSuccess = !empty($vsRow['secondary_plate']) ? $vsRow['secondary_plate'] : null;
        } else {
            $insertPrimaryUnit = $localPdo->prepare(
                'INSERT INTO vehicle_units (plate_number, brand, model, unit_type, status, created_by_user_id, created_by_membership_id, created_by_role)
                 VALUES (:plate_number, :brand, :model, :unit_type, :status, :uid, :membership_id, :role)'
            );
            $insertPrimaryUnit->execute([
                ':plate_number' => $plateNumber,
                ':brand'        => $brand !== '' ? $brand : null,
                ':model'        => $model !== '' ? $model : null,
                ':unit_type'    => 'tractor',
                ':status'       => 'active',
                ':uid'          => $uid,
                ':membership_id' => $membershipId,
                ':role'         => $role,
            ]);
            $primaryUnitId = (int)$localPdo->lastInsertId();
            \App\Service\EntityAssignmentService::autoAssignCreator($localPdo, 'vehicle_unit', $primaryUnitId, $membershipId);

            $secondaryUnitId = null;
            if ($secondaryPlateNumber !== '') {
                $insertSecondaryUnit = $localPdo->prepare(
                    'INSERT INTO vehicle_units (plate_number, unit_type, status, created_by_user_id, created_by_membership_id, created_by_role)
                     VALUES (:plate_number, :unit_type, :status, :uid, :membership_id, :role)'
                );
                $insertSecondaryUnit->execute([
                    ':plate_number' => $secondaryPlateNumber,
                    ':unit_type'    => 'trailer',
                    ':status'       => 'active',
                    ':uid'          => $uid,
                    ':membership_id' => $membershipId,
                    ':role'         => $role,
                ]);
                $secondaryUnitId = (int)$localPdo->lastInsertId();
                \App\Service\EntityAssignmentService::autoAssignCreator($localPdo, 'vehicle_unit', $secondaryUnitId, $membershipId);
            }

            $insertVehicleSet = $localPdo->prepare(
                'INSERT INTO vehicle_sets (set_type, primary_vehicle_unit_id, secondary_vehicle_unit_id, status, created_by_user_id, created_by_role)
                 VALUES (:set_type, :primary_id, :secondary_id, :status, :uid, :role)'
            );
            $insertVehicleSet->execute([
                ':set_type'     => $setType,
                ':primary_id'   => $primaryUnitId,
                ':secondary_id' => $secondaryUnitId,
                ':status'       => 'active',
                ':uid'          => $uid,
                ':role'         => $role,
            ]);
            $vehicleSetId = (int)$localPdo->lastInsertId();
            $vehiclePrimaryPlateForSuccess = $plateNumber;
            $vehicleSecondaryPlateForSuccess = $secondaryPlateNumber !== '' ? $secondaryPlateNumber : null;
        }

        $existingBlock = $localPdo->prepare('SELECT id FROM driver_vehicle_blocks WHERE driver_id = ? AND vehicle_set_id = ?');
        $existingBlock->execute([$driverId, $vehicleSetId]);
        $blockRow = $existingBlock->fetch(PDO::FETCH_ASSOC);
        if ($blockRow) {
            $blockId = (int)$blockRow['id'];
        } else {
            $insertBlock = $localPdo->prepare(
                'INSERT INTO driver_vehicle_blocks (driver_id, vehicle_set_id, status, created_by_user_id, created_by_role)
                 VALUES (:driver_id, :vehicle_set_id, :status, :uid, :role)'
            );
            $insertBlock->execute([
                ':driver_id'       => $driverId,
                ':vehicle_set_id'  => $vehicleSetId,
                ':status'          => 'active',
                ':uid'             => $uid,
                ':role'            => $role,
            ]);
            $blockId = (int)$localPdo->lastInsertId();
        }

        $existingCrew = $localPdo->prepare('SELECT id FROM crews WHERE contractor_id = ? AND driver_vehicle_block_id = ? AND deleted_at IS NULL');
        $existingCrew->execute([$contractorId, $blockId]);
        if (!$existingCrew->fetch()) {
            $insertCrew = $localPdo->prepare(
                'INSERT INTO crews (contractor_id, driver_vehicle_block_id, status, created_by_user_id, created_by_role)
                 VALUES (:contractor_id, :driver_vehicle_block_id, :status, :uid, :role)'
            );
            $insertCrew->execute([
                ':contractor_id'           => $contractorId,
                ':driver_vehicle_block_id' => $blockId,
                ':status'                  => 'active',
                ':uid'                     => $uid,
                ':role'                    => $role,
            ]);
        }

        $crewFetch = $localPdo->prepare('SELECT id FROM crews WHERE contractor_id = ? AND driver_vehicle_block_id = ? AND deleted_at IS NULL');
        $crewFetch->execute([$contractorId, $blockId]);
        $crewRow = $crewFetch->fetch(PDO::FETCH_ASSOC);
        $crewId = $crewRow ? (int)$crewRow['id'] : 0;

        $localPdo->commit();

        $createdContractor = ['id' => $contractorId, 'name' => $contractorNameForSuccess, 'inn' => $contractorInnForSuccess];
        $createdDriver = ['id' => $driverId, 'full_name' => $driverNameForSuccess];
        $createdVehicleSet = [
            'id'              => $vehicleSetId,
            'primary_plate'   => $vehiclePrimaryPlateForSuccess,
            'secondary_plate' => $vehicleSecondaryPlateForSuccess,
        ];
        $createdBlock = ['id' => $blockId];
        $createdCrew = ['id' => $crewId];
        $success = true;
    } catch (\Exception $e) {
        $localPdo->rollBack();
        throw $e;
    }
} catch (\Exception $e) {
    $company = $company ?? null;
    $formError = 'Ошибка создания: ' . $e->getMessage();
}

if (!isset($contractors) || !is_array($contractors)) { $contractors = []; }
if (!isset($drivers) || !is_array($drivers)) { $drivers = []; }
if (!isset($vehicleSets) || !is_array($vehicleSets)) { $vehicleSets = []; }

ob_start();
require base_path('app/View/pages/company_contractors_create_full.php');
$content = ob_get_clean();
require base_path('app/View/layouts/main.php');
