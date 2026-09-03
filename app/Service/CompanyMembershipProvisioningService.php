<?php

namespace App\Service;

use PDO;
use RuntimeException;

/**
 * Coordinates membership creation with the tenant actor projection without
 * introducing a second authentication account. Central DB remains authority.
 */
final class CompanyMembershipProvisioningService
{
    private CompanyMembershipService $memberships;
    private CompanyRoleService $roles;
    private CompanyActorProjectionService $actors;

    public function __construct(private readonly array $config)
    {
        $this->memberships = new CompanyMembershipService();
        $this->roles = new CompanyRoleService();
        $this->actors = new CompanyActorProjectionService($config);
    }

    /**
     * @return array{global_user_id:int,membership_id:int,tenant_user_id:int,created_global_user:bool}
     */
    public function addExistingGlobalUser(
        PDO $centralPdo,
        array $company,
        int $globalUserId,
        int $roleId
    ): array {
        $globalUser = $this->memberships->findGlobalUserById($centralPdo, $globalUserId);
        if (!$globalUser) {
            throw new RuntimeException('Глобальная учётная запись не найдена.');
        }
        return $this->provision($centralPdo, $company, $globalUser, $roleId, false, null);
    }

    /**
     * @return array{global_user_id:int,membership_id:int,tenant_user_id:int,created_global_user:bool}
     */
    public function createGlobalUserAndMembership(
        PDO $centralPdo,
        array $company,
        array $userData,
        int $roleId
    ): array {
        return $this->provision($centralPdo, $company, null, $roleId, true, $userData);
    }

    /**
     * Convenience contract for UI: if login exists globally, create only the
     * membership; otherwise create one global identity and the membership.
     * Existing-account password is never requested or changed by this path.
     *
     * @return array{global_user_id:int,membership_id:int,tenant_user_id:int,created_global_user:bool}
     */
    public function addByLoginOrCreate(
        PDO $centralPdo,
        array $company,
        string $login,
        int $roleId,
        array $newUserData = []
    ): array {
        $login = trim($login);
        if ($login === '') {
            throw new RuntimeException('Укажите логин.');
        }

        $existing = $this->memberships->findGlobalUserByLogin($centralPdo, $login);
        if ($existing) {
            return $this->addExistingGlobalUser($centralPdo, $company, (int) $existing['id'], $roleId);
        }

        $newUserData['login'] = $login;
        return $this->createGlobalUserAndMembership($centralPdo, $company, $newUserData, $roleId);
    }

    private function provision(
        PDO $centralPdo,
        array $company,
        ?array $globalUser,
        int $roleId,
        bool $createGlobalUser,
        ?array $newUserData
    ): array {
        $companyId = (int) ($company['id'] ?? 0);
        if ($companyId <= 0) {
            throw new RuntimeException('Компания не определена.');
        }

        $role = $this->roles->getRole($centralPdo, $companyId, $roleId);
        if (!$role) {
            throw new RuntimeException('Роль не найдена в текущей компании.');
        }

        $localPdo = $this->actors->getLocalPdo($company);
        $this->actors->assertProjectionSchema($localPdo);

        $actorChange = null;
        $centralPdo->beginTransaction();
        try {
            if ($createGlobalUser) {
                if (!is_array($newUserData)) {
                    throw new RuntimeException('Данные новой учётной записи не переданы.');
                }
                $globalUserId = $this->memberships->createGlobalUser($centralPdo, $companyId, $newUserData);
                $globalUser = $this->memberships->findGlobalUserById($centralPdo, $globalUserId);
                if (!$globalUser) {
                    throw new RuntimeException('Созданная глобальная учётная запись не найдена.');
                }
            }

            if (!$globalUser) {
                throw new RuntimeException('Глобальная учётная запись не определена.');
            }

            $globalUserId = (int) $globalUser['id'];
            $membershipId = $this->memberships->createMembership(
                $centralPdo,
                $companyId,
                $globalUserId,
                $roleId,
                null
            );

            $compatibilityRole = ($role['system_key'] ?? null) === CompanyRoleService::SYSTEM_LEADER
                ? 'company_owner'
                : (trim((string) ($role['legacy_role_code'] ?? '')) ?: 'company_member');

            $actorResult = $this->actors->linkOrCreate(
                $localPdo,
                $globalUser,
                $membershipId,
                $compatibilityRole,
                'active'
            );
            $actorChange = $actorResult['change'];
            $tenantUserId = (int) $actorResult['tenant_user_id'];
            $this->memberships->setTenantUserId($centralPdo, $companyId, $membershipId, $tenantUserId);

            $centralPdo->commit();

            return [
                'global_user_id' => $globalUserId,
                'membership_id' => $membershipId,
                'tenant_user_id' => $tenantUserId,
                'created_global_user' => $createGlobalUser,
            ];
        } catch (\Throwable $e) {
            if ($centralPdo->inTransaction()) {
                $centralPdo->rollBack();
            }

            if (is_array($actorChange)) {
                try {
                    $this->actors->compensate($localPdo, $actorChange);
                } catch (\Throwable $compensationError) {
                    throw new RuntimeException(
                        'CRITICAL: central membership rollback выполнен, но tenant actor compensation не удалась: '
                        . $compensationError->getMessage(),
                        0,
                        $e
                    );
                }
            }

            throw $e;
        }
    }
}
