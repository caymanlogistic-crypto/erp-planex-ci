<?php

namespace App\Service;

use PDO;

/**
 * Canonical company authorization resolver.
 *
 * Security authority is current central persistence on every protected request:
 * global user session identity -> selected company -> active membership -> role ->
 * page permission/personal override -> regulated data scope/personal override.
 *
 * Page keys supplied to this service must come from the server-owned PageRegistry
 * (Agent 03). Client input is never a permission authority.
 */
final class EffectiveAccessResolver
{
    public const SYSTEM_LEADER = 'leader';

    public const SCOPE_ALL = 'all';
    public const SCOPE_ONLY_OWN = 'only_own';
    public const SCOPE_NONE = 'none';

    public const DATA_ENTITIES = [
        'client',
        'contractor',
        'vehicle_unit',
        'driver',
        'linear_trip',
        'waste_trip',
    ];

    /**
     * Resolve current persisted security context. Never trusts session role/status.
     *
     * @return array{allowed:bool,reason:string,is_superadmin:bool,is_leader:bool,global_user_id:?int,membership_id:?int,tenant_user_id:?int,company_id:?int,role_id:?int,role_name:?string}
     */
    public function resolveContext(PDO $centralPdo, array $session): array
    {
        if (($session['role_code'] ?? '') === 'superadmin') {
            $superadminId = (int) ($session['user_id'] ?? 0);
            if ($superadminId <= 0) {
                return $this->denyContext('missing_superadmin_identity');
            }

            $stmt = $centralPdo->prepare('SELECT id FROM superadmin_users WHERE id = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([$superadminId]);
            if (!$stmt->fetchColumn()) {
                return $this->denyContext('inactive_or_missing_superadmin');
            }

            return [
                'allowed' => true,
                'reason' => 'superadmin',
                'is_superadmin' => true,
                'is_leader' => false,
                'global_user_id' => null,
                'membership_id' => null,
                'tenant_user_id' => null,
                'company_id' => null,
                'role_id' => null,
                'role_name' => 'Superadmin',
            ];
        }

        // Old company sessions are intentionally invalid after the security cutover.
        if ((int) ($session['session_auth_version'] ?? 0) < 2) {
            return $this->denyContext('legacy_session');
        }

        $globalUserId = (int) ($session['global_user_id'] ?? 0);
        $membershipId = (int) ($session['membership_id'] ?? 0);
        $tenantUserId = (int) ($session['tenant_user_id'] ?? 0);
        $companyId = (int) ($session['company_id'] ?? 0);
        if ($globalUserId <= 0 || $membershipId <= 0 || $tenantUserId <= 0 || $companyId <= 0) {
            return $this->denyContext('missing_company_identity');
        }

        $stmt = $centralPdo->prepare(
            'SELECT m.id AS membership_id, m.company_id, m.user_id AS global_user_id,
                    m.tenant_user_id, m.status AS membership_status,
                    r.id AS role_id, r.name AS role_name, r.system_key,
                    c.status AS company_status
               FROM company_memberships m
               JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
               JOIN companies c ON c.id = m.company_id
              WHERE m.id = ?
                AND m.user_id = ?
                AND m.company_id = ?
                AND m.tenant_user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$membershipId, $globalUserId, $companyId, $tenantUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $this->denyContext('membership_mismatch');
        }
        if (($row['membership_status'] ?? '') !== 'active') {
            return $this->denyContext('inactive_membership');
        }
        if (($row['company_status'] ?? '') !== 'active') {
            return $this->denyContext('inactive_company');
        }
        if ((int) ($row['role_id'] ?? 0) <= 0) {
            return $this->denyContext('missing_role');
        }

        $isLeader = ($row['system_key'] ?? null) === self::SYSTEM_LEADER;
        return [
            'allowed' => true,
            'reason' => $isLeader ? 'leader' : 'membership',
            'is_superadmin' => false,
            'is_leader' => $isLeader,
            'global_user_id' => $globalUserId,
            'membership_id' => $membershipId,
            'tenant_user_id' => $tenantUserId,
            'company_id' => $companyId,
            'role_id' => (int) $row['role_id'],
            'role_name' => (string) ($row['role_name'] ?? ''),
        ];
    }

    /**
     * Check an exact server-owned OR-set for a page/operation.
     * Empty mapping is unknown/unregistered and therefore denied.
     */
    public function canAccessAnyPage(PDO $centralPdo, array $session, array $serverPageKeys): bool
    {
        $context = $this->resolveContext($centralPdo, $session);
        if (!$context['allowed']) {
            return false;
        }

        $pageKeys = $this->normalizeServerPageKeys($serverPageKeys);
        if ($pageKeys === []) {
            return false;
        }
        if ($context['is_superadmin'] || $context['is_leader']) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($pageKeys), '?'));

        $roleStmt = $centralPdo->prepare(
            "SELECT page_key
               FROM company_role_page_permissions
              WHERE role_id = ?
                AND is_allowed = 1
                AND page_key IN ($placeholders)"
        );
        $roleStmt->execute(array_merge([(int) $context['role_id']], $pageKeys));
        $roleAllowed = array_fill_keys($roleStmt->fetchAll(PDO::FETCH_COLUMN) ?: [], true);

