<?php

$router->post('/company/crews/create', function () use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $pageTitle = 'Создать экипаж';
    $pageContext = 'Экипажи › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $errors = [];
    $old = $_POST;
    $formError = null;
    $success = false;
    $createdCrew = null;
    $blockingNotices = [];
    $contractors = [];
    $driverVehicleBlocks = [];

    if ($companyId <= 0) {
        $company = null;
        $formError = 'Компания не найдена';
        ob_start(); require base_path('app/View/pages/company_crews_create.php');
        $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
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
            ob_start(); require base_path('app/View/pages/company_crews_create.php');
            $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Экипажи › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $formError = 'Создание экипажа недоступно';
            ob_start(); require base_path('app/View/pages/company_crews_create.php');
            $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'));
            $localPdo->exec($migrationSql);
        }

        $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
        $userId = (int)$_SESSION['user_id'];

        if ($isLogist) {
            $cStmt = $localPdo->prepare("SELECT id, name, inn FROM contractors WHERE status = 'active' AND deleted_at IS NULL AND (created_by_user_id = ? OR id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'contractor' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL)) ORDER BY name");
            $cStmt->execute([$userId, $userId]);
            $contractors = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $contractors = $localPdo->query("SELECT id, name, inn FROM contractors WHERE status = 'active' AND deleted_at IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($isLogist) {
            $dvbStmt = $localPdo->prepare("SELECT dvb.id, d.full_name AS driver_name, d.phone AS driver_phone, vs.set_type, vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate FROM driver_vehicle_blocks dvb JOIN drivers d ON dvb.driver_id = d.id JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id WHERE dvb.status = 'active' AND dvb.deleted_at IS NULL AND (dvb.created_by_user_id = ? OR dvb.id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'driver_vehicle_block' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL)) ORDER BY d.full_name");
            $dvbStmt->execute([$userId, $userId]);
            $driverVehicleBlocks = $dvbStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $driverVehicleBlocks = $localPdo->query("SELECT dvb.id, d.full_name AS driver_name, d.phone AS driver_phone, vs.set_type, vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate FROM driver_vehicle_blocks dvb JOIN drivers d ON dvb.driver_id = d.id JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id WHERE dvb.status = 'active' AND dvb.deleted_at IS NULL ORDER BY d.full_name")->fetchAll(PDO::FETCH_ASSOC);
        }

        $blockingNotices = [];
        if (empty($contractors)) {
            $blockingNotices[] = ['message' => 'Сначала создайте подрядчика.', 'link' => '/company/contractors/create', 'action' => 'Создать подрядчика'];
        }
        if (empty($driverVehicleBlocks)) {
            $blockingNotices[] = ['message' => 'Сначала создайте связку «Водители+ТС».', 'link' => '/company/driver-vehicle-blocks/create', 'action' => 'Создать связку'];
        }

        $contractorId = trim($_POST['contractor_id'] ?? '');
        $driverVehicleBlockId = trim($_POST['driver_vehicle_block_id'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        $comments = trim($_POST['comments'] ?? '');

        if ($contractorId === '') {
            $errors['contractor_id'] = 'Обязательное поле';
        }
        if ($driverVehicleBlockId === '') {
            $errors['driver_vehicle_block_id'] = 'Обязательное поле';
        }

        // Backend validation: logist can only use their own contractors and blocks
        if ($contractorId !== '' && $isLogist) {
            $cCheck = $localPdo->prepare("SELECT created_by_user_id FROM contractors WHERE id = ?");
            $cCheck->execute([(int)$contractorId]);
            $cOwner = $cCheck->fetchColumn();
            if ($cOwner === false) {
                $errors['contractor_id'] = 'Перевозчик не найден.';
            } elseif ((int)$cOwner !== $userId) {
                $cGrant = $localPdo->prepare("SELECT COUNT(*) FROM entity_access_grants WHERE entity_type = 'contractor' AND entity_id = ? AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL");
                $cGrant->execute([(int)$contractorId, $userId]);
                if ($cGrant->fetchColumn() == 0) {
                    $errors['contractor_id'] = 'Перевозчик недоступен.';
                }
            }
        }

        if ($driverVehicleBlockId !== '' && $isLogist) {
            $bCheck = $localPdo->prepare("SELECT created_by_user_id FROM driver_vehicle_blocks WHERE id = ?");
            $bCheck->execute([(int)$driverVehicleBlockId]);
            $bOwner = $bCheck->fetchColumn();
            if ($bOwner === false) {
                $errors['driver_vehicle_block_id'] = 'Связка не найдена.';
            } elseif ((int)$bOwner !== $userId) {
                $bGrant = $localPdo->prepare("SELECT COUNT(*) FROM entity_access_grants WHERE entity_type = 'driver_vehicle_block' AND entity_id = ? AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL");
                $bGrant->execute([(int)$driverVehicleBlockId, $userId]);
                if ($bGrant->fetchColumn() == 0) {
                    $errors['driver_vehicle_block_id'] = 'Связка недоступна.';
                }
            }
        }

        if ($contractorId !== '' && $driverVehicleBlockId !== '') {
            $dupStmt = $localPdo->prepare('SELECT COUNT(*) FROM crews WHERE contractor_id = ? AND driver_vehicle_block_id = ?');
            $dupStmt->execute([(int)$contractorId, (int)$driverVehicleBlockId]);
            if ($dupStmt->fetchColumn() > 0) {
                $errors['driver_vehicle_block_id'] = 'Такой экипаж уже существует.';
            }
        }

        if (!empty($errors)) {
            ob_start(); require base_path('app/View/pages/company_crews_create.php');
            $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
            return;
        }

        $insert = $localPdo->prepare(
            'INSERT INTO crews (contractor_id, driver_vehicle_block_id, status, comments, created_by_user_id, created_by_role)
             VALUES (:contractor_id, :driver_vehicle_block_id, :status, :comments, :uid, :role)'
        );
        $insert->execute([
            ':contractor_id' => (int)$contractorId,
            ':driver_vehicle_block_id' => (int)$driverVehicleBlockId,
            ':status' => $status,
            ':comments' => $comments !== '' ? $comments : null,
            ':uid' => (int)$_SESSION['user_id'],
            ':role' => $_SESSION['role_code'],
        ]);

        $newId = (int)$localPdo->lastInsertId();
        $ctrName = $localPdo->prepare("SELECT name FROM contractors WHERE id = ?");
        $ctrName->execute([(int)$contractorId]);
        $createdCrew = [
            'id' => $newId,
            'contractor_name' => $ctrName->fetchColumn() ?: '',
        ];
        $success = true;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $formError = 'Ошибка создания экипажа: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_crews_create.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.route_executors');

// --- Crew View ---
$router->get('/company/crews/{id}', function ($crewId) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $crewId = (int)$crewId;
    $pageTitle = 'Экипаж';
    $pageContext = 'Экипажи › Компания';
    $entityNotFound = false;
    $grants = [];
    $logists = [];

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $crew = null;
        $dbError = null;

        ob_start();
        require base_path('app/View/pages/company_crew_view.php');
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
            $crew = null;
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_crew_view.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Экипажи › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $crew = null;
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_crew_view.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'));
            $localPdo->exec($migrationSql);
        }

        try {
            $localPdo->query("SELECT created_by_user_id FROM crews LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $localPdo->exec("ALTER TABLE crews ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL");
        }

        try {
            $localPdo->query("SELECT 1 FROM entity_access_grants LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/009_create_entity_access_grants.sql'));
            $localPdo->exec($migrationSql);
        }

        $cStmt = $localPdo->prepare(
            "SELECT c.*,
                    ct.name AS contractor_name, ct.inn AS contractor_inn,
                    d.full_name AS driver_name, d.phone AS driver_phone,
                    dvb.id AS driver_vehicle_block_id,
                    vs.set_type, vs.id AS vehicle_set_id,
                    vu1.plate_number AS primary_plate, vu1.brand AS primary_brand, vu1.model AS primary_model,
                    vu2.plate_number AS secondary_plate, vu2.brand AS secondary_brand, vu2.model AS secondary_model
             FROM crews c
             JOIN contractors ct ON c.contractor_id = ct.id
             JOIN driver_vehicle_blocks dvb ON c.driver_vehicle_block_id = dvb.id
             JOIN drivers d ON dvb.driver_id = d.id
             JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
             LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
             LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
             WHERE c.id = ?"
        );
        $cStmt->execute([$crewId]);
        $crew = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$crew) {
            $entityNotFound = true;
        }

        // Access check for logist
        $accessDenied = null;
        if ($crew) {
            $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
            if ($isLogist) {
                $userId = (int)$_SESSION['user_id'];
                if (!hasRouteExecutorAccess($localPdo, $crew, $userId, 'view')) {
                    $accessDenied = 'У вас нет доступа к этой записи.';
                }
            }
        }

        $pageTitle = ($crew && !$accessDenied) ? 'Экипаж #' . $crew['id'] : 'Экипаж';

        $grants = [];
        $logists = [];
        if (($_SESSION['role_code'] ?? '') === 'company_owner') {
            $grantsStmt = $localPdo->prepare(
                "SELECT g.*, u.full_name AS logist_name
                 FROM entity_access_grants g
                 LEFT JOIN users u ON g.granted_to_user_id = u.id
                 WHERE g.entity_type = ? AND g.entity_id = ?"
            );
            $grantsStmt->execute(['crew', $crewId]);
            $grants = $grantsStmt->fetchAll(PDO::FETCH_ASSOC);

            $logists = $localPdo->query("SELECT id, full_name, login FROM users WHERE role_code IN ('logist', 'senior_logist') AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        }

        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $crew = null;
        $grants = [];
        $logists = [];
        $dbError = 'Не удалось подключиться к базе данных компании.';
    }

    ob_start();
    require base_path('app/View/pages/company_crew_view.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.route_executors');

// --- Crew Edit (form) ---
$router->get('/company/crews/{id}/edit', function ($crewId) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $crewId = (int)$crewId;
    $pageTitle = 'Редактировать экипаж';
    $pageContext = 'Экипажи › Компания';
    $entityNotFound = false;

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $success = false;
    $formError = null;
    $errors = [];

    if ($companyId <= 0) {
        $company = null;
        $crew = null;
        $old = [];
        $dbError = null;
        $contractors = [];
        $driverVehicleBlocks = [];
        $blockingNotices = [];

        ob_start();
        require base_path('app/View/pages/company_crew_edit.php');
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
            $crew = null;
            $old = [];
            $dbError = null;
            $contractors = [];
            $driverVehicleBlocks = [];
            $blockingNotices = [];

            ob_start();
            require base_path('app/View/pages/company_crew_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Экипажи › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $crew = null;
            $old = [];
            $dbError = null;
            $contractors = [];
            $driverVehicleBlocks = [];
            $blockingNotices = [];

            ob_start();
            require base_path('app/View/pages/company_crew_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'));
            $localPdo->exec($migrationSql);
        }

        $cStmt = $localPdo->prepare('SELECT * FROM crews WHERE id = ?');
        $cStmt->execute([$crewId]);
        $crew = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$crew) {
            $entityNotFound = true;
            $old = [];
            $contractors = [];
            $driverVehicleBlocks = [];
            $blockingNotices = [];
        } else {
            // --- PERMISSION CHECK (view or edit for GET) ---
            $currentUserId = (int)($_SESSION['user_id'] ?? 0);
            $currentRole = $_SESSION['role_code'] ?? '';

            if ($currentRole !== 'company_owner' && $currentRole !== 'senior_logist') {
                $isCreator = ($crew['created_by_user_id'] ?? 0) === $currentUserId;

                $grantStmt = $localPdo->prepare(
                    "SELECT 1 FROM entity_access_grants
                     WHERE entity_type = 'crew' AND entity_id = ?
                     AND granted_to_user_id = ? AND access_level = 'edit'
                     AND (revoked_at IS NULL)"
                );
                $grantStmt->execute([$crewId, $currentUserId]);
                $hasGrant = (bool)$grantStmt->fetchColumn();

                if (!$isCreator && !$hasGrant) {
                    http_response_code(403);
                    header('Content-Type: text/plain');
                    echo '403 Forbidden';
                    exit;
                }
            }

            $old = $crew;

            $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
            $userId = (int)$_SESSION['user_id'];

            if ($isLogist) {
                $cStmt = $localPdo->prepare("SELECT id, name, inn FROM contractors WHERE status = 'active' AND deleted_at IS NULL AND (created_by_user_id = ? OR id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'contractor' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL)) ORDER BY name");
                $cStmt->execute([$userId, $userId]);
                $contractors = $cStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $contractors = $localPdo->query("SELECT id, name, inn FROM contractors WHERE status = 'active' AND deleted_at IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            }

            $currentBlockId = (int)($crew['driver_vehicle_block_id'] ?? 0);
            if ($isLogist) {
                $dvbStmt = $localPdo->prepare(
                    "SELECT dvb.id, d.full_name AS driver_name, d.phone AS driver_phone, vs.set_type,
                     vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate
                     FROM driver_vehicle_blocks dvb
                     JOIN drivers d ON dvb.driver_id = d.id
                     JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
                     LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                     LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                     WHERE dvb.deleted_at IS NULL AND (dvb.status = 'active' OR dvb.id = ?)
                       AND (dvb.created_by_user_id = ? OR dvb.id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'driver_vehicle_block' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL))
                     ORDER BY d.full_name"
                );
                $dvbStmt->execute([$currentBlockId, $userId, $userId]);
                $driverVehicleBlocks = $dvbStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $driverVehicleBlocks = $localPdo->query(
                    "SELECT dvb.id, d.full_name AS driver_name, d.phone AS driver_phone, vs.set_type,
                     vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate
                     FROM driver_vehicle_blocks dvb
                     JOIN drivers d ON dvb.driver_id = d.id
                     JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
                     LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                     LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                     WHERE dvb.status = 'active' OR dvb.id = {$currentBlockId}
                     ORDER BY d.full_name"
                )->fetchAll(PDO::FETCH_ASSOC);
            }

            $blockingNotices = [];
            if (empty($contractors)) {
                $blockingNotices[] = [
                    'message' => 'Нет активных подрядчиков.',
                    'link' => '/company/contractors/create',
                    'action' => 'Создать подрядчика',
                ];
            }
            if (empty($driverVehicleBlocks)) {
                $blockingNotices[] = [
                    'message' => 'Нет активных связок.',
                    'link' => '/company/driver-vehicle-blocks/create',
                    'action' => 'Создать связку',
                ];
            }
        }

        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $crew = null;
        $old = [];
        $dbError = 'Не удалось подключиться к базе данных компании.';
        $contractors = [];
        $driverVehicleBlocks = [];
        $blockingNotices = [];
    }

    ob_start();
    require base_path('app/View/pages/company_crew_edit.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.route_executors');

// --- Crew Edit (handle) ---
$router->post('/company/crews/{id}/edit', function ($crewId) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $crewId = (int)$crewId;
    $pageTitle = 'Редактировать экипаж';
    $pageContext = 'Экипажи › Компания';
    $entityNotFound = false;

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $errors = [];
    $old = $_POST;
    $formError = null;
    $success = false;

    if ($companyId <= 0) {
        $company = null;
        $crew = null;
        $dbError = null;
        $contractors = [];
        $driverVehicleBlocks = [];
        $blockingNotices = [];
        $formError = 'Компания не найдена';

        ob_start();
        require base_path('app/View/pages/company_crew_edit.php');
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
            $crew = null;
            $dbError = null;
            $contractors = [];
            $driverVehicleBlocks = [];
            $blockingNotices = [];
            $formError = 'Компания не найдена';

            ob_start();
            require base_path('app/View/pages/company_crew_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Экипажи › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $crew = null;
            $dbError = null;
            $contractors = [];
            $driverVehicleBlocks = [];
            $blockingNotices = [];
            $formError = 'Редактирование экипажа недоступно';

            ob_start();
            require base_path('app/View/pages/company_crew_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'));
            $localPdo->exec($migrationSql);
        }

        $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
        $userId = (int)$_SESSION['user_id'];

        if ($isLogist) {
            $cStmt = $localPdo->prepare("SELECT id, name, inn FROM contractors WHERE status = 'active' AND deleted_at IS NULL AND (created_by_user_id = ? OR id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'contractor' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL)) ORDER BY name");
            $cStmt->execute([$userId, $userId]);
            $contractors = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $contractors = $localPdo->query("SELECT id, name, inn FROM contractors WHERE status = 'active' AND deleted_at IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        }

        $cStmt = $localPdo->prepare('SELECT * FROM crews WHERE id = ?');
        $cStmt->execute([$crewId]);
        $crew = $cStmt->fetch(PDO::FETCH_ASSOC);

        $currentBlockId = $crew ? (int)($crew['driver_vehicle_block_id'] ?? 0) : 0;
        if ($isLogist) {
            $dvbStmt = $localPdo->prepare(
                "SELECT dvb.id, d.full_name AS driver_name, d.phone AS driver_phone, vs.set_type,
                 vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate
                 FROM driver_vehicle_blocks dvb
                 JOIN drivers d ON dvb.driver_id = d.id
                 JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
                 LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                 LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                 WHERE (dvb.status = 'active'" . ($currentBlockId > 0 ? " OR dvb.id = ?" : "") . ")
                   AND (dvb.created_by_user_id = ? OR dvb.id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'driver_vehicle_block' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL))
                 ORDER BY d.full_name"
            );
            $params = $currentBlockId > 0 ? [$currentBlockId, $userId, $userId] : [$userId, $userId];
            $dvbStmt->execute($params);
            $driverVehicleBlocks = $dvbStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $driverVehicleBlocks = $localPdo->query(
                "SELECT dvb.id, d.full_name AS driver_name, d.phone AS driver_phone, vs.set_type,
                 vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate
                 FROM driver_vehicle_blocks dvb
                 JOIN drivers d ON dvb.driver_id = d.id
                 JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
                 LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                 LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                 WHERE dvb.status = 'active' " . ($currentBlockId > 0 ? "OR dvb.id = " . $currentBlockId : "") . "
                 ORDER BY d.full_name"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $blockingNotices = [];
        if (empty($contractors)) {
            $blockingNotices[] = [
                'message' => 'Нет активных подрядчиков.',
                'link' => '/company/contractors/create',
                'action' => 'Создать подрядчика',
            ];
        }
        if (empty($driverVehicleBlocks)) {
            $blockingNotices[] = [
                'message' => 'Нет активных связок «Водители+ТС».',
                'link' => '/company/driver-vehicle-blocks/create',
                'action' => 'Создать блок',
            ];
        }

        if (!empty($blockingNotices)) {
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_crew_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        if (!$crew) {
            $entityNotFound = true;
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_crew_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        // --- PERMISSION CHECK (edit required for POST) ---
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $currentRole = $_SESSION['role_code'] ?? '';

        if ($currentRole !== 'company_owner' && $currentRole !== 'senior_logist') {
            $isCreator = ($crew['created_by_user_id'] ?? 0) === $currentUserId;

            $grantStmt = $localPdo->prepare(
                "SELECT 1 FROM entity_access_grants
                 WHERE entity_type = 'crew' AND entity_id = ?
                 AND granted_to_user_id = ? AND access_level = 'edit'
                 AND (revoked_at IS NULL)"
            );
            $grantStmt->execute([$crewId, $currentUserId]);
            $hasEditGrant = (bool)$grantStmt->fetchColumn();

            if (!$isCreator && !$hasEditGrant) {
                http_response_code(403);
                header('Content-Type: text/plain');
                echo '403 Forbidden';
                exit;
            }
        }

        $contractorId = trim($_POST['contractor_id'] ?? '');
        $driverVehicleBlockId = trim($_POST['driver_vehicle_block_id'] ?? '');

        if ($contractorId === '') {
            $errors['contractor_id'] = 'Выберите подрядчика';
        } else {
            $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM contractors WHERE id = ? AND status = ?');
            $checkStmt->execute([$contractorId, 'active']);
            if ($checkStmt->fetchColumn() == 0) {
                $errors['contractor_id'] = 'Подрядчик не найден';
            }
        }

        if ($driverVehicleBlockId === '') {
            $errors['driver_vehicle_block_id'] = 'Выберите связку «Водители+ТС»';
        } else {
            $checkDvbStmt = $localPdo->prepare('SELECT COUNT(*) FROM driver_vehicle_blocks WHERE id = ?');
            $checkDvbStmt->execute([(int)$driverVehicleBlockId]);
            if ($checkDvbStmt->fetchColumn() == 0) {
                $errors['driver_vehicle_block_id'] = 'Связка «Водители+ТС» не найдена';
            }
        }

        // Backend access validation for logist
        if (empty($errors) && $isLogist) {
            $cCheck = $localPdo->prepare("SELECT created_by_user_id FROM contractors WHERE id = ?");
            $cCheck->execute([(int)$contractorId]);
            $cOwner = $cCheck->fetchColumn();
            if ($cOwner !== false && (int)$cOwner !== $userId) {
                $cGrant = $localPdo->prepare("SELECT COUNT(*) FROM entity_access_grants WHERE entity_type = 'contractor' AND entity_id = ? AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL");
                $cGrant->execute([(int)$contractorId, $userId]);
                if ($cGrant->fetchColumn() == 0) {
                    $errors['contractor_id'] = 'Перевозчик недоступен.';
                }
            }

            $bCheck = $localPdo->prepare("SELECT created_by_user_id FROM driver_vehicle_blocks WHERE id = ?");
            $bCheck->execute([(int)$driverVehicleBlockId]);
            $bOwner = $bCheck->fetchColumn();
            if ($bOwner !== false && (int)$bOwner !== $userId) {
                $bGrant = $localPdo->prepare("SELECT COUNT(*) FROM entity_access_grants WHERE entity_type = 'driver_vehicle_block' AND entity_id = ? AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL");
                $bGrant->execute([(int)$driverVehicleBlockId, $userId]);
                if ($bGrant->fetchColumn() == 0) {
                    $errors['driver_vehicle_block_id'] = 'Связка недоступна.';
                }
            }
        }

        if (empty($errors)) {
            $dupStmt = $localPdo->prepare(
                'SELECT COUNT(*) FROM crews WHERE contractor_id = ? AND driver_vehicle_block_id = ? AND id != ?'
            );
            $dupStmt->execute([$contractorId, $driverVehicleBlockId, $crewId]);
            if ($dupStmt->fetchColumn() > 0) {
                $formError = 'Такой экипаж уже существует в этой компании';
            }
        }

        if (!empty($errors) || $formError) {
            ob_start();
            require base_path('app/View/pages/company_crew_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $status = trim($_POST['status'] ?? 'active');
        $comments = trim($_POST['comments'] ?? '');

        $update = $localPdo->prepare(
            'UPDATE crews SET contractor_id = :contractor_id, driver_vehicle_block_id = :driver_vehicle_block_id,
             status = :status, comments = :comments,
             updated_by_user_id = :uid, updated_by_role = :role
             WHERE id = :id'
        );
        $update->execute([
            ':contractor_id' => $contractorId,
            ':driver_vehicle_block_id' => $driverVehicleBlockId,
            ':status'        => $status,
            ':comments'      => $comments !== '' ? $comments : null,
            ':uid'           => (int)$_SESSION['user_id'],
            ':role'          => $_SESSION['role_code'],
            ':id'            => $crewId,
        ]);

        $cStmt = $localPdo->prepare(
            "SELECT c.*,
                    ct.name AS contractor_name,
                    vu1.plate_number,
                    d.full_name AS driver_name
             FROM crews c
             JOIN contractors ct ON c.contractor_id = ct.id
             JOIN driver_vehicle_blocks dvb ON c.driver_vehicle_block_id = dvb.id
             JOIN drivers d ON dvb.driver_id = d.id
             JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
             LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
             WHERE c.id = ?"
        );
        $cStmt->execute([$crewId]);
        $crew = $cStmt->fetch(PDO::FETCH_ASSOC);
        $success = true;
        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $crew = $crew ?? null;
        $dbError = null;
        $contractors = $contractors ?? [];
        $driverVehicleBlocks = $driverVehicleBlocks ?? [];
        $blockingNotices = $blockingNotices ?? [];
        $formError = 'Ошибка обновления экипажа: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_crew_edit.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
}, 'company.route_executors');

// --- Crew Archive ---
$router->post('/company/crews/{id}/archive', function ($crewId) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $crewId = (int)$crewId;

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        header('Location: /company/crews');
        exit;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || $company['status'] !== 'active') {
            header('Location: /company/crews');
            exit;
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'));
            $localPdo->exec($migrationSql);
        }

        // --- PERMISSION CHECK ---
        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $currentRole = $_SESSION['role_code'] ?? '';

        $crewStmt = $localPdo->prepare('SELECT * FROM crews WHERE id = ?');
        $crewStmt->execute([$crewId]);
        $crew = $crewStmt->fetch(PDO::FETCH_ASSOC);

        if (!$crew) {
            header('Location: /company/crews');
            exit;
        }

        $currentUserId = (int)($_SESSION['user_id'] ?? 0);
        $currentRole = $_SESSION['role_code'] ?? '';

        if ($currentRole !== 'company_owner' && $currentRole !== 'senior_logist') {
            $isCreator = ($crew['created_by_user_id'] ?? 0) === $currentUserId;

            $grantStmt = $localPdo->prepare(
                "SELECT 1 FROM entity_access_grants
                 WHERE entity_type = 'crew' AND entity_id = ?
                 AND granted_to_user_id = ? AND access_level = 'edit'
                 AND (revoked_at IS NULL)"
            );
            $grantStmt->execute([$crewId, $currentUserId]);
            $hasEditGrant = (bool)$grantStmt->fetchColumn();

            if (!$isCreator && !$hasEditGrant) {
                http_response_code(403);
                header('Content-Type: text/plain');
                echo '403 Forbidden';
                exit;
            }
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $rl = (string)($_SESSION['role_code'] ?? '');
        $update = $localPdo->prepare("UPDATE crews SET deleted_at = NOW(), deleted_by_user_id = ?, deleted_by_role = ? WHERE id = ?");
        $update->execute([$uid, $rl, $crewId]);

        $displayName = 'Экипаж #' . $crewId;
        if (!empty($crew['contractor_name'])) {
            $displayName = $crew['contractor_name'];
        }
        $snapshot = json_encode($crew, JSON_UNESCAPED_UNICODE);
        $un = $_SESSION['user_name'] ?? '';
        try {
            $cp = $db->connection();
            \App\Service\AuditService::recordDeletion($cp, $company, 'crew', $crewId, 'crews', $displayName, $uid, $rl, $un, null, $snapshot);
        } catch (\Exception $auditEx) {
            error_log('Audit failed for crew ' . $crewId . ': ' . $auditEx->getMessage());
        }

        header('Location: /company/crews');
        exit;
    } catch (\Exception $e) {
        header('Location: /company/crews');
        exit;
    }
}, 'company.route_executors');

// ---- VEHICLE SETS (Транспорт) ----
