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
            if (is_string($pageKey) && $this->registry->hasPage($pageKey) && $this->registry->isGrantable($pageKey)) {
                $pages[$pageKey] = true;
            }
        }

        foreach ($snapshot->pageOverrides as $pageKey => $override) {
            if (!$this->registry->hasPage((string) $pageKey) || !$this->registry->isGrantable((string) $pageKey)) {
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

        // Existing trip-closing grants also open the new registry until a role
        // explicitly grants or denies its dedicated permission.
        $registryKey = 'company.documents.trip_closing_registry';
        if (($pages['company.documents.trip_closing'] ?? false)
            && !in_array($registryKey, $snapshot->rolePageKeys, true)
            && ($snapshot->pageOverrides[$registryKey] ?? 'inherit') === 'inherit') {
            $pages[$registryKey] = true;
        }

        // Preserve existing warehouse access until the new address permission
        // is explicitly saved for a role; afterward the two pages are independent.
        $addressKey = 'company.warehouses.addresses';
        if (($pages['company.warehouses.stock'] ?? false)
            && !in_array($addressKey, $snapshot->configuredRolePageKeys, true)
            && ($snapshot->pageOverrides[$addressKey] ?? 'inherit') === 'inherit') {
            $pages[$addressKey] = true;
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

        // Managers who can work with waste trips can inspect GPS for their own
        // trips. A dedicated GPS grant or explicit denial still takes precedence.
        $gpsKey = 'company.trips.gps_coverage';
        if (($pages['company.trips.waste'] ?? false)
            && !in_array($gpsKey, $snapshot->rolePageKeys, true)
            && ($snapshot->pageOverrides[$gpsKey] ?? 'inherit') === 'inherit') {
            $pages[$gpsKey] = true;
        }
        if (($scopes['waste_trip'] ?? EffectiveAccess::SCOPE_NONE) === EffectiveAccess::SCOPE_NONE) {
            $pages[$gpsKey] = false;
        }

        return EffectiveAccess::member(
            $snapshot->membershipId,
            $snapshot->companyId,
            $pages,
            $scopes,
            $snapshot->compatibilityRoleCode,
        );
    }

    private function validScope(mixed $scope): bool
    {
        return in_array($scope, [EffectiveAccess::SCOPE_ALL, EffectiveAccess::SCOPE_OWN, EffectiveAccess::SCOPE_NONE], true);
    }
}
