<?php

namespace App\Service;

use PDO;

final class CompanyRoleService
{
    public const OWNER_SYSTEM_KEY = 'company_owner';
    private const RESERVED_NAMES = ['руководитель', 'superadmin'];
    private const DATA_SCOPES = ['all', 'own', 'none'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listForCompany(int $companyId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.name, r.system_key, r.is_system, COUNT(m.id) AS membership_count
             FROM company_roles r
             LEFT JOIN company_memberships m ON m.role_id = r.id AND m.company_id = r.company_id
             WHERE r.company_id = ?
             GROUP BY r.id, r.name, r.system_key, r.is_system
             ORDER BY r.is_system DESC, r.name, r.id'
        );
        $stmt->execute([$companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{id:int,name:string,system_key:?string} */
    public function create(int $companyId, string $name): array
    {
        $displayName = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $normalized = IdentityNormalizer::roleName($displayName);
        $this->assertValidOrdinaryName($displayName, $normalized);

        $this->pdo->beginTransaction();
        try {
            $this->assertUniqueName($companyId, $normalized);
            $stmt = $this->pdo->prepare(
                'INSERT INTO company_roles (company_id, name, normalized_name, system_key, is_system, created_at, updated_at)
                 VALUES (:company_id, :name, :normalized_name, NULL, 0, NOW(), NOW())'
            );
            $stmt->execute([
                ':company_id' => $companyId,
                ':name' => $displayName,
                ':normalized_name' => $normalized,
            ]);
            $roleId = (int) $this->pdo->lastInsertId();
            $this->seedDenyDataScopes($roleId);
            $this->pdo->commit();
            return ['id' => $roleId, 'name' => $displayName, 'system_key' => null];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{id:int,name:string,system_key:?string} */
    public function copy(int $companyId, int $sourceRoleId, string $name, array $allPageKeys = []): array
    {
        $displayName = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $normalized = IdentityNormalizer::roleName($displayName);
        $this->assertValidOrdinaryName($displayName, $normalized);

        $this->pdo->beginTransaction();
        try {
            $source = $this->lockRole($companyId, $sourceRoleId);
            $this->assertUniqueName($companyId, $normalized);
            $insert = $this->pdo->prepare(
                'INSERT INTO company_roles (company_id, name, normalized_name, system_key, is_system, created_at, updated_at)
                 VALUES (:company_id, :name, :normalized_name, NULL, 0, NOW(), NOW())'
            );
            $insert->execute([
                ':company_id' => $companyId,
                ':name' => $displayName,
                ':normalized_name' => $normalized,
            ]);
            $roleId = (int) $this->pdo->lastInsertId();

            if (($source['system_key'] ?? null) === self::OWNER_SYSTEM_KEY) {
                if ($allPageKeys === []) {
                    throw new \InvalidArgumentException('Для копирования Руководителя нужен snapshot текущего Page Registry.');
                }
                $pageInsert = $this->pdo->prepare(
                    'INSERT INTO role_page_permissions (role_id, page_key, is_allowed) VALUES (:role_id, :page_key, 1)'
                );
                foreach (array_values(array_unique($allPageKeys)) as $pageKey) {
                    $pageInsert->execute([':role_id' => $roleId, ':page_key' => (string) $pageKey]);
                }
                $scopeInsert = $this->pdo->prepare(
                    "INSERT INTO role_data_scopes (role_id, entity_type, scope) VALUES (:role_id, :entity_type, 'all')"
                );
                foreach (self::regulatedEntities() as $entityType) {
                    $scopeInsert->execute([':role_id' => $roleId, ':entity_type' => $entityType]);
                }
            } else {
                $copyPages = $this->pdo->prepare(
                    'INSERT INTO role_page_permissions (role_id, page_key, is_allowed)
                     SELECT :target_role_id, page_key, is_allowed FROM role_page_permissions WHERE role_id = :source_role_id'
                );
                $copyPages->execute([':target_role_id' => $roleId, ':source_role_id' => $sourceRoleId]);
                $copyScopes = $this->pdo->prepare(
                    'INSERT INTO role_data_scopes (role_id, entity_type, scope)
                     SELECT :target_role_id, entity_type, scope FROM role_data_scopes WHERE role_id = :source_role_id'
                );
                $copyScopes->execute([':target_role_id' => $roleId, ':source_role_id' => $sourceRoleId]);
            }

            $this->pdo->commit();
            return ['id' => $roleId, 'name' => $displayName, 'system_key' => null];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,bool> $pagePermissions @param array<string,string> $dataScopes */
    public function save(int $companyId, int $roleId, string $name, array $pagePermissions, array $dataScopes): void
    {
        $displayName = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        $normalized = IdentityNormalizer::roleName($displayName);
        $this->assertValidOrdinaryName($displayName, $normalized);

        $this->pdo->beginTransaction();
        try {
            $role = $this->lockRole($companyId, $roleId);
            if ((int) ($role['is_system'] ?? 0) === 1 || ($role['system_key'] ?? null) !== null) {
                throw new \DomainException('Системную роль нельзя изменять.');
            }
            $this->assertUniqueName($companyId, $normalized, $roleId);
            $stmt = $this->pdo->prepare(
                'UPDATE company_roles SET name = :name, normalized_name = :normalized_name, updated_at = NOW()
                 WHERE id = :id AND company_id = :company_id'
            );
            $stmt->execute([':name' => $displayName, ':normalized_name' => $normalized, ':id' => $roleId, ':company_id' => $companyId]);

            $this->pdo->prepare('DELETE FROM role_page_permissions WHERE role_id = ?')->execute([$roleId]);
            $pageInsert = $this->pdo->prepare(
                'INSERT INTO role_page_permissions (role_id, page_key, is_allowed) VALUES (:role_id, :page_key, :is_allowed)'
            );
            foreach ($pagePermissions as $pageKey => $allowed) {
                if (!is_string($pageKey) || !preg_match('/^[a-z0-9_.-]+$/', $pageKey)) {
                    throw new \InvalidArgumentException('Некорректный page_key.');
                }
                $pageInsert->execute([':role_id' => $roleId, ':page_key' => $pageKey, ':is_allowed' => $allowed ? 1 : 0]);
            }

            $this->pdo->prepare('DELETE FROM role_data_scopes WHERE role_id = ?')->execute([$roleId]);
            $scopeInsert = $this->pdo->prepare(
                'INSERT INTO role_data_scopes (role_id, entity_type, scope) VALUES (:role_id, :entity_type, :scope)'
            );
            foreach (self::regulatedEntities() as $entityType) {
                $scope = $dataScopes[$entityType] ?? 'none';
                if (!in_array($scope, self::DATA_SCOPES, true)) {
                    throw new \InvalidArgumentException('Некорректный режим доступа к данным.');
                }
                $scopeInsert->execute([':role_id' => $roleId, ':entity_type' => $entityType, ':scope' => $scope]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delete(int $companyId, int $roleId): void
    {
        $this->pdo->beginTransaction();
        try {
            $role = $this->lockRole($companyId, $roleId);
            if ((int) ($role['is_system'] ?? 0) === 1 || ($role['system_key'] ?? null) !== null) {
                throw new \DomainException('Системную роль нельзя удалить.');
            }
            $count = $this->pdo->prepare('SELECT id FROM company_memberships WHERE company_id = ? AND role_id = ? FOR UPDATE');
            $count->execute([$companyId, $roleId]);
            $used = count($count->fetchAll(PDO::FETCH_COLUMN));
            if ($used > 0) {
                throw new \DomainException("Роль используется {$used} сотрудниками. Сначала назначьте им другую роль.");
            }
            $this->pdo->prepare('DELETE FROM company_roles WHERE id = ? AND company_id = ?')->execute([$roleId, $companyId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<string> */
    public static function regulatedEntities(): array
    {
        return ['client', 'contractor', 'vehicle_unit', 'driver', 'linear_trip', 'waste_trip'];
    }

    private function seedDenyDataScopes(int $roleId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO role_data_scopes (role_id, entity_type, scope) VALUES (:role_id, :entity_type, 'none')"
        );
        foreach (self::regulatedEntities() as $entityType) {
            $stmt->execute([':role_id' => $roleId, ':entity_type' => $entityType]);
        }
    }

    /** @return array<string,mixed> */
    private function lockRole(int $companyId, int $roleId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM company_roles WHERE id = ? AND company_id = ? FOR UPDATE');
        $stmt->execute([$roleId, $companyId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$role) {
            throw new \DomainException('Роль не найдена.');
        }
        return $role;
    }

    private function assertUniqueName(int $companyId, string $normalized, ?int $excludeRoleId = null): void
    {
        $sql = 'SELECT COUNT(*) FROM company_roles WHERE company_id = :company_id AND normalized_name = :normalized_name';
        $params = [':company_id' => $companyId, ':normalized_name' => $normalized];
        if ($excludeRoleId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeRoleId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \DomainException('Роль с таким названием уже существует.');
        }
    }

    private function assertValidOrdinaryName(string $displayName, string $normalized): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($displayName) : strlen($displayName);
        if ($displayName === '' || $length > 100) {
            throw new \InvalidArgumentException('Укажите название роли длиной до 100 символов.');
        }
        if (in_array($normalized, self::RESERVED_NAMES, true)) {
            throw new \DomainException('Это название зарезервировано системой.');
        }
    }
}
