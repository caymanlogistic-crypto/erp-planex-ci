<?php

$router->get('/company/dashboard', function () use ($config, $db) {
    requireRole(['company_owner', 'senior_logist', 'logist']);

    $pageTitle = 'Обзор';
    $pageContext = 'ДАШБОРД';

    $roleCode = $_SESSION['role_code'];
    $companyId = (int)$_SESSION['company_id'];
    $userId = (int)$_SESSION['user_id'];

    $companyName = null;
    $companyError = false;
    $metrics = [];
    $logistNoAccess = false;

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare(
            'SELECT id, name, db_identifier, status, db_host, db_port, db_username, db_password '
            . 'FROM companies WHERE id = ?'
        );
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || $company['status'] !== 'active') {
            $companyError = true;
        } else {
            $companyName = $company['name'];
            $localDbConfig = companyDatabaseConfig($config, $company);
            $localDb = new \App\Core\Database($localDbConfig);
            $localPdo = $localDb->connection();

            if ($roleCode === 'company_owner' || $roleCode === 'senior_logist') {
                $metrics = [
                    'total_contractors' => 0, 'active_contractors' => 0,
                    'total_drivers' => 0, 'active_drivers' => 0,
                    'total_vehicles' => 0, 'active_vehicles' => 0,
                    'total_vehicle_sets' => 0, 'active_vehicle_sets' => 0,
                    'total_dvbs' => 0, 'active_dvbs' => 0,
                    'total_crews' => 0, 'active_crews' => 0, 'archived_crews' => 0,
                    'total_documents' => 0,
                ];

                $tables = ['contractors', 'drivers', 'vehicle_units', 'vehicle_sets', 'driver_vehicle_blocks', 'crews'];
                foreach ($tables as $table) {
                    try {
                        $key = ($table === 'vehicle_units') ? 'vehicles' : $table;
                        $key = ($table === 'driver_vehicle_blocks') ? 'dvbs' : $key;
                        $lStmt = $localPdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active FROM `{$table}`");
                        $row = $lStmt->fetch();
                        $metrics["total_{$key}"] = (int)$row['total'];
                        $metrics["active_{$key}"] = (int)$row['active'];
                    } catch (\Exception $e) { /* table may not exist */ }
                }

                try {
                    $stmtA = $localPdo->query("SELECT COUNT(*) FROM crews WHERE status = 'archived'");
                    $metrics['archived_crews'] = (int)$stmtA->fetchColumn();
                } catch (\Exception $e) {}

                try {
                    $stmtD = $localPdo->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL");
                    $metrics['total_documents'] = (int)$stmtD->fetchColumn();
                } catch (\Exception $e) {}
            } else {
                // logist
                $myMetrics = [
                    'my_contractors' => 0,
                    'my_drivers' => 0,
                    'my_vehicles' => 0,
                    'my_vehicle_sets' => 0,
                    'my_dvbs' => 0,
                    'my_crews' => 0,
                    'grants_count' => 0,
                ];
                $hasData = false;

                $myTables = ['contractors', 'drivers', 'vehicle_units', 'vehicle_sets', 'driver_vehicle_blocks', 'crews'];
                foreach ($myTables as $table) {
                    try {
                        $key = ($table === 'vehicle_units') ? 'vehicles' : $table;
                        $key = ($table === 'driver_vehicle_blocks') ? 'dvbs' : $key;
                        $mStmt = $localPdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE created_by_user_id = ?");
                        $mStmt->execute([$userId]);
                        $count = (int)$mStmt->fetchColumn();
                        $myMetrics["my_{$key}"] = $count;
                        if ($count > 0) $hasData = true;
                    } catch (\Exception $e) {
                        // column created_by_user_id may not exist; silently skip
                    }
                }

                try {
                    $gStmt = $localPdo->prepare("SELECT COUNT(*) FROM entity_access_grants WHERE granted_to_user_id = ? AND revoked_at IS NULL");
                    $gStmt->execute([$userId]);
                    $myMetrics['grants_count'] = (int)$gStmt->fetchColumn();
                    if ($myMetrics['grants_count'] > 0) $hasData = true;
                } catch (\Exception $e) {}

                if ($hasData) {
                    $metrics = $myMetrics;
                } else {
                    $logistNoAccess = true;
                }
            }
        }
    } catch (\Exception $e) {
        $companyError = true;
    }

    ob_start();
    require base_path('app/View/pages/company_dashboard.php');
    $content = ob_get_clean();

    require base_path('app/View/layouts/main.php');
}, 'company.dashboard');

