<?php

namespace App\Service;

use App\Core\Database;
use PDO;

final class ContractorService
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
        if ($companyId <= 0) {
            return null;
        }
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
        applyLocalMigrations($localPdo);
        $this->ensureContractorTables($localPdo);
        return $localPdo;
    }

    public function ensureContractorTables(PDO $localPdo): void
    {
        try {
            $localPdo->query("SELECT 1 FROM contractors LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $localPdo->exec(file_get_contents(base_path('database/migrations-local/003_create_company_contractors.sql')));
        }

        try {
            $localPdo->query("SELECT created_by_user_id FROM contractors LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $localPdo->exec("ALTER TABLE contractors ADD COLUMN created_by_user_id INT UNSIGNED DEFAULT NULL, ADD COLUMN created_by_role VARCHAR(20) DEFAULT NULL");
        }

        try {
            $localPdo->query("SELECT 1 FROM entity_access_grants LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $localPdo->exec(file_get_contents(base_path('database/migrations-local/009_create_entity_access_grants.sql')));
        }
    }

    public function ensureDocumentTables(PDO $localPdo): void
    {
        try {
            $localPdo->query("SELECT 1 FROM documents LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $localPdo->exec(file_get_contents(base_path('database/migrations-local/007_create_company_documents.sql')));
        }
        try {
            $localPdo->query("SELECT 1 FROM document_types LIMIT 1")->fetch();
        } catch (\Exception $e) {
            $localPdo->exec(file_get_contents(base_path('database/migrations-local/024_create_document_types.sql')));
            $localPdo->exec(file_get_contents(base_path('database/migrations-local/025_add_document_type_id.sql')));
        }
    }

    public function listContractors(PDO $localPdo, bool $isLogist, int $userId): array
    {
        if ($isLogist) {
            $stmt = $localPdo->prepare(
                "SELECT c.*,
                        " . ContractorContactService::buildPrimaryContactSubquery('contact_person') . " AS primary_contact_person,
                        " . ContractorContactService::buildPrimaryContactSubquery('phone') . " AS primary_contact_phone,
                        " . ContractorContactService::buildDocumentEmailSubquery() . " AS doc_email
                 FROM contractors c
                 WHERE c.deleted_at IS NULL AND (c.created_by_user_id = ? OR c.id IN (SELECT entity_id FROM entity_access_grants WHERE entity_type = 'contractor' AND granted_to_user_id = ? AND access_level = 'view'))
                 ORDER BY c.created_at DESC"
            );
            $stmt->execute([$userId, $userId]);
        } else {
            $stmt = $localPdo->query(
                "SELECT c.*,
                        " . ContractorContactService::buildPrimaryContactSubquery('contact_person') . " AS primary_contact_person,
                        " . ContractorContactService::buildPrimaryContactSubquery('phone') . " AS primary_contact_phone,
                        " . ContractorContactService::buildDocumentEmailSubquery() . " AS doc_email
                 FROM contractors c
                 WHERE c.deleted_at IS NULL
                 ORDER BY c.created_at DESC"
            );
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getContractorById(PDO $localPdo, int $id): ?array
    {
        $stmt = $localPdo->prepare('SELECT * FROM contractors WHERE id = ? AND deleted_at IS NULL');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function validateContractor(array $data, PDO $localPdo, ?int $excludeId = null): array
    {
        $errors = [];
        $name = trim($data['name'] ?? '');
        $inn = trim($data['inn'] ?? '');

        if ($name === '') {
            $errors['name'] = 'Обязательное поле';
        }

        if ($inn === '') {
            $errors['inn'] = 'Укажите ИНН';
        } elseif (!preg_match('/^\d+$/', $inn) || (strlen($inn) !== 10 && strlen($inn) !== 12)) {
            $errors['inn'] = 'ИНН должен содержать 10 или 12 цифр';
        }

        if (($data['kpp'] ?? '') !== '' && !preg_match('/^\d{9}$/', $data['kpp'])) {
            $errors['kpp'] = 'КПП должен содержать 9 цифр';
        }

        if (($data['ogrn'] ?? '') !== '' && (!preg_match('/^\d+$/', $data['ogrn']) || (strlen($data['ogrn']) !== 13 && strlen($data['ogrn']) !== 15))) {
            $errors['ogrn'] = 'ОГРН/ОГРНИП должен содержать 13 или 15 цифр';
        }

        if (($data['bank_account'] ?? '') !== '' && !preg_match('/^\d{20}$/', $data['bank_account'])) {
            $errors['bank_account'] = 'Расчётный счёт должен содержать 20 цифр';
        }
        if (($data['bank_bik'] ?? '') !== '' && !preg_match('/^\d{9}$/', $data['bank_bik'])) {
            $errors['bank_bik'] = 'БИК должен содержать 9 цифр';
        }
        if (($data['bank_corr_account'] ?? '') !== '' && !preg_match('/^\d{20}$/', $data['bank_corr_account'])) {
            $errors['bank_corr_account'] = 'Корр. счёт должен содержать 20 цифр';
        }
        $bankInn = trim((string)($data['bank_inn'] ?? ''));
        if ($bankInn !== '' && !preg_match('/^\d{10}$/', $bankInn)) {
            $errors['bank_inn'] = 'ИНН банка должен содержать 10 цифр';
        }

        if ($inn !== '') {
            $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM contractors WHERE inn = ?');
            $params = [$inn];
            if ($excludeId !== null) {
                $checkStmt = $localPdo->prepare('SELECT COUNT(*) FROM contractors WHERE inn = ? AND id != ?');
                $params[] = $excludeId;
            }
            $checkStmt->execute($params);
            if ($checkStmt->fetchColumn() > 0) {
                $errors['inn'] = 'ИНН уже используется в этой компании';
            }
        }

        return $errors;
    }

    public function createContractor(PDO $localPdo, array $data, int $userId, string $role): int
    {
        $insert = $localPdo->prepare(
            'INSERT INTO contractors (name, inn, kpp, ogrn, contractor_type, legal_address, physical_address,
             bank_account, bank_name, bank_bik, bank_corr_account, bank_inn,
             director_full_name, director_position,
             status, comments, created_by_user_id, created_by_role)
             VALUES (:name, :inn, :kpp, :ogrn, :contractor_type, :legal_address, :physical_address,
             :bank_account, :bank_name, :bank_bik, :bank_corr_account, :bank_inn,
             :director_full_name, :director_position,
             :status, :comments, :created_by_user_id, :created_by_role)'
        );
        $insert->execute([
            ':name'               => trim($data['name'] ?? ''),
            ':inn'                => trim($data['inn'] ?? ''),
            ':kpp'                => ($v = trim($data['kpp'] ?? '')) !== '' ? $v : null,
            ':ogrn'               => ($v = trim($data['ogrn'] ?? '')) !== '' ? $v : null,
            ':contractor_type'    => ($data['contractor_type'] ?? '') !== '' ? $data['contractor_type'] : null,
            ':legal_address'      => ($v = trim($data['legal_address'] ?? '')) !== '' ? $v : null,
            ':physical_address'   => ($v = trim($data['physical_address'] ?? '')) !== '' ? $v : null,
            ':bank_account'       => ($data['bank_account'] ?? '') !== '' ? $data['bank_account'] : null,
            ':bank_name'          => ($data['bank_name'] ?? '') !== '' ? $data['bank_name'] : null,
            ':bank_bik'           => ($data['bank_bik'] ?? '') !== '' ? $data['bank_bik'] : null,
            ':bank_corr_account'  => ($data['bank_corr_account'] ?? '') !== '' ? $data['bank_corr_account'] : null,
            ':bank_inn'           => ($v = trim((string)($data['bank_inn'] ?? ''))) !== '' ? $v : null,
            ':director_full_name' => ($v = trim($data['director_full_name'] ?? '')) !== '' ? $v : null,
            ':director_position'  => ($v = trim($data['director_position'] ?? '')) !== '' ? $v : null,
            ':status'             => 'active',
            ':comments'           => ($v = trim($data['comments'] ?? '')) !== '' ? $v : null,
            ':created_by_user_id' => $userId,
            ':created_by_role'    => $role,
        ]);
        return (int)$localPdo->lastInsertId();
    }

    public function updateContractor(PDO $localPdo, int $id, array $data, int $userId, string $role): void
    {
        $update = $localPdo->prepare(
            'UPDATE contractors SET
                name = :name,
                inn = :inn,
                kpp = :kpp,
                ogrn = :ogrn,
                contractor_type = :contractor_type,
                legal_address = :legal_address,
                physical_address = :physical_address,
                bank_account = :bank_account,
                bank_name = :bank_name,
                bank_bik = :bank_bik,
                bank_corr_account = :bank_corr_account,
                bank_inn = :bank_inn,
                director_full_name = :director_full_name,
                director_position = :director_position,
                status = :status,
                comments = :comments,
                updated_by_user_id = :updated_by_user_id,
                updated_by_role = :updated_by_role
             WHERE id = :id'
        );
        $update->execute([
            ':name'             => trim($data['name'] ?? ''),
            ':inn'              => trim($data['inn'] ?? ''),
            ':kpp'              => ($v = trim($data['kpp'] ?? '')) !== '' ? $v : null,
            ':ogrn'             => ($v = trim($data['ogrn'] ?? '')) !== '' ? $v : null,
            ':contractor_type'  => ($data['contractor_type'] ?? '') !== '' ? $data['contractor_type'] : null,
            ':legal_address'    => ($v = trim($data['legal_address'] ?? '')) !== '' ? $v : null,
            ':physical_address' => ($v = trim($data['physical_address'] ?? '')) !== '' ? $v : null,
            ':bank_account'     => ($data['bank_account'] ?? '') !== '' ? $data['bank_account'] : null,
            ':bank_name'        => ($data['bank_name'] ?? '') !== '' ? $data['bank_name'] : null,
            ':bank_bik'         => ($data['bank_bik'] ?? '') !== '' ? $data['bank_bik'] : null,
            ':bank_corr_account'=> ($data['bank_corr_account'] ?? '') !== '' ? $data['bank_corr_account'] : null,
            ':bank_inn'         => ($v = trim((string)($data['bank_inn'] ?? ''))) !== '' ? $v : null,
            ':director_full_name' => ($v = trim($data['director_full_name'] ?? '')) !== '' ? $v : null,
            ':director_position'  => ($v = trim($data['director_position'] ?? '')) !== '' ? $v : null,
            ':status'           => $data['status'] ?? 'active',
            ':comments'         => ($v = trim($data['comments'] ?? '')) !== '' ? $v : null,
            ':updated_by_user_id' => $userId,
            ':updated_by_role'   => $role,
            ':id'               => $id,
        ]);
    }

    public function archiveContractor(PDO $localPdo, int $id): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role_code'] ?? '');
        $update = $localPdo->prepare("UPDATE contractors SET deleted_at = NOW(), deleted_by_user_id = ?, deleted_by_role = ? WHERE id = ? AND deleted_at IS NULL");
        $update->execute([$userId, $role, $id]);
    }

    public function getDocTypes(PDO $localPdo, string $entityType = 'contractor'): array
    {
        try {
            $this->ensureDocumentTables($localPdo);
            return $localPdo->query("SELECT id, name, code, entity_type FROM document_types WHERE entity_type = '$entityType' OR entity_type IS NULL ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getContractorGrants(PDO $localPdo, int $contractorId): array
    {
        $stmt = $localPdo->prepare(
            "SELECT g.*, u.full_name AS logist_name
             FROM entity_access_grants g
             LEFT JOIN users u ON g.granted_to_user_id = u.id
             WHERE g.entity_type = ? AND g.entity_id = ?"
        );
        $stmt->execute(['contractor', $contractorId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLogists(PDO $localPdo): array
    {
        return $localPdo->query("SELECT id, full_name, login FROM users WHERE role_code IN ('logist', 'senior_logist') AND status='active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function checkLogistAccess(PDO $localPdo, int $entityId, int $userId, string $requiredLevel = 'view'): ?string
    {
        $stmt = $localPdo->prepare("SELECT access_level FROM entity_access_grants WHERE entity_type = 'contractor' AND entity_id = ? AND granted_to_user_id = ? AND access_level IN ('view','edit') AND revoked_at IS NULL LIMIT 1");
        $stmt->execute([$entityId, $userId]);
        return $stmt->fetchColumn() ?: null;
    }

    public function checkLogistCanEdit(PDO $localPdo, int $entityId, int $userId, int $createdByUserId): bool
    {
        if ($createdByUserId === $userId) {
            return true;
        }
        $grantLevel = $this->checkLogistAccess($localPdo, $entityId, $userId, 'edit');
        return $grantLevel === 'edit';
    }
}
