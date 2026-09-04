<?php

namespace App\Service;

use PDO;

final class AccountAuthenticationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return null|array{account_id:int,full_name:string,memberships:list<array{membership_id:int,company_id:int,company_name:string}>}
     */
    public function authenticate(string $login, string $password): ?array
    {
        $normalized = IdentityNormalizer::login($login);
        if ($normalized === '' || $password === '') {
            return null;
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, full_name, password_hash FROM user_accounts
             WHERE normalized_login = ? AND status = 'active'"
        );
        $stmt->execute([$normalized]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$account || !password_verify($password, (string) $account['password_hash'])) {
            return null;
        }
        $memberships = $this->pdo->prepare(
            "SELECT m.id AS membership_id, m.company_id, c.name AS company_name
             FROM company_memberships m
             JOIN companies c ON c.id = m.company_id
             WHERE m.account_id = ? AND m.status = 'active' AND c.status = 'active'
             ORDER BY c.name, c.id"
        );
        $memberships->execute([(int) $account['id']]);
        $rows = $memberships->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return null;
        }
        return [
            'account_id' => (int) $account['id'],
            'full_name' => (string) $account['full_name'],
            'memberships' => array_map(static fn (array $row): array => [
                'membership_id' => (int) $row['membership_id'],
                'company_id' => (int) $row['company_id'],
                'company_name' => (string) $row['company_name'],
            ], $rows),
        ];
    }

    /** @param array<string,mixed> $session @param array{account_id:int,full_name:string,memberships:list<array{membership_id:int,company_id:int,company_name:string}>} $auth */
    public function establishSession(array &$session, array $auth, ?int $requestedCompanyId = null): void
    {
        $selected = null;
        foreach ($auth['memberships'] as $membership) {
            if ($requestedCompanyId === null || $membership['company_id'] === $requestedCompanyId) {
                $selected = $membership;
                break;
            }
        }
        if ($selected === null) {
            throw new \DomainException('Нет активного доступа к выбранной компании.');
        }
        $session['account_id'] = $auth['account_id'];
        $session['user_id'] = $auth['account_id']; // compatibility only; never membership identity
        $session['company_id'] = $selected['company_id'];
        $session['company_name'] = $selected['company_name'];
        $session['user_name'] = $auth['full_name'];
        unset($session['role_code'], $session['role_id'], $session['membership_id'], $session['permissions'], $session['data_scopes']);
        $session['user'] = [
            'account_id' => $auth['account_id'],
            'user_id' => $auth['account_id'],
            'company_id' => $selected['company_id'],
        ];
    }
}
