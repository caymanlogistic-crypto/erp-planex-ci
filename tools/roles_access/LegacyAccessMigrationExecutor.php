<?php

declare(strict_types=1);

namespace Planex\Tools\RolesAccess;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Idempotent fleet migration. Central writes commit before tenant projections;
 * readiness remains blocked/migrated until a separate BEFORE/AFTER proof passes.
 */
final class LegacyAccessMigrationExecutor
{
    /** @var array<string,string> */
    private const ROLE_NAMES = [
        'company_owner' => 'Руководитель',
        'senior_logist' => 'Логист+',
        'logist' => 'Логист',
    ];

    /**
     * @param array<int,PDO> $tenants company_id => tenant PDO
     * @param array<int,array<string,array{pages:list<string>,scopes:array<string,string>}>> $profiles
     */
    public static function migrateAll(PDO $central, array $tenants, array $profiles, string $sourceHead): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $sourceHead)) {
            throw new RuntimeException('source_head must be an exact 40-hex commit.');
        }
        if ($tenants === []) {
            throw new RuntimeException('At least one tenant DB is required.');
        }

        $identityRows = [];
        foreach ($tenants as $companyId => $tenant) {
            self::assertTenant((int) $companyId, $tenant);
            array_push($identityRows, ...AccessSnapshotCollector::legacyIdentityRows($central, $tenant, (int) $companyId));
        }
        $identityPlan = MigrationProof::identityPlan($identityRows);
        if ($identityPlan['blocked']) {
            return ['blocked' => true, 'identity_plan' => $identityPlan, 'companies' => []];
        }

        $accountIds = [];
        $membershipIds = [];
        $roleIds = [];
        $central->beginTransaction();
        try {
            foreach ($tenants as $companyId => $_tenant) {
                $companyId = (int) $companyId;
                self::assertNotActivated($central, $companyId);
                $roleIds[$companyId] = self::ensureRoles($central, $companyId, $profiles[$companyId] ?? null);
            }

            foreach ($identityPlan['accounts'] as $account) {
                $accountIds[$account['migration_key']] = self::ensureAccount($central, $account);
            }
            foreach ($identityPlan['memberships'] as $membership) {
                $companyId = (int) $membership['company_id'];
                $roleCode = (string) $membership['legacy_role_code'];
                if (!isset($roleIds[$companyId][$roleCode])) {
                    throw new RuntimeException("No migrated role profile for company {$companyId}, role {$roleCode}");
                }
                $accountId = $accountIds[$membership['account_migration_key']] ?? null;
                if (!is_int($accountId)) {
                    throw new RuntimeException('Account mapping is incomplete.');
                }
                $membershipId = self::ensureMembership(
                    $central,
                    $accountId,
                    $companyId,
                    $roleIds[$companyId][$roleCode],
                    (string) $membership['status'],
                    (string) $membership['legacy_source'],
                    (int) $membership['legacy_source_id'],
                );
                $membershipIds[$membership['migration_key']] = $membershipId;
            }
            $central->commit();
        } catch (Throwable $e) {
            if ($central->inTransaction()) {
                $central->rollBack();
            }
            throw $e;
        }

        $companyReports = [];
        foreach ($tenants as $companyId => $tenant) {
            $companyId = (int) $companyId;
            $tenantUserMap = [];
            foreach ($identityPlan['memberships'] as $membership) {
                if ((int) $membership['company_id'] !== $companyId || $membership['legacy_source'] !== 'tenant_user') {
                    continue;
                }
                $key = (string) $membership['migration_key'];
                $tenantUserMap[(int) $membership['legacy_source_id']] = $membershipIds[$key];
            }

            $assignmentPlan = self::tenantAssignmentPlan($tenant, $tenantUserMap);
            if ($assignmentPlan['blocked']) {
                throw new RuntimeException('Tenant assignment migration is blocked: ' . json_encode($assignmentPlan['conflicts'], JSON_THROW_ON_ERROR));
            }

            $tenant->beginTransaction();
            try {
                self::projectTenantMemberships($tenant, $tenantUserMap);
                self::projectCreators($tenant, $tenantUserMap);
                self::insertAssignments($tenant, $assignmentPlan['assignments']);
                $tenant->commit();
            } catch (Throwable $e) {
                if ($tenant->inTransaction()) {
                    $tenant->rollBack();
                }
                throw $e;
            }

            $ready = $central->prepare(
                "INSERT INTO company_access_readiness(company_id,state,migration_key)
                 VALUES (?,'migrated',?)
                 ON DUPLICATE KEY UPDATE state='migrated',migration_key=VALUES(migration_key),
                    before_sha256=NULL,after_sha256=NULL,comparison_sha256=NULL,
                    verified_at=NULL,activated_at=NULL"
            );
            $ready->execute([$companyId, 'roles-access:' . $sourceHead]);
            $companyReports[$companyId] = [
                'memberships' => count($tenantUserMap),
                'assignments' => count($assignmentPlan['assignments']),
                'state' => 'migrated',
            ];
        }

        return ['blocked' => false, 'identity_plan' => $identityPlan, 'companies' => $companyReports];
    }

    private static function assertTenant(int $companyId, mixed $tenant): void
    {
        if ($companyId <= 0 || !$tenant instanceof PDO) {
            throw new RuntimeException('tenants must be a company_id => PDO map.');
        }
    }

    private static function assertNotActivated(PDO $pdo, int $companyId): void
    {
        $stmt = $pdo->prepare("SELECT state,activated_at FROM company_access_readiness WHERE company_id=?");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (($row['state'] ?? '') === 'ready' || ($row['activated_at'] ?? null) !== null)) {
            throw new RuntimeException("Company {$companyId} access is already activated.");
        }
    }

    /** @return array<string,int> */
    private static function ensureRoles(PDO $pdo, int $companyId, mixed $profiles): array
    {
        if (!is_array($profiles) || array_keys(self::ROLE_NAMES) !== array_keys($profiles)) {
            throw new RuntimeException("Company {$companyId} must provide ordered company_owner/senior_logist/logist factual profiles.");
        }
        $ids = [];
        foreach (self::ROLE_NAMES as $code => $name) {
            $profile = $profiles[$code];
            if (!is_array($profile) || !is_array($profile['pages'] ?? null) || !is_array($profile['scopes'] ?? null)) {
                throw new RuntimeException("Incomplete role profile {$companyId}:{$code}");
            }
            foreach (['client', 'contractor', 'vehicle_unit', 'driver', 'linear_trip', 'waste_trip'] as $entityType) {
                if (!in_array($profile['scopes'][$entityType] ?? null, ['all', 'own', 'none'], true)) {
                    throw new RuntimeException("Incomplete role scope {$companyId}:{$code}:{$entityType}");
                }
            }

            $systemKey = $code === 'company_owner' ? 'company_owner' : null;
            $lookup = $pdo->prepare('SELECT id FROM company_roles WHERE company_id=? AND normalized_name=?');
            $lookup->execute([$companyId, $code]);
            $roleId = $lookup->fetchColumn();
            if ($roleId === false) {
                $insert = $pdo->prepare(
                    'INSERT INTO company_roles(company_id,name,normalized_name,system_key,is_system) VALUES (?,?,?,?,?)'
                );
                $insert->execute([$companyId, $name, $code, $systemKey, $systemKey === null ? 0 : 1]);
                $roleId = (int) $pdo->lastInsertId();
            } else {
                $roleId = (int) $roleId;
                $check = $pdo->prepare('SELECT system_key,is_system FROM company_roles WHERE id=? AND company_id=?');
                $check->execute([$roleId, $companyId]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                if (($existing['system_key'] ?? null) !== $systemKey || (int) ($existing['is_system'] ?? -1) !== ($systemKey === null ? 0 : 1)) {
                    throw new RuntimeException("Existing role identity mismatch {$companyId}:{$code}");
                }
            }
            $ids[$code] = $roleId;

            $pdo->prepare('DELETE FROM role_page_permissions WHERE role_id=?')->execute([$roleId]);
            $pageInsert = $pdo->prepare('INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES (?,?,1)');
            $pages = array_values(array_unique(array_map('strval', $profile['pages'])));
            sort($pages, SORT_STRING);
            foreach ($pages as $pageKey) {
                if ($pageKey === '') {
                    throw new RuntimeException("Empty page key in role profile {$companyId}:{$code}");
                }
                $pageInsert->execute([$roleId, $pageKey]);
            }

            $scopeInsert = $pdo->prepare(
                'INSERT INTO role_data_scopes(role_id,entity_type,scope) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE scope=VALUES(scope)'
            );
            foreach ($profile['scopes'] as $entityType => $scope) {
                $scopeInsert->execute([$roleId, $entityType, $scope]);
            }
        }
        return $ids;
    }

    private static function ensureAccount(PDO $pdo, array $account): int
    {
        $lookup = $pdo->prepare('SELECT * FROM user_accounts WHERE normalized_login=?');
        $lookup->execute([$account['normalized_login']]);
        $existing = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            foreach (['full_name', 'email', 'phone', 'password_hash'] as $field) {
                if ((string) ($existing[$field] ?? '') !== (string) ($account[$field] ?? '')) {
                    throw new RuntimeException('Existing global identity mismatch for ' . $account['normalized_login'] . ':' . $field);
                }
            }
            return (int) $existing['id'];
        }
        $insert = $pdo->prepare(
            'INSERT INTO user_accounts(full_name,login,normalized_login,email,phone,password_hash,status)
             VALUES (?,?,?,?,?,?,"active")'
        );
        $insert->execute([
            $account['full_name'], $account['login'], $account['normalized_login'],
            $account['email'], $account['phone'], $account['password_hash'],
        ]);
        return (int) $pdo->lastInsertId();
    }

    private static function ensureMembership(
        PDO $pdo,
        int $accountId,
        int $companyId,
        int $roleId,
        string $status,
        string $legacySource,
        int $legacySourceId,
    ): int {
        $status = $status === 'active' ? 'active' : 'blocked';
        $lookup = $pdo->prepare('SELECT * FROM company_memberships WHERE account_id=? AND company_id=?');
        $lookup->execute([$accountId, $companyId]);
        $existing = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int) $existing['role_id'] !== $roleId
                || (string) ($existing['legacy_source'] ?? '') !== $legacySource
                || (int) ($existing['legacy_source_id'] ?? 0) !== $legacySourceId) {
                throw new RuntimeException("Existing membership mismatch {$accountId}:{$companyId}");
            }
            return (int) $existing['id'];
        }
        $insert = $pdo->prepare(
            'INSERT INTO company_memberships(account_id,company_id,role_id,status,legacy_source,legacy_source_id)
             VALUES (?,?,?,?,?,?)'
        );
        $insert->execute([$accountId, $companyId, $roleId, $status, $legacySource, $legacySourceId]);
        return (int) $pdo->lastInsertId();
    }

    private static function tenantAssignmentPlan(PDO $tenant, array $tenantUserMap): array
    {
        $grants = $tenant->query(
            'SELECT entity_type,entity_id,granted_to_user_id,revoked_at,1 is_live FROM entity_access_grants'
        )->fetchAll(PDO::FETCH_ASSOC);

        // Historical creator identity is preserved only by created_by_membership_id.
        // It must not be duplicated into entity_assignments: assignments migrate
        // only explicit factual live grants.
        return MigrationProof::assignmentPlan($grants, [], $tenantUserMap);
    }

    private static function projectTenantMemberships(PDO $tenant, array $tenantUserMap): void
    {
        $update = $tenant->prepare('UPDATE users SET membership_id=? WHERE id=? AND (membership_id IS NULL OR membership_id=?)');
        $check = $tenant->prepare('SELECT membership_id FROM users WHERE id=?');
        foreach ($tenantUserMap as $tenantUserId => $membershipId) {
            $update->execute([$membershipId, $tenantUserId, $membershipId]);
            $check->execute([$tenantUserId]);
            if ((int) $check->fetchColumn() !== $membershipId) {
                throw new RuntimeException("Tenant membership projection failed for user {$tenantUserId}");
            }
        }
    }

    private static function projectCreators(PDO $tenant, array $tenantUserMap): void
    {
        foreach (['clients', 'contractors', 'vehicle_units', 'drivers', 'linear_routes', 'waste_trips'] as $table) {
            $rows = $tenant->query("SELECT id,created_by_user_id FROM `{$table}` WHERE created_by_user_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
            $update = $tenant->prepare("UPDATE `{$table}` SET created_by_membership_id=? WHERE id=? AND (created_by_membership_id IS NULL OR created_by_membership_id=?)");
            foreach ($rows as $row) {
                $membershipId = $tenantUserMap[(int) $row['created_by_user_id']] ?? null;
                if ($membershipId !== null) {
                    $update->execute([$membershipId, (int) $row['id'], $membershipId]);
                }
            }
        }
    }

    private static function insertAssignments(PDO $tenant, array $assignments): void
    {
        $insert = $tenant->prepare(
            'INSERT INTO entity_assignments(entity_type,entity_id,membership_id,assigned_by_membership_id)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE assigned_by_membership_id=VALUES(assigned_by_membership_id)'
        );
        foreach ($assignments as $assignment) {
            $insert->execute([
                $assignment['entity_type'], $assignment['entity_id'],
                $assignment['membership_id'], $assignment['assigned_by_membership_id'],
            ]);
        }
    }
}
