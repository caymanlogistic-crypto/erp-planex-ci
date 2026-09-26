<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/http_runtime.php';

function checkReturn(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

putenv('APP_BASE_PATH=/erpv2');
$_SESSION = [];
$_SERVER = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/erpv2/company/clients?status=active&page=2',
    'HTTP_HOST' => 'plan-ex.ru',
];
checkReturn(rememberLoginReturnTarget() === '/company/clients?status=active&page=2', 'GET page and query not captured');
checkReturn(consumeLoginReturnTarget() === '/company/clients?status=active&page=2', 'return target not consumed');
checkReturn(consumeLoginReturnTarget() === null, 'return target must be single use');

$_SERVER = [
    'REQUEST_METHOD' => 'POST',
    'REQUEST_URI' => '/erpv2/company/clients/save',
    'HTTP_HOST' => 'plan-ex.ru',
    'HTTP_REFERER' => 'https://plan-ex.ru/erpv2/company/clients/42?tab=contacts',
];
checkReturn(loginReturnTargetFromRequest() === '/company/clients/42?tab=contacts', 'POST must return to its source page');

$_SERVER['HTTP_REFERER'] = 'https://attacker.example/erpv2/company/clients';
checkReturn(loginReturnTargetFromRequest() === null, 'foreign referer accepted');
$_SERVER['HTTP_REFERER'] = 'https://plan-ex.ru/erp/company/clients';
checkReturn(loginReturnTargetFromRequest() === null, 'wrong application base accepted');

foreach (['//attacker.example', '/company\\evil', '/company/clients#fragment', '/login', '/companyx/page'] as $unsafe) {
    checkReturn(safeLoginReturnTarget($unsafe) === null, 'unsafe return target accepted: ' . $unsafe);
}
$_SESSION['_login_return_to'] = '//attacker.example';
checkReturn(consumeLoginReturnTarget() === null, 'stored unsafe target accepted');

$index = (string) file_get_contents($root . '/public/index.php');
$guard = strpos($index, 'redirectUnauthenticatedProtectedRequest();');
$csrf = strpos($index, 'verifyCsrfRequest();');
$router = strpos($index, '$router->dispatch(');
checkReturn($guard !== false && $csrf !== false && $router !== false && $guard < $csrf && $csrf < $router,
    'expired-session redirect must precede CSRF and route authorization');

echo "session reauthentication return: PASS\n";
