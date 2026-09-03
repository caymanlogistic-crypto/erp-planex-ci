<?php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Service\CompanyMembershipService;

require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/CompanyRoleService.php';
require_once BASE_PATH . '/app/Service/CompanyMembershipService.php';

final class AuthController
{
    private CompanyMembershipService $memberships;

    public function __construct(
        private readonly array $config,
        private readonly Database $db
    ) {
        $this->memberships = new CompanyMembershipService();
    }

    public function showLogin(): void
    {
        if (isAuthenticated()) {
            $role = $_SESSION['role_code'] ?? '';
            if ($role === 'superadmin') {
                redirect_to('/superadmin/companies');
            }

            redirect_to('/company/dashboard');
        }

        $loginValue = '';
        $errors = [];
        $authError = null;
        $multiLogistError = null;
        $devSeedPassword = null;

        $allowDevSeed = ($this->config['app']['app_env'] ?? 'production') === 'local'
            && filter_var(env('APP_ALLOW_DEV_SEED', false), FILTER_VALIDATE_BOOL);

        try {
            $pdo = $this->db->connection();
            $count = $pdo->query("SELECT COUNT(*) FROM superadmin_users")->fetchColumn();
            if ($allowDevSeed && (int) $count === 0) {
                $tempPassword = generatePassword(10);
                $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare(
                    "INSERT INTO superadmin_users (name, email, password_hash, role, is_active, created_at, updated_at)
                     VALUES (:name, :email, :hash, :role, 1, NOW(), NOW())"
                );
                $stmt->execute([
                    ':name' => 'Super Admin',
                    ':email' => 'admin@planex.local',
                    ':hash' => $hash,
                    ':role' => 'admin',
                ]);
                $devSeedPassword = $tempPassword;
            }
        } catch (\Exception $e) {
            $devSeedPassword = null;
        }

        ob_start();
        require base_path('app/View/pages/login_form.php');
        $content = ob_get_clean();

        require base_path('app/View/layouts/auth-layout.php');
    }

