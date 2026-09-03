<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class CompanyMembershipService
{
    public function findGlobalUserByLogin(PDO $pdo, string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM company_users WHERE login = ? LIMIT 2');
        $stmt->execute([$login]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) > 1) {
            throw new RuntimeException('Обнаружен конфликт глобального логина. Требуется миграционное исправление.');
        }
        return $rows[0] ?? null;
    }

    public function findGlobalUserById(PDO $pdo, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM company_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function membershipsForUser(PDO $pdo, int $userId, bool $onlyActive = true): array
    {
        $sql = 'SELECT m.*, r.name AS role_name, r.system_key, r.legacy_role_code,
                       c.name AS company_name, c.status AS company_status
                  FROM company_memberships m
                  JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
                  JOIN companies c ON c.id = m.company_id
                 WHERE m.user_id = ?';
        $params = [$userId];
        if ($onlyActive) {
            $sql .= " AND m.status = 'active' AND c.status = 'active'";
        }
        $sql .= ' ORDER BY c.name ASC, m.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Company-admin listing. It intentionally exposes only memberships of the
     * requested company; no other-company role/status/override state is joined.
     */
    public function membershipsForCompany(PDO $pdo, int $companyId): array
    {
        $stmt = $pdo->prepare(
            'SELECT m.*, r.name AS role_name, r.system_key, r.legacy_role_code,
                    u.full_name, u.login, u.email, u.phone, u.position
               FROM company_memberships m
               JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
               JOIN company_users u ON u.id = m.user_id
              WHERE m.company_id = ?
              ORDER BY CASE WHEN r.system_key = ? THEN 0 ELSE 1 END, u.full_name ASC, m.id ASC'
        );
        $stmt->execute([$companyId, CompanyRoleService::SYSTEM_LEADER]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMembership(PDO $pdo, int $userId, int $companyId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT m.*, r.name AS role_name, r.system_key, r.legacy_role_code,
                       c.name AS company_name, c.status AS company_status
                  FROM company_memberships m
                  JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
                  JOIN companies c ON c.id = m.company_id
                 WHERE m.user_id = ? AND m.company_id = ?
                 LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getMembershipById(PDO $pdo, int $companyId, int $membershipId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT m.*, r.name AS role_name, r.system_key, r.legacy_role_code
                  FROM company_memberships m
                  JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
                 WHERE m.id = ? AND m.company_id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$membershipId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function selectInitialMembership(array $globalUser, array $activeMemberships): ?array
    {
        if ($activeMemberships === []) {
            return null;
        }

        $legacyHomeCompanyId = (int) ($globalUser['company_id'] ?? 0);
        if ($legacyHomeCompanyId > 0) {
            foreach ($activeMemberships as $membership) {
                if ((int) ($membership['company_id'] ?? 0) === $legacyHomeCompanyId) {
                    return $membership;
                }
            }
        }

        return $activeMemberships[0];
    }

    public function assertMembershipUsable(array $membership): void
    {
        if (($membership['status'] ?? '') !== 'active') {
            throw new RuntimeException('Доступ к компании отключён.');
        }
        if (($membership['company_status'] ?? 'active') !== 'active') {
            throw new RuntimeException('Компания недоступна.');
        }
        if ((int) ($membership['tenant_user_id'] ?? 0) <= 0) {
            throw new RuntimeException('Учётная запись компании не завершила безопасную миграцию actor identity.');
        }
    }

    public function compatibilityRoleCode(array $membership): string
    {
        if (($membership['system_key'] ?? null) === CompanyRoleService::SYSTEM_LEADER) {
            return 'company_owner';
        }

        $legacy = trim((string) ($membership['legacy_role_code'] ?? ''));
        return $legacy !== '' ? $legacy : 'company_member';
    }

    public function createMembership(PDO $pdo, int $companyId, int $userId, int $roleId, ?int $tenantUserId = null): int
    {
        $this->requireRoleInCompany($pdo, $companyId, $roleId);

        $exists = $pdo->prepare('SELECT id FROM company_memberships WHERE company_id = ? AND user_id = ? LIMIT 1');
        $exists->execute([$companyId, $userId]);
        if ($exists->fetchColumn()) {
            throw new RuntimeException('Пользователь уже добавлен в компанию.');
        }

        $user = $pdo->prepare('SELECT id FROM company_users WHERE id = ? LIMIT 1');
        $user->execute([$userId]);
        if (!$user->fetchColumn()) {
            throw new RuntimeException('Глобальная учётная запись не найдена.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO company_memberships (company_id, user_id, role_id, tenant_user_id, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$companyId, $userId, $roleId, $tenantUserId, 'active']);
        return (int) $pdo->lastInsertId();
    }

    public function createGlobalUser(PDO $pdo, ?int $homeCompanyId, array $data): int
    {
        $fullName = trim((string) ($data['full_name'] ?? ''));
        $login = trim((string) ($data['login'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $email = trim((string) ($data['email'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $position = trim((string) ($data['position'] ?? ''));

        if ($fullName === '' || $login === '' || $password === '') {
            throw new RuntimeException('ФИО, логин и пароль обязательны.');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $login)) {
            throw new RuntimeException('Логин содержит недопустимые символы.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Некорректный email.');
        }
        if ($this->findGlobalUserByLogin($pdo, $login)) {
            throw new RuntimeException('Такой глобальный логин уже существует.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO company_users
                (company_id, full_name, login, email, phone, position, password_hash, role, status, comments)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->execute([
            $homeCompanyId && $homeCompanyId > 0 ? $homeCompanyId : null,
            $fullName,
            $login,
            $email !== '' ? $email : null,
            $phone !== '' ? $phone : null,
            $position !== '' ? $position : null,
            password_hash($password, PASSWORD_BCRYPT),
            'member',
            'active',
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function setTenantUserId(PDO $pdo, int $companyId, int $membershipId, int $tenantUserId): void
    {
        if ($tenantUserId <= 0) {
            throw new RuntimeException('Некорректный tenant user id.');
        }
        $stmt = $pdo->prepare(
            'UPDATE company_memberships
                SET tenant_user_id = ?
              WHERE id = ? AND company_id = ?'
        );
        $stmt->execute([$tenantUserId, $membershipId, $companyId]);
        if ($stmt->rowCount() !== 1) {
            $check = $this->getMembershipById($pdo, $companyId, $membershipId);
            if (!$check || (int) ($check['tenant_user_id'] ?? 0) !== $tenantUserId) {
                throw new RuntimeException('Не удалось связать membership с tenant user.');
            }
        }
    }

    public function updateMembership(PDO $pdo, int $companyId, int $membershipId, int $roleId, string $status): void
    {
        if (!in_array($status, ['active', 'blocked', 'archived'], true)) {
            throw new RuntimeException('Недопустимый статус пользователя.');
        }

        $pdo->beginTransaction();
        try {
            // Lock the complete active-leader set in deterministic order before
            // evaluating the invariant. This serializes concurrent leader removal.
            $this->lockActiveLeaderMemberships($pdo, $companyId);

            $current = $this->getMembershipById($pdo, $companyId, $membershipId, true);
            if (!$current) {
                throw new RuntimeException('Membership не найден.');
            }
            $newRole = $this->requireRoleInCompany($pdo, $companyId, $roleId);

            $isCurrentLeader = ($current['system_key'] ?? null) === CompanyRoleService::SYSTEM_LEADER;
            $willBeActiveLeader = $status === 'active'
                && (($newRole['system_key'] ?? null) === CompanyRoleService::SYSTEM_LEADER);

            if ($isCurrentLeader && (string) $current['status'] === 'active' && !$willBeActiveLeader) {
                $this->assertAnotherActiveLeaderExists($pdo, $companyId, $membershipId);
            }

            $stmt = $pdo->prepare(
                'UPDATE company_memberships SET role_id = ?, status = ? WHERE id = ? AND company_id = ?'
            );
            $stmt->execute([$roleId, $status, $membershipId, $companyId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function removeMembership(PDO $pdo, int $companyId, int $membershipId): void
    {
        $pdo->beginTransaction();
        try {
            $this->lockActiveLeaderMemberships($pdo, $companyId);

            $current = $this->getMembershipById($pdo, $companyId, $membershipId, true);
            if (!$current) {
                throw new RuntimeException('Membership не найден.');
            }
            if (($current['system_key'] ?? null) === CompanyRoleService::SYSTEM_LEADER
                && (string) $current['status'] === 'active') {
                $this->assertAnotherActiveLeaderExists($pdo, $companyId, $membershipId);
            }

            $stmt = $pdo->prepare('DELETE FROM company_memberships WHERE id = ? AND company_id = ?');
            $stmt->execute([$membershipId, $companyId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function savePersonalAccess(PDO $pdo, int $companyId, int $membershipId, array $pageOverrides, array $dataOverrides): void
    {
        $membership = $this->getMembershipById($pdo, $companyId, $membershipId);
        if (!$membership) {
            throw new RuntimeException('Membership не найден.');
        }
        if (($membership['system_key'] ?? null) === CompanyRoleService::SYSTEM_LEADER) {
            if ($pageOverrides !== [] || $dataOverrides !== []) {
                throw new RuntimeException('Руководителя нельзя ограничивать персональными настройками.');
            }
            // Heal any historical/corrupt rows instead of silently leaving them.
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM company_membership_page_overrides WHERE membership_id = ?')->execute([$membershipId]);
                $pdo->prepare('DELETE FROM company_membership_data_overrides WHERE membership_id = ?')->execute([$membershipId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            return;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM company_membership_page_overrides WHERE membership_id = ?')->execute([$membershipId]);
            $pageInsert = $pdo->prepare(
                'INSERT INTO company_membership_page_overrides (membership_id, page_key, effect) VALUES (?, ?, ?)'
            );
            foreach ($pageOverrides as $pageKey => $effect) {
                $pageKey = trim((string) $pageKey);
                $effect = (string) $effect;
                if ($pageKey === '' || $effect === '' || $effect === 'role') {
                    continue;
                }
                if (!in_array($effect, ['allow', 'deny'], true)) {
                    throw new RuntimeException('Недопустимое персональное правило страницы.');
                }
                $pageInsert->execute([$membershipId, $pageKey, $effect]);
            }

            $pdo->prepare('DELETE FROM company_membership_data_overrides WHERE membership_id = ?')->execute([$membershipId]);
            $dataInsert = $pdo->prepare(
                'INSERT INTO company_membership_data_overrides (membership_id, entity_key, scope) VALUES (?, ?, ?)'
            );
            foreach ($dataOverrides as $entityKey => $scope) {
                $entityKey = (string) $entityKey;
                $scope = (string) $scope;
                if ($scope === '' || $scope === 'role') {
                    continue;
                }
                if (!in_array($entityKey, CompanyRoleService::DATA_ENTITIES, true)
                    || !in_array($scope, ['all', 'only_own', 'none'], true)) {
                    throw new RuntimeException('Недопустимая персональная настройка данных.');
                }
                $dataInsert->execute([$membershipId, $entityKey, $scope]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function requireRoleInCompany(PDO $pdo, int $companyId, int $roleId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM company_roles WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$roleId, $companyId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$role) {
            throw new RuntimeException('Роль не найдена в текущей компании.');
        }
        return $role;
    }

    private function lockActiveLeaderMemberships(PDO $pdo, int $companyId): void
    {
        $stmt = $pdo->prepare(
            "SELECT m.id
               FROM company_memberships m
               JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
              WHERE m.company_id = ?
                AND m.status = 'active'
                AND r.system_key = ?
              ORDER BY m.id
              FOR UPDATE"
        );
        $stmt->execute([$companyId, CompanyRoleService::SYSTEM_LEADER]);
        $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function assertAnotherActiveLeaderExists(PDO $pdo, int $companyId, int $excludedMembershipId): void
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM company_memberships m
               JOIN company_roles r ON r.id = m.role_id AND r.company_id = m.company_id
              WHERE m.company_id = ?
                AND m.status = 'active'
                AND r.system_key = ?
                AND m.id <> ?"
        );
        $stmt->execute([$companyId, CompanyRoleService::SYSTEM_LEADER, $excludedMembershipId]);
        if ((int) $stmt->fetchColumn() < 1) {
            throw new RuntimeException('В компании должен оставаться минимум один активный Руководитель.');
        }
    }
}
