<?php

namespace App\Security;

use PDO;

/**
 * Request-scoped central RBAC reader.
 *
 * This object intentionally has no cache: every protected request observes
 * current membership, role, override and readiness state.
 */
final class PdoAccessStateProvider implements AccessStateProvider
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isCompanyAccessReady(int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT state, before_sha256, after_sha256, comparison_sha256, verified_at, activated_at
             FROM company_access_readiness
             WHERE company_id = ?"
        );
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['state'] ?? '') !== 'ready' || empty($row['verified_at']) || empty($row['activated_at'])) {
            return false;
        }

        foreach (['before_sha256', 'after_sha256', 'comparison_sha256'] as $field) {
            if (!preg_match('/^[a-f0-9]{64}$/', (string) ($row[$field] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    public function loadMembershipAccess(int $accountId, int $companyId): ?MembershipAccessSnapshot
    {
        if ($accountId <= 0 || $companyId <= 0) {
            return null;
        }

        $membership = $this->pdo->prepare(
            'SELECT m.id, m.company_id, m.status, m.role_id, r.system_key, r.normalized_name
             FROM company_memberships m
             JOIN company_roles r
               ON r.id = m.role_id
              AND r.company_id = m.company_id
             JOIN user_accounts a
               ON a.id = m.account_id
             JOIN companies c
               ON c.id = m.company_id
             WHERE m.account_id = ?
               AND m.company_id = ?
               AND a.status = \'active\'
               AND c.status = \'active\''
        );
        $membership->execute([$accountId, $companyId]);
        $row = $membership->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $roleId = (int) $row['role_id'];
        $membershipId = (int) $row['id'];

        $pages = $this->pdo->prepare(
            'SELECT page_key, is_allowed
             FROM role_page_permissions
             WHERE role_id = ?
             ORDER BY page_key'
        );
        $pages->execute([$roleId]);
        $rolePageKeys = [];
        $configuredRolePageKeys = [];
        foreach ($pages->fetchAll(PDO::FETCH_ASSOC) as $page) {
            $pageKey = (string) ($page['page_key'] ?? '');
            if ($pageKey === '') continue;
            $configuredRolePageKeys[] = $pageKey;
            if ((bool) ($page['is_allowed'] ?? false)) $rolePageKeys[] = $pageKey;
        }

        $pageOverridesStmt = $this->pdo->prepare(
            'SELECT page_key, effect
             FROM membership_page_overrides
             WHERE membership_id = ?
             ORDER BY page_key'
        );
        $pageOverridesStmt->execute([$membershipId]);
        $pageOverrides = [];
        foreach ($pageOverridesStmt->fetchAll(PDO::FETCH_ASSOC) as $override) {
            $effect = (string) ($override['effect'] ?? '');
            $pageKey = (string) ($override['page_key'] ?? '');
            if ($pageKey !== '' && in_array($effect, ['allow', 'deny'], true)) {
                $pageOverrides[$pageKey] = $effect;
            }
        }

        $scopes = $this->pdo->prepare(
            'SELECT entity_type, scope
             FROM role_data_scopes
             WHERE role_id = ?
             ORDER BY entity_type'
        );
        $scopes->execute([$roleId]);
        $roleDataScopes = [];
        foreach ($scopes->fetchAll(PDO::FETCH_ASSOC) as $scopeRow) {
            $entityType = (string) ($scopeRow['entity_type'] ?? '');
            $scope = (string) ($scopeRow['scope'] ?? '');
            if ($entityType !== '' && in_array($scope, ['all', 'own', 'none'], true)) {
                $roleDataScopes[$entityType] = $scope;
            }
        }

        $scopeOverridesStmt = $this->pdo->prepare(
            'SELECT entity_type, scope
             FROM membership_data_scope_overrides
             WHERE membership_id = ?
             ORDER BY entity_type'
        );
        $scopeOverridesStmt->execute([$membershipId]);
        $dataScopeOverrides = [];
        foreach ($scopeOverridesStmt->fetchAll(PDO::FETCH_ASSOC) as $override) {
            $entityType = (string) ($override['entity_type'] ?? '');
            $scope = (string) ($override['scope'] ?? '');
            if ($entityType !== '' && in_array($scope, ['all', 'own', 'none'], true)) {
                $dataScopeOverrides[$entityType] = $scope;
            }
        }

        return new MembershipAccessSnapshot(
            $membershipId,
            (int) $row['company_id'],
            (string) $row['status'],
            $roleId,
            (string) ($row['system_key'] ?? ''),
            $rolePageKeys,
            $pageOverrides,
            $roleDataScopes,
            $dataScopeOverrides,
            self::compatibilityRoleCode((string) ($row['system_key'] ?? ''), (string) ($row['normalized_name'] ?? '')),
            $configuredRolePageKeys,
        );
    }

    private static function compatibilityRoleCode(string $systemKey, string $normalizedName): string
    {
        if ($systemKey === 'company_owner') {
            return 'company_owner';
        }
        if ($systemKey !== '') {
            return 'custom_role';
        }

        return in_array($normalizedName, ['senior_logist', 'logist'], true)
            ? $normalizedName
            : 'custom_role';
    }
}
