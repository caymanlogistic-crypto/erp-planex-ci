<?php

namespace App\Http\Controllers;

use App\Core\Database;
use App\Security\AccessStateProvider;
use App\Service\AccountAuthenticationService;

require_once BASE_PATH . '/app/Support/company_database.php';

final class AuthController
{
    public function __construct(
        private readonly array $config,
        private readonly Database $db,
        private readonly ?AccessStateProvider $accessStateProvider = null
    ) {
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
            ob_start();
            require base_path('app/View/pages/login_form.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/auth-layout.php');
            return;
        }

        try {
            $pdo = $this->db->connection();

            $superStmt = $pdo->prepare(
                "SELECT * FROM superadmin_users WHERE email = :login AND is_active = 1"
            );
            $superStmt->execute([':login' => $loginValue]);
            $superUser = $superStmt->fetch(\PDO::FETCH_ASSOC);

            if ($superUser && password_verify($password, $superUser['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $superUser['id'];
                $_SESSION['role_code'] = 'superadmin';
                $_SESSION['company_id'] = null;
                $_SESSION['user_name'] = $superUser['name'] ?? 'Super Admin';
                $_SESSION['user'] = [
                    'user_id' => $_SESSION['user_id'],
                    'role_code' => 'superadmin',
                    'company_id' => null,
                ];
                redirect_to('/superadmin/companies');
            }

            // The new global identity path is enabled only together with the
            // central access readiness provider. Partial schema rollout must
            // never silently activate a membership or bypass the readiness gate.
            if ($this->accessStateProvider !== null) {
                require_once base_path('app/Service/IdentityNormalizer.php');
                require_once base_path('app/Service/AccountAuthenticationService.php');
                $globalAuth = (new AccountAuthenticationService($pdo))->authenticate($loginValue, $password);
                if ($globalAuth !== null) {
                    $readyCompanyId = null;
                    foreach ($globalAuth['memberships'] as $membership) {
                        if ($this->accessStateProvider->isCompanyAccessReady($membership['company_id'])) {
                            $readyCompanyId = $membership['company_id'];
                            break;
                        }
                    }
                    if ($readyCompanyId === null) {
                        $authError = 'Система доступов компании ещё не готова. Обратитесь к администратору.';
                    } else {
                        session_regenerate_id(true);
                        (new AccountAuthenticationService($pdo))->establishSession($_SESSION, $globalAuth, $readyCompanyId);
                        redirect_to('/company/dashboard');
                    }
                } else {
                    // Once the global access provider is wired, tenant-local
                    // authentication must not become a bypass around membership.
                    $authError = 'Неверный логин или пароль.';
                }
            }

            if ($authError === null) {
                $ownerStmt = $pdo->prepare(
                "SELECT cu.*, c.name as company_name, c.status as company_status
                 FROM company_users cu
                 JOIN companies c ON cu.company_id = c.id
                 WHERE cu.login = :login
                   AND cu.role = 'company_owner'"
            );
                $ownerStmt->execute([':login' => $loginValue]);
                $owner = $ownerStmt->fetch(\PDO::FETCH_ASSOC);

                if ($owner && password_verify($password, $owner['password_hash'])) {
                if ($owner['status'] !== 'active') {
                    $authError = 'Доступ к компании временно ограничен. Обратитесь к администратору.';
                } elseif (!in_array($owner['company_status'], ['active'], true)) {
                    $authError = 'Доступ к компании временно ограничен. Обратитесь к администратору.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $owner['id'];
                    $_SESSION['role_code'] = 'company_owner';
                    $_SESSION['company_id'] = (int) $owner['company_id'];
                    $_SESSION['user_name'] = $owner['full_name'];
                    $_SESSION['company_name'] = $owner['company_name'];
                    $_SESSION['user'] = [
                        'user_id' => $_SESSION['user_id'],
                        'role_code' => 'company_owner',
                        'company_id' => $_SESSION['company_id'],
                    ];
                    redirect_to('/company/dashboard');
                }
                }

                $companiesStmt = $pdo->query("SELECT id, name, db_identifier, db_host, db_port, db_username, db_password FROM companies WHERE status = 'active'");
                $activeCompanies = $companiesStmt->fetchAll(\PDO::FETCH_ASSOC);

                $logistCandidates = [];

                foreach ($activeCompanies as $ac) {
                try {
                    $localDbConfig = companyDatabaseConfig($this->config, $ac);
                    $localDb = new Database($localDbConfig);
                    $localPdo = $localDb->connection();

                    $logistStmt = $localPdo->prepare(
                        "SELECT * FROM users WHERE login = :login AND status = 'active'"
                    );
                    $logistStmt->execute([':login' => $loginValue]);
                    $logistUser = $logistStmt->fetch(\PDO::FETCH_ASSOC);

                    if ($logistUser) {
                        $logistCandidates[] = [
                            'user' => $logistUser,
                            'company_id' => (int) $ac['id'],
                            'company_name' => $ac['name'],
                            'db_identifier' => $ac['db_identifier'],
                        ];
                    }
                } catch (\Exception $e) {
                    continue;
                }
                }

                if (count($logistCandidates) === 1) {
                $candidate = $logistCandidates[0];
                if (password_verify($password, $candidate['user']['password_hash'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) $candidate['user']['id'];
                    $_SESSION['role_code'] = $candidate['user']['role_code'] ?? 'logist';
                    $_SESSION['company_id'] = $candidate['company_id'];
                    $_SESSION['user_name'] = $candidate['user']['full_name'];
                    $_SESSION['company_name'] = $candidate['company_name'];
                    $_SESSION['user'] = [
                        'user_id' => $_SESSION['user_id'],
                        'role_code' => $_SESSION['role_code'],
                        'company_id' => $_SESSION['company_id'],
                    ];
                    redirect_to('/company/dashboard');
                }
                $authError = 'Неверный логин или пароль.';
                } elseif (count($logistCandidates) > 1) {
                $multiLogistError = 'Логин найден в нескольких компаниях, обратитесь к администратору.';
                } else {
                $authError = 'Неверный логин или пароль.';
                }
            }
        } catch (\Exception $e) {
            $authError = 'Неверный логин или пароль.';
        }

        ob_start();
        require base_path('app/View/pages/login_form.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/auth-layout.php');
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
}
