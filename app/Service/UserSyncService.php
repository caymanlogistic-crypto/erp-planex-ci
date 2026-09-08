<?php

namespace App\Service;

use PDO;
use App\Core\Database;

final class UserSyncService
{
    public function __construct(
        private readonly array $config
    ) {}

    /**
     * Get local PDO for a company tenant database.
     * Does NOT run tenant migrations — schema must be provisioned by
     * dedicated provisioning/migration workflows only.
     */
    public function getLocalPdo(array $company): PDO
    {
        $localDbConfig = companyDatabaseConfig($this->config, $company);
        $db = new Database($localDbConfig);
        return $db->connection();
    }

    /**
     * Assert that the tenant users table has all columns required by CRUD.
     * Read-only; never creates or alters the schema.
     * Fails with a clear error if the table or any required column is missing.
     */
    public function ensureUsersTable(PDO $localPdo): void
    {
        $requiredColumns = [
            'id', 'full_name', 'login', 'email', 'phone', 'password_hash',
            'role_code', 'status', 'created_by_user_id', 'created_by_role',
            'created_at', 'updated_at', 'deleted_at', 'deleted_by_user_id',
            'deleted_by_role',
        ];

        try {
            $stmt = $localPdo->query("SHOW COLUMNS FROM `users`");
            $existing = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Таблица users не найдена в БД компании. '
                . 'Выполните полную миграцию через штатный процесс развёртывания.'
            );
        }

