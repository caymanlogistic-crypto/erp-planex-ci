<?php

namespace App\Service;

use App\Core\Database;
use PDO;

final class DriverService
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {
    }

    public function getCompanyId(): int
    {
        return (int)(getSessionCompanyId() ?? 0);
    }

    public function loadCompany(int $companyId): ?array
    {
        if ($companyId <= 0) return null;
        $pdo = $this->db->connection();
        $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getLocalPdo(array $company): PDO
    {
        $localDbConfig = companyDatabaseConfig($this->config, $company);
        $localDb = new Database($localDbConfig);
        $localPdo = $localDb->connection();
        $this->ensureDriverTables($localPdo);
        return $localPdo;
    }

    public function ensureDriverTables(PDO $localPdo): void
    {
        try { $localPdo->query("SELECT 1 FROM drivers LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/004_create_company_drivers.sql'))); }
        try { $localPdo->query("SELECT created_by_user_id FROM drivers LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec("ALTER TABLE drivers ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL"); }
        try { $localPdo->query("SELECT 1 FROM entity_access_grants LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/009_create_entity_access_grants.sql'))); }
    }

    public function ensureDocumentTables(PDO $localPdo): void
    {
        try { $localPdo->query("SELECT 1 FROM documents LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/007_create_company_documents.sql'))); }
        try { $localPdo->query("SELECT 1 FROM document_types LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/024_create_document_types.sql'))); $localPdo->exec(file_get_contents(base_path('database/migrations-local/025_add_document_type_id.sql'))); }
        try { $localPdo->query("SELECT created_by_user_id FROM documents LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec("ALTER TABLE documents ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL"); }
    }

    public function getDocTypes(PDO $localPdo): array
    {
        try {
            $this->ensureDocumentTables($localPdo);
            return $localPdo->query("SELECT id, name, code, entity_type FROM document_types WHERE entity_type = 'driver' OR entity_type IS NULL ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) { return []; }
    }

    public function listDrivers(PDO $localPdo, bool $isLogist, int $userId): array
    {
        $access = RegulatedDataScopeService::condition('driver', 'd.id');
        $stmt = $localPdo->prepare(
            "SELECT d.*, COALESCE(dp.phone, d.phone) AS main_phone,
                    (SELECT COUNT(*) FROM driver_phones WHERE driver_id = d.id AND is_main = 0) AS extra_phones_count,
                    (SELECT COUNT(*) FROM documents doc WHERE doc.entity_type = 'driver' AND doc.entity_id = d.id AND doc.deleted_at IS NULL) AS files_count
             FROM drivers d
             LEFT JOIN driver_phones dp ON d.id = dp.driver_id AND dp.is_main = 1
             WHERE d.deleted_at IS NULL AND {$access['sql']}
             ORDER BY d.created_at DESC"
        );
        $stmt->execute($access['params']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDriverById(PDO $localPdo, int $id): ?array
    {
        $access = RegulatedDataScopeService::condition('driver', 'drivers.id');
        $stmt = $localPdo->prepare("SELECT * FROM drivers WHERE id = ? AND deleted_at IS NULL AND {$access['sql']}");
        $stmt->execute(array_merge([$id], $access['params']));
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getDriverPhones(PDO $localPdo, int $driverId): array
    {
        $stmt = $localPdo->prepare("SELECT * FROM driver_phones WHERE driver_id = ? ORDER BY is_main DESC, id ASC");
        $stmt->execute([$driverId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDriverBlocks(PDO $localPdo, int $driverId): array
    {
        try {
            $stmt = $localPdo->prepare(
                "SELECT dvb.id, dvb.status, dvb.driver_id, dvb.vehicle_set_id,
                 vs.set_type, vu1.plate_number AS primary_plate, vu1.brand AS primary_brand, vu1.model AS primary_model,
                 vu2.plate_number AS secondary_plate, vu2.brand AS secondary_brand, vu2.model AS secondary_model
                 FROM driver_vehicle_blocks dvb
                 JOIN vehicle_sets vs ON dvb.vehicle_set_id = vs.id
                 LEFT JOIN vehicle_units vu1 ON vs.primary_vehicle_unit_id = vu1.id
                 LEFT JOIN vehicle_units vu2 ON vs.secondary_vehicle_unit_id = vu2.id
                 WHERE dvb.driver_id = ? AND dvb.status != 'archived' ORDER BY dvb.id DESC"
            );
            $stmt->execute([$driverId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) { return []; }
    }

    public function getDriverDocuments(PDO $localPdo, int $driverId): array
    {
        $stmt = $localPdo->prepare(
            "SELECT id, entity_id, document_type, original_name, mime_type, stored_name, file_size
             FROM documents
             WHERE entity_type = 'driver' AND entity_id = ? AND deleted_at IS NULL
             ORDER BY id"
        );
        $stmt->execute([$driverId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDocsByType(PDO $localPdo, array $documents): array
    {
        $docsByType = ['passport' => [], 'license' => [], 'snils' => [], 'other' => []];
        foreach ($documents as $doc) {
            $dt = mb_strtolower($doc['document_type'] ?? '');
            if (strpos($dt, 'паспорт') !== false) { $docsByType['passport'][] = $doc; }
            elseif (strpos($dt, 'водительск') !== false || strpos($dt, 'ву') !== false) { $docsByType['license'][] = $doc; }
            elseif (strpos($dt, 'снилс') !== false) { $docsByType['snils'][] = $doc; }
            else { $docsByType['other'][] = $doc; }
        }
        return $docsByType;
    }

    public function getGrants(PDO $localPdo, int $driverId): array
    {
        $stmt = $localPdo->prepare("SELECT a.*, u.full_name AS logist_name FROM entity_assignments a LEFT JOIN users u ON u.membership_id = a.membership_id WHERE a.entity_type = ? AND a.entity_id = ?");
        $stmt->execute(['driver', $driverId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLogists(PDO $localPdo): array
    {
        return $localPdo->query("SELECT membership_id AS id, full_name, login FROM users WHERE membership_id IS NOT NULL AND status='active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function checkLogistAccess(PDO $localPdo, int $entityId, int $userId): ?string
    {
        return RegulatedDataScopeService::canAccess($localPdo, 'driver', 'drivers', $entityId) ? 'edit' : null;
    }

    public function checkLogistCanEdit(PDO $localPdo, int $entityId, int $userId, int $createdByUserId): bool
    {
        return RegulatedDataScopeService::canAccess($localPdo, 'driver', 'drivers', $entityId);
    }

    public function createDriver(PDO $localPdo, array $data, int $userId, string $role): int
    {
        $membershipId = RegulatedDataScopeService::assertCanCreate('driver');
        return EntityAssignmentService::createAssigned($localPdo, 'driver', $membershipId, function () use ($localPdo, $data, $userId, $role, $membershipId): int {
        $insert = $localPdo->prepare(
            'INSERT INTO drivers (full_name, phone, email, license_number, license_category,
             license_issue_date, license_expire_date, passport_number, passport_issued_by,
             passport_department_code, passport_issue_date, snils, status, comments, created_by_user_id, created_by_membership_id, created_by_role)
             VALUES (:full_name, :phone, :email, :license_number, :license_category,
             :license_issue_date, :license_expire_date, :passport_number, :passport_issued_by,
             :passport_department_code, :passport_issue_date, :snils, :status, :comments, :created_by_user_id, :created_by_membership_id, :created_by_role)'
        );
        $insert->execute([
            ':full_name' => $data['full_name'],
            ':phone' => $data['phone'] ?? '',
            ':email' => !empty($data['email']) ? $data['email'] : null,
            ':license_number' => $data['license_number'] ?? null,
            ':license_category' => !empty($data['license_category']) ? $data['license_category'] : null,
            ':license_issue_date' => $data['license_issue_date'] ?: null,
            ':license_expire_date' => !empty($data['license_expire_date']) ? $data['license_expire_date'] : null,
            ':passport_number' => $data['passport_number'] ?? null,
            ':passport_issued_by' => !empty($data['passport_issued_by']) ? $data['passport_issued_by'] : null,
            ':passport_department_code' => $data['passport_department_code'] ?? null,
            ':passport_issue_date' => $data['passport_issue_date'] ?: null,
            ':snils' => $data['snils'] ?? null,
            ':status' => 'active',
            ':comments' => !empty($data['comments']) ? $data['comments'] : null,
            ':created_by_user_id' => $userId,
            ':created_by_membership_id' => $membershipId,
            ':created_by_role' => $role,
        ]);
        return (int)$localPdo->lastInsertId();
        });
    }

    public function updateDriver(PDO $localPdo, int $id, array $data, int $userId, string $role): void
    {
        $update = $localPdo->prepare(
            'UPDATE drivers SET
                full_name = :full_name, phone = :phone, email = :email,
                license_number = :license_number, license_category = :license_category,
                license_issue_date = :license_issue_date, license_expire_date = :license_expire_date,
                passport_number = :passport_number, passport_issued_by = :passport_issued_by,
                passport_department_code = :passport_department_code, passport_issue_date = :passport_issue_date,
                snils = :snils, status = :status, comments = :comments,
                updated_by_user_id = :updated_by_user_id, updated_by_role = :updated_by_role
             WHERE id = :id'
        );
        $update->execute([
            ':full_name' => $data['full_name'] ?? '',
            ':phone' => $data['phone'] ?? '',
            ':email' => !empty($data['email']) ? $data['email'] : null,
            ':license_number' => $data['license_number'] ?? null,
            ':license_category' => !empty($data['license_category']) ? $data['license_category'] : null,
            ':license_issue_date' => $data['license_issue_date'] ?? null,
            ':license_expire_date' => $data['license_expire_date'] ?? null,
            ':passport_number' => $data['passport_number'] ?? null,
            ':passport_issued_by' => !empty($data['passport_issued_by']) ? $data['passport_issued_by'] : null,
            ':passport_department_code' => $data['passport_department_code'] ?? null,
            ':passport_issue_date' => $data['passport_issue_date'] ?? null,
            ':snils' => $data['snils'] ?? null,
            ':status' => $data['status'] ?? 'active',
            ':comments' => !empty($data['comments']) ? $data['comments'] : null,
            ':updated_by_user_id' => $userId,
            ':updated_by_role' => $role,
            ':id' => $id,
        ]);
    }

    public function archiveDriver(PDO $localPdo, int $id): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role_code'] ?? '');
        $localPdo->prepare("UPDATE drivers SET deleted_at = NOW(), deleted_by_user_id = ?, deleted_by_role = ? WHERE id = ? AND deleted_at IS NULL")->execute([$userId, $role, $id]);
    }

    public function checkDriverHasCrews(PDO $localPdo, int $id): bool
    {
        try { $localPdo->query("SELECT 1 FROM crews LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/006_create_company_crews.sql'))); }
        try { $localPdo->query("SELECT 1 FROM driver_vehicle_blocks LIMIT 1")->fetch(); }
        catch (\Exception $e) { $localPdo->exec(file_get_contents(base_path('database/migrations-local/018_create_driver_vehicle_blocks.sql'))); }

        $stmt = $localPdo->prepare(
            'SELECT 1 FROM crews c
             LEFT JOIN driver_vehicle_blocks dvb ON dvb.id = c.driver_vehicle_block_id
             LEFT JOIN crew_drivers cd ON cd.crew_id = c.id
             WHERE c.deleted_at IS NULL AND (dvb.driver_id = ? OR cd.driver_id = ?)
             LIMIT 1'
        );
        $stmt->execute([$id, $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function getMainPhone(PDO $localPdo, array $phones, array $driver): ?string
    {
        foreach ($phones as $ph) {
            if (!empty($ph['is_main']) && !empty($ph['phone'])) return $ph['phone'];
        }
        if (!empty($phones)) return $phones[0]['phone'] ?? null;
        return $driver['phone'] ?? null;
    }
}
