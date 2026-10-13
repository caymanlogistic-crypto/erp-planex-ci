<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

final class CompanyAccountPasswordResetService
{
    public function __construct(
        private readonly PDO $centralPdo,
        private readonly PDO $tenantPdo
    ) {
    }

    public function reset(int $companyId, int $tenantUserId, string $newPassword): void
    {
        if ($companyId <= 0 || $tenantUserId <= 0 || $newPassword === '') {
            throw new RuntimeException('Некорректные данные для сброса пароля.');
        }

        $tenantCommitted = false;
        $oldTenantHash = null;
        try {
            $this->centralPdo->beginTransaction();
            $this->tenantPdo->beginTransaction();

            $tenantQuery = $this->tenantPdo->prepare(
                'SELECT id, membership_id, login, password_hash FROM users WHERE id = ? FOR UPDATE'
            );
            $tenantQuery->execute([$tenantUserId]);
            $tenantUser = $tenantQuery->fetch(PDO::FETCH_ASSOC);
            $membershipId = (int) ($tenantUser['membership_id'] ?? 0);
            if ($membershipId <= 0) {
                throw new RuntimeException('Учётная запись не связана с доступом компании.');
            }

            $accountQuery = $this->centralPdo->prepare(
                'SELECT a.id, a.normalized_login FROM company_memberships m
                 JOIN user_accounts a ON a.id = m.account_id
                 WHERE m.id = ? AND m.company_id = ? FOR UPDATE'
            );
            $accountQuery->execute([$membershipId, $companyId]);
            $account = $accountQuery->fetch(PDO::FETCH_ASSOC);
            if (!$account || !hash_equals(
                (string) $account['normalized_login'],
                IdentityNormalizer::login((string) $tenantUser['login'])
            )) {
                throw new RuntimeException('Учётная запись пользователя не совпадает с доступом компании.');
            }

            $oldTenantHash = (string) $tenantUser['password_hash'];
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateTenant = $this->tenantPdo->prepare(
                'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?'
            );
            $updateTenant->execute([$newHash, $tenantUserId]);
            $updateCentral = $this->centralPdo->prepare(
                'UPDATE user_accounts SET password_hash = ?, updated_at = NOW() WHERE id = ?'
            );
            $updateCentral->execute([$newHash, (int) $account['id']]);

            $this->tenantPdo->commit();
            $tenantCommitted = true;
            $this->centralPdo->commit();
        } catch (Throwable $error) {
            if ($this->centralPdo->inTransaction()) {
                $this->centralPdo->rollBack();
            }
            if ($this->tenantPdo->inTransaction()) {
                $this->tenantPdo->rollBack();
            }
            if ($tenantCommitted && $oldTenantHash !== null) {
                try {
                    $restore = $this->tenantPdo->prepare(
                        'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?'
                    );
                    $restore->execute([$oldTenantHash, $tenantUserId]);
                } catch (Throwable $restoreError) {
                    throw new RuntimeException('CRITICAL: не удалось восстановить пароль после ошибки сброса.', 0, $restoreError);
                }
            }
            throw $error;
        }
    }
}