        $missing = array_diff($requiredColumns, $existing);
        if (!empty($missing)) {
            throw new \RuntimeException(
                'Таблица users в БД компании неполная. Отсутствуют столбцы: '
                . implode(', ', $missing)
                . '. Выполните полную миграцию через штатный процесс развёртывания.'
            );
        }
    }

    /**
     * Update the tenant compatibility projection using stable membership_id.
     * Global authentication/profile and central membership remain authoritative.
     * This method performs no schema changes and fails closed before migration 131+.
     *
     * @param array{id:int,full_name:string,login:string,email?:?string,phone?:?string,password_hash:string} $account
     * @param array{id:int,status:string} $membership
     * @param array{id:int,system_key:?string} $role
     */
    public function syncMembershipProjection(PDO $localPdo, array $account, array $membership, array $role): void
    {
        $columns = $localPdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!in_array('membership_id', $columns, true)) {
            throw new \RuntimeException('Tenant users.membership_id отсутствует; требуется migration 131+.');
        }
        $localPdo->beginTransaction();
        try {
            $membershipId = (int) $membership['id'];
            $find = $localPdo->prepare('SELECT id FROM users WHERE membership_id = ? FOR UPDATE');
            $find->execute([$membershipId]);
            $tenantId = $find->fetchColumn();
            if ($tenantId === false) {
                $byLogin = $localPdo->prepare('SELECT id,membership_id FROM users WHERE login = ? AND deleted_at IS NULL ORDER BY id FOR UPDATE');
                $byLogin->execute([(string) $account['login']]);
                $loginRows = $byLogin->fetchAll(PDO::FETCH_ASSOC);
                if (count($loginRows) > 1) {
                    throw new \RuntimeException('Tenant users содержит несколько активных записей с этим логином.');
                }
                if ($loginRows !== []) {
                    $projected = (int) ($loginRows[0]['membership_id'] ?? 0);
                    if ($projected > 0 && $projected !== $membershipId) {
                        throw new \RuntimeException('Tenant user уже связан с другим membership.');
                    }
                    $tenantId = (int) $loginRows[0]['id'];
                    $localPdo->prepare('UPDATE users SET membership_id=? WHERE id=?')->execute([$membershipId, $tenantId]);
                }
            }
            $roleCode = ($role['system_key'] ?? null) === CompanyRoleService::OWNER_SYSTEM_KEY
                ? 'company_owner'
                : 'membership_role';
            if ($tenantId === false) {
                $insert = $localPdo->prepare(
                    'INSERT INTO users
                     (membership_id, full_name, login, email, phone, password_hash, role_code, status, created_by_user_id, created_by_role)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $membershipId, (string) $account['full_name'], (string) $account['login'],
                    $account['email'] ?? null, $account['phone'] ?? null, (string) $account['password_hash'],
                    $roleCode, (string) $membership['status'], (int) ($_SESSION['account_id'] ?? 0), 'membership',
                ]);
            } else {
                $update = $localPdo->prepare(
                    'UPDATE users SET full_name = ?, login = ?, email = ?, phone = ?, password_hash = ?,
                     role_code = ?, status = ?, updated_at = NOW() WHERE membership_id = ?'
                );
                $update->execute([
                    (string) $account['full_name'], (string) $account['login'], $account['email'] ?? null,
                    $account['phone'] ?? null, (string) $account['password_hash'], $roleCode,
                    (string) $membership['status'], $membershipId,
                ]);
            }
            $localPdo->commit();
        } catch (\Throwable $e) {
            if ($localPdo->inTransaction()) {
                $localPdo->rollBack();
            }
            throw $e;
        }
    }

    public function resolveMembershipTenantUserId(PDO $centralPdo, PDO $localPdo, int $companyId, int $membershipId): int
    {
        if ($companyId <= 0 || $membershipId <= 0) {
            throw new \RuntimeException('Менеджер не выбран.');
        }
        $stmt = $centralPdo->prepare(
            "SELECT m.id AS membership_id,m.status AS membership_status,
                    a.id,a.full_name,a.login,a.email,a.phone,a.password_hash,a.status AS account_status,
                    r.id AS role_id,r.system_key
               FROM company_memberships m
               JOIN user_accounts a ON a.id=m.account_id
               JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
              WHERE m.id=? AND m.company_id=? LIMIT 1"
        );
        $stmt->execute([$membershipId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string) ($row['membership_status'] ?? '') !== 'active' || (string) ($row['account_status'] ?? '') !== 'active') {
            throw new \RuntimeException('Выбранный менеджер недоступен или неактивен.');
        }
        $this->syncMembershipProjection(
            $localPdo,
            [
                'id'=>(int)$row['id'],'full_name'=>(string)$row['full_name'],'login'=>(string)$row['login'],
                'email'=>$row['email']??null,'phone'=>$row['phone']??null,'password_hash'=>(string)$row['password_hash'],
            ],
            ['id'=>$membershipId,'status'=>'active'],
            ['id'=>(int)$row['role_id'],'system_key'=>$row['system_key']??null],
        );
        $find = $localPdo->prepare("SELECT id FROM users WHERE membership_id=? AND status='active' AND deleted_at IS NULL ORDER BY id LIMIT 2");
        $find->execute([$membershipId]);
        $ids = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
        if (count($ids) !== 1) {
            throw new \RuntimeException('Не удалось однозначно сопоставить менеджера с БД компании.');
        }
        return $ids[0];
    }

    public function loginExistsInCentral(PDO $centralPdo, string $login, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            $stmt = $centralPdo->prepare("SELECT COUNT(*) FROM company_users WHERE login = ? AND id != ?");
            $stmt->execute([$login, $excludeId]);
        } else {
            $stmt = $centralPdo->prepare("SELECT COUNT(*) FROM company_users WHERE login = ?");
            $stmt->execute([$login]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }

    public function loginExistsInTenant(PDO $localPdo, string $login, ?int $excludeId = null): bool
    {
        if ($excludeId) {
            $stmt = $localPdo->prepare("SELECT COUNT(*) FROM users WHERE login = ? AND id != ?");
            $stmt->execute([$login, $excludeId]);
        } else {
            $stmt = $localPdo->prepare("SELECT COUNT(*) FROM users WHERE login = ?");
            $stmt->execute([$login]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Create a user in both central and tenant DB.
     *
     * Best-effort two-connection consistency with compensation:
     * - Transactions are opened on BOTH connections before writes.
     * - Commit order: tenant first, then central.
     * - If a pre-commit write fails, both transactions are rolled back.
     * - If tenant commit succeeds but central commit fails,
     *   the tenant insert is compensated (deleted).
     * - If compensation itself fails, a critical error is thrown.
     *
     * @return array{central_id: int, full_name: string, login: string, role: string}
     */
    public function createUser(
        PDO $centralPdo,
        array $company,
        string $fullName,
        string $login,
        string $password,
        string $role,
        ?string $email = null,
        ?string $phone = null,
        ?string $comments = null
    ): array {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');

        $localPdo = $this->getLocalPdo($company);
        $this->ensureUsersTable($localPdo);

        $centralPdo->beginTransaction();
        $localPdo->beginTransaction();

        try {
            $stmtCentral = $centralPdo->prepare(
                "INSERT INTO company_users (company_id, role, full_name, login, email, phone, password_hash, status, comments, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?)"
            );
            $stmtCentral->execute([
                (int)$company['id'],
                $role,
                $fullName,
                $login,
                $email ?: null,
                $phone ?: null,
                $hash,
                $comments ?: null,
                $now,
                $now,
            ]);
            $centralUserId = (int)$centralPdo->lastInsertId();

            $stmtLocal = $localPdo->prepare(
                "INSERT INTO users (full_name, login, email, phone, password_hash, role_code, status, created_by_user_id, created_by_role)
                 VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)"
            );
            $stmtLocal->execute([
                $fullName,
                $login,
                $email ?: null,
                $phone ?: null,
                $hash,
                $role,
                (int)($_SESSION['user_id'] ?? 0),
                $_SESSION['role_code'] ?? 'superadmin',
            ]);

            if ($stmtLocal->rowCount() === 0) {
                throw new \RuntimeException('Tenant insert affected 0 rows');
            }

            $localPdo->commit();

            try {
                $centralPdo->commit();
            } catch (\Exception $e) {
                $this->compensateCreateTenant($localPdo, $login);
                throw new \RuntimeException('Ошибка создания учётной записи: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            $this->rollbackBoth($centralPdo, $localPdo);
            throw new \RuntimeException('Ошибка создания учётной записи: ' . $e->getMessage());
        }

        return [
            'central_id' => $centralUserId,
            'full_name'  => $fullName,
            'login'      => $login,
            'role'       => $role,
        ];
    }

    /**
     * Update user in both central and tenant DB.
     *
     * Best-effort two-connection consistency with compensation:
     * - Complete prior values are loaded before writes for restoration.
     * - Transactions are opened on BOTH connections before writes.
     * - Commit order: tenant first, then central.
     * - If tenant commit succeeds but central commit fails,
     *   the tenant row is restored from the prior snapshot.
     * - Tenant lookup uses old login with separate bind names
     *   (fixes bug when login changes).
     * - Idempotent/no-op updates (rowCount === 0) are not treated
     *   as missing; existence is verified separately.
     * - If compensation itself fails, a critical error is thrown.
     */
    public function updateUser(
        PDO $centralPdo,
        array $company,
        int $centralUserId,
        string $fullName,
        string $login,
        ?string $email = null,
        ?string $phone = null,
        ?string $role = null,
        ?string $status = null,
        ?string $comments = null
    ): void {
        $stmtOld = $centralPdo->prepare("SELECT * FROM company_users WHERE id = ? AND company_id = ?");
        $stmtOld->execute([$centralUserId, (int)$company['id']]);
        $oldUser = $stmtOld->fetch(PDO::FETCH_ASSOC);
        if ($oldUser === false) {
            throw new \RuntimeException('Пользователь не найден в центральном реестре');
        }
        $oldLogin = $oldUser['login'];

        $localPdo = $this->getLocalPdo($company);
        $this->ensureUsersTable($localPdo);

        $tenantExists = $this->loginExistsInTenant($localPdo, $oldLogin);

        $centralPdo->beginTransaction();
        $localPdo->beginTransaction();

        try {
            // --- Central update ---
            $centralFields = [];
            $centralParams = [];

            if ($fullName !== null) { $centralFields[] = 'full_name = :fn'; $centralParams[':fn'] = $fullName; }
            if ($login !== null) { $centralFields[] = 'login = :login'; $centralParams[':login'] = $login; }
            if ($email !== null) { $centralFields[] = 'email = :email'; $centralParams[':email'] = $email ?: null; }
            if ($phone !== null) { $centralFields[] = 'phone = :phone'; $centralParams[':phone'] = $phone ?: null; }
            if ($role !== null) { $centralFields[] = 'role = :role'; $centralParams[':role'] = $role; }
            if ($status !== null) { $centralFields[] = 'status = :status'; $centralParams[':status'] = $status; }
            if ($comments !== null) { $centralFields[] = 'comments = :comments'; $centralParams[':comments'] = $comments ?: null; }

            if (!empty($centralFields)) {
                $centralFields[] = 'updated_at = NOW()';
                $centralParams[':id'] = $centralUserId;
                $centralParams[':cid'] = (int)$company['id'];

                $stmt = $centralPdo->prepare(
                    "UPDATE company_users SET " . implode(', ', $centralFields) . " WHERE id = :id AND company_id = :cid"
                );
                $stmt->execute($centralParams);
            }

            // --- Tenant update ---
            $localFields = [];
            $localParams = [];

            if ($fullName !== null) { $localFields[] = 'full_name = :fn'; $localParams[':fn'] = $fullName; }
            if ($login !== null) { $localFields[] = 'login = :login_new'; $localParams[':login_new'] = $login; }
            if ($email !== null) { $localFields[] = 'email = :email'; $localParams[':email'] = $email ?: null; }
            if ($phone !== null) { $localFields[] = 'phone = :phone'; $localParams[':phone'] = $phone ?: null; }
            if ($role !== null) { $localFields[] = 'role_code = :role'; $localParams[':role'] = $role; }
            if ($status !== null) { $localFields[] = 'status = :status'; $localParams[':status'] = $status; }

            $loginChanged = ($login !== null && $login !== $oldLogin);

            if (!empty($localFields) || $loginChanged) {
                $localFields[] = 'updated_at = NOW()';
                $localParams[':old_login'] = $oldLogin;

                $localStmt = $localPdo->prepare(
                    "UPDATE users SET " . implode(', ', $localFields) . " WHERE login = :old_login"
                );
                $localStmt->execute($localParams);

                if ($localStmt->rowCount() === 0 && !$tenantExists) {
                    throw new \RuntimeException('Tenant user not found');
                }
            }

            $localPdo->commit();

            try {
                $centralPdo->commit();
            } catch (\Exception $e) {
                $this->compensateUpdateTenant($localPdo, $oldUser, $login);
                throw new \RuntimeException('Ошибка обновления учётной записи: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            $this->rollbackBoth($centralPdo, $localPdo);
            throw new \RuntimeException('Ошибка обновления учётной записи: ' . $e->getMessage());
        }
    }

    /**
     * Reset password in both central and tenant DB.
     *
     * Best-effort two-connection consistency with compensation:
     * - Prior password hash is retained for restoration.
     * - Transactions are opened on BOTH connections before writes.
     * - Commit order: tenant first, then central.
     * - If tenant commit succeeds but central commit fails,
     *   the tenant password is restored from the prior snapshot.
     * - If compensation itself fails, a critical error is thrown.
     * - Hash is never exposed in messages or logs.
     */
    public function resetPassword(
        PDO $centralPdo,
        array $company,
        int $centralUserId,
        string $newPassword
    ): void {
        $stmtOld = $centralPdo->prepare("SELECT login, password_hash FROM company_users WHERE id = ? AND company_id = ?");
        $stmtOld->execute([$centralUserId, (int)$company['id']]);
        $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
        if ($old === false) {
            throw new \RuntimeException('Пользователь не найден в центральном реестре');
        }
        $oldHash = $old['password_hash'];
        $login = $old['login'];

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        $localPdo = $this->getLocalPdo($company);
        $this->ensureUsersTable($localPdo);

        $centralPdo->beginTransaction();
        $localPdo->beginTransaction();

        try {
            $stmtCentral = $centralPdo->prepare(
                "UPDATE company_users SET password_hash = ?, updated_at = NOW() WHERE id = ? AND company_id = ?"
            );
            $stmtCentral->execute([$hash, $centralUserId, (int)$company['id']]);

            if ($stmtCentral->rowCount() === 0) {
                throw new \RuntimeException('Central user not found');
            }

            $stmtLocal = $localPdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE login = ?");
            $stmtLocal->execute([$hash, $login]);

            if ($stmtLocal->rowCount() === 0) {
                throw new \RuntimeException('Tenant user not found');
            }

            $localPdo->commit();

            try {
                $centralPdo->commit();
            } catch (\Exception $e) {
                $this->compensatePasswordTenant($localPdo, $login, $oldHash);
                throw new \RuntimeException('Ошибка сброса пароля: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            $this->rollbackBoth($centralPdo, $localPdo);
            throw new \RuntimeException('Ошибка сброса пароля: ' . $e->getMessage());
        }
    }

    /**
     * Set user status in both central and tenant DB.
     *
     * Best-effort two-connection consistency with compensation:
     * - Prior status is retained for restoration.
     * - Transactions are opened on BOTH connections before writes.
     * - Commit order: tenant first, then central.
     * - If tenant commit succeeds but central commit fails,
     *   the tenant status is restored from the prior snapshot.
     * - If compensation itself fails, a critical error is thrown.
     */
    public function setStatus(
        PDO $centralPdo,
        array $company,
        int $centralUserId,
        string $status
    ): void {
        $stmtOld = $centralPdo->prepare(
            "SELECT login, status, password_hash, full_name, email, phone, role, comments FROM company_users WHERE id = ? AND company_id = ?"
        );
        $stmtOld->execute([$centralUserId, (int)$company['id']]);
        $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
        if ($old === false) {
            throw new \RuntimeException('Пользователь не найден в центральном реестре');
        }
        $login = $old['login'];

        $localPdo = $this->getLocalPdo($company);
        $this->ensureUsersTable($localPdo);

        $centralPdo->beginTransaction();
        $localPdo->beginTransaction();

        try {
            $stmtCentral = $centralPdo->prepare(
                "UPDATE company_users SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?"
            );
            $stmtCentral->execute([$status, $centralUserId, (int)$company['id']]);

            if ($stmtCentral->rowCount() === 0) {
                throw new \RuntimeException('Central user not found');
            }

            if ($status === 'archived') {
                $stmtLocal = $localPdo->prepare(
                    "UPDATE users SET status = 'archived', deleted_at = NOW(), deleted_by_user_id = ?, deleted_by_role = ?, updated_at = NOW() WHERE login = ?"
                );
                $stmtLocal->execute([
                    (int)($_SESSION['user_id'] ?? 0),
                    $_SESSION['role_code'] ?? 'superadmin',
                    $login,
                ]);
            } else {
                $stmtLocal = $localPdo->prepare(
                    "UPDATE users SET status = ?, updated_at = NOW() WHERE login = ?"
                );
                $stmtLocal->execute([$status, $login]);
            }

            if ($stmtLocal->rowCount() === 0) {
                throw new \RuntimeException('Tenant user not found');
            }

            $localPdo->commit();

            try {
                $centralPdo->commit();
            } catch (\Exception $e) {
                $this->compensateStatusTenant($localPdo, $login, $old['status']);
                throw new \RuntimeException('Ошибка изменения статуса: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            $this->rollbackBoth($centralPdo, $localPdo);
            throw new \RuntimeException('Ошибка изменения статуса: ' . $e->getMessage());
        }
    }

    // --- Compensation helpers ---

    private function rollbackBoth(PDO $centralPdo, PDO $localPdo): void
    {
        if ($localPdo->inTransaction()) {
            $localPdo->rollBack();
        }
        if ($centralPdo->inTransaction()) {
            $centralPdo->rollBack();
        }
    }

    private function compensateCreateTenant(PDO $localPdo, string $login): void
    {
        $localPdo->beginTransaction();
        try {
            $stmt = $localPdo->prepare("DELETE FROM users WHERE login = ?");
            $stmt->execute([$login]);
            $localPdo->commit();
        } catch (\Exception $e) {
            $localPdo->rollBack();
            throw new \RuntimeException('CRITICAL: compensation for tenant create failed: ' . $e->getMessage());
        }
    }

    private function compensateUpdateTenant(PDO $localPdo, array $oldUser, ?string $requestedLogin): void
    {
        $loginUsedForFind = ($requestedLogin !== null && $requestedLogin !== $oldUser['login'])
            ? $requestedLogin
            : $oldUser['login'];

        $localPdo->beginTransaction();
        try {
            $stmt = $localPdo->prepare(
                "UPDATE users SET full_name = :fn, login = :login_restore, email = :email, phone = :phone, role_code = :role, status = :status, updated_at = NOW() WHERE login = :current_login"
            );
            $stmt->execute([
                ':fn'              => $oldUser['full_name'],
                ':login_restore'   => $oldUser['login'],
                ':email'           => $oldUser['email'] ?: null,
                ':phone'           => $oldUser['phone'] ?: null,
                ':role'            => $oldUser['role'],
                ':status'          => $oldUser['status'],
                ':current_login'   => $loginUsedForFind,
            ]);
            $localPdo->commit();
        } catch (\Exception $e) {
            $localPdo->rollBack();
            throw new \RuntimeException('CRITICAL: compensation for tenant user update failed: ' . $e->getMessage());
        }
    }

    private function compensatePasswordTenant(PDO $localPdo, string $login, string $oldHash): void
    {
        $localPdo->beginTransaction();
        try {
            $stmt = $localPdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE login = ?");
            $stmt->execute([$oldHash, $login]);
            $localPdo->commit();
        } catch (\Exception $e) {
            $localPdo->rollBack();
            throw new \RuntimeException('CRITICAL: compensation for tenant password reset failed: ' . $e->getMessage());
        }
    }

    private function compensateStatusTenant(PDO $localPdo, string $login, string $oldStatus): void
    {
        $localPdo->beginTransaction();
        try {
            $stmt = $localPdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE login = ?");
            $stmt->execute([$oldStatus, $login]);
            $localPdo->commit();
        } catch (\Exception $e) {
            $localPdo->rollBack();
            throw new \RuntimeException('CRITICAL: compensation for tenant status change failed: ' . $e->getMessage());
        }
    }

    // --- Reconciliation for pre-existing central-only rows ---

    /**
     * Reconcile a central-only user into the tenant DB.
     * Uses the existing password_hash from central (no plaintext required).
     * Does NOT overwrite an existing tenant user.
     * Skips company_owner role.
     * Never prints/exposes the password hash.
     *
     * @return array{success: bool, error?: string, login?: string, role?: string}
     */
    public function reconcileCentralUser(
        PDO $centralPdo,
        array $company,
        int $centralUserId
    ): array {
        $stmt = $centralPdo->prepare(
            "SELECT * FROM company_users WHERE id = ? AND company_id = ?"
        );
        $stmt->execute([$centralUserId, (int)$company['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => 'Central user not found'];
        }

        if ($user['role'] === 'company_owner') {
            return ['success' => false, 'error' => 'company_owner excluded from reconciliation'];
        }

        $localPdo = $this->getLocalPdo($company);
        $this->ensureUsersTable($localPdo);

        if ($this->loginExistsInTenant($localPdo, $user['login'])) {
            return ['success' => false, 'error' => 'User already exists in tenant'];
        }

        $stmtLocal = $localPdo->prepare(
            "INSERT INTO users (full_name, login, email, phone, password_hash, role_code, status, created_by_user_id, created_by_role, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmtLocal->execute([
            $user['full_name'],
            $user['login'],
            $user['email'] ?: null,
            $user['phone'] ?: null,
            $user['password_hash'],
            $user['role'],
            $user['status'],
            (int)($_SESSION['user_id'] ?? 0),
            $_SESSION['role_code'] ?? 'superadmin',
        ]);

        if ($stmtLocal->rowCount() === 0) {
            return ['success' => false, 'error' => 'Tenant insert failed'];
        }

        return ['success' => true, 'login' => $user['login'], 'role' => $user['role']];
    }

    /**
     * Reconcile by login (looks up central user by login).
     */
    public function reconcileCentralUserByLogin(
        PDO $centralPdo,
        array $company,
        string $login
    ): array {
        $stmt = $centralPdo->prepare(
            "SELECT * FROM company_users WHERE login = ? AND company_id = ?"
        );
        $stmt->execute([$login, (int)$company['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['success' => false, 'error' => 'Central user not found'];
        }

        return $this->reconcileCentralUser($centralPdo, $company, (int)$user['id']);
    }

    // --- Lookup helpers ---

    public function getTenantUserByLogin(array $company, string $login): ?array
    {
        try {
            $localPdo = $this->getLocalPdo($company);
            $this->ensureUsersTable($localPdo);
            $stmt = $localPdo->prepare("SELECT * FROM users WHERE login = ?");
            $stmt->execute([$login]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getCentralUser(PDO $centralPdo, int $companyId, int $userId): ?array
    {
        $stmt = $centralPdo->prepare(
            "SELECT * FROM company_users WHERE id = ? AND company_id = ? AND role IN ('logist', 'senior_logist')"
        );
        $stmt->execute([$userId, $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List central-only users (exist in central but missing in tenant) for reconciliation.
     * Skips company_owner.
     *
     * @return array<int, array{id: int, login: string, full_name: string, role: string, status: string}>
     */
    public function listCentralOnlyUsers(PDO $centralPdo, array $company): array
    {
        $localPdo = $this->getLocalPdo($company);
        $this->ensureUsersTable($localPdo);

        $stmt = $centralPdo->prepare(
            "SELECT id, login, full_name, role, status FROM company_users
             WHERE company_id = ? AND role != 'company_owner'"
        );
        $stmt->execute([(int)$company['id']]);
        $centralUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $orphans = [];
        foreach ($centralUsers as $u) {
            if (!$this->loginExistsInTenant($localPdo, $u['login'])) {
                $orphans[] = $u;
            }
        }
        return $orphans;
    }
}
