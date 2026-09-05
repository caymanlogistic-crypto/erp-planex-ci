<?php

    $crewId = (int)$crewId;
    $pageTitle = 'Редактировать исполнителя рейса';
    $pageContext = 'Исполнители рейса › Компания';
    $entityNotFound = false;

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $success = false;
    $formError = null;
    $errors = [];

    if ($companyId <= 0) {
        $company = null;
        $crew = null; $old = []; $dbError = null;
        $contractors = []; $drivers = []; $vehicleSets = [];
        $blockingNotices = [];

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
            $company = null;
            $crew = null; $old = []; $dbError = null;
            $contractors = []; $drivers = []; $vehicleSets = [];
            $blockingNotices = [];

            ob_start();
            require base_path('app/View/pages/company_route_executor_edit.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Исполнители рейса › Компания: ' . $company['name'];

        if ($company['status'] !== 'active') {
            $crew = null; $old = []; $dbError = null;
            $contractors = []; $drivers = []; $vehicleSets = [];
            $blockingNotices = [];

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

        // Load crew with JOIN to get driver_id and vehicle_set_id
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
            $old = [];
            $contractors = []; $drivers = []; $vehicleSets = [];
            $blockingNotices = [];
        } else {
            $old = $crew;

            // Load contractors
            $contractors = $localPdo->query("SELECT id, name, inn FROM contractors WHERE deleted_at IS NULL AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

            // Load drivers (include current driver even if inactive)
            $currentDriverId = (int)($crew['driver_id'] ?? 0);
            $drivers = $localPdo->query(
                "SELECT id, full_name, phone FROM drivers
                 WHERE deleted_at IS NULL
                   AND (status = 'active'" . ($currentDriverId > 0 ? " OR id = " . $currentDriverId : "") . ")
                 ORDER BY full_name"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Load vehicle sets (include current even if inactive)
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
        }

        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $crew = null; $old = [];
        $dbError = 'Не удалось подключиться к базе данных компании.';
        $contractors = []; $drivers = []; $vehicleSets = [];
        $blockingNotices = [];
    }

    ob_start();
    require base_path('app/View/pages/company_route_executor_edit.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
