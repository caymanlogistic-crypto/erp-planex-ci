<?php

namespace App\Security;

final class EffectiveAccessResolver
{
    public const SYSTEM_OWNER_KEY = 'company_owner';

    public function __construct(
        private readonly PageRegistry $registry,
        private readonly AccessStateProvider $provider,
    ) {
    }

    public function resolve(AccessRequestContext $request): EffectiveAccess
    {
        if ($request->technicalSuperadmin) {
            return EffectiveAccess::technicalSuperadmin($this->registry->pageKeys());
        }

        if ($request->accountId <= 0 || $request->companyId <= 0) {
            return EffectiveAccess::denied('missing_account_or_company');
        }

        try {
            if (!$this->provider->isCompanyAccessReady($request->companyId)) {
                return EffectiveAccess::denied('access_not_ready');
            }

            $snapshot = $this->provider->loadMembershipAccess($request->accountId, $request->companyId);
        } catch (\Throwable) {
            return EffectiveAccess::denied('access_state_error');
        }

        if ($snapshot === null
            || $snapshot->membershipId <= 0
            || $snapshot->companyId !== $request->companyId
            || $snapshot->status !== 'active') {
            return EffectiveAccess::denied('inactive_or_missing_membership');
        }

        if ($snapshot->roleSystemKey === self::SYSTEM_OWNER_KEY) {
            return EffectiveAccess::systemOwner($snapshot->membershipId, $snapshot->companyId, $this->registry->pageKeys());
        }

        if ($snapshot->roleId <= 0 || $snapshot->roleSystemKey !== '') {
            return EffectiveAccess::denied('invalid_role');
        }

        $pages = array_fill_keys($this->registry->pageKeys(), false);
        foreach ($snapshot->rolePageKeys as $pageKey) {
            if (is_string($pageKey) && $this->registry->hasPage($pageKey)) {
                $pages[$pageKey] = true;
            }
        }

        foreach ($snapshot->pageOverrides as $pageKey => $override) {
            if (!$this->registry->hasPage((string) $pageKey)) {
                continue;
            }
            if ($override === 'allow') {
                $pages[$pageKey] = true;
            } elseif ($override === 'deny') {
                $pages[$pageKey] = false;
            } elseif ($override !== 'inherit') {
                $pages[$pageKey] = false;
            }
        }

        $scopes = [];
        foreach (['client', 'contractor', 'vehicle_unit', 'driver', 'linear_trip', 'waste_trip'] as $entityType) {
            $roleScope = $snapshot->roleDataScopes[$entityType] ?? EffectiveAccess::SCOPE_NONE;
            $scope = $this->validScope($roleScope) ? $roleScope : EffectiveAccess::SCOPE_NONE;
            $override = $snapshot->dataScopeOverrides[$entityType] ?? 'inherit';
            if ($override !== 'inherit') {
                $scope = $this->validScope($override) ? $override : EffectiveAccess::SCOPE_NONE;
            }
            $scopes[$entityType] = $scope;
        }

        return EffectiveAccess::member($snapshot->membershipId, $snapshot->companyId, $pages, $scopes);
    }

    private function validScope(mixed $scope): bool
    {
        return in_array($scope, [EffectiveAccess::SCOPE_ALL, EffectiveAccess::SCOPE_OWN, EffectiveAccess::SCOPE_NONE], true);
    }
}
