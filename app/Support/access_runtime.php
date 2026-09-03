<?php

use App\Service\EffectiveAccessResolver;

if (!function_exists('effectiveAccessResolver')) {
    function effectiveAccessResolver(): EffectiveAccessResolver
    {
        static $resolver = null;
        if (!$resolver instanceof EffectiveAccessResolver) {
            $resolver = new EffectiveAccessResolver();
        }
        return $resolver;
    }
}

if (!function_exists('currentEffectiveAccessContext')) {
    function currentEffectiveAccessContext(PDO $centralPdo): array
    {
        return effectiveAccessResolver()->resolveContext($centralPdo, $_SESSION);
    }
}

if (!function_exists('hasCompanyPageAccess')) {
    /**
     * $serverPageKeys MUST originate from PageRegistry/route registration.
     * Never pass request payload page_key here.
     */
    function hasCompanyPageAccess(PDO $centralPdo, array $serverPageKeys): bool
    {
        return effectiveAccessResolver()->canAccessAnyPage($centralPdo, $_SESSION, $serverPageKeys);
    }
}

if (!function_exists('companyDataScope')) {
    function companyDataScope(PDO $centralPdo, string $entityKey): string
    {
        return effectiveAccessResolver()->dataScope($centralPdo, $_SESSION, $entityKey);
    }
}

if (!function_exists('companyAccessDecision')) {
    function companyAccessDecision(PDO $centralPdo, array $serverPageKeys, ?string $entityKey = null): array
    {
        return effectiveAccessResolver()->decision($centralPdo, $_SESSION, $serverPageKeys, $entityKey);
    }
}

if (!function_exists('requireCompanyAccess')) {
    /**
     * Canonical fail-closed company request guard. Returns the decision so an
     * Agent 05 data layer can enforce ONLY_OWN using its membership/actor context.
     */
    function requireCompanyAccess(PDO $centralPdo, array $serverPageKeys, ?string $entityKey = null): array
    {
        $decision = companyAccessDecision($centralPdo, $serverPageKeys, $entityKey);
        if (!($decision['allowed'] ?? false)) {
            denyEntityAccess();
        }
        return $decision;
    }
}