    public function login(): void
    {
        if (isAuthenticated()) {
            if (($_SESSION['role_code'] ?? '') === 'superadmin') {
                redirect_to('/superadmin/companies');
            }
            redirect_to('/company/dashboard');
        }

        $loginValue = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $errors = [];
        $authError = null;
        $multiLogistError = null;
        $devSeedPassword = null;

        if ($loginValue === '') {
            $errors['login'] = 'Введите логин или email';
        }

        if ($password === '') {
            $errors['password'] = 'Введите пароль';
        }

        if (!empty($errors)) {
            $this->renderLogin($loginValue, $errors, $authError, $multiLogistError, $devSeedPassword);
            return;
        }

        try {
            $pdo = $this->db->connection();

            // superadmin remains a separate technical authentication boundary.
            $superStmt = $pdo->prepare(
                "SELECT * FROM superadmin_users WHERE email = :login AND is_active = 1"
            );
            $superStmt->execute([':login' => $loginValue]);
            $superUser = $superStmt->fetch(\PDO::FETCH_ASSOC);

            if ($superUser && password_verify($password, $superUser['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $superUser['id'];
                $_SESSION['global_user_id'] = null;
                $_SESSION['membership_id'] = null;
                $_SESSION['tenant_user_id'] = null;
                $_SESSION['role_id'] = null;
                $_SESSION['role_code'] = 'superadmin';
                $_SESSION['role_name'] = 'Superadmin';
                $_SESSION['company_id'] = null;
                $_SESSION['company_name'] = null;
                $_SESSION['user_name'] = $superUser['name'] ?? 'Super Admin';
                $_SESSION['is_company_leader'] = false;
                $_SESSION['session_auth_version'] = 2;
                $_SESSION['user'] = [
                    'user_id' => $_SESSION['user_id'],
                    'global_user_id' => null,
                    'membership_id' => null,
                    'tenant_user_id' => null,
                    'role_code' => 'superadmin',
                    'company_id' => null,
                ];
                redirect_to('/superadmin/companies');
            }

            // One canonical company-user authentication lookup. Tenant users are
            // never scanned and never verify passwords after this cutover.
            $globalUser = $this->memberships->findGlobalUserByLogin($pdo, $loginValue);
            if (!$globalUser || !password_verify($password, (string) $globalUser['password_hash'])) {
                $authError = 'Неверный логин или пароль.';
                $this->renderLogin($loginValue, $errors, $authError, $multiLogistError, $devSeedPassword);
                return;
            }

            $activeMemberships = $this->memberships->membershipsForUser($pdo, (int) $globalUser['id'], true);
            $selected = $this->memberships->selectInitialMembership($globalUser, $activeMemberships);
            if (!$selected) {
                $authError = 'Нет активного доступа ни к одной компании. Обратитесь к Руководителю.';
                $this->renderLogin($loginValue, $errors, $authError, $multiLogistError, $devSeedPassword);
                return;
            }

            $this->activateCompanyMembership($globalUser, $selected);
            redirect_to('/company/dashboard');
        } catch (\Throwable $e) {
            // Authentication deliberately does not expose migration/DB detail.
            $authError = 'Не удалось войти. Обратитесь к администратору.';
        }

        $this->renderLogin($loginValue, $errors, $authError, $multiLogistError, $devSeedPassword);
    }

    public function switchCompany(): void
    {
        if (!isAuthenticated() || ($_SESSION['role_code'] ?? '') === 'superadmin') {
            http_response_code(403);
            return;
        }

        $globalUserId = (int) ($_SESSION['global_user_id'] ?? 0);
        $companyId = (int) ($_POST['company_id'] ?? 0);
        if ($globalUserId <= 0 || $companyId <= 0) {
            http_response_code(400);
            return;
        }

        try {
            $pdo = $this->db->connection();
            $globalUser = $this->memberships->findGlobalUserById($pdo, $globalUserId);
            $membership = $this->memberships->getMembership($pdo, $globalUserId, $companyId);
            if (!$globalUser || !$membership) {
                http_response_code(403);
                return;
            }

            $this->activateCompanyMembership($globalUser, $membership);
            redirect_to('/company/dashboard');
        } catch (\Throwable $e) {
            http_response_code(403);
        }
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        redirect_to('/login', 302);
    }

    private function activateCompanyMembership(array $globalUser, array $membership): void
    {
        $this->memberships->assertMembershipUsable($membership);

        $tenantUserId = (int) $membership['tenant_user_id'];
        $globalUserId = (int) $globalUser['id'];
        $membershipId = (int) $membership['id'];
        $roleCode = $this->memberships->compatibilityRoleCode($membership);
        $isLeader = ($membership['system_key'] ?? null) === \App\Service\CompanyRoleService::SYSTEM_LEADER;

        session_regenerate_id(true);
        $_SESSION['global_user_id'] = $globalUserId;
        $_SESSION['membership_id'] = $membershipId;
        $_SESSION['tenant_user_id'] = $tenantUserId;
        // Compatibility alias only for legacy tenant creator/FK code.
        $_SESSION['user_id'] = $tenantUserId;
        $_SESSION['role_id'] = (int) $membership['role_id'];
        $_SESSION['role_code'] = $roleCode;
        $_SESSION['role_name'] = (string) ($membership['role_name'] ?? '');
        $_SESSION['company_id'] = (int) $membership['company_id'];
        $_SESSION['company_name'] = (string) ($membership['company_name'] ?? '');
        $_SESSION['user_name'] = (string) ($globalUser['full_name'] ?? '');
        $_SESSION['is_company_leader'] = $isLeader;
        $_SESSION['session_auth_version'] = 2;
        $_SESSION['user'] = [
            'user_id' => $tenantUserId,
            'global_user_id' => $globalUserId,
            'membership_id' => $membershipId,
            'tenant_user_id' => $tenantUserId,
            'role_id' => (int) $membership['role_id'],
            'role_code' => $roleCode,
            'company_id' => (int) $membership['company_id'],
            'is_company_leader' => $isLeader,
        ];
    }

    private function renderLogin(
        string $loginValue,
        array $errors,
        ?string $authError,
        ?string $multiLogistError,
        ?string $devSeedPassword
    ): void {
        ob_start();
        require base_path('app/View/pages/login_form.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/auth-layout.php');
    }
}
