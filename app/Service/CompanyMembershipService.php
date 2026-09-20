<?php

namespace App\Service;

use PDO;

final class CompanyMembershipService
{
    private const STATUSES = ['active', 'blocked'];
    private const PAGE_OVERRIDE_VALUES = ['allow', 'deny'];
    private const DATA_OVERRIDE_VALUES = ['all', 'own', 'none'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Returns only memberships of the selected company and never exposes
     * password hashes or memberships/roles belonging to another company.
     *
     * @return list<array<string,mixed>>
     */
    public function listForCompany(int $companyId, array $pageKeys = [], ?array $roles = null): array
    {
        $maxSelect = $this->maxUserIdReady() ? 'a.max_user_id' : 'NULL AS max_user_id';
        $stmt = $this->pdo->prepare(
            'SELECT m.id, m.account_id, a.full_name, a.login, a.email, a.phone, ' . $maxSelect . ',
                    m.status, m.role_id, r.name AS role_name, r.system_key AS role_system_key
             FROM company_memberships m
             JOIN user_accounts a ON a.id = m.account_id
             JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
             WHERE m.company_id = ?
             ORDER BY a.full_name, m.id'
        );
        $stmt->execute([$companyId]);
        $memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $roles ??= (new CompanyRoleService($this->pdo))->listForCompany($companyId, $pageKeys);

        $pageOverrides = $this->pdo->prepare(
            'SELECT o.membership_id, o.page_key, o.effect
             FROM membership_page_overrides o
             JOIN company_memberships m ON m.id = o.membership_id
             WHERE m.company_id = ?'
        );
        $pageOverrides->execute([$companyId]);
        $pageOverrideRows = $pageOverrides->fetchAll(PDO::FETCH_ASSOC);

        $scopeOverrides = $this->pdo->prepare(
            'SELECT o.membership_id, o.entity_type, o.scope
             FROM membership_data_scope_overrides o
             JOIN company_memberships m ON m.id = o.membership_id
             WHERE m.company_id = ?'
        );
        $scopeOverrides->execute([$companyId]);
        $scopeOverrideRows = $scopeOverrides->fetchAll(PDO::FETCH_ASSOC);

        $rolesById = [];
        foreach ($roles as $role) {
            $rolesById[(int) ($role['id'] ?? 0)] = $role;
        }
        $membershipIndexes = [];
        foreach ($memberships as $index => &$membership) {
            $membershipId = (int) ($membership['id'] ?? 0);
            $roleId = (int) ($membership['role_id'] ?? 0);
            $role = $rolesById[$roleId] ?? null;
            if ($role === null) {
                unset($memberships[$index]);
                continue;
            }
            $membershipIndexes[$membershipId] = $index;
            $membership['id'] = $membershipId;
            $membership['account_id'] = (int) ($membership['account_id'] ?? 0);
            $membership['role_id'] = $roleId;
            $membership['page_overrides'] = [];
            $membership['data_scope_overrides'] = [];
            $membership['effective_pages'] = $role['pages'];
            $membership['effective_data_scopes'] = $role['data_scopes'];
            $membership['is_system_owner'] = ($role['system_key'] ?? null) === CompanyRoleService::OWNER_SYSTEM_KEY;
        }
        unset($membership);

        foreach ($pageOverrideRows as $row) {
            $membershipId = (int) ($row['membership_id'] ?? 0);
            $pageKey = (string) ($row['page_key'] ?? '');
            $effect = (string) ($row['effect'] ?? '');
            if (!isset($membershipIndexes[$membershipId])
                || !array_key_exists($pageKey, $memberships[$membershipIndexes[$membershipId]]['effective_pages'])
                || !in_array($effect, self::PAGE_OVERRIDE_VALUES, true)) {
                continue;
            }
            $index = $membershipIndexes[$membershipId];
            $memberships[$index]['page_overrides'][$pageKey] = $effect;
            if (!$memberships[$index]['is_system_owner']) {
                $memberships[$index]['effective_pages'][$pageKey] = $effect === 'allow';
            }
        }
        foreach ($scopeOverrideRows as $row) {
            $membershipId = (int) ($row['membership_id'] ?? 0);
            $entityType = (string) ($row['entity_type'] ?? '');
            $scope = (string) ($row['scope'] ?? '');
            if (!isset($membershipIndexes[$membershipId])
                || !array_key_exists($entityType, $memberships[$membershipIndexes[$membershipId]]['effective_data_scopes'])
                || !in_array($scope, self::DATA_OVERRIDE_VALUES, true)) {
                continue;
            }
            $index = $membershipIndexes[$membershipId];
            $memberships[$index]['data_scope_overrides'][$entityType] = $scope;
            if (!$memberships[$index]['is_system_owner']) {
                $memberships[$index]['effective_data_scopes'][$entityType] = $scope;
            }
        }

        foreach ($memberships as &$membership) {
            $membership['personal_settings_count'] = count($membership['page_overrides'])
                + count($membership['data_scope_overrides']);
            $membership['effective_page_count'] = count(array_filter(
                $membership['effective_pages'],
                static fn (bool $allowed): bool => $allowed
            ));
        }
        unset($membership);

        return array_values($memberships);
    }

    /**
     * Creates a global account only when the normalized login does not exist,
     * then creates exactly one membership for the selected company.
     *
     * @return array{account_id:int,membership_id:int,account_created:bool}
     */
    public function add(
        int $companyId,
        int $roleId,
        string $fullName,
        string $login,
        ?string $plainPassword,
        ?string $email = null,
        ?string $phone = null,
        string $status = 'active',
        ?string $maxUserId = null,
        bool $maxUserIdProvided = false
    ): array {
        $login = trim($login);
        $fullName = trim($fullName);
        $normalizedLogin = IdentityNormalizer::login($login);
        if ($normalizedLogin === '' || $fullName === '') {
            throw new \InvalidArgumentException('Укажите ФИО и логин.');
        }
        if ($email !== null && trim($email) !== '' && !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Некорректный email.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Некорректный статус membership.');
        }
        $maxUserId = $maxUserIdProvided ? $this->normalizeMaxUserId($maxUserId) : null;
        $maxReady = $this->maxUserIdReady();
        if ($maxUserIdProvided && $maxUserId !== null && !$maxReady) throw new \DomainException('Поле MAX ID ещё не подключено миграцией базы.');

        $this->pdo->beginTransaction();
        try {
            $this->assertRoleBelongsToCompany($companyId, $roleId);
            $accountStmt = $this->pdo->prepare(
                'SELECT id, full_name, login, email, phone, password_hash, ' . ($maxReady ? 'max_user_id' : 'NULL AS max_user_id') . ' FROM user_accounts WHERE normalized_login = ? FOR UPDATE'
            );
            $accountStmt->execute([$normalizedLogin]);
            $account = $accountStmt->fetch(PDO::FETCH_ASSOC);
            $accountCreated = false;
            if ($maxReady && $maxUserId !== null) {
                $this->assertMaxUserIdAvailable($maxUserId, $account ? (int)$account['id'] : null);
            }

            if (!$account) {
                if ($plainPassword === null || $plainPassword === '') {
                    throw new \InvalidArgumentException('Для новой учётной записи требуется пароль.');
                }
                $columns = 'full_name, login, normalized_login, email, phone, password_hash' . ($maxReady ? ', max_user_id' : '') . ', status, created_at, updated_at';
                $values = ':full_name, :login, :normalized_login, :email, :phone, :password_hash' . ($maxReady ? ', :max_user_id' : '') . ", 'active', NOW(), NOW()";
                $insertAccount = $this->pdo->prepare('INSERT INTO user_accounts (' . $columns . ') VALUES (' . $values . ')');
                $params = [
                    ':full_name' => trim($fullName),
                    ':login' => $login,
                    ':normalized_login' => $normalizedLogin,
                    ':email' => $email !== null && trim($email) !== '' ? trim($email) : null,
                    ':phone' => $phone !== null && trim($phone) !== '' ? trim($phone) : null,
                    ':password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
                ];
                if ($maxReady) $params[':max_user_id'] = $maxUserId;
                $insertAccount->execute($params);
                $accountId = (int) $this->pdo->lastInsertId();
                $accountCreated = true;
            } else {
                $accountId = (int) $account['id'];
                $this->assertCompatibleExistingIdentity($account, $fullName, $email, $phone, $plainPassword);
                if ($maxReady && $maxUserIdProvided && $maxUserId !== null) {
                    $storedMax = trim((string)($account['max_user_id'] ?? ''));
                    if ($storedMax !== '' && $storedMax !== $maxUserId) throw new \DomainException('Учётная запись существует, но MAX ID отличается. Требуется разрешить конфликт identity.');
                    if ($storedMax === '') $this->pdo->prepare('UPDATE user_accounts SET max_user_id=?,updated_at=NOW() WHERE id=?')->execute([$maxUserId,$accountId]);
                }
            }

            $existing = $this->pdo->prepare(
                'SELECT id FROM company_memberships WHERE account_id = ? AND company_id = ? FOR UPDATE'
            );
            $existing->execute([$accountId, $companyId]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                throw new \DomainException('Пользователь уже добавлен в компанию. Membership: ' . (int) $existingId);
            }

            $insertMembership = $this->pdo->prepare(
                'INSERT INTO company_memberships (account_id, company_id, role_id, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())'
            );
            $insertMembership->execute([$accountId, $companyId, $roleId, $status]);
            $membershipId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();

            return [
                'account_id' => $accountId,
                'membership_id' => $membershipId,
                'account_created' => $accountCreated,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string,string> $pageOverrides @param array<string,string> $dataScopeOverrides */
    public function save(
        int $companyId,
        int $membershipId,
        int $roleId,
        string $status,
        array $pageOverrides,
        array $dataScopeOverrides,
        array $allowedPageKeys = [],
        ?string $maxUserId = null,
        bool $maxUserIdProvided = false
    ): void {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Некорректный статус membership.');
        }
        $maxUserId = $maxUserIdProvided ? $this->normalizeMaxUserId($maxUserId) : null;
        $maxReady = $this->maxUserIdReady();
        if ($maxUserIdProvided && $maxUserId !== null && !$maxReady) throw new \DomainException('Поле MAX ID ещё не подключено миграцией базы.');
        $allowedPageKeyMap = array_fill_keys(array_values(array_unique(array_map('strval', $allowedPageKeys))), true);
        if ($pageOverrides !== [] && $allowedPageKeyMap === []) {
            throw new \InvalidArgumentException('Для сохранения персональных страниц нужен snapshot текущего Page Registry.');
        }
        foreach ($pageOverrides as $pageKey => $_effect) {
            if (!is_string($pageKey) || !isset($allowedPageKeyMap[$pageKey])) {
                throw new \InvalidArgumentException('Неизвестная или системная персональная страница.');
            }
        }
        $this->pdo->beginTransaction();
        try {
            $membership = $this->lockMembership($companyId, $membershipId);
            if ($maxReady && $maxUserIdProvided && $maxUserId !== null) {
                $this->assertMaxUserIdAvailable($maxUserId, (int)$membership['account_id']);
            }
            $targetRole = $this->lockRole($companyId, $roleId);
            $currentOwner = ($membership['role_system_key'] ?? null) === CompanyRoleService::OWNER_SYSTEM_KEY;
            $targetOwner = ($targetRole['system_key'] ?? null) === CompanyRoleService::OWNER_SYSTEM_KEY;
            if ($currentOwner && (!$targetOwner || $status !== 'active')) {
                $this->assertAnotherActiveOwner($companyId, $membershipId);
            }
            if ($targetOwner && ($pageOverrides !== [] || $dataScopeOverrides !== [])) {
                throw new \DomainException('Руководителя нельзя ограничить персональными настройками.');
            }

            $update = $this->pdo->prepare(
                'UPDATE company_memberships SET role_id = ?, status = ?, updated_at = NOW()
                 WHERE id = ? AND company_id = ?'
            );
            $update->execute([$roleId, $status, $membershipId, $companyId]);
            if ($maxReady && $maxUserIdProvided) {
                $this->pdo->prepare('UPDATE user_accounts SET max_user_id=?,updated_at=NOW() WHERE id=?')
                    ->execute([$maxUserId, (int)$membership['account_id']]);
            }

            $this->replacePageOverrides($membershipId, $pageOverrides);
            $this->replaceDataScopeOverrides($membershipId, $dataScopeOverrides);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function remove(int $companyId, int $membershipId): void
    {
        $this->pdo->beginTransaction();
        try {
            $membership = $this->lockMembership($companyId, $membershipId);
            if (($membership['role_system_key'] ?? null) === CompanyRoleService::OWNER_SYSTEM_KEY
                && ($membership['status'] ?? '') === 'active') {
                $this->assertAnotherActiveOwner($companyId, $membershipId);
            }
            $stmt = $this->pdo->prepare('DELETE FROM company_memberships WHERE id = ? AND company_id = ?');
            $stmt->execute([$membershipId, $companyId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array{id:int,name:string,status:string}> */
    public function activeCompaniesForAccount(int $accountId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.name, c.status
             FROM company_memberships m
             JOIN companies c ON c.id = m.company_id
             WHERE m.account_id = ? AND m.status = 'active' AND c.status = 'active'
             ORDER BY c.name, c.id"
        );
        $stmt->execute([$accountId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function lockMembership(int $companyId, int $membershipId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, r.system_key AS role_system_key
             FROM company_memberships m
             JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
             WHERE m.id = ? AND m.company_id = ? FOR UPDATE'
        );
        $stmt->execute([$membershipId, $companyId]);
        $membership = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$membership) {
            throw new \DomainException('Пользователь компании не найден.');
        }
        return $membership;
    }

    /** @return array<string,mixed> */
    private function lockRole(int $companyId, int $roleId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM company_roles WHERE id = ? AND company_id = ? FOR UPDATE');
        $stmt->execute([$roleId, $companyId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$role) {
            throw new \DomainException('Роль не принадлежит выбранной компании.');
        }
        return $role;
    }

    private function assertRoleBelongsToCompany(int $companyId, int $roleId): void
    {
        $this->lockRole($companyId, $roleId);
    }

    private function assertAnotherActiveOwner(int $companyId, int $excludedMembershipId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.id
             FROM company_memberships m
             JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
             WHERE m.company_id = ? AND m.id <> ? AND m.status = 'active'
               AND r.system_key = ? FOR UPDATE"
        );
        $stmt->execute([$companyId, $excludedMembershipId, CompanyRoleService::OWNER_SYSTEM_KEY]);
        if ($stmt->fetchColumn() === false) {
            throw new \DomainException('В компании должен оставаться хотя бы один активный Руководитель.');
        }
    }

    /** @param array<string,string> $overrides */
    private function replacePageOverrides(int $membershipId, array $overrides): void
    {
        $this->pdo->prepare('DELETE FROM membership_page_overrides WHERE membership_id = ?')->execute([$membershipId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO membership_page_overrides (membership_id, page_key, effect) VALUES (?, ?, ?)'
        );
        foreach ($overrides as $pageKey => $effect) {
            if (!is_string($pageKey) || !preg_match('/^[a-z0-9_.-]+$/', $pageKey)
                || !in_array($effect, self::PAGE_OVERRIDE_VALUES, true)) {
                throw new \InvalidArgumentException('Некорректная персональная настройка страницы.');
            }
            $insert->execute([$membershipId, $pageKey, $effect]);
        }
    }

    /** @param array<string,string> $overrides */
    private function replaceDataScopeOverrides(int $membershipId, array $overrides): void
    {
        $this->pdo->prepare('DELETE FROM membership_data_scope_overrides WHERE membership_id = ?')->execute([$membershipId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO membership_data_scope_overrides (membership_id, entity_type, scope) VALUES (?, ?, ?)'
        );
        foreach ($overrides as $entityType => $scope) {
            if (!in_array($entityType, CompanyRoleService::regulatedEntities(), true)
                || !in_array($scope, self::DATA_OVERRIDE_VALUES, true)) {
                throw new \InvalidArgumentException('Некорректная персональная настройка данных.');
            }
            $insert->execute([$membershipId, $entityType, $scope]);
        }
    }

    private function maxUserIdReady(): bool
    {
        try {
            return (int)$this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND COLUMN_NAME='max_user_id'"
            )->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeMaxUserId(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1
            || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw new \InvalidArgumentException('MAX ID должен быть положительным int64-идентификатором.');
        }
        return $value;
    }

    private function assertMaxUserIdAvailable(string $maxUserId, ?int $accountId = null): void
    {
        $sql = 'SELECT id FROM user_accounts WHERE max_user_id=?';
        $params = [$maxUserId];
        if ($accountId !== null) {
            $sql .= ' AND id<>?';
            $params[] = $accountId;
        }
        $sql .= ' LIMIT 1 FOR UPDATE';
        $q = $this->pdo->prepare($sql);
        $q->execute($params);
        if ($q->fetchColumn() !== false) throw new \DomainException('Этот MAX ID уже используется другой учётной записью.');
    }

    /** @param array<string,mixed> $account */
    private function assertCompatibleExistingIdentity(array $account, string $fullName, ?string $email, ?string $phone, ?string $plainPassword): void
    {
        $checks = [
            'ФИО' => [(string) ($account['full_name'] ?? ''), trim($fullName)],
            'email' => [(string) ($account['email'] ?? ''), trim((string) $email)],
            'телефон' => [(string) ($account['phone'] ?? ''), trim((string) $phone)],
        ];
        foreach ($checks as $label => [$stored, $requested]) {
            if ($requested !== '' && $stored !== '' && $stored !== $requested) {
                throw new \DomainException("Учётная запись существует, но поле «{$label}» отличается. Требуется разрешить конфликт identity.");
            }
        }
        if ($plainPassword !== null && $plainPassword !== ''
            && !password_verify($plainPassword, (string) ($account['password_hash'] ?? ''))) {
            throw new \DomainException('Учётная запись существует, но пароль не совпадает. Требуется разрешить конфликт identity.');
        }
    }
}
