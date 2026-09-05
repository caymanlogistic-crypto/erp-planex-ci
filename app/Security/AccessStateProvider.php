<?php

namespace App\Security;

/**
 * Central identity/access persistence boundary.
 *
 * Implementations must read current state for every protected request. They
 * must not keep a role, membership status or permission snapshot in session.
 */
interface AccessStateProvider
{
    public function isCompanyAccessReady(int $companyId): bool;

    public function loadMembershipAccess(int $accountId, int $companyId): ?MembershipAccessSnapshot;
}
