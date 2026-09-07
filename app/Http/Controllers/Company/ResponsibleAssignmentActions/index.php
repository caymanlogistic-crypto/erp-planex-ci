<?php

    requireRole('company_owner');
    $pageTitle = 'Ответственные логисты';
    $pageContext = 'Ответственные логисты › Компания';

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $activeTab = $_GET['tab'] ?? 'route_executor';
    if (!in_array($activeTab, ['route_executor', 'contractor', 'driver', 'vehicle_set'], true)) {
        $activeTab = 'route_executor';
    }

    // Query params for after-redirect messages
    $successMessage = null;
    $formError = null;
    if (isset($_GET['msg'])) {
        switch ($_GET['msg']) {
            case 'assigned':
                $successMessage = 'Ответственность успешно переназначена.';
                break;
            case 'already_assigned':
                $formError = 'Выбранный логист уже является ответственным для некоторых записей.';
                break;
            case 'error':
                $formError = 'Ошибка при переназначении. Попробуйте снова.';
                break;
        }
    }

    if ($companyId <= 0) {
        $company = null;
        $items = [];
        $logists = [];
        $dbError = null;
        ob_start();
        require base_path('app/View/pages/company_responsible_assignments.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        return;
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || $company['status'] !== 'active') {
            $company = $company ?? null;
            $items = [];
            $logists = [];
            $dbError = null;
            ob_start();
            require base_path('app/View/pages/company_responsible_assignments.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            return;
        }

        $pageContext = 'Ответственные логисты › Компания: ' . $company['name'];

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        // Ensure key tables exist
        try { $localPdo->query("SELECT 1 FROM contractors LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/003_create_company_contractors.sql'))); }
        try { $localPdo->query("SELECT 1 FROM drivers LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/004_create_company_drivers.sql'))); }
        try { $localPdo->query("SELECT 1 FROM vehicle_sets LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/017_create_vehicle_sets.sql'))); }
        try { $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'))); }

        // Central membership/role state is authoritative. The service returns
        // active Логист/Логист+ memberships by stable central membership_id;
        // tenant users.role_code and projection completeness are not authority.
        $logists = \App\Service\EntityResponsibilityService::eligibleRecipients(
            $pdo,
            $localPdo,
            $companyId
        );

        // Load items based on active tab
        switch ($activeTab) {
            case 'route_executor':
                $items = $localPdo->query(
                    "SELECT c.id AS crew_id,
                            ct.name AS contractor_name,
                            d.full_name AS driver_name,
                            CONCAT(vu1.plate_number, IFNULL(CONCAT(' + ', vu2.plate_number), '')) AS plates,
                            responsible_user.id AS logist_id,
                            responsible_user.full_name AS logist_name,
                            COALESCE(er.membership_id, creator_user.membership_id) AS logist_membership_id
                     FROM crews c
                     JOIN contractors ct ON c.contractor_id = ct.id
                     JOIN driver_vehicle_blocks dvb ON c.driver_vehicle_block_id = dvb.id
                     JOIN drivers d ON dvb.driver_id = d.id
                     JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
                     LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                     LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                     LEFT JOIN users creator_user ON c.created_by_user_id = creator_user.id
                     LEFT JOIN entity_responsibilities er ON er.entity_type='route_executor' AND er.entity_id=c.id
                     LEFT JOIN users responsible_user ON responsible_user.membership_id=COALESCE(er.membership_id, creator_user.membership_id)
                     WHERE c.status != 'archived'
                     ORDER BY ct.name"
                )->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'contractor':
                $items = $localPdo->query(
                    "SELECT c.id, c.name, c.inn,
                            responsible_user.id AS logist_id,
                            responsible_user.full_name AS logist_name,
                            COALESCE(er.membership_id, c.created_by_membership_id, creator_user.membership_id) AS logist_membership_id,
                            COUNT(DISTINCT cr.id) AS crew_count
                     FROM contractors c
                     LEFT JOIN users creator_user ON c.created_by_user_id = creator_user.id
                     LEFT JOIN entity_responsibilities er ON er.entity_type='contractor' AND er.entity_id=c.id
                     LEFT JOIN users responsible_user ON responsible_user.membership_id=COALESCE(er.membership_id, c.created_by_membership_id, creator_user.membership_id)
                     LEFT JOIN crews cr ON cr.contractor_id = c.id AND cr.status != 'archived'
                     WHERE c.status != 'archived'
                     GROUP BY c.id, c.name, c.inn, responsible_user.id, responsible_user.full_name
                     ORDER BY c.name"
                )->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'driver':
                $items = $localPdo->query(
                    "SELECT d.id, d.full_name, d.phone,
                            responsible_user.id AS logist_id,
                            responsible_user.full_name AS logist_name,
                            COALESCE(er.membership_id, d.created_by_membership_id, creator_user.membership_id) AS logist_membership_id,
                            COUNT(DISTINCT cr.id) AS crew_count
                     FROM drivers d
                     LEFT JOIN users creator_user ON d.created_by_user_id = creator_user.id
                     LEFT JOIN entity_responsibilities er ON er.entity_type='driver' AND er.entity_id=d.id
                     LEFT JOIN users responsible_user ON responsible_user.membership_id=COALESCE(er.membership_id, d.created_by_membership_id, creator_user.membership_id)
                     LEFT JOIN driver_vehicle_blocks dvb ON dvb.driver_id = d.id
                     LEFT JOIN crews cr ON cr.driver_vehicle_block_id = dvb.id AND cr.status != 'archived'
                     WHERE d.status != 'archived'
                     GROUP BY d.id, d.full_name, d.phone, responsible_user.id, responsible_user.full_name
                     ORDER BY d.full_name"
                )->fetchAll(PDO::FETCH_ASSOC);
                break;

            case 'vehicle_set':
                $items = $localPdo->query(
                    "SELECT vs.id, vs.set_type,
                            vu1.plate_number,
                            responsible_user.id AS logist_id,
                            responsible_user.full_name AS logist_name,
                            COALESCE(er.membership_id, vs.created_by_membership_id, creator_user.membership_id) AS logist_membership_id,
                            COUNT(DISTINCT cr.id) AS crew_count
                     FROM vehicle_sets vs
                     LEFT JOIN users creator_user ON vs.created_by_user_id = creator_user.id
                     LEFT JOIN entity_responsibilities er ON er.entity_type='vehicle_set' AND er.entity_id=vs.id
                     LEFT JOIN users responsible_user ON responsible_user.membership_id=COALESCE(er.membership_id, vs.created_by_membership_id, creator_user.membership_id)
                     LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                     LEFT JOIN driver_vehicle_blocks dvb ON dvb.vehicle_set_id = vs.id
                     LEFT JOIN crews cr ON cr.driver_vehicle_block_id = dvb.id AND cr.status != 'archived'
                     WHERE vs.status != 'archived'
                     GROUP BY vs.id, vs.set_type, vu1.plate_number, responsible_user.id, responsible_user.full_name
                     ORDER BY vu1.plate_number"
                )->fetchAll(PDO::FETCH_ASSOC);
                break;
        }

        $logistNamesByMembership = [];
        foreach ($logists as $logist) {
            $membershipId = (int)($logist['membership_id'] ?? 0);
            if ($membershipId > 0) {
                $logistNamesByMembership[$membershipId] = (string)($logist['full_name'] ?? '');
            }
        }
        foreach ($items as &$item) {
            $membershipId = (int)($item['logist_membership_id'] ?? 0);
            if ($membershipId > 0 && empty($item['logist_name']) && isset($logistNamesByMembership[$membershipId])) {
                $item['logist_name'] = $logistNamesByMembership[$membershipId];
            }
        }
        unset($item);

        $dbError = null;
    } catch (\Exception $e) {
        $company = $company ?? null;
        $items = [];
        $logists = [];
        $dbError = 'Не удалось загрузить данные: ' . $e->getMessage();
    }

    ob_start();
    require base_path('app/View/pages/company_responsible_assignments.php');
    $content = ob_get_clean();
    require base_path('app/View/layouts/main.php');
