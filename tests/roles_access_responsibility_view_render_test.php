<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
define('BASE_PATH', $root);
define('STORAGE_PATH', $root . '/storage');
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Support/http_runtime.php';

putenv('APP_BASE_PATH=/erpv2');
$_SESSION = ['_csrf_token' => str_repeat('a', 64)];
$company = ['id' => 1, 'name' => 'QA Company', 'status' => 'active'];
$activeTab = 'contractor';
$successMessage = null;
$formError = null;
$dbError = null;
$items = [[
    'id' => 18,
    'name' => 'QA Contractor',
    'inn' => '7700000000',
    'logist_id' => 8,
    'logist_name' => 'QA Owner',
    'crew_count' => 0,
]];
$logists = [
    ['id' => 8, 'membership_id' => 20, 'full_name' => 'QA Owner', 'login' => 'qa-owner'],
    ['id' => 9, 'membership_id' => 21, 'full_name' => 'QA Dynamic', 'login' => 'qa-dynamic'],
];

ob_start();
require $root . '/app/View/pages/company_responsible_assignments.php';
$html = (string) ob_get_clean();

$must = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$must(str_contains($html, 'data-ux-page="responsible-assignments"'), 'responsibility page shell did not render');
$must(str_contains($html, 'QA Contractor'), 'contractor row did not render');
$must(str_contains($html, 'data-membership-id="21"'), 'dynamic recipient option did not render');
$must(substr_count($html, 'name="_csrf_token"') >= 2, 'mass and inline responsibility forms must both render CSRF fields');
$must(!str_contains($html, 'csrfInput'), 'obsolete CSRF helper leaked into rendered responsibility page');

echo "ROLES_ACCESS_RESPONSIBILITY_VIEW_RENDER_PASS=YES\n";
