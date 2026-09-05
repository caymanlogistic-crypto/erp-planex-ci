<?php

$router->post('/company/driver-vehicle-blocks/create', function () use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $pageTitle = 'Создать связку';
    $pageContext = 'Водители+ТС › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $errors = []; $old = $_POST; $formError = null; $success = false; $createdBlock = null; $drivers = []; $vehicleSets = [];

    if ($companyId <= 0) { $company = null; $formError = 'Компания не найдена'; goto renderPostBlock; }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]); $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) { $company = null; $formError = 'Компания не найдена'; goto renderPostBlock; }
        if ($company['status'] !== 'active') { $formError = 'Создание недоступно'; goto renderPostBlock; }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig); $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
        $userId = (int)$_SESSION['user_id'];

        if ($isLogist) {
            $dStmt = $localPdo->prepare("SELECT * FROM drivers WHERE status = 'active' AND deleted_at IS NULL AND (created_by_user_id = ? OR id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'driver' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL)) ORDER BY full_name");
            $dStmt->execute([$userId, $userId]);
            $drivers = $dStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $drivers = $localPdo->query("SELECT * FROM drivers WHERE status = 'active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($isLogist) {
            $vsStmt = $localPdo->prepare("SELECT vs.*, vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate FROM vehicle_sets vs LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id WHERE vs.status = 'active' AND vs.deleted_at IS NULL AND (vs.created_by_user_id = ? OR vs.id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'vehicle_set' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL)) ORDER BY vs.id");
            $vsStmt->execute([$userId, $userId]);
            $vehicleSets = $vsStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $vehicleSets = $localPdo->query("SELECT vs.*, vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate FROM vehicle_sets vs LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id WHERE vs.status = 'active' AND vs.deleted_at IS NULL ORDER BY vs.id")->fetchAll(PDO::FETCH_ASSOC);
        }

        $driverId = trim($_POST['driver_id'] ?? '');
        $vehicleSetId = trim($_POST['vehicle_set_id'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        $comments = trim($_POST['comments'] ?? '');

        if ($driverId === '') { $errors['driver_id'] = 'Обязательное поле'; }
        if ($vehicleSetId === '') { $errors['vehicle_set_id'] = 'Обязательное поле'; }

        // Backend validation: logist can only use their own drivers/vehicle_sets
        if ($driverId !== '' && $isLogist) {
            $dCheck = $localPdo->prepare("SELECT created_by_user_id FROM drivers WHERE id = ?");
            $dCheck->execute([(int)$driverId]);
            $dOwner = $dCheck->fetchColumn();
            if ($dOwner === false) {
                $errors['driver_id'] = 'Водитель не найден.';
            } elseif ((int)$dOwner !== $userId) {
                $dGrant = $localPdo->prepare("SELECT COUNT(*) FROM entity_access_grants WHERE entity_type = 'driver' AND entity_id = ? AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL");
                $dGrant->execute([(int)$driverId, $userId]);
                if ($dGrant->fetchColumn() == 0) {
                    $errors['driver_id'] = 'Водитель недоступен.';
                }
            }
        }

        if ($vehicleSetId !== '' && $isLogist) {
            $vsCheck = $localPdo->prepare("SELECT created_by_user_id FROM vehicle_sets WHERE id = ?");
            $vsCheck->execute([(int)$vehicleSetId]);
            $vsOwner = $vsCheck->fetchColumn();
            if ($vsOwner === false) {
                $errors['vehicle_set_id'] = 'Транспорт не найден.';
            } elseif ((int)$vsOwner !== $userId) {
                $vsGrant = $localPdo->prepare("SELECT COUNT(*) FROM entity_access_grants WHERE entity_type = 'vehicle_set' AND entity_id = ? AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL");
                $vsGrant->execute([(int)$vehicleSetId, $userId]);
                if ($vsGrant->fetchColumn() == 0) {
                    $errors['vehicle_set_id'] = 'Транспорт недоступен.';
                }
            }
        }

        if ($driverId !== '' && $vehicleSetId !== '') {
            $dupStmt = $localPdo->prepare('SELECT COUNT(*) FROM driver_vehicle_blocks WHERE driver_id = ? AND vehicle_set_id = ?');
            $dupStmt->execute([(int)$driverId, (int)$vehicleSetId]);
            if ($dupStmt->fetchColumn() > 0) {
                $errors['driver_id'] = 'Такая связка уже существует.';
            }
        }

        if (!empty($errors)) { goto renderPostBlock; }

        $insert = $localPdo->prepare(
            'INSERT INTO driver_vehicle_blocks (driver_id, vehicle_set_id, status, comments, created_by_user_id, created_by_role)
             VALUES (:driver_id, :vehicle_set_id, :status, :comments, :uid, :role)'
        );
        $insert->execute([
            ':driver_id' => (int)$driverId,
            ':vehicle_set_id' => (int)$vehicleSetId,
            ':status' => $status,
            ':comments' => $comments !== '' ? $comments : null,
            ':uid' => (int)$_SESSION['user_id'],
            ':role' => $_SESSION['role_code'],
        ]);

        $newId = (int)$localPdo->lastInsertId();
        $dName = $localPdo->prepare("SELECT full_name FROM drivers WHERE id = ?");
        $dName->execute([(int)$driverId]);
        $createdBlock = [
            'id' => $newId,
            'driver_name' => $dName->fetchColumn() ?: '',
            'set_type' => $vehicleSets[array_search((int)$vehicleSetId, array_column($vehicleSets, 'id'))]['set_type'] ?? '',
        ];
        $success = true;
        ob_start(); require base_path('app/View/pages/company_driver_vehicle_blocks_create.php');
        $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
        return;
    } catch (\Exception $e) {
        $company = $company ?? null; $formError = 'Ошибка: ' . $e->getMessage();
    }

    renderPostBlock:
    ob_start(); require base_path('app/View/pages/company_driver_vehicle_blocks_create.php');
    $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
}, 'company.route_executors');

