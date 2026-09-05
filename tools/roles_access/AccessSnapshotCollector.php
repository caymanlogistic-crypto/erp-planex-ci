<?php

declare(strict_types=1);

namespace Planex\Tools\RolesAccess;

use PDO;
use RuntimeException;

/**
 * Collects factual legacy/new access from one company central+tenant DB pair.
 * It performs read-only queries only; schema/data migration is a separate gate.
 */
final class AccessSnapshotCollector
{
    /** @var array<string,string> */
    private const TABLES = [
        'clients' => 'clients',
        'contractors' => 'contractors',
        'vehicle_units' => 'vehicle_units',
        'vehicle_sets' => 'vehicle_sets',
        'drivers' => 'drivers',
        'linear_trips' => 'linear_routes',
        'waste_trips' => 'waste_trips',
    ];

    /**
     * @param array<string,list<string>> $legacyPagesByRole factual page matrix from the legacy route audit
     */
    public static function before(
        PDO $central,
        PDO $tenant,
        int $companyId,
        string $sourceHead,
        array $legacyPagesByRole,
    ): array {
        self::assertInputs($companyId, $sourceHead);
        $subjects = self::legacySubjects($central, $tenant, $companyId);
        $allIds = self::allEntityIds($tenant);
        $memberships = [];

        foreach ($subjects as $subject) {
            $active = ($subject['status'] ?? '') === 'active';
            $role = (string) $subject['role_code'];
            if (!array_key_exists($role, $legacyPagesByRole) || !is_array($legacyPagesByRole[$role])) {
                throw new RuntimeException('Missing factual legacy page matrix for role ' . $role);
            }
            $pages = $active ? self::normalizedIds($legacyPagesByRole[$role]) : [];
            $row = [
                'migration_key' => self::migrationKey($companyId, (string) $subject['legacy_source'], (int) $subject['legacy_source_id']),
                'company_id' => $companyId,
                'pages' => $pages,
            ];

            foreach (self::TABLES as $category => $table) {
                if (!$active) {
                    $row[$category] = [];
                } elseif (in_array($role, ['company_owner', 'senior_logist'], true)) {
                    $row[$category] = $allIds[$category];
                } elseif ($role === 'logist' && ($subject['tenant_user_ids'] ?? []) !== []) {
                    $visible = [];
                    foreach (array_values(array_unique(array_map('intval', $subject['tenant_user_ids']))) as $tenantUserId) {
                        $visible = array_merge($visible, self::legacyOwnIds($tenant, $table, $category, $tenantUserId));
                    }
                    $row[$category] = self::normalizedIds($visible);
                } else {
                    $row[$category] = [];
                }
            }
            $memberships[] = $row;
        }

        return MigrationProof::canonicalSnapshot([
            'phase' => 'BEFORE',
            'source_head' => $sourceHead,
            'memberships' => $memberships,
        ]);
    }

    /** @param list<string> $registeredPageKeys */
    public static function after(
        PDO $central,
        PDO $tenant,
        int $companyId,
        string $sourceHead,
        array $registeredPageKeys,
    ): array {
        self::assertInputs($companyId, $sourceHead);
        $registeredPageKeys = self::normalizedIds($registeredPageKeys);
        $allIds = self::allEntityIds($tenant);
        $stmt = $central->prepare(
            'SELECT m.id,m.status,m.legacy_source,m.legacy_source_id,r.id role_id,r.system_key
             FROM company_memberships m
             JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
             WHERE m.company_id=?
             ORDER BY m.legacy_source,m.legacy_source_id'
        );
        $stmt->execute([$companyId]);
        $memberships = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $membership) {
            $membershipId = (int) $membership['id'];
            $roleId = (int) $membership['role_id'];
            $active = ($membership['status'] ?? '') === 'active';
            $pages = [];
            if ($active && ($membership['system_key'] ?? null) === 'company_owner') {
                $pages = $registeredPageKeys;
            } elseif ($active) {
                $pages = self::effectivePages($central, $roleId, $membershipId, $registeredPageKeys);
            }

            $row = [
                'migration_key' => self::migrationKey($companyId, (string) $membership['legacy_source'], (int) $membership['legacy_source_id']),
                'company_id' => $companyId,
                'pages' => $pages,
            ];
            foreach (self::TABLES as $category => $table) {
                if (!$active) {
                    $row[$category] = [];
                    continue;
                }
                $scope = self::effectiveScope($central, $roleId, $membershipId, self::scopeEntity($category));
                $row[$category] = match ($scope) {
                    'all' => $allIds[$category],
                    'own' => $category === 'vehicle_sets'
                        ? self::newVehicleSetIds($tenant, $membershipId)
                        : self::newOwnIds($tenant, $table, $category, $membershipId),
                    'none' => [],
                    default => throw new RuntimeException('Invalid effective data scope: ' . $scope),
                };
            }
            $memberships[] = $row;
        }

