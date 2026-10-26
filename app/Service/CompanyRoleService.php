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

    /**
     * @param list<string> $pageKeys Current keys from the canonical Page Registry.
     * @return list<array<string,mixed>>
     */
    public function listForCompany(int $companyId, array $pageKeys = []): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.name, r.system_key, r.is_system, r.default_page_key, COUNT(m.id) AS membership_count
             FROM company_roles r
             LEFT JOIN company_memberships m ON m.role_id = r.id AND m.company_id = r.company_id
             WHERE r.company_id = ?
             GROUP BY r.id, r.name, r.system_key, r.is_system, r.default_page_key
             ORDER BY r.is_system DESC, r.name, r.id'
        );
        $stmt->execute([$companyId]);
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pages = $this->pdo->prepare(
            'SELECT p.role_id, p.page_key, p.is_allowed
             FROM role_page_permissions p
             JOIN company_roles r ON r.id = p.role_id
             WHERE r.company_id = ?'
        );
        $pages->execute([$companyId]);
        $pageRows = $pages->fetchAll(PDO::FETCH_ASSOC);

        $scopes = $this->pdo->prepare(
            'SELECT s.role_id, s.entity_type, s.scope
             FROM role_data_scopes s
             JOIN company_roles r ON r.id = s.role_id
             WHERE r.company_id = ?'
        );
        $scopes->execute([$companyId]);
        $scopeRows = $scopes->fetchAll(PDO::FETCH_ASSOC);

        $roleIndexes = [];
        foreach ($roles as $index => &$role) {
            $roleId = (int) ($role['id'] ?? 0);
            $roleIndexes[$roleId] = $index;
            $role['id'] = $roleId;
            $role['is_system'] = (bool) ($role['is_system'] ?? false);
            $role['membership_count'] = (int) ($role['membership_count'] ?? 0);
            $role['default_page_key'] = isset($role['default_page_key']) && $role['default_page_key'] !== '' ? (string)$role['default_page_key'] : null;
            $role['pages'] = array_fill_keys($pageKeys, false);
            $role['data_scopes'] = array_fill_keys(self::regulatedEntities(), 'none');
        }
        unset($role);

        $configuredPages = [];
        foreach ($pageRows as $row) {
            $roleId = (int) ($row['role_id'] ?? 0);
            $pageKey = (string) ($row['page_key'] ?? '');
            if (!isset($roleIndexes[$roleId]) || !array_key_exists($pageKey, $roles[$roleIndexes[$roleId]]['pages'])) {
                continue;
            }
            $roles[$roleIndexes[$roleId]]['pages'][$pageKey] = (bool) ($row['is_allowed'] ?? false);
            $configuredPages[$roleId][$pageKey] = true;
        }
        foreach ($scopeRows as $row) {
            $roleId = (int) ($row['role_id'] ?? 0);
            $entityType = (string) ($row['entity_type'] ?? '');
            $scope = (string) ($row['scope'] ?? '');
            if (!isset($roleIndexes[$roleId])
                || !array_key_exists($entityType, $roles[$roleIndexes[$roleId]]['data_scopes'])
                || !in_array($scope, self::DATA_SCOPES, true)) {
                continue;
            }
            $roles[$roleIndexes[$roleId]]['data_scopes'][$entityType] = $scope;
        }

        foreach ($roles as &$role) {
            $roleId = (int) $role['id'];
            if (!isset($configuredPages[$roleId]['company.warehouses.addresses'])
                && ($role['pages']['company.warehouses.stock'] ?? false)) {
                $role['pages']['company.warehouses.addresses'] = true;
            }
            if (($role['system_key'] ?? null) === self::OWNER_SYSTEM_KEY) {
                $role['default_page_key'] = 'company.dashboard';
                $role['pages'] = array_fill_keys($pageKeys, true);
                $role['data_scopes'] = array_fill_keys(self::regulatedEntities(), 'all');
            }
            $role['available_page_count'] = count(array_filter($role['pages'], static fn (bool $allowed): bool => $allowed));
        }
        unset($role);

        return $roles;
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

    /**
     * @param array<string,bool> $pagePermissions
     * @param array<string,string> $dataScopes
     * @param list<string> $allowedPageKeys Current grantable PageRegistry keys.
     * @param list<string> $landingPageKeys Current grantable landing keys in canonical order.
     */
    public function save(
        int $companyId,
        int $roleId,
        string $name,
        array $pagePermissions,
        array $dataScopes,
        ?string $defaultPageKey = null,
        array $allowedPageKeys = [],
        array $landingPageKeys = []
    ): void
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
            $allowedKeyMap = array_fill_keys(array_values(array_unique(array_map('strval', $allowedPageKeys))), true);
            if ($allowedKeyMap === []) {
                throw new \InvalidArgumentException('Для сохранения роли нужен snapshot текущего Page Registry.');
            }
            foreach ($pagePermissions as $pageKey => $_allowed) {
                if (!is_string($pageKey) || !isset($allowedKeyMap[$pageKey])) {
                    throw new \InvalidArgumentException('Неизвестный или недоступный для роли page_key.');
                }
            }
            $allowedLanding = [];
            foreach ($landingPageKeys as $pageKey) {
                if (isset($allowedKeyMap[$pageKey]) && !empty($pagePermissions[$pageKey])) {
                    $allowedLanding[] = (string)$pageKey;
                }
            }
            $defaultPageKey = trim((string)($defaultPageKey ?? ''));
            if ($allowedLanding === []) {
                $defaultPageKey = null;
            } elseif ($defaultPageKey === '') {
                $defaultPageKey = $allowedLanding[0];
            } elseif (!in_array($defaultPageKey, $allowedLanding, true)) {
                throw new \InvalidArgumentException('Выберите доступную страницу по умолчанию.');
            }

            $stmt = $this->pdo->prepare(
                'UPDATE company_roles SET name = :name, normalized_name = :normalized_name, default_page_key = :default_page_key, updated_at = NOW()
                 WHERE id = :id AND company_id = :company_id'
            );
            $stmt->execute([':name' => $displayName, ':normalized_name' => $normalized, ':default_page_key' => $defaultPageKey, ':id' => $roleId, ':company_id' => $companyId]);

            $this->pdo->prepare('DELETE FROM role_page_permissions WHERE role_id = ?')->execute([$roleId]);
            $pageInsert = $this->pdo->prepare(
                'INSERT INTO role_page_permissions (role_id, page_key, is_allowed) VALUES (:role_id, :page_key, :is_allowed)'
            );
            foreach ($pagePermissions as $pageKey => $allowed) {
                $pageInsert->execute([':role_id' => $roleId, ':page_key' => $pageKey, ':is_allowed' => $allowed ? 1 : 0]);
            }
            // Existing roles have no row for this newly configurable page.
            // A saved unchecked box needs an explicit denial to end inheritance.
            $addressKey = 'company.warehouses.addresses';
            if (isset($allowedKeyMap[$addressKey]) && !array_key_exists($addressKey, $pagePermissions)) {
                $pageInsert->execute([':role_id' => $roleId, ':page_key' => $addressKey, ':is_allowed' => 0]);
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
