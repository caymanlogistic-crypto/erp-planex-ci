<?php

namespace App\Security;

/** Stable key for browser-only registry filters; never grants access. */
final class RegistryUiStateScope
{
    public static function fingerprint(int $accountId, EffectiveAccess $access): string
    {
        $keys = array_column(PageRegistry::companyPages(), 'key');
        sort($keys, SORT_STRING);
        $permissions = [];
        foreach ($keys as $key) {
            $permissions[$key] = $access->canAccessPage($key);
        }
        $scopes = [];
        foreach (['client', 'contractor', 'driver', 'linear_trip', 'vehicle_unit', 'waste_trip'] as $entity) {
            $scopes[$entity] = $access->dataScope($entity);
        }
        return hash('sha256', json_encode([
            'account' => $accountId,
            'membership' => $access->membershipId(),
            'company' => $access->companyId(),
            'active' => $access->isActive(),
            'system_owner' => $access->isSystemOwner(),
            'technical_superadmin' => $access->isTechnicalSuperadmin(),
            'role' => $access->compatibilityRoleCode(),
            'permissions' => $permissions,
            'data_scopes' => $scopes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
