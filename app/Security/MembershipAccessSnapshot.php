<?php

namespace App\Security;

final class MembershipAccessSnapshot
{
    /**
     * @param list<string> $rolePageKeys
     * @param array<string, 'allow'|'deny'|'inherit'> $pageOverrides
     * @param array<string, 'all'|'own'|'none'> $roleDataScopes
     * @param array<string, 'all'|'own'|'none'|'inherit'> $dataScopeOverrides
     * @param list<string> $configuredRolePageKeys
     */
    public function __construct(
        public readonly int $membershipId,
        public readonly int $companyId,
        public readonly string $status,
        public readonly int $roleId,
        public readonly string $roleSystemKey,
        public readonly array $rolePageKeys,
        public readonly array $pageOverrides,
        public readonly array $roleDataScopes,
        public readonly array $dataScopeOverrides,
        public readonly string $compatibilityRoleCode = 'custom_role',
        public readonly array $configuredRolePageKeys = [],
    ) {
    }
}
