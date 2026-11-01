<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Security/EffectiveAccess.php';
require_once __DIR__ . '/../app/Security/AccessRuntime.php';
require_once __DIR__ . '/../app/Service/FeoRequestService.php';
require_once __DIR__ . '/../app/Service/FeoRequestInteractionService.php';
require_once __DIR__ . '/../app/Service/FeoXlsxParser.php';
require_once __DIR__ . '/../app/Http/Controllers/Company/FeoRequestsController.php';

use App\Core\Database;
use App\Http\Controllers\Company\FeoRequestsController;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;

$db = new Database(['host' => 'invalid.test', 'database' => 'not_used']);
$controller = new FeoRequestsController([], $db);
$page = ['company.feo.requests' => true];
$scopes = [];
$cases = [
    'owner' => [EffectiveAccess::systemOwner(11, 7, ['company.feo.requests']), true],
    'senior' => [EffectiveAccess::member(12, 7, $page, $scopes, 'senior_logist'), true],
    'logist_with_grant' => [EffectiveAccess::member(13, 7, $page, $scopes, 'logist'), false],
    'custom_with_grant' => [EffectiveAccess::member(14, 7, $page, $scopes, 'custom_role'), false],
    'technical_superadmin' => [EffectiveAccess::technicalSuperadmin(['company.feo.requests']), false],
    'inactive' => [EffectiveAccess::denied('inactive'), false],
];

$guard = new ReflectionMethod($controller, 'guardImportHistoryRole');
foreach ($cases as $name => [$access, $allowed]) {
    AccessRuntime::setCurrent($access);
    http_response_code(200);
    ob_start();
    $actual = $guard->invoke($controller);
    $output = ob_get_clean();
    if ($actual !== $allowed || ($allowed && $output !== '') || (!$allowed && (http_response_code() !== 403 || $output !== 'Доступ запрещён'))) {
        throw new RuntimeException("Unexpected history access: {$name}");
    }
}

AccessRuntime::setCurrent($cases['logist_with_grant'][0]);
foreach (['rollbackImport', 'restoreImport', 'deleteImport'] as $action) {
    http_response_code(200);
    ob_start();
    $controller->{$action}();
    $output = ob_get_clean();
    if (http_response_code() !== 403 || $output !== 'Доступ запрещён' || $db->isConnected()) {
        throw new RuntimeException("Denied {$action} reached DB or returned wrong response");
    }
}

AccessRuntime::clear();
echo "FEO_IMPORT_HISTORY_ROLE_GUARD=PASS\n";