        $overrideStmt = $centralPdo->prepare(
            "SELECT page_key, effect
               FROM company_membership_page_overrides
              WHERE membership_id = ?
                AND page_key IN ($placeholders)"
        );
        $overrideStmt->execute(array_merge([(int) $context['membership_id']], $pageKeys));
        $overrides = [];
        foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $effect = (string) ($row['effect'] ?? '');
            if (!in_array($effect, ['allow', 'deny'], true)) {
                // Corrupt/unknown override fails closed for that page.
                $effect = 'deny';
            }
            $overrides[(string) $row['page_key']] = $effect;
        }

        foreach ($pageKeys as $pageKey) {
            if (isset($overrides[$pageKey])) {
                if ($overrides[$pageKey] === 'allow') {
                    return true;
                }
                continue;
            }
            if (isset($roleAllowed[$pageKey])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve effective scope for one regulated entity family. This does not
     * perform assignment/creator filtering; Agent 05 consumes this result.
     */
    public function dataScope(PDO $centralPdo, array $session, string $entityKey): string
    {
        if (!in_array($entityKey, self::DATA_ENTITIES, true)) {
            return self::SCOPE_NONE;
        }

        $context = $this->resolveContext($centralPdo, $session);
        if (!$context['allowed']) {
            return self::SCOPE_NONE;
        }
        if ($context['is_superadmin'] || $context['is_leader']) {
            return self::SCOPE_ALL;
        }

        $overrideStmt = $centralPdo->prepare(
            'SELECT scope FROM company_membership_data_overrides WHERE membership_id = ? AND entity_key = ? LIMIT 1'
        );
        $overrideStmt->execute([(int) $context['membership_id'], $entityKey]);
        $override = $overrideStmt->fetchColumn();
        if ($override !== false) {
            return $this->normalizeScope((string) $override);
        }

        $roleStmt = $centralPdo->prepare(
            'SELECT scope FROM company_role_data_scopes WHERE role_id = ? AND entity_key = ? LIMIT 1'
        );
        $roleStmt->execute([(int) $context['role_id'], $entityKey]);
        $roleScope = $roleStmt->fetchColumn();
        if ($roleScope === false) {
            return self::SCOPE_NONE;
        }

        return $this->normalizeScope((string) $roleScope);
    }

    /**
     * Convenience decision for protected operations. A page-only operation may
     * pass null entityKey. A regulated operation is denied when scope is NONE;
     * ONLY_OWN still requires Agent 05 entity enforcement before data is returned.
     *
     * @return array{allowed:bool,page_allowed:bool,data_scope:?string,requires_own_filter:bool,context:array}
     */
    public function decision(
        PDO $centralPdo,
        array $session,
        array $serverPageKeys,
        ?string $entityKey = null
    ): array {
        $context = $this->resolveContext($centralPdo, $session);
        if (!$context['allowed']) {
            return [
                'allowed' => false,
                'page_allowed' => false,
                'data_scope' => $entityKey !== null ? self::SCOPE_NONE : null,
                'requires_own_filter' => false,
                'context' => $context,
            ];
        }

        $pageAllowed = $this->canAccessAnyPage($centralPdo, $session, $serverPageKeys);
        if (!$pageAllowed) {
            return [
                'allowed' => false,
                'page_allowed' => false,
                'data_scope' => $entityKey !== null ? self::SCOPE_NONE : null,
                'requires_own_filter' => false,
                'context' => $context,
            ];
        }

        if ($entityKey === null) {
            return [
                'allowed' => true,
                'page_allowed' => true,
                'data_scope' => null,
                'requires_own_filter' => false,
                'context' => $context,
            ];
        }

        $scope = $this->dataScope($centralPdo, $session, $entityKey);
        return [
            'allowed' => $scope !== self::SCOPE_NONE,
            'page_allowed' => true,
            'data_scope' => $scope,
            'requires_own_filter' => $scope === self::SCOPE_ONLY_OWN,
            'context' => $context,
        ];
    }

    private function normalizeScope(string $scope): string
    {
        return in_array($scope, [self::SCOPE_ALL, self::SCOPE_ONLY_OWN, self::SCOPE_NONE], true)
            ? $scope
            : self::SCOPE_NONE;
    }

    private function normalizeServerPageKeys(array $serverPageKeys): array
    {
        $result = [];
        foreach ($serverPageKeys as $pageKey) {
            if (!is_string($pageKey)) {
                continue;
            }
            $pageKey = trim($pageKey);
            if ($pageKey === '' || strlen($pageKey) > 160) {
                continue;
            }
            $result[$pageKey] = true;
        }
        return array_keys($result);
    }

    private function denyContext(string $reason): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'is_superadmin' => false,
            'is_leader' => false,
            'global_user_id' => null,
            'membership_id' => null,
            'tenant_user_id' => null,
            'company_id' => null,
            'role_id' => null,
            'role_name' => null,
        ];
    }
}
