<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = static function (string $message): never { throw new RuntimeException($message); };
$ok = static function (bool $value, string $message) use ($fail): void { if (!$value) $fail($message); };

require_once $root . '/app/Security/PageRegistry.php';
require_once $root . '/app/Security/EffectiveAccess.php';
require_once $root . '/app/Service/CompanyLandingPageService.php';

$registry = new App\Security\PageRegistry();
$cash = $registry->page('company.finance.cash');
$ok(is_array($cash), 'cash page missing');
$ok(($cash['nav_visible'] ?? true) === false, 'cash must be hidden from navigation');
$ok($registry->isGrantable('company.finance.cash'), 'cash compatibility permission must remain manageable');
$ok(($cash['badge_provider'] ?? '') === 'cash_attention', 'cash badge provider metadata missing');

$operations = $registry->page('company.finance.operations');
$ok(is_array($operations), 'operations page missing');
$ok(($operations['nav_visible'] ?? true) === false, 'operations must be hidden from navigation');
$ok($registry->isGrantable('company.finance.operations'), 'operations permission must remain manageable');

$responsibility = $registry->page('company.responsible_assignments');
$ok(is_array($responsibility), 'responsibility page missing from canonical registry');
$ok(($responsibility['owner_only'] ?? false) === true, 'responsibility tool must be owner-only');
$ok(($responsibility['grantable'] ?? true) === false, 'responsibility tool must not be ordinary grantable permission');
$ok(($responsibility['landing_eligible'] ?? true) === false, 'responsibility tool must not be default landing');
$ok(($responsibility['nav_visible'] ?? false) === true, 'responsibility tool must stay in canonical navigation');
$ok(!in_array('company.responsible_assignments', $registry->grantablePageKeys(), true), 'owner-only tool leaked into role matrix');

$owner = App\Security\EffectiveAccess::systemOwner(11, 7, $registry->pageKeys());
$landingReflection = new ReflectionClass(App\Service\CompanyLandingPageService::class);
$landing = $landingReflection->newInstanceWithoutConstructor();
$registryProperty = $landingReflection->getProperty('registry');
$registryProperty->setValue($landing, $registry);
$ok($landing->pathForAccess($owner) === '/company/dashboard', 'owner landing must explicitly resolve to dashboard');

$m132 = (string)file_get_contents($root . '/database/migrations-local/132_split_assignment_and_responsibility.sql');
$ok(str_contains($m132, 'legacy_unclassified'), '132 must preserve unknown provenance explicitly');
$ok(!preg_match('/UPDATE\s+entity_assignments\b/i', $m132), '132 must not guess existing assignment provenance with UPDATE');
$ok(!str_contains($m132, 'assigned_by_membership_id = e.created_by_membership_id'), '132 contains forbidden equality provenance heuristic');
$ok(str_contains($m132, 'uk_entity_assignment_kind'), '132 must support creator+manual dual provenance');
$ok(str_contains($m132, 'entity_responsibilities'), '132 responsibility projection missing');

$m014 = (string)file_get_contents($root . '/database/migrations/014_add_role_default_page.sql');
$ok(str_contains($m014, "system_key = 'company_owner'"), '014 must resolve owner system role explicitly');
$ok(str_contains($m014, "default_page_key = 'company.dashboard'"), '014 owner default must be dashboard');

$routes = (string)file_get_contents($root . '/app/Http/Routes/company_responsible_assignments.php');
$ok(str_contains($routes, "'company.responsible_assignments'"), 'responsibility route lacks canonical page key');
$ok(str_contains($routes, "'/company/roles-access/assignments/reassign'"), 'explicit M:N endpoint must be separate');
$ok(str_contains($routes, "'/company/responsible-assignments/reassign'"), 'responsibility transfer endpoint missing');

$layout = (string)file_get_contents($root . '/app/View/layouts/main.php');
$hotfix = (string)file_get_contents($root . '/app/Support/runtime_ui_hotfixes.php');
$ok(str_contains($layout, 'navigationTree()') && str_contains($layout, 'navigationBadgeHtml'), 'registry navigation/badge renderer missing');
$ok(!str_contains($hotfix, 'normalizeFinanceNavigation(root,base);'), 'legacy JS still rewrites finance navigation');
$ok(!preg_match('~preg_replace\([^;]+/company/finance/cash~s', $hotfix), 'hotfix still replaces cash navigation');

echo "AUDIT_BLOCKERS_CONTRACT=PASS\n";
echo "HIDDEN_FINANCE_PERMISSIONS_MANAGEABLE=PASS\n";
echo "OWNER_DEFAULT_LANDING=PASS\n";
echo "RESPONSIBILITY_TOOL_CANONICAL=PASS\n";
echo "MIGRATION_132_NO_PROVENANCE_GUESS=PASS\n";
