<?php

namespace App\Service;

use App\Security\AccessRequestContext;
use App\Security\EffectiveAccess;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;
use App\Security\PdoAccessStateProvider;
use PDO;

final class CompanyLandingPageService
{
    public const OWNER_DEFAULT_PAGE_KEY = 'company.dashboard';

    public function __construct(
        private readonly PDO $central,
        private readonly PageRegistry $registry = new PageRegistry(),
    ) {
    }

    public function pathForAccount(int $accountId, int $companyId): string
    {
        $access = (new EffectiveAccessResolver(
            $this->registry,
            new PdoAccessStateProvider($this->central)
        ))->resolve(new AccessRequestContext($accountId, $companyId));

        return $this->pathForAccess($access);
    }

    public function pathForAccess(EffectiveAccess $access): string
    {
        if (!$access->isActive()) {
            return '/access-unavailable';
        }
        if ($access->isTechnicalSuperadmin()) {
            return '/superadmin/companies';
        }

        if ($access->isSystemOwner()) {
            $ownerPage = $this->registry->page(self::OWNER_DEFAULT_PAGE_KEY);
            if (is_array($ownerPage)
                && (bool)($ownerPage['landing_eligible'] ?? true)
                && $access->canAccessPage(self::OWNER_DEFAULT_PAGE_KEY)) {
                return (string)$ownerPage['path'];
            }
            return $this->firstAccessibleLanding($access);
        }

        $defaultPageKey = $this->persistedRoleDefaultPageKey(
            (int)($access->membershipId() ?? 0),
            (int)($access->companyId() ?? 0)
        );
        if ($defaultPageKey !== null) {
            $page = $this->registry->page($defaultPageKey);
            if (is_array($page)
                && $this->registry->isGrantable($defaultPageKey)
                && (bool)($page['landing_eligible'] ?? true)
                && $access->canAccessPage($defaultPageKey)) {
                return (string)$page['path'];
            }
        }

        return $this->firstAccessibleLanding($access);
    }

    private function firstAccessibleLanding(EffectiveAccess $access): string
    {
        foreach ($this->registry->landingPages() as $page) {
            $key = (string)$page['key'];
            if (!$this->registry->isGrantable($key)) {
                continue;
            }
            if ($access->canAccessPage($key)) {
                return (string)$page['path'];
            }
        }

        return '/access-unavailable';
    }

    private function persistedRoleDefaultPageKey(int $membershipId, int $companyId): ?string
    {
        if ($membershipId <= 0 || $companyId <= 0) {
            return null;
        }
        $stmt = $this->central->prepare(
            'SELECT r.default_page_key
               FROM company_memberships m
               JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
              WHERE m.id=? AND m.company_id=? AND m.status=\'active\'
              LIMIT 1'
        );
        $stmt->execute([$membershipId, $companyId]);
        $value = $stmt->fetchColumn();
        $value = $value === false ? '' : trim((string)$value);

        return $value !== '' ? $value : null;
    }
}