$router->get('/company/driver-vehicle-blocks/{id}', function ($id) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $pageTitle = 'Водители+ТС';
    $pageContext = 'Водители+ТС › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $grants = []; $logists = []; $linkedContractor = null;

    if ($companyId <= 0) { $company = null; $block = null; $dbError = null; goto renderViewBlock; }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]); $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) { $company = null; $block = null; $dbError = null; goto renderViewBlock; }
        if ($company['status'] !== 'active') { $block = null; $dbError = null; goto renderViewBlock; }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig); $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $stmt = $localPdo->prepare(
            "SELECT dvb.*, d.full_name AS driver_name, d.phone AS driver_phone, vs.set_type,
             vu1.plate_number AS primary_plate, vu1.brand AS primary_brand, vu1.model AS primary_model,
             vu2.plate_number AS secondary_plate, vu2.brand AS secondary_brand, vu2.model AS secondary_model
             FROM driver_vehicle_blocks dvb
             JOIN drivers d ON dvb.driver_id = d.id
             JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
             LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
             LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
             WHERE dvb.id = ?"
        );
        $stmt->execute([(int)$id]); $block = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $accessDenied = null;
        if ($block) {
            $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
            if ($isLogist) {
                $userId = (int)$_SESSION['user_id'];
                $hasGrant = false;
                $gc = $localPdo->prepare("SELECT access_level FROM entity_access_grants WHERE entity_type = 'driver_vehicle_block' AND entity_id = ? AND granted_to_user_id = ? AND revoked_at IS NULL LIMIT 1");
                $gc->execute([(int)$id, $userId]);
                $gr = $gc->fetch(PDO::FETCH_ASSOC);
                $hasGrant = ($gr && in_array($gr['access_level'], ['view', 'edit']));
                if ((int)$block['created_by_user_id'] !== $userId && !$hasGrant) {
                    $accessDenied = 'У вас нет доступа к этой записи.';
                }
            }
        }

        // Загрузка привязанного перевозчика через crews
        $linkedContractor = null;
        if ($block) {
            try {
                $crewStmt = $localPdo->prepare("SELECT c.contractor_id FROM crews c WHERE c.driver_vehicle_block_id = ? AND c.status != 'archived' LIMIT 1");
                $crewStmt->execute([(int)$id]);
                $crewRow = $crewStmt->fetch(PDO::FETCH_ASSOC);
                if ($crewRow && !empty($crewRow['contractor_id'])) {
                    $cStmt = $localPdo->prepare('SELECT id, name FROM contractors WHERE id = ?');
                    $cStmt->execute([(int)$crewRow['contractor_id']]);
                    $contractor = $cStmt->fetch(PDO::FETCH_ASSOC);
                    if ($contractor) {
                        $linkedContractor = ['id' => $contractor['id'], 'name' => $contractor['name']];
                    }
                }
            } catch (\Exception $e) {
                // Игнорируем ошибку загрузки перевозчика
            }
        }

        if ($block && !$accessDenied) { $pageTitle = 'Связка #' . $block['id']; }

        if (($_SESSION['role_code'] ?? '') === 'company_owner') {
            $grantsStmt = $localPdo->prepare("SELECT g.*, u.full_name AS logist_name FROM entity_access_grants g LEFT JOIN users u ON g.granted_to_user_id = u.id WHERE g.entity_type = ? AND g.entity_id = ?");
            $grantsStmt->execute(['driver_vehicle_block', (int)$id]);
            $grants = $grantsStmt->fetchAll(PDO::FETCH_ASSOC);
            $logists = $localPdo->query("SELECT id, full_name, login FROM users WHERE role_code IN ('logist', 'senior_logist') AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        }
        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null; $block = null; $grants = []; $logists = []; $linkedContractor = null;
        $dbError = 'Не удалось загрузить: ' . $e->getMessage();
    }

    renderViewBlock:
    ob_start(); require base_path('app/View/pages/company_driver_vehicle_block_view.php');
    $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
}, 'company.route_executors');

