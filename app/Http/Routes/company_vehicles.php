<?php

$router->get('/company/vehicles', function () use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $pageTitle = 'Транспортные единицы';
    $pageContext = 'Транспортные единицы › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $vehicles = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_vehicles.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $company = null;
            $vehicles = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_vehicles.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Транспортные единицы › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $vehicles = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_vehicles.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM vehicle_units LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/005_create_company_vehicles.sql'));
            if ($migrationSql !== false) {
                $localPdo->exec($migrationSql);
            }
            try {
                $localPdo->exec("RENAME TABLE vehicles TO vehicle_units");
            } catch (\Exception $renameEx) {
                // Table may already be vehicle_units or vehicles may not exist
            }
        }

        try {
            $localPdo->query("SELECT created_by_user_id FROM vehicle_units LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $localPdo->exec("ALTER TABLE vehicle_units ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL");
        }

        try {
            $localPdo->query("SELECT 1 FROM entity_access_grants LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/009_create_entity_access_grants.sql'));
            $localPdo->exec($migrationSql);
        }

        $access = \App\Service\RegulatedDataScopeService::condition('vehicle_unit', 'vehicle_units.id');
        $vehicleStmt = $localPdo->prepare("SELECT * FROM vehicle_units WHERE deleted_at IS NULL AND {$access['sql']} ORDER BY created_at DESC");
        $vehicleStmt->execute($access['params']);
        $vehicles = $vehicleStmt->fetchAll(PDO::FETCH_ASSOC);
        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $vehicles = [];
        $dbError = 'Не удалось подключиться к базе данных компании.';
    }

    ob_start();
    require base_path('app/View/pages/company_vehicles.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.vehicle_units');

$router->get('/company/vehicles/create', function () use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $pageTitle = 'Добавить транспортную единицу';
    $pageContext = 'Транспортные единицы › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $success = false;
        $errors = [];
        $old = [];
        $formError = null;
        $createdVehicle = null;

        ob_start();
        require base_path('app/View/pages/company_vehicles_create.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $company = null;
            $success = false;
            $errors = [];
            $old = [];
            $formError = null;
            $createdVehicle = null;

            ob_start();
            require base_path('app/View/pages/company_vehicles_create.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Транспортные единицы › Компания: ' . $company['name'];

        $success = false;
        $errors = [];
        $old = [];
        $formError = null;
        $createdVehicle = null;
    } catch (\Exception $e) {
        $company = null;
        $success = false;
        $errors = [];
        $old = [];
        $formError = 'Ошибка загрузки данных: ' . $e->getMessage();
        $createdVehicle = null;
    }

    ob_start();
    require base_path('app/View/pages/company_vehicles_create.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.vehicle_units');

$router->post('/company/vehicles/create', function () use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $pageTitle = 'Добавить транспортную единицу';
    $pageContext = 'Транспортные единицы › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $errors = [];
    $old = $_POST;
    $formError = null;
    $success = false;
    $createdVehicle = null;

    if ($companyId <= 0) {
        $company = null;
        $formError = 'Компания не найдена';

        ob_start();
        require base_path('app/View/pages/company_vehicles_create.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $company = null;
            $formError = 'Компания не найдена';

            ob_start();
            require base_path('app/View/pages/company_vehicles_create.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Транспортные единицы › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $formError = 'Добавление транспорта недоступно';

            ob_start();
            require base_path('app/View/pages/company_vehicles_create.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM vehicle_units LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/005_create_company_vehicles.sql'));
            if ($migrationSql !== false) {
                $localPdo->exec($migrationSql);
            }
            try {
                $localPdo->exec("RENAME TABLE vehicles TO vehicle_units");
            } catch (\Exception $renameEx) {
                // Table may already be vehicle_units or vehicles may not exist
            }
        }

        $plateNumber = trim($_POST['plate_number'] ?? '');
        $unitType = trim($_POST['unit_type'] ?? '');
        $vinAbsent = (string) ($_POST['vin_absent'] ?? '') === '1';
        if ($vinAbsent && trim((string) ($_POST['vin'] ?? '')) !== '') $errors['vin'] = 'При отметке об отсутствии VIN оставьте поле пустым';

        if ($plateNumber === '') {
            $errors['plate_number'] = 'Обязательное поле';
        }
        if ($unitType === '') {
            $errors['unit_type'] = 'Укажите тип транспортной единицы';
        }

        if (empty($errors['plate_number'])) {
            $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM vehicle_units WHERE plate_number = ?');
            $checkStmt->execute([$plateNumber]);
            if ($checkStmt->fetchColumn() > 0) {
                $errors['plate_number'] = 'Госномер уже используется в этой компании';
            }
        }

        if (!empty($errors)) {
            ob_start();
            require base_path('app/View/pages/company_vehicles_create.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $vin = trim($_POST['vin'] ?? '');
        $stsNumber = trim($_POST['sts_number'] ?? '');
        $ptsNumber = trim($_POST['pts_number'] ?? '');
        $capacityTons = trim($_POST['capacity_tons'] ?? '');
        $volumeM3 = trim($_POST['volume_m3'] ?? '');
        $comments = trim($_POST['comments'] ?? '');

        $membershipId = \App\Service\RegulatedDataScopeService::assertCanCreate('vehicle_unit');
        $localPdo->beginTransaction();
        $insert = $localPdo->prepare(
             'INSERT INTO vehicle_units (plate_number, brand, model, unit_type, vin, vin_absent,
             sts_number, pts_number, capacity_tons, volume_m3, status, comments, created_by_user_id, created_by_membership_id, created_by_role)
              VALUES (:plate_number, :brand, :model, :unit_type, :vin, :vin_absent,
             :sts_number, :pts_number, :capacity_tons, :volume_m3, :status, :comments, :created_by_user_id, :created_by_membership_id, :created_by_role)'
        );
        $insert->execute([
            ':plate_number'       => $plateNumber,
            ':brand'              => $brand !== '' ? $brand : null,
            ':model'              => $model !== '' ? $model : null,
            ':unit_type'          => $unitType,
            ':vin'                => $vin !== '' ? $vin : null,
            ':vin_absent'         => $vinAbsent ? 1 : 0,
            ':sts_number'         => $stsNumber !== '' ? $stsNumber : null,
            ':pts_number'         => $ptsNumber !== '' ? $ptsNumber : null,
            ':capacity_tons'      => $capacityTons !== '' ? $capacityTons : null,
            ':volume_m3'          => $volumeM3 !== '' ? $volumeM3 : null,
            ':status'             => 'active',
            ':comments'           => $comments !== '' ? $comments : null,
            ':created_by_user_id' => (int)$_SESSION['user_id'],
            ':created_by_membership_id' => $membershipId,
            ':created_by_role'    => $_SESSION['role_code'],
        ]);

        $lastId = (int)$localPdo->lastInsertId();
        \App\Service\EntityAssignmentService::autoAssignCreator($localPdo, 'vehicle_unit', $lastId, $membershipId);
        $localPdo->commit();
        $selectStmt = $localPdo->prepare('SELECT * FROM vehicle_units WHERE id = ?');
        $selectStmt->execute([$lastId]);
        $createdVehicle = $selectStmt->fetch(PDO::FETCH_ASSOC);
        $success = true;
    } catch (\Exception $e) {
        if (isset($localPdo) && $localPdo instanceof PDO && $localPdo->inTransaction()) {
            $localPdo->rollBack();
        }
        $company = $company ?? null;
        $formError = 'Ошибка добавления транспорта: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_vehicles_create.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.vehicle_units');

// --- Vehicle View ---
$router->get('/company/vehicles/{id}', function ($vehicleId) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $vehicleId = (int)$vehicleId;
    $pageTitle = 'Транспортные единицы';
    $pageContext = 'Транспортные единицы › Компания';
    $entityNotFound = false;
    $grants = [];
    $logists = [];

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $vehicle = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_vehicle_view.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $company = null;
            $vehicle = null;
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_vehicle_view.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Транспортные единицы › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $vehicle = null;
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_vehicle_view.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM vehicle_units LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/005_create_company_vehicles.sql'));
            if ($migrationSql !== false) {
                $localPdo->exec($migrationSql);
            }
            try {
                $localPdo->exec("RENAME TABLE vehicles TO vehicle_units");
            } catch (\Exception $renameEx) {
                // Table may already be vehicle_units or vehicles may not exist
            }
        }

        try {
            $localPdo->query("SELECT created_by_user_id FROM vehicle_units LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $localPdo->exec("ALTER TABLE vehicle_units ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL");
        }

        try {
            $localPdo->query("SELECT 1 FROM entity_access_grants LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/009_create_entity_access_grants.sql'));
            $localPdo->exec($migrationSql);
        }

        $accessDenied = null;
        $vStmt = $localPdo->prepare('SELECT * FROM vehicle_units WHERE id = ?');
        $vStmt->execute([$vehicleId]);
        $vehicle = $vStmt->fetch(PDO::FETCH_ASSOC);
        if ($vehicle && !\App\Service\RegulatedDataScopeService::canAccess($localPdo, 'vehicle_unit', 'vehicle_units', $vehicleId)) {
            $vehicle = null;
            $accessDenied = 'У вас нет доступа к этой записи.';
        }

        if (!$vehicle && $accessDenied === null) {
            $entityNotFound = true;
        }
        $relatedSets = [];
        if ($vehicle) {
            // Load related vehicle sets
            if (!$accessDenied) {
                $rsStmt = $localPdo->prepare(
                    "SELECT vs.*, vu1.plate_number AS primary_plate, vu1.brand AS primary_brand,
                     vu2.plate_number AS secondary_plate, vu2.brand AS secondary_brand
                     FROM vehicle_sets vs
                     LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                     LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                     WHERE vs.primary_vehicle_unit_id = ? OR vs.secondary_vehicle_unit_id = ?
                     ORDER BY vs.id DESC"
                );
                $rsStmt->execute([$vehicleId, $vehicleId]);
                $relatedSets = $rsStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $pageTitle = $vehicle ? 'Транспортная единица: ' . $vehicle['plate_number'] : 'Транспортная единица';

        $grants = [];
        $logists = [];
        if (\App\Service\AccessControlService::isEffectiveSystemOwner()) {
            $grantsStmt = $localPdo->prepare(
                "SELECT a.*, u.full_name AS logist_name
                 FROM entity_assignments a
                 LEFT JOIN users u ON u.membership_id = a.membership_id
                 WHERE a.entity_type = ? AND a.entity_id = ?"
            );
            $grantsStmt->execute(['vehicle_unit', $vehicleId]);
            $grants = $grantsStmt->fetchAll(PDO::FETCH_ASSOC);

            $logists = $localPdo->query("SELECT membership_id AS id, full_name, login FROM users WHERE membership_id IS NOT NULL AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        }

        $dbError = null;
    } catch (\Exception $e) {
        if (isset($localPdo) && $localPdo instanceof PDO && $localPdo->inTransaction()) {
            $localPdo->rollBack();
        }
        $company = $company ?? null;
        $vehicle = null;
        $grants = [];
        $logists = [];
        $relatedSets = [];
        $dbError = 'Не удалось подключиться к базе данных компании.';
    }

    ob_start();
    require base_path('app/View/pages/company_vehicle_view.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.vehicle_units');

// --- Vehicle Edit (form) ---
$router->get('/company/vehicles/{id}/edit', function ($vehicleId) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $vehicleId = (int)$vehicleId;
    $pageTitle = 'Редактировать транспортную единицу';
    $pageContext = 'Транспортные единицы › Компания';
    $entityNotFound = false;

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $success = false;
    $formError = null;
    $errors = [];

    if ($companyId <= 0) {
        $company = null;
        $vehicle = null;
        $old = [];
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_vehicle_edit.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $company = null;
            $vehicle = null;
            $old = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_vehicle_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Транспортные единицы › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $vehicle = null;
            $old = [];
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_vehicle_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM vehicle_units LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/005_create_company_vehicles.sql'));
            if ($migrationSql !== false) {
                $localPdo->exec($migrationSql);
            }
            try {
                $localPdo->exec("RENAME TABLE vehicles TO vehicle_units");
            } catch (\Exception $renameEx) {
                // Table may already be vehicle_units or vehicles may not exist
            }
        }

        $vStmt = $localPdo->prepare('SELECT * FROM vehicle_units WHERE id = ?');
        $vStmt->execute([$vehicleId]);
        $vehicle = $vStmt->fetch(PDO::FETCH_ASSOC);

        if ($vehicle && !\App\Service\RegulatedDataScopeService::canAccess($localPdo, 'vehicle_unit', 'vehicle_units', $vehicleId)) {
            $vehicle = null;
        }

        if (!$vehicle) {
            $entityNotFound = true;
            $old = [];
        } else {
            $old = $vehicle;
        }

        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $vehicle = null;
        $old = [];
        $dbError = 'Не удалось подключиться к базе данных компании.';
    }

    ob_start();
    require base_path('app/View/pages/company_vehicle_edit.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.vehicle_units');

// --- Vehicle Edit (handle) ---
$router->post('/company/vehicles/{id}/edit', function ($vehicleId) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $vehicleId = (int)$vehicleId;
    $pageTitle = 'Редактировать транспортную единицу';
    $pageContext = 'Транспортные единицы › Компания';
    $entityNotFound = false;

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $errors = [];
    $old = $_POST;
    $formError = null;
    $success = false;

    if ($companyId <= 0) {
        $company = null;
        $vehicle = null;
        $dbError = null;
        $formError = 'Компания не найдена';

        ob_start();
        require base_path('app/View/pages/company_vehicle_edit.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company) {
            $company = null;
            $vehicle = null;
            $dbError = null;
            $formError = 'Компания не найдена';

            ob_start();
            require base_path('app/View/pages/company_vehicle_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Транспортные единицы › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $vehicle = null;
            $dbError = null;
            $formError = 'Редактирование транспорта недоступно';

            ob_start();
            require base_path('app/View/pages/company_vehicle_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM vehicle_units LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/005_create_company_vehicles.sql'));
            if ($migrationSql !== false) {
                $localPdo->exec($migrationSql);
            }
            try {
                $localPdo->exec("RENAME TABLE vehicles TO vehicle_units");
            } catch (\Exception $renameEx) {
                // Table may already be vehicle_units or vehicles may not exist
            }
        }

        $vStmt = $localPdo->prepare('SELECT * FROM vehicle_units WHERE id = ?');
        $vStmt->execute([$vehicleId]);
        $vehicle = $vStmt->fetch(PDO::FETCH_ASSOC);

        if ($vehicle && !\App\Service\RegulatedDataScopeService::canAccess($localPdo, 'vehicle_unit', 'vehicle_units', $vehicleId)) {
            http_response_code(403);
            $vehicle = null;
            $formError = 'У вас нет доступа к этой записи.';
        }

        if (!$vehicle) {
            $entityNotFound = true;
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_vehicle_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $plateNumber = trim($_POST['plate_number'] ?? '');
        $unitType = trim($_POST['unit_type'] ?? '');
        $vinAbsent = (string) ($_POST['vin_absent'] ?? '') === '1';
        if ($vinAbsent && trim((string) ($_POST['vin'] ?? '')) !== '') $errors['vin'] = 'При отметке об отсутствии VIN оставьте поле пустым';

        if ($plateNumber === '') {
            $errors['plate_number'] = 'Обязательное поле';
        }
        if ($unitType === '') {
            $errors['unit_type'] = 'Укажите тип транспортной единицы';
        }

        if (empty($errors['plate_number'])) {
            $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM vehicle_units WHERE plate_number = ? AND id != ?');
            $checkStmt->execute([$plateNumber, $vehicleId]);
            if ($checkStmt->fetchColumn() > 0) {
                $errors['plate_number'] = 'Госномер уже используется в этой компании';
            }
        }

        if (!empty($errors)) {
            ob_start();
            require base_path('app/View/pages/company_vehicle_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $vin = trim($_POST['vin'] ?? '');
        $stsNumber = trim($_POST['sts_number'] ?? '');
        $ptsNumber = trim($_POST['pts_number'] ?? '');
        $capacityTons = trim($_POST['capacity_tons'] ?? '');
        $volumeM3 = trim($_POST['volume_m3'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        $comments = trim($_POST['comments'] ?? '');

        $update = $localPdo->prepare(
            'UPDATE vehicle_units SET plate_number = :plate_number, brand = :brand, model = :model,
              unit_type = :unit_type, vin = :vin, vin_absent = :vin_absent, sts_number = :sts_number,
             pts_number = :pts_number, capacity_tons = :capacity_tons, volume_m3 = :volume_m3,
             status = :status, comments = :comments
             WHERE id = :id'
        );
        $update->execute([
            ':plate_number'  => $plateNumber,
            ':brand'         => $brand !== '' ? $brand : null,
            ':model'         => $model !== '' ? $model : null,
            ':unit_type'     => $unitType,
            ':vin'           => $vin !== '' ? $vin : null,
            ':vin_absent'    => $vinAbsent ? 1 : 0,
            ':sts_number'    => $stsNumber !== '' ? $stsNumber : null,
            ':pts_number'    => $ptsNumber !== '' ? $ptsNumber : null,
            ':capacity_tons' => $capacityTons !== '' ? $capacityTons : null,
            ':volume_m3'     => $volumeM3 !== '' ? $volumeM3 : null,
            ':status'        => $status,
            ':comments'      => $comments !== '' ? $comments : null,
            ':id'            => $vehicleId,
        ]);

        $vStmt = $localPdo->prepare('SELECT * FROM vehicle_units WHERE id = ?');
        $vStmt->execute([$vehicleId]);
        $vehicle = $vStmt->fetch(PDO::FETCH_ASSOC);
        $success = true;
        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $vehicle = $vehicle ?? null;
        $dbError = null;
        $formError = 'Ошибка обновления транспорта: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_vehicle_edit.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.vehicle_units');

// --- Vehicle Archive ---
$router->post('/company/vehicles/{id}/archive', function ($vehicleId) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $vehicleId = (int)$vehicleId;

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        header('Location: /company/vehicles');
        exit;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || $company['status'] !== 'active') {
            header('Location: /company/vehicles');
            exit;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM vehicle_units LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/005_create_company_vehicles.sql'));
            if ($migrationSql !== false) {
                $localPdo->exec($migrationSql);
            }
            try {
                $localPdo->exec("RENAME TABLE vehicles TO vehicle_units");
            } catch (\Exception $renameEx) {
                // Table may already be vehicle_units or vehicles may not exist
            }
        }

        try {
            $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'));
            $localPdo->exec($migrationSql);
        }

        $vStmt = $localPdo->prepare('SELECT * FROM vehicle_units WHERE id = ?');
        $vStmt->execute([$vehicleId]);
        $vehicleForDelete = $vStmt->fetch(PDO::FETCH_ASSOC);
        if ($vehicleForDelete && !\App\Service\RegulatedDataScopeService::canAccess($localPdo, 'vehicle_unit', 'vehicle_units', $vehicleId)) {
            http_response_code(403);
            return;
        }

        $crewCheck = $localPdo->prepare('SELECT COUNT(*) FROM crews WHERE vehicle_id = ? AND status = ?');
        $crewCheck->execute([$vehicleId, 'active']);
        if ($crewCheck->fetchColumn() > 0) {
            $pageTitle = 'Невозможно удалить';
            $pageContext = 'Транспортные единицы › Компания';
            $companyError = false;
            $company = $company;
            $message = 'Транспорт используется в активных экипажах. Сначала удалите транспорт из всех экипажей.';

            ob_start();
            echo '<div class="page-head"><div><h1>' . e($pageTitle) . '</h1></div></div>';
            echo '<div class="notice warn">' . e($message) . '</div>';
            echo '<div class="form-actions"><a href="/company/vehicles/' . $vehicleId . '" class="btn btn-ghost">< К просмотру</a></div>';
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $rl = (string)($_SESSION['role_code'] ?? '');
        $update = $localPdo->prepare("UPDATE vehicle_units SET deleted_at = NOW(), deleted_by_user_id = ?, deleted_by_role = ? WHERE id = ?");
        $update->execute([$uid, $rl, $vehicleId]);

        if ($vehicleForDelete) {
            $displayName = $vehicleForDelete['plate_number'] ?? '#' . $vehicleId;
            $snapshot = json_encode($vehicleForDelete, JSON_UNESCAPED_UNICODE);
            $un = $_SESSION['user_name'] ?? '';
            try {
                $cp = $db->connection();
                \App\Service\AuditService::recordDeletion($cp, $company, 'vehicle_unit', $vehicleId, 'vehicle_units', $displayName, $uid, $rl, $un, null, $snapshot);
            } catch (\Exception $auditEx) {
                error_log('Audit failed for vehicle ' . $vehicleId . ': ' . $auditEx->getMessage());
            }
        }

        header('Location: /company/vehicles/' . $vehicleId);
        exit;
    } catch (\Exception $e) {
        header('Location: /company/vehicles');
        exit;
    }
}, 'company.vehicle_units');