        return MigrationProof::canonicalSnapshot([
            'phase' => 'AFTER',
            'source_head' => $sourceHead,
            'memberships' => $memberships,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public static function legacyIdentityRows(PDO $central, PDO $tenant, int $companyId): array
    {
        self::assertCompanyId($companyId);
        $owners = $central->prepare(
            "SELECT id,full_name,login,email,phone,password_hash,role role_code,status
             FROM company_users WHERE company_id=? ORDER BY id"
        );
        $owners->execute([$companyId]);
        $rows = [];
        foreach ($owners->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['company_id'] = $companyId;
            $row['legacy_source'] = 'central_company_user';
            $row['legacy_source_id'] = (int) $row['id'];
            unset($row['id']);
            $rows[] = $row;
        }

        $users = $tenant->query(
            "SELECT id,full_name,login,email,phone,password_hash,role_code,status
             FROM users WHERE deleted_at IS NULL ORDER BY id"
        );
        foreach ($users->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['company_id'] = $companyId;
            $row['legacy_source'] = 'tenant_user';
            $row['legacy_source_id'] = (int) $row['id'];
            unset($row['id']);
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private static function legacySubjects(PDO $central, PDO $tenant, int $companyId): array
    {
        $rows = MigrationProof::collapseExactMirrors(self::legacyIdentityRows($central, $tenant, $companyId));
        foreach ($rows as &$row) {
            $row['role_code'] = (string) ($row['role_code'] ?? '');
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,list<string>> */
    private static function allEntityIds(PDO $tenant): array
    {
        $out = [];
        foreach (self::TABLES as $category => $table) {
            $out[$category] = self::queryIds($tenant, "SELECT id FROM `{$table}` ORDER BY id");
        }
        return $out;
    }

    /** @return list<string> */
    private static function legacyOwnIds(PDO $tenant, string $table, string $category, int $tenantUserId): array
    {
        if ($category === 'waste_trips') {
            return self::queryIds($tenant, "SELECT id FROM `{$table}` WHERE created_by_user_id=? ORDER BY id", [$tenantUserId]);
        }
        $entityType = self::legacyGrantEntity($category);
        return self::queryIds(
            $tenant,
            "SELECT id FROM `{$table}`
             WHERE created_by_user_id=?
                OR id IN (
                    SELECT entity_id FROM entity_access_grants
                    WHERE entity_type=? AND granted_to_user_id=?
                      AND access_level IN ('view','edit') AND revoked_at IS NULL
                )
             ORDER BY id",
            [$tenantUserId, $entityType, $tenantUserId],
        );
    }

    /** @return list<string> */
    private static function newOwnIds(PDO $tenant, string $table, string $category, int $membershipId): array
    {
        if ($category === 'waste_trips') {
            return self::queryIds($tenant, "SELECT id FROM `{$table}` WHERE created_by_membership_id=? ORDER BY id", [$membershipId]);
        }
        if ($category === 'linear_trips') {
            return self::queryIds(
                $tenant,
                "SELECT id FROM `{$table}`
                 WHERE created_by_membership_id=?
                    OR id IN (
                        SELECT entity_id FROM legacy_access_projections
                        WHERE entity_type='linear_trip' AND membership_id=?
                    )
                 ORDER BY id",
                [$membershipId, $membershipId],
            );
        }
        $entityType = self::scopeEntity($category);
        return self::queryIds(
            $tenant,
            "SELECT id FROM `{$table}`
             WHERE created_by_membership_id=?
                OR id IN (
                    SELECT entity_id FROM entity_assignments
                    WHERE entity_type=? AND membership_id=?
                )
             ORDER BY id",
            [$membershipId, $entityType, $membershipId],
        );
    }

    /** @return list<string> */
    private static function newVehicleSetIds(PDO $tenant, int $membershipId): array
    {
        return self::queryIds(
            $tenant,
            "SELECT vs.id FROM vehicle_sets vs
             WHERE vs.created_by_membership_id=?
                OR vs.id IN (
                    SELECT entity_id FROM legacy_access_projections
                    WHERE entity_type='vehicle_set' AND membership_id=?
                )
                OR vs.primary_vehicle_unit_id IN (
                    SELECT vu.id FROM vehicle_units vu
                    WHERE vu.created_by_membership_id=?
                       OR vu.id IN (SELECT entity_id FROM entity_assignments WHERE entity_type='vehicle_unit' AND membership_id=?)
                )
                OR vs.secondary_vehicle_unit_id IN (
                    SELECT vu.id FROM vehicle_units vu
                    WHERE vu.created_by_membership_id=?
                       OR vu.id IN (SELECT entity_id FROM entity_assignments WHERE entity_type='vehicle_unit' AND membership_id=?)
                )
             ORDER BY vs.id",
            [$membershipId, $membershipId, $membershipId, $membershipId, $membershipId, $membershipId],
        );
    }

    /** @return list<string> */
    private static function effectivePages(PDO $pdo, int $roleId, int $membershipId, array $registered): array
    {
        $stmt = $pdo->prepare(
            "SELECT page_key FROM role_page_permissions WHERE role_id=? AND is_allowed=1
             UNION
             SELECT page_key FROM membership_page_overrides WHERE membership_id=? AND effect='allow'"
        );
        $stmt->execute([$roleId, $membershipId]);
        $allowed = array_fill_keys(self::normalizedIds($stmt->fetchAll(PDO::FETCH_COLUMN)), true);
        $denied = $pdo->prepare("SELECT page_key FROM membership_page_overrides WHERE membership_id=? AND effect='deny'");
        $denied->execute([$membershipId]);
        foreach ($denied->fetchAll(PDO::FETCH_COLUMN) as $pageKey) {
            unset($allowed[(string) $pageKey]);
        }
        return array_values(array_intersect($registered, array_keys($allowed)));
    }

    private static function effectiveScope(PDO $pdo, int $roleId, int $membershipId, string $entityType): string
    {
        $override = $pdo->prepare('SELECT scope FROM membership_data_scope_overrides WHERE membership_id=? AND entity_type=?');
        $override->execute([$membershipId, $entityType]);
        $value = $override->fetchColumn();
        if ($value !== false) {
            return (string) $value;
        }
        $role = $pdo->prepare('SELECT scope FROM role_data_scopes WHERE role_id=? AND entity_type=?');
        $role->execute([$roleId, $entityType]);
        return (string) ($role->fetchColumn() ?: 'none');
    }

    /** @return list<string> */
    private static function queryIds(PDO $pdo, string $sql, array $params = []): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return self::normalizedIds($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    private static function normalizedIds(array $values): array
    {
        $values = array_values(array_unique(array_map('strval', $values)));
        sort($values, SORT_NATURAL);
        return $values;
    }

    private static function scopeEntity(string $category): string
    {
        return match ($category) {
            'clients' => 'client',
            'contractors' => 'contractor',
            'vehicle_units' => 'vehicle_unit',
            'vehicle_sets' => 'vehicle_unit',
            'drivers' => 'driver',
            'linear_trips' => 'linear_trip',
            'waste_trips' => 'waste_trip',
            default => throw new RuntimeException('Unsupported snapshot category: ' . $category),
        };
    }

    private static function legacyGrantEntity(string $category): string
    {
        return match ($category) {
            'clients' => 'client',
            'contractors' => 'contractor',
            'vehicle_units' => 'vehicle_unit',
            'vehicle_sets' => 'vehicle_set',
            'drivers' => 'driver',
            'linear_trips' => 'linear_route',
            default => throw new RuntimeException('Unsupported legacy grant category: ' . $category),
        };
    }

    private static function migrationKey(int $companyId, string $source, int $sourceId): string
    {
        if (!in_array($source, ['central_company_user', 'tenant_user'], true) || $sourceId <= 0) {
            throw new RuntimeException('Invalid legacy membership source.');
        }
        return "company:{$companyId}:{$source}:{$sourceId}";
    }

    private static function assertInputs(int $companyId, string $sourceHead): void
    {
        self::assertCompanyId($companyId);
        if (!preg_match('/^[a-f0-9]{40}$/', $sourceHead)) {
            throw new RuntimeException('source_head must be an exact 40-hex commit.');
        }
    }

    private static function assertCompanyId(int $companyId): void
    {
        if ($companyId <= 0) {
            throw new RuntimeException('company_id must be positive.');
        }
    }
}