$router->get('/company/driver-vehicle-blocks/{id}/edit', function ($id) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $pageTitle = 'Редактировать связку';
    $pageContext = 'Водители+ТС › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $entityNotFound = false; $success = false; $drivers = []; $vehicleSets = [];
    $blockDriver = null; $blockPlate = null;

    if ($companyId <= 0) { $company = null; $block = null; $errors = []; $old = []; $formError = null; goto renderEditBlock; }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]); $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) { $company = null; $block = null; $errors = []; $old = []; $formError = null; goto renderEditBlock; }
        if ($company['status'] !== 'active') { $block = null; $errors = []; $old = []; $formError = null; goto renderEditBlock; }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig); $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $stmt = $localPdo->prepare('SELECT * FROM driver_vehicle_blocks WHERE id = ?');
        $stmt->execute([(int)$id]); $block = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$block) { $entityNotFound = true; $errors = []; $old = []; $formError = null; goto renderEditBlock; }

        // Загрузка данных водителя и транспорта для информационного блока
        try {
            $dStmt = $localPdo->prepare('SELECT full_name, phone FROM drivers WHERE id = ?');
            $dStmt->execute([(int)$block['driver_id']]);
            $blockDriver = $dStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $vsStmt = $localPdo->prepare(
                "SELECT vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate
                 FROM vehicle_sets vs
                 LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                 LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                 WHERE vs.id = ?"
            );
            $vsStmt->execute([(int)$block['vehicle_set_id']]);
            $vsRow = $vsStmt->fetch(PDO::FETCH_ASSOC);
            if ($vsRow) {
                $blockPlate = $vsRow['primary_plate'] ?? '';
                if (!empty($vsRow['secondary_plate'])) {
                    $blockPlate .= ' + ' . $vsRow['secondary_plate'];
                }
            }
        } catch (\Exception $e) {
            $blockDriver = null; $blockPlate = null;
        }

        $isLogist = ($_SESSION['role_code'] ?? '') === 'logist';
        $userId = (int)$_SESSION['user_id'];

        if ($isLogist) {
            $dStmt = $localPdo->prepare("SELECT * FROM drivers WHERE status = 'active' AND deleted_at IS NULL AND (created_by_user_id = ? OR id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'driver' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL)) ORDER BY full_name");
            $dStmt->execute([$userId, $userId]);
            $drivers = $dStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $drivers = $localPdo->query("SELECT * FROM drivers WHERE status = 'active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($isLogist) {
            $vsStmt = $localPdo->prepare("SELECT vs.*, vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate FROM vehicle_sets vs LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id WHERE vs.status = 'active' AND vs.deleted_at IS NULL AND (vs.created_by_user_id = ? OR vs.id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'vehicle_set' AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL)) ORDER BY vs.id");
            $vsStmt->execute([$userId, $userId]);
            $vehicleSets = $vsStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $vehicleSets = $localPdo->query("SELECT vs.*, vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate FROM vehicle_sets vs LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id WHERE vs.status = 'active' AND vs.deleted_at IS NULL ORDER BY vs.id")->fetchAll(PDO::FETCH_ASSOC);
        }
        $errors = []; $old = $block; $formError = null;
    } catch (\Exception $e) {
        $company = $company ?? null; $block = null; $errors = []; $old = []; $formError = 'Ошибка: ' . $e->getMessage();
        $blockDriver = null; $blockPlate = null;
    }

    renderEditBlock:
    ob_start(); require base_path('app/View/pages/company_driver_vehicle_block_edit.php');
    $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
}, 'company.route_executors');