$router->post('/company/access-grants/grant', function () use ($config, $db) {
    requireRole('company_owner');

    // D-007: legacy entity_access_grants is read-only migration input once the
    // centralized roles/access runtime is active.
    if (\App\Security\AccessRuntime::current()->isActive()) {
        http_response_code(410);
        return null;
    }

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $entityType = trim($_POST['entity_type'] ?? '');
    $entityId = (int)($_POST['entity_id'] ?? 0);
    $grantedToUserId = (int)($_POST['granted_to_user_id'] ?? 0);
    $redirect = $_POST['redirect'] ?? '/company/dashboard';

    $allowedEntityTypes = ['client', 'contractor', 'driver', 'vehicle_unit', 'vehicle_set', 'driver_vehicle_block', 'crew'];

    if (!in_array($entityType, $allowedEntityTypes, true)) {
        redirect_to($redirect);
    }

    if ($entityId <= 0 || $grantedToUserId <= 0 || $companyId <= 0) {
        redirect_to($redirect);
    }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || $company['status'] !== 'active') {
            redirect_to($redirect);
        }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig);
        $localPdo = $localDb->connection();
                applyLocalMigrations($localPdo);

        try {
            $localPdo->query("SELECT 1 FROM entity_access_grants LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $migrationSql = file_get_contents(base_path('database/migrations-local/009_create_entity_access_grants.sql'));
            $localPdo->exec($migrationSql);
        }

        $accessLevel = trim($_POST['access_level'] ?? 'view');
        if (!in_array($accessLevel, ['view', 'edit'], true)) { $accessLevel = 'view'; }
        $comment = trim($_POST['comment'] ?? '');

        // Check for existing grant (update if exists, insert if not)
        $existingStmt = $localPdo->prepare(
            'SELECT id, access_level FROM entity_access_grants
             WHERE entity_type = ? AND entity_id = ? AND granted_to_user_id = ? AND revoked_at IS NULL'
        );
        $existingStmt->execute([$entityType, $entityId, $grantedToUserId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $update = $localPdo->prepare(
                'UPDATE entity_access_grants SET access_level = ?, comment = ?, updated_at = NOW() WHERE id = ?'
            );
            $update->execute([$accessLevel, $comment, $existing['id']]);
        } else {
            $insert = $localPdo->prepare(
                'INSERT INTO entity_access_grants (entity_type, entity_id, granted_to_user_id, granted_by_user_id, access_level, comment)
                 VALUES (:entity_type, :entity_id, :granted_to_user_id, :granted_by_user_id, :access_level, :comment)'
            );
            $insert->execute([
                ':entity_type'        => $entityType,
                ':entity_id'          => $entityId,
                ':granted_to_user_id' => $grantedToUserId,
                ':granted_by_user_id' => (int)$_SESSION['user_id'],
                ':access_level'       => $accessLevel,
                ':comment'            => $comment !== '' ? $comment : null,
            ]);
        }
    } catch (\Exception $e) {
        if ($e->getCode() != 23000) {
            error_log('Access grant error: ' . $e->getMessage());
        }
    }

    redirect_to($redirect);
}, 'company.roles_access');

// Revoke access grant
$router->post('/company/access-grants/{id}/revoke', function ($id) use ($config, $db) {
    requireRole('company_owner');

    // D-007: revocation moves to centralized assignment endpoints.
    if (\App\Security\AccessRuntime::current()->isActive()) {
        http_response_code(410);
        return null;
    }

    $companyId = (int)(getSessionCompanyId() ?? 0);
    $redirect = $_GET['redirect'] ?? '/company/dashboard';

    if ($companyId <= 0) { redirect_to($redirect); }

    try {
        $pdo = $db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || $company['status'] !== 'active') { redirect_to($redirect); }

        $localDbConfig = companyDatabaseConfig($config, $company);
        $localDb = new \App\Core\Database($localDbConfig); $localPdo = $localDb->connection();
        applyLocalMigrations($localPdo);

        $update = $localPdo->prepare('UPDATE entity_access_grants SET revoked_at = NOW(), revoked_by_user_id = ? WHERE id = ?');
        $update->execute([(int)$_SESSION['user_id'], (int)$id]);
    } catch (\Exception $e) {}

    redirect_to($redirect);
}, 'company.roles_access');

// ============================================================
// BLOCK D5: Contractor assignment management
// ============================================================
