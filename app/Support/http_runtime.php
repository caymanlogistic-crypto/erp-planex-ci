<?php

if (!function_exists('sendSecurityHeaders')) {
    function sendSecurityHeaders(bool $isHttps): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'");

        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

if (!function_exists('csrfToken')) {
    function csrfToken(): string
    {
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrfField')) {
    function csrfField(): string
    {
        return '<input type="hidden" name="_csrf_token" value="' . e(csrfToken()) . '">';
    }
}

if (!function_exists('verifyCsrfRequest')) {
    function verifyCsrfRequest(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $provided = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = $_SESSION['_csrf_token'] ?? '';

        if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
            http_response_code(419);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Сессия формы истекла. Обновите страницу и повторите действие.';
            exit;
        }
    }
}

if (!function_exists('startCsrfFormInjection')) {
    function startCsrfFormInjection(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        ob_start(static function (string $buffer): string {
            $contentType = '';
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Type:') === 0) {
                    $contentType = strtolower($header);
                    break;
                }
            }

            if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
                return $buffer;
            }

            return preg_replace_callback(
                '~<form\b([^>]*)>~i',
                static function (array $match): string {
                    $attributes = $match[1] ?? '';
                    if (!preg_match('~\bmethod\s*=\s*(["\']?)post\1~i', $attributes)) {
                        return $match[0];
                    }

                    return $match[0] . csrfField();
                },
                $buffer
            ) ?? $buffer;
        });
    }
}

if (!function_exists('isAuthenticated')) {
    function isAuthenticated(): bool
    {
        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('currentAccessRequestContext')) {
    function currentAccessRequestContext(): \App\Security\AccessRequestContext
    {
        return \App\Security\AccessRequestContext::fromSession($_SESSION);
    }
}

if (!function_exists('recoverLegacyRolesAccessSession')) {
    function recoverLegacyRolesAccessSession(\PDO $pdo, \App\Security\AccessStateProvider $provider): void
    {
        $result = (new \App\Service\LegacySessionIdentityUpgradeService($pdo, $provider))->upgrade($_SESSION);
        if ($result === \App\Service\LegacySessionIdentityUpgradeService::UPGRADED) {
            session_regenerate_id(true);
            return;
        }
        if ($result !== \App\Service\LegacySessionIdentityUpgradeService::INVALID) {
            return;
        }

        session_regenerate_id(true);
        $_SESSION = [];
        if (current_app_path() !== '/login') {
            redirect_to('/login');
        }
    }
}

if (!function_exists('hasPageAccess')) {
    /** @param string|list<string> $pageKeys */
    function hasPageAccess(string|array $pageKeys): bool
    {
        $keys = is_array($pageKeys) ? $pageKeys : [$pageKeys];
        foreach ($keys as $pageKey) {
            if (is_string($pageKey) && \App\Service\AccessControlService::canAccessPage($pageKey)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('requireRole')) {
    function requireRole(string|array $roles): void
    {
        if (!isAuthenticated()) {
            redirect_to('/login');
        }

        $allowed = is_array($roles) ? $roles : [$roles];

        // Company routes have already been authorized by Router from the
        // current central snapshot. Superadmin is intentionally NOT part of
        // the configurable company ACL and must always keep its literal role
        // boundary, even when a normal company membership is active.
        if (\App\Security\AccessRuntime::current()->isActive()
            && !\App\Security\AccessRuntime::current()->isTechnicalSuperadmin()
            && !in_array('superadmin', $allowed, true)) {
            return;
        }

        if (!in_array($_SESSION['role_code'] ?? '', $allowed, true)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            $pageTitle = 'Доступ запрещён';
            ob_start();
            require base_path('app/View/pages/error_403.php');
            $content = ob_get_clean();
            require base_path('app/View/layouts/main.php');
            exit;
        }
    }
}

if (!function_exists('requireFinanceAccess')) {
    function requireFinanceAccess(): void
    {
        $roleCode = $_SESSION['role_code'] ?? '';
        if (in_array($roleCode, ['company_owner', 'superadmin'], true)) {
            return;
        }
        requireRole(['company_owner']);
    }
}

if (!function_exists('hasFinanceAccess')) {
    function hasFinanceAccess(): bool
    {
        return in_array($_SESSION['role_code'] ?? '', ['company_owner', 'superadmin'], true);
    }
}

if (!function_exists('denyEntityAccess')) {
    function denyEntityAccess(): never
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $pageTitle = 'Доступ запрещён';
        ob_start();
        require base_path('app/View/pages/error_403.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
        exit;
    }
}

if (!function_exists('getSessionCompanyId')) {
    function getSessionCompanyId(): ?int
    {
        return isset($_SESSION['company_id']) ? (int) $_SESSION['company_id'] : null;
    }
}

if (!function_exists('jsonResponse')) {
    function jsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('requestJsonBody')) {
    function requestJsonBody(): array
    {
        $rawBody = file_get_contents('php://input');

        if (!is_string($rawBody) || trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }
}

if (!function_exists('contractorFormDefaultContacts')) {
    function contractorFormDefaultContacts(): array
    {
        return contactFieldsDefaultRows();
    }
}

if (!function_exists('clientFormDefaultContacts')) {
    function clientFormDefaultContacts(): array
    {
        return contactFieldsDefaultRows();
    }
}