$router->post('/company/driver-vehicle-blocks/{id}/edit', function ($id) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $pageTitle = 'Редактировать связку';
    $pageContext = 'Водители+ТС › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $entityNotFound = false; $success = false;
    $blockDriver = null; $blockPlate = null;

    if ($companyId <= 0) { $company = null; $block = null; $errors = []; $old = $_POST; $formError = 'Компания не найдена'; goto renderEditBlockPost; }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]); $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) { $company = null; $block = null; $errors = []; $old = $_POST; $formError = 'Компания не найдена'; goto renderEditBlockPost; }
        if ($company['status'] !== 'active') { $block = null; $errors = []; $old = $_POST; $formError = 'Редактирование недоступно'; goto renderEditBlockPost; }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig); $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $stmt = $localPdo->prepare('SELECT * FROM driver_vehicle_blocks WHERE id = ?');
        $stmt->execute([(int)$id]); $block = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$block) { $entityNotFound = true; $errors = []; $old = $_POST; $formError = null; goto renderEditBlockPost; }

        // Загрузка данных водителя и транспорта для информационного блока
        try {
            $dStmt = $localPdo->prepare('SELECT full_name, phone FROM drivers WHERE id = ?');
            $dStmt->execute([(int)$block['driver_id']]);
            $blockDriver = $dStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $vsStmt = $localPdo->prepare(
                "SELECT vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate
                 FROM vehicle_sets vs
                 LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                 LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                 WHERE vs.id = ?"
            );
            $vsStmt->execute([(int)$block['vehicle_set_id']]);
            $vsRow = $vsStmt->fetch(PDO::FETCH_ASSOC);
            if ($vsRow) {
                $blockPlate = $vsRow['primary_plate'] ?? '';
                if (!empty($vsRow['secondary_plate'])) {
                    $blockPlate .= ' + ' . $vsRow['secondary_plate'];
                }
            }
        } catch (\Exception $e) {
            $blockDriver = null; $blockPlate = null;
        }

        $errors = []; $old = $_POST; $formError = null;

        // Immutable: driver_id и vehicle_set_id не принимаются из формы
        // Обновляются только status и comments

        if (!empty($errors)) { goto renderEditBlockPost; }

        $update = $localPdo->prepare(
            'UPDATE driver_vehicle_blocks SET status = :status, comments = :comments, updated_by_user_id = :uid, updated_by_role = :role WHERE id = :id'
        );
        $update->execute([
            ':status' => $_POST['status'] ?? $block['status'],
            ':comments' => $_POST['comments'] ?? null,
            ':uid' => (int)$_SESSION['user_id'],
            ':role' => $_SESSION['role_code'],
            ':id' => (int)$id,
        ]);

        $block = $localPdo->prepare('SELECT * FROM driver_vehicle_blocks WHERE id = ?');
        $block->execute([(int)$id]); $block = $block->fetch(PDO::FETCH_ASSOC);
        $success = true;
    } catch (\Exception $e) {
        $company = $company ?? null; $block = $block ?? null;
        $errors = []; $old = $_POST; $formError = 'Ошибка: ' . $e->getMessage();
        $blockDriver = null; $blockPlate = null;
    }

    renderEditBlockPost:
    ob_start(); require base_path('app/View/pages/company_driver_vehicle_block_edit.php');
    $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
}, 'company.route_executors');

$router->post('/company/driver-vehicle-blocks/{id}/archive', function ($id) use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);
    $companyId = (int)(getSessionCompanyId() ?? 0);
    if ($companyId <= 0) { header('Location: /company/driver-vehicle-blocks'); exit; }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]); $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company || $company['status'] !== 'active') { header('Location: /company/driver-vehicle-blocks'); exit; }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig); $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        try { $localPdo->query("SELECT 1 FROM driver_vehicle_blocks LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/018_create_driver_vehicle_blocks.sql'))); }

        try { $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'))); }

        $blockStmt = $localPdo->prepare('SELECT * FROM driver_vehicle_blocks WHERE id = ?');
        $blockStmt->execute([(int)$id]);
        $block = $blockStmt->fetch(PDO::FETCH_ASSOC);

        $crewCheck = $localPdo->prepare('SELECT COUNT(*) FROM crews WHERE driver_vehicle_block_id = ? AND status = ?');
        $crewCheck->execute([(int)$id, 'active']);
        if ($crewCheck->fetchColumn() > 0) {
            $_SESSION['flash'] = 'Связка используется в активных экипажах. Сначала архивируйте экипажи.';
            header('Location: /company/driver-vehicle-blocks/' . $id); exit;
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        $rl = (string)($_SESSION['role_code'] ?? '');
        $update = $localPdo->prepare("UPDATE driver_vehicle_blocks SET deleted_at = NOW(), deleted_by_user_id = ?, deleted_by_role = ? WHERE id = ?");
        $update->execute([$uid, $rl, (int)$id]);

        if ($block) {
            $displayName = 'Связка #' . $id;
            $snapshot = json_encode($block, JSON_UNESCAPED_UNICODE);
            $un = $_SESSION['user_name'] ?? '';
            try {
                $cp = $db->connection();
                \App\Service\AuditService::recordDeletion($cp, $company, 'driver_vehicle_block', (int)$id, 'driver_vehicle_blocks', $displayName, $uid, $rl, $un, null, $snapshot);
            } catch (\Exception $auditEx) {
                error_log('Audit failed for DVB ' . $id . ': ' . $auditEx->getMessage());
            }
        }

        header('Location: /company/driver-vehicle-blocks'); exit;
    } catch (\Exception $e) {
        header('Location: /company/driver-vehicle-blocks'); exit;
    }
}, 'company.route_executors');
