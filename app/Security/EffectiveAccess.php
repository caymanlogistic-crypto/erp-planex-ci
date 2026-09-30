<?php

namespace App\Security;

final class EffectiveAccess
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_OWN = 'own';
    public const SCOPE_NONE = 'none';

    /** @var list<string> */
    private const DATA_ENTITY_KEYS = [
        'client',
        'contractor',
        'vehicle_unit',
        'driver',
        'linear_trip',
        'waste_trip',
    ];

    /**
     * @param array<string, bool> $pagePermissions
     * @param array<string, 'all'|'own'|'none'> $dataScopes
     */
    private function __construct(
        private readonly ?int $membershipId,
        private readonly ?int $companyId,
        private readonly bool $technicalSuperadmin,
        private readonly bool $systemOwner,
        private readonly bool $active,
        private readonly array $pagePermissions,
        private readonly array $dataScopes,
        private readonly string $denialReason,
        private readonly string $compatibilityRoleCode,
    ) {
    }

    /** @param list<string> $allPageKeys */
    public static function technicalSuperadmin(array $allPageKeys): self
    {
        return new self(null, null, true, false, true, array_fill_keys($allPageKeys, true), self::allDataScopes(), '', 'superadmin');
    }

    /** @param list<string> $allPageKeys */
    public static function systemOwner(int $membershipId, int $companyId, array $allPageKeys): self
    {
        return new self($membershipId, $companyId, false, true, true, array_fill_keys($allPageKeys, true), self::allDataScopes(), '', 'company_owner');
    }

    /**
     * @param array<string, bool> $pagePermissions
     * @param array<string, 'all'|'own'|'none'> $dataScopes
     */
    public static function member(int $membershipId, int $companyId, array $pagePermissions, array $dataScopes, string $compatibilityRoleCode = 'custom_role'): self
    {
        if (!in_array($compatibilityRoleCode, ['senior_logist', 'logist', 'custom_role'], true)) {
            $compatibilityRoleCode = 'custom_role';
        }
        return new self($membershipId, $companyId, false, false, true, $pagePermissions, $dataScopes, '', $compatibilityRoleCode);
    }

    public static function denied(string $reason = 'unresolved_access'): self
    {
        return new self(null, null, false, false, false, [], [], $reason, '');
    }

    public function membershipId(): ?int
    {
        return $this->membershipId;
    }

    public function companyId(): ?int
    {
        return $this->companyId;
    }

    public function isTechnicalSuperadmin(): bool
    {
        return $this->technicalSuperadmin;
    }

    public function isSystemOwner(): bool
    {
        return $this->systemOwner;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function denialReason(): string
    {
        return $this->denialReason;
    }

    public function compatibilityRoleCode(): string
    {
        return $this->compatibilityRoleCode;
    }

    public function canAccessPage(string $pageKey): bool
    {
        if ($pageKey === 'company.finance.received_paid') {
            $pageKey = 'company.finance.invoices';
        }
        return $this->active && ($this->pagePermissions[$pageKey] ?? false) === true;
    }

    /** @return 'all'|'own'|'none' */
    public function dataScope(string $entityType): string
    {
        if (!$this->active || !in_array($entityType, self::DATA_ENTITY_KEYS, true)) {
            return self::SCOPE_NONE;
        }

        $scope = $this->dataScopes[$entityType] ?? self::SCOPE_NONE;

        return in_array($scope, [self::SCOPE_ALL, self::SCOPE_OWN, self::SCOPE_NONE], true)
            ? $scope
            : self::SCOPE_NONE;
    }

    /** @return array<string, 'all'> */
    private static function allDataScopes(): array
    {
        return array_fill_keys(self::DATA_ENTITY_KEYS, self::SCOPE_ALL);
    }
}
