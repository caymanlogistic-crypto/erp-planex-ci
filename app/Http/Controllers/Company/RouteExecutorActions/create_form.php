<?php

    $pageTitle = 'Создать исполнителя рейса';
    $pageContext = 'Исполнители рейса › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);

    if ($companyId <= 0) {
        $company = null;
        $success = false; $errors = []; $old = []; $formError = null; $createdExecutor = null;
        $blockingNotices = []; $contractors = []; $drivers = []; $vehicleSets = [];
        ob_start(); require base_path('app/View/pages/company_route_executors_create.php');
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
            $success = false; $errors = []; $old = []; $formError = null; $createdExecutor = null;
            $blockingNotices = []; $contractors = []; $drivers = []; $vehicleSets = [];
            ob_start(); require base_path('app/View/pages/company_route_executors_create.php');
            $content = ob_get_clean(); require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Исполнители рейса › Компания: ' . $company['name'];

        $success = false; $errors = []; $old = []; $formError = null; $createdExecutor = null;

        if ($company['status'] !== 'active') {
            $blockingNotices = []; $contractors = []; $drivers = []; $vehicleSets = [];
        } else {
            $localDbConfig = companyDatabaseConfig($config, $company);
            $localDb = new \App\Core\Database($localDbConfig); $localPdo = $localDb->connection();
            applyLocalMigrations($localPdo);

            try { $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch(); }
            catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'))); }

            try { $localPdo->query("SELECT created_by_user_id FROM crews LIMIT 1")->fetch(); }
            catch (\Exception $e) { $localPdo->exec("ALTER TABLE crews ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL"); }

            try { $localPdo->query("SELECT 1 FROM driver_vehicle_blocks LIMIT 1")->fetch(); }
            catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/018_create_driver_vehicle_blocks.sql'))); }

            // Load contractors for dropdown
            $contractors = \App\Service\RouteExecutorService::filterVisibleContractors($localPdo, $localPdo->query("SELECT id, name, inn FROM contractors WHERE deleted_at IS NULL AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC));

            // Load drivers for dropdown
            $drivers = \App\Service\RouteExecutorService::filterVisibleDrivers($localPdo, $localPdo->query("SELECT id, full_name, phone FROM drivers WHERE deleted_at IS NULL AND status = 'active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC));

            // Load vehicle sets for dropdown
            $vehicleSets = \App\Service\RouteExecutorService::filterVisibleVehicleSets($localPdo, $localPdo->query("SELECT vs.id, vs.set_type, vs.primary_vehicle_unit_id, vs.secondary_vehicle_unit_id, vu1.plate_number AS primary_plate, vu2.plate_number AS secondary_plate FROM vehicle_sets vs LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id WHERE vs.deleted_at IS NULL AND vs.status = 'active' ORDER BY vs.set_type")->fetchAll(PDO::FETCH_ASSOC));

            $blockingNotices = [];
            if (empty($contractors)) {
                $blockingNotices[] = ['message' => 'Сначала создайте подрядчика.', 'link' => '/company/contractors/create', 'action' => 'Создать подрядчика'];
            }
            if (empty($drivers)) {
                $blockingNotices[] = ['message' => 'Сначала создайте водителя.', 'link' => '/company/drivers', 'action' => 'Создать водителя'];
            }
            if (empty($vehicleSets)) {
                $blockingNotices[] = ['message' => 'Сначала создайте транспортный комплект.', 'link' => '/company/vehicle-sets', 'action' => 'Создать ТС'];
            }
        }
    } catch (\Exception $e) {
        $company = null;
        $success = false; $errors = []; $old = []; $formError = 'Ошибка загрузки данных: ' . $e->getMessage();
        $createdExecutor = null; $blockingNotices = []; $contractors = []; $drivers = []; $vehicleSets = [];
    }

    ob_start();
    require base_path('app/View/pages/company_route_executors_create.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
