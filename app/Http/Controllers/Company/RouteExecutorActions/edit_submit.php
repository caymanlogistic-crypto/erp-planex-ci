<?php

    $crewId = (int)$crewId;
    $pageTitle = 'Редактировать исполнителя рейса';
    $pageContext = 'Исполнители рейса › Компания';
    $entityNotFound = false;

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $errors = [];
    $old = $_POST;
    $formError = null;
    $success = false;

    if ($companyId <= 0) {
        $company = null; $crew = null; $dbError = null;
        $contractors = []; $drivers = []; $vehicleSets = [];
        $blockingNotices = [];
        $formError = 'Компания не найдена';

        ob_start();
        require base_path('app/View/pages/company_route_executor_edit.php');
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
            $company = null; $crew = null; $dbError = null;
            $contractors = []; $drivers = []; $vehicleSets = [];
            $blockingNotices = [];
            $formError = 'Компания не найдена';

            ob_start();
            require base_path('app/View/pages/company_route_executor_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Исполнители рейса › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $crew = null; $dbError = null;
            $contractors = []; $drivers = []; $vehicleSets = [];
            $blockingNotices = [];
            $formError = 'Редактирование исполнителя рейса недоступно';

            ob_start();
            require base_path('app/View/pages/company_route_executor_edit.php');
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
            $localPdo->query("SELECT 1 FROM driver_vehicle_blocks LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/018_create_driver_vehicle_blocks.sql'));
            $localPdo->exec($migrationSql);
        }

        $userId = (int)$_SESSION['user_id'];
        $userRole = $_SESSION['role_code'];

        // Load current crew with DV block info
        $cStmt = $localPdo->prepare(
            "SELECT c.*,
                    ct.created_by_user_id AS contractor_created_by_user_id,
                    d.created_by_user_id AS driver_created_by_user_id,
                    dvb.driver_id, dvb.vehicle_set_id, dvb.created_by_user_id AS dvb_created_by_user_id,
                    vs.created_by_user_id AS vehicle_set_created_by_user_id
             FROM crews c
             JOIN contractors ct ON c.contractor_id = ct.id
             JOIN driver_vehicle_blocks dvb ON c.driver_vehicle_block_id = dvb.id
             JOIN drivers d ON dvb.driver_id = d.id
             JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
             WHERE c.id = ?"
        );
        $cStmt->execute([$crewId]);
        $crew = $cStmt->fetch(PDO::FETCH_ASSOC);

        if (!$crew) {
            $entityNotFound = true;
            $dbError = null;
            $contractors = []; $drivers = []; $vehicleSets = [];
            $blockingNotices = [];

            ob_start();
            require base_path('app/View/pages/company_route_executor_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        // Reload dropdowns
        $contractors = $localPdo->query("SELECT id, name, inn FROM contractors WHERE deleted_at IS NULL AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

        $currentDriverId = (int)($crew['driver_id'] ?? 0);
        $drivers = $localPdo->query(
            "SELECT id, full_name, phone FROM drivers
             WHERE deleted_at IS NULL
               AND (status = 'active'" . ($currentDriverId > 0 ? " OR id = " . $currentDriverId : "") . ")
             ORDER BY full_name"
        )->fetchAll(PDO::FETCH_ASSOC);

        $currentVsId = (int)($crew['vehicle_set_id'] ?? 0);
        $vehicleSets = $localPdo->query(
            "SELECT vs.id, vs.set_type, vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate
             FROM vehicle_sets vs
             LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
             LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
             WHERE vs.deleted_at IS NULL
               AND (vs.status = 'active'" . ($currentVsId > 0 ? " OR vs.id = " . $currentVsId : "") . ")
             ORDER BY vs.set_type"
        )->fetchAll(PDO::FETCH_ASSOC);

        $blockingNotices = [];
        if (empty($contractors)) {
            $blockingNotices[] = ['message' => 'Нет активных подрядчиков.', 'link' => '/company/contractors/create', 'action' => 'Создать подрядчика'];
        }
        if (empty($drivers)) {
            $blockingNotices[] = ['message' => 'Нет активных водителей.', 'link' => '/company/drivers', 'action' => 'Создать водителя'];
        }
        if (empty($vehicleSets)) {
            $blockingNotices[] = ['message' => 'Нет активных транспортных комплектов.', 'link' => '/company/vehicle-sets', 'action' => 'Создать ТС'];
        }

        if (!empty($blockingNotices)) {
            $dbError = null;

            ob_start();
            require base_path('app/View/pages/company_route_executor_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $contractorId = trim($_POST['contractor_id'] ?? '');
        $driverId = trim($_POST['driver_id'] ?? '');
        $vehicleSetId = trim($_POST['vehicle_set_id'] ?? '');

        if ($contractorId === '') {
            $errors['contractor_id'] = 'Выберите подрядчика';
        }
        if ($driverId === '') {
            $errors['driver_id'] = 'Выберите водителя';
        }
        if ($vehicleSetId === '') {
            $errors['vehicle_set_id'] = 'Выберите транспортный комплект';
        }

        if (empty($errors)) {
            $cCheck = $localPdo->prepare("SELECT 1 FROM contractors WHERE id = ? AND deleted_at IS NULL AND status = 'active'");
            $cCheck->execute([(int)$contractorId]);
            if (!$cCheck->fetchColumn()) $errors['contractor_id'] = 'Подрядчик не найден или неактивен.';
            $dCheck = $localPdo->prepare("SELECT 1 FROM drivers WHERE id = ? AND deleted_at IS NULL AND status = 'active'");
            $dCheck->execute([(int)$driverId]);
            if (!$dCheck->fetchColumn()) $errors['driver_id'] = 'Водитель не найден или неактивен.';
            $vsCheck = $localPdo->prepare("SELECT 1 FROM vehicle_sets WHERE id = ? AND deleted_at IS NULL AND status = 'active'");
            $vsCheck->execute([(int)$vehicleSetId]);
            if (!$vsCheck->fetchColumn()) $errors['vehicle_set_id'] = 'Транспортный комплект не найден или неактивен.';
        }

        if (!empty($errors)) {
            ob_start();
            require base_path('app/View/pages/company_route_executor_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        // --- Core logic: find or create driver_vehicle_block, then update crew ---
        $localPdo->beginTransaction();
        try {
            // 1. Find or create driver_vehicle_block for new driver+vehicle_set
            $dvbStmt = $localPdo->prepare("SELECT id FROM driver_vehicle_blocks WHERE driver_id = ? AND vehicle_set_id = ? LIMIT 1");
            $dvbStmt->execute([(int)$driverId, (int)$vehicleSetId]);
            $newDvbId = $dvbStmt->fetchColumn();

            if (!$newDvbId) {
                $dvbInsert = $localPdo->prepare(
                    "INSERT INTO driver_vehicle_blocks (driver_id, vehicle_set_id, status, created_by_user_id, created_by_role)
                     VALUES (:driver_id, :vehicle_set_id, 'active', :uid, :role)"
                );
                $dvbInsert->execute([
                    ':driver_id' => (int)$driverId,
                    ':vehicle_set_id' => (int)$vehicleSetId,
                    ':uid' => $userId,
                    ':role' => $userRole,
                ]);
                $newDvbId = (int)$localPdo->lastInsertId();
            }

            // 2. Check duplicate (excluding current crew)
            $dupStmt = $localPdo->prepare(
                "SELECT COUNT(*) FROM crews WHERE contractor_id = ? AND driver_vehicle_block_id = ? AND id != ?"
            );
            $dupStmt->execute([(int)$contractorId, $newDvbId, $crewId]);
            if ($dupStmt->fetchColumn() > 0) {
                $localPdo->rollBack();
                $formError = 'Такой исполнитель рейса уже существует в этой компании.';
                ob_start();
                require base_path('app/View/pages/company_route_executor_edit.php');
                $content = ob_get_clean();
                require base_path('app/View/layouts/main.php');
                return;
            }

            // 3. Fetch primary_vehicle_unit_id for legacy crews.vehicle_id sync
            $vsUnitStmt = $localPdo->prepare("SELECT primary_vehicle_unit_id FROM vehicle_sets WHERE id = ? AND deleted_at IS NULL AND status = 'active'");
            $vsUnitStmt->execute([(int)$vehicleSetId]);
            $primaryVehicleUnitId = $vsUnitStmt->fetchColumn();
            if (!$primaryVehicleUnitId) {
                $localPdo->rollBack();
                $formError = 'Выбранный транспортный комплект не содержит транспортного средства.';
                ob_start();
                require base_path('app/View/pages/company_route_executor_edit.php');
                $content = ob_get_clean();
                require base_path('app/View/layouts/main.php');
                return;
            }

            // 4. Update crew (including legacy driver_id/vehicle_id)
            $status = trim($_POST['status'] ?? 'active');
            $comments = trim($_POST['comments'] ?? '');

            $update = $localPdo->prepare(
                'UPDATE crews SET contractor_id = :contractor_id, driver_vehicle_block_id = :driver_vehicle_block_id,
                 driver_id = :driver_id, vehicle_id = :vehicle_id,
                 status = :status, comments = :comments,
                 updated_by_user_id = :uid, updated_by_role = :role
                 WHERE id = :id'
            );
            $update->execute([
                ':contractor_id' => (int)$contractorId,
                ':driver_vehicle_block_id' => $newDvbId,
                ':driver_id' => (int)$driverId,
                ':vehicle_id' => (int)$primaryVehicleUnitId,
                ':status' => $status,
                ':comments' => $comments !== '' ? $comments : null,
                ':uid' => $userId,
                ':role' => $userRole,
                ':id' => $crewId,
            ]);

            $localPdo->commit();

            // Fetch updated crew for success display
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
            $localPdo->rollBack();
            $formError = 'Ошибка обновления исполнителя рейса: ' . $e->getMessage();
        }
    } catch (\Exception $e) {
        $company = $company ?? null;
        $crew = $crew ?? null;
        $dbError = null;
        $contractors = $contractors ?? [];
        $drivers = $drivers ?? [];
        $vehicleSets = $vehicleSets ?? [];
        $blockingNotices = $blockingNotices ?? [];
        $formError = 'Ошибка обновления исполнителя рейса: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_route_executor_edit.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
