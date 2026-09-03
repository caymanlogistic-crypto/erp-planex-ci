<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class CompanyRoleService
{
    public const SYSTEM_LEADER = 'leader';

    public const DATA_ENTITIES = [
        'client',
        'contractor',
        'vehicle_unit',
        'driver',
        'linear_trip',
        'waste_trip',
    ];

    public static function normalizeName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    public function listRoles(PDO $pdo, int $companyId): array
    {
        $stmt = $pdo->prepare(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM company_memberships m WHERE m.role_id = r.id) AS membership_count,
                    (SELECT COUNT(*) FROM company_role_page_permissions p WHERE p.role_id = r.id AND p.is_allowed = 1) AS allowed_page_count
               FROM company_roles r
              WHERE r.company_id = ?
              ORDER BY CASE WHEN r.system_key = ? THEN 0 ELSE 1 END, r.name ASC'
        );
        $stmt->execute([$companyId, self::SYSTEM_LEADER]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getRole(PDO $pdo, int $companyId, int $roleId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM company_roles WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$roleId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createRole(PDO $pdo, int $companyId, string $name): int
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $normalized = self::normalizeName($name);
        $this->assertOrdinaryRoleName($name, $normalized);

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare('SELECT 1 FROM company_roles WHERE company_id = ? AND name_normalized = ? LIMIT 1');
            $dup->execute([$companyId, $normalized]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('Роль с таким названием уже существует.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO company_roles (company_id, name, name_normalized, system_key, legacy_role_code)
                 VALUES (?, ?, ?, NULL, NULL)'
            );
            $insert->execute([$companyId, $name, $normalized]);
            $roleId = (int) $pdo->lastInsertId();

            $scope = $pdo->prepare(
                'INSERT INTO company_role_data_scopes (role_id, entity_key, scope) VALUES (?, ?, ?)'
            );
            foreach (self::DATA_ENTITIES as $entityKey) {
                $scope->execute([$roleId, $entityKey, 'none']);
            }

            $pdo->commit();
            return $roleId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function renameRole(PDO $pdo, int $companyId, int $roleId, string $name): void
    {
        $role = $this->requireOrdinaryRole($pdo, $companyId, $roleId);
        unset($role);

        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $normalized = self::normalizeName($name);
        $this->assertOrdinaryRoleName($name, $normalized);

        $dup = $pdo->prepare(
            'SELECT 1 FROM company_roles WHERE company_id = ? AND name_normalized = ? AND id <> ? LIMIT 1'
        );
        $dup->execute([$companyId, $normalized, $roleId]);
        if ($dup->fetchColumn()) {
            throw new RuntimeException('Роль с таким названием уже существует.');
        }

        $stmt = $pdo->prepare('UPDATE company_roles SET name = ?, name_normalized = ? WHERE id = ? AND company_id = ?');
        $stmt->execute([$name, $normalized, $roleId, $companyId]);
    }

    public function copyRole(PDO $pdo, int $companyId, int $sourceRoleId, string $newName): int
    {
        $source = $this->getRole($pdo, $companyId, $sourceRoleId);
        if (!$source) {
            throw new RuntimeException('Исходная роль не найдена.');
        }

        $newName = trim((string) preg_replace('/\s+/u', ' ', $newName));
        $normalized = self::normalizeName($newName);
        $this->assertOrdinaryRoleName($newName, $normalized);

        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare('SELECT 1 FROM company_roles WHERE company_id = ? AND name_normalized = ? LIMIT 1');
            $dup->execute([$companyId, $normalized]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('Роль с таким названием уже существует.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO company_roles (company_id, name, name_normalized, system_key, legacy_role_code)
                 VALUES (?, ?, ?, NULL, NULL)'
            );
            $insert->execute([$companyId, $newName, $normalized]);
            $newRoleId = (int) $pdo->lastInsertId();

            $pages = $pdo->prepare(
                'INSERT INTO company_role_page_permissions (role_id, page_key, is_allowed)
                 SELECT ?, page_key, is_allowed FROM company_role_page_permissions WHERE role_id = ?'
            );
            $pages->execute([$newRoleId, $sourceRoleId]);

            $scopes = $pdo->prepare(
                'INSERT INTO company_role_data_scopes (role_id, entity_key, scope)
                 SELECT ?, entity_key, scope FROM company_role_data_scopes WHERE role_id = ?'
            );
            $scopes->execute([$newRoleId, $sourceRoleId]);

            foreach (self::DATA_ENTITIES as $entityKey) {
                $ensure = $pdo->prepare(
                    'INSERT INTO company_role_data_scopes (role_id, entity_key, scope)
                     SELECT ?, ?, ? WHERE NOT EXISTS (
                         SELECT 1 FROM company_role_data_scopes WHERE role_id = ? AND entity_key = ?
                     )'
                );
                $ensure->execute([$newRoleId, $entityKey, 'none', $newRoleId, $entityKey]);
            }

            $pdo->commit();
            return $newRoleId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function deleteRole(PDO $pdo, int $companyId, int $roleId): void
    {
        $this->requireOrdinaryRole($pdo, $companyId, $roleId);

        $used = $pdo->prepare('SELECT COUNT(*) FROM company_memberships WHERE company_id = ? AND role_id = ?');
        $used->execute([$companyId, $roleId]);
        $count = (int) $used->fetchColumn();
        if ($count > 0) {
            throw new RuntimeException('Роль используется ' . $count . ' сотрудниками. Сначала назначьте им другую роль.');
        }

        $stmt = $pdo->prepare('DELETE FROM company_roles WHERE id = ? AND company_id = ? AND system_key IS NULL');
        $stmt->execute([$roleId, $companyId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Роль не удалена.');
        }
    }

    public function saveRoleAccess(PDO $pdo, int $companyId, int $roleId, array $pagePermissions, array $dataScopes): void
    {
        $role = $this->getRole($pdo, $companyId, $roleId);
        if (!$role) {
            throw new RuntimeException('Роль не найдена.');
        }
        if (($role['system_key'] ?? null) === self::SYSTEM_LEADER) {
            throw new RuntimeException('Системная роль Руководитель всегда имеет полный доступ.');
        }

        $validScopes = ['all', 'only_own', 'none'];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM company_role_page_permissions WHERE role_id = ?')->execute([$roleId]);
            $pageInsert = $pdo->prepare(
                'INSERT INTO company_role_page_permissions (role_id, page_key, is_allowed) VALUES (?, ?, 1)'
            );
            foreach ($pagePermissions as $pageKey => $allowed) {
                $pageKey = trim((string) $pageKey);
                if ($pageKey !== '' && (bool) $allowed) {
                    $pageInsert->execute([$roleId, $pageKey]);
                }
            }

            $scopeUpsert = $pdo->prepare(
                'INSERT INTO company_role_data_scopes (role_id, entity_key, scope)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE scope = VALUES(scope)'
            );
            foreach (self::DATA_ENTITIES as $entityKey) {
                $scope = (string) ($dataScopes[$entityKey] ?? 'none');
                if (!in_array($scope, $validScopes, true)) {
                    throw new RuntimeException('Недопустимый режим доступа к данным: ' . $entityKey);
                }
                $scopeUpsert->execute([$roleId, $entityKey, $scope]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function requireOrdinaryRole(PDO $pdo, int $companyId, int $roleId): array
    {
        $role = $this->getRole($pdo, $companyId, $roleId);
        if (!$role) {
            throw new RuntimeException('Роль не найдена.');
        }
        if (($role['system_key'] ?? null) !== null && (string) $role['system_key'] !== '') {
            throw new RuntimeException('Системную роль нельзя изменить этой операцией.');
        }
        return $role;
    }

    private function assertOrdinaryRoleName(string $name, string $normalized): void
    {
        if ($name === '' || $normalized === '') {
            throw new RuntimeException('Укажите название роли.');
        }
        if (strlen($name) > 120) {
            throw new RuntimeException('Название роли слишком длинное.');
        }

        $reserved = [self::normalizeName('Руководитель'), self::normalizeName('superadmin')];
        if (in_array($normalized, $reserved, true)) {
            throw new RuntimeException('Это системное название роли зарезервировано.');
        }
    }
}
