<?php

namespace App\Service;

use PDO;

final class CompanySwitchService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $session */
    public function switch(array &$session, int $targetCompanyId): void
    {
        $accountId = filter_var($session['account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($accountId === false || $targetCompanyId < 1 || ($session['role_code'] ?? '') === 'superadmin') {
            throw new \DomainException('Переключение компании недоступно.');
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.id, m.company_id, c.name AS company_name
             FROM company_memberships m
             JOIN companies c ON c.id = m.company_id
             WHERE m.account_id = ? AND m.company_id = ?
               AND m.status = 'active' AND c.status = 'active'"
        );
        $stmt->execute([(int) $accountId, $targetCompanyId]);
        $membership = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$membership) {
            throw new \DomainException('Нет активного доступа к выбранной компании.');
        }

        // Only the active-company selector is mutable. Permissions are loaded
        // from central state by AccessStateProvider on the next request.
        $session['company_id'] = (int) $membership['company_id'];
        $session['company_name'] = (string) $membership['company_name'];
        unset(
            $session['role_code'],
            $session['membership_id'],
            $session['role_id'],
            $session['permissions'],
            $session['data_scopes'],
            $session['user']['role_code'],
        );
    }
}
