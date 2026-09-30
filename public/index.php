<?php
/**
 * ERP PLANEX — Entry Point
 *
 * Минимальная техническая точка входа.
 * PDO-обёртка и роутер интегрированы.
 * Бизнес-маршруты, авторизация — не подключаются.
 */
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// Session isolation must be configured before session_start(). Load only the
// environment helpers here; bootstrap/app.php will reuse them via require_once.
require_once __DIR__ . '/../app/Support/helpers.php';
require_once __DIR__ . '/../app/Support/environment.php';
loadEnvFileNonOverwriting(dirname(__DIR__) . '/.env');

$sessionName = trim((string) getenv('SESSION_COOKIE_NAME'));
if ($sessionName !== '') {
    session_name($sessionName);
}

$sessionSavePath = trim((string) getenv('SESSION_SAVE_PATH'));
if ($sessionSavePath !== '') {
    session_save_path($sessionSavePath);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

define('ERP_MAX_FILE_SIZE', 20 * 1024 * 1024);        // 20 MB per file
define('ERP_MAX_TOTAL_UPLOAD_SIZE', 80 * 1024 * 1024); // 80 MB per form submit

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

$config = require_once __DIR__ . '/../bootstrap/app.php';
start_base_path_header_rewrite();
start_base_path_output_rewrite();

require_once base_path('app/Support/entrypoint_dependencies.php');

sendSecurityHeaders($isHttps);
startCsrfFormInjection();
redirectUnauthenticatedProtectedRequest();
use App\Core\Database;
use App\Http\Router;
use App\Security\AccessRequestContext;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;
use App\Security\PdoAccessStateProvider;
use App\Service\CompanyInnLookupService;
use App\Service\ContractorContactService;
use App\Service\ClientContactService;
$db = new Database($config['database']);
$pageRegistry = new PageRegistry();
$rolesAccessEnabled = filter_var(env('ERP_ROLES_ACCESS_ENABLED', false), FILTER_VALIDATE_BOOL);
$accessStateProvider = $rolesAccessEnabled
    ? new PdoAccessStateProvider($db->connection())
    : null;
$router = new Router($pageRegistry);

// Activation is an explicit deployment switch. Sessions created before the
// global identity rollout are upgraded only through immutable migration
// provenance. If that identity cannot be proven exactly, invalidate the old
// session and force a normal login instead of turning an otherwise-valid old
// cookie into an opaque company-route 403.
if ($rolesAccessEnabled) {
    recoverLegacyRolesAccessSession($db->connection(), $accessStateProvider);
    $router->enableCompanyAccess(
        new EffectiveAccessResolver($pageRegistry, $accessStateProvider),
        AccessRequestContext::fromSession($_SESSION)
    );
    enforceUsableCompanyAccessSession();
    // role_code is compatibility metadata only. Authority is resolved from
    // current central membership state first, then this value is refreshed
    // for legacy UI/audit code on every request. Dynamic roles never
    // masquerade as one of the privileged preset roles.
    $compatibilityRoleCode = \App\Security\AccessRuntime::current()->compatibilityRoleCode();
    if ($compatibilityRoleCode !== '') {
        $_SESSION['role_code'] = $compatibilityRoleCode;
        if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
            $_SESSION['user'] = [];
        }
        $_SESSION['user']['role_code'] = $compatibilityRoleCode;
    } else {
        unset($_SESSION['role_code'], $_SESSION['user']['role_code']);
    }
}

verifyCsrfRequest();
// Route registration extracted from the legacy index monolith.
require_once base_path('app/Http/Routes/core.php');
require_once base_path('app/Http/Routes/superadmin.php');
require_once base_path('app/Http/Routes/company_logists.php');
require_once base_path('app/Http/Routes/company_clients.php');
require_once base_path('app/Http/Routes/company_contractors.php');
require_once base_path('app/Http/Routes/company_drivers.php');
require_once base_path('app/Http/Routes/company_vehicles.php');
require_once base_path('app/Http/Routes/legacy_redirects.php');
require_once base_path('app/Http/Routes/company_crews_legacy.php');
require_once base_path('app/Http/Routes/company_vehicle_sets.php');
require_once base_path('app/Http/Routes/company_driver_vehicle_blocks_legacy.php');
require_once base_path('app/Http/Routes/company_documents.php');
require_once base_path('app/Http/Routes/company_document_generator.php');
require_once base_path('app/Http/Routes/auth.php');
require_once base_path('app/Http/Routes/company_dashboard.php');
require_once base_path('app/Http/Routes/company_switch.php');
require_once base_path('app/Http/Routes/company_roles_access.php');
require_once base_path('app/Http/Routes/company_contractor_assignments.php');
require_once base_path('app/Http/Routes/company_route_executors.php');
require_once base_path('app/Http/Routes/company_responsible_assignments.php');
require_once base_path('app/Http/Routes/company_linear_trips.php');
require_once base_path('app/Http/Routes/company_statistics.php');
require_once base_path('app/Http/Routes/company_feo_requests.php');
require_once base_path('app/Http/Routes/company_feo_request_costs.php');
require_once base_path('app/Http/Routes/company_feo_map.php');
require_once base_path('app/Http/Routes/superadmin_management.php');
require_once base_path('app/Http/Routes/superadmin_company_delete.php');
require_once base_path('app/Http/Routes/superadmin_db_usage.php');
require_once base_path('app/Http/Routes/superadmin_deleted_data.php');
require_once base_path('app/Http/Routes/company_bank_finance.php');
require_once base_path('app/Http/Routes/company_finance_invoices.php');
require_once base_path('app/Http/Routes/company_finance_operations.php');
require_once base_path('app/Http/Routes/company_finance_cash.php');
require_once base_path('app/Http/Routes/company_finance_employee_payments.php');
require_once base_path('app/Http/Routes/company_finance_dds_categories.php');
require_once base_path('app/Http/Routes/company_finance_payment_calendar.php');
require_once base_path('app/Http/Routes/company_finance_cash_flow_report.php');
require_once base_path('app/Http/Routes/company_finance_management_balance.php');
require_once base_path('app/Http/Routes/company_finance_payment_plan_fact.php');
require_once base_path('app/Http/Routes/company_finance_dashboard.php');
require_once base_path('app/Http/Routes/company_finance_matching_rules.php');
require_once base_path('app/Http/Routes/company_production_calendar.php');
require_once base_path('app/Http/Routes/company_max_messaging.php');
require_once base_path('app/Http/Routes/company_trip_documents.php'); require_once base_path('app/Http/Routes/company_trip_completion.php'); require_once base_path('app/Http/Routes/company_trip_closing.php');
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
