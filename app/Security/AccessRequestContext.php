<?php

namespace App\Security;

final class AccessRequestContext
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $companyId,
        public readonly bool $technicalSuperadmin = false,
    ) {
    }

    public static function fromSession(array $session): self
    {
        return new self(
            max(0, (int) ($session['account_id'] ?? 0)),
            max(0, (int) ($session['company_id'] ?? 0)),
            ($session['role_code'] ?? '') === 'superadmin',
        );
    }
}
