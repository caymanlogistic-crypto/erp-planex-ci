<?php

declare(strict_types=1);

$controller = (string) file_get_contents(__DIR__ . '/../app/Http/Controllers/AuthController.php');
$routes = (string) file_get_contents(__DIR__ . '/../app/Http/Routes/auth.php');
$membership = (string) file_get_contents(__DIR__ . '/../app/Service/CompanyMembershipService.php');
$actor = (string) file_get_contents(__DIR__ . '/../app/Service/CompanyActorProjectionService.php');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$assert(str_contains($controller, 'findGlobalUserByLogin'), 'Company auth must use central global user lookup.');
$assert(str_contains($controller, 'membershipsForUser'), 'Company auth must resolve company membership after global auth.');
$assert(!str_contains($controller, 'SELECT * FROM users WHERE login'), 'AuthController must not authenticate against tenant users.');
$assert(!str_contains($controller, 'foreach ($activeCompanies'), 'AuthController must not scan tenant databases for login.');
$assert(str_contains($controller, "\$_SESSION['global_user_id']"), 'Session must carry global_user_id.');
$assert(str_contains($controller, "\$_SESSION['membership_id']"), 'Session must carry membership_id.');
$assert(str_contains($controller, "\$_SESSION['tenant_user_id']"), 'Session must carry tenant_user_id.');
$assert(str_contains($controller, "\$_SESSION['user_id'] = \$tenantUserId"), 'Legacy user_id alias must point to tenant actor id.');
$assert(str_contains($controller, "\$_SESSION['session_auth_version'] = 2"), 'New company auth session version marker missing.');
$assert(str_contains($routes, "post('/company/switch'"), 'Company switch POST route missing.');
$assert(str_contains($controller, 'getMembership($pdo, $globalUserId, $companyId)'), 'Company switch must resolve membership by current global account and requested company.');
$assert(str_contains($controller, 'assertMembershipUsable($membership)'), 'Company switch/login must fail closed for inactive/unmigrated membership.');
$assert(str_contains($membership, "return 'company_owner';"), 'System leader compatibility role mapping missing.');
$assert(str_contains($membership, "return \$legacy !== '' ? \$legacy : 'company_member';"), 'Dynamic role compatibility mapping missing.');
$assert(str_contains($actor, 'must never become a second credential authority') || str_contains($actor, 'never become a second credential authority'), 'Actor projection must document non-auth authority.');
$assert(!str_contains($actor, "(string) \$globalUser['password_hash']"), 'Tenant actor creation must not copy global password hash.');

echo "ROLES_ACCESS_AUTH_CUTOVER=PASS\n";
