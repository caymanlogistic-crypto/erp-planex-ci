<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$registry = (string)file_get_contents($root . '/app/Security/PageRegistry.php');
$service = (string)file_get_contents($root . '/app/Service/NavigationCounterService.php');
$layout = (string)file_get_contents($root . '/app/View/layouts/main.php');
$feoRoutes = (string)file_get_contents($root . '/app/Http/Routes/company_feo_requests.php');
$hotfix = (string)file_get_contents($root . '/app/Support/runtime_ui_hotfixes.php');

$must = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach ([
    "'company.finance.cash'", "badgeProvider: 'cash_attention'",
    "'company.trips.linear'", "badgeProvider: 'linear_trips_problem'",
    "'company.trips.waste'", "badgeProvider: 'departures_problem'",
    "'company.documents.collection'", "badgeProvider: 'trip_completion_problem'",
    "'company.documents.tn_review'", "badgeProvider: 'tn_review_pending'",
    "'company.documents.trip_closing_registry'", "badgeProvider: 'trip_closing_blocked_unloaded'",
    "'company.feo.requests'", "badgeProvider: 'feo_freshness'",
    "'company.feo.map_placement'", "badgeProvider: 'feo_placement_problem'",
    "'company.feo.map'", "badgeProvider: 'feo_map_problem'",
    "'company.feo.utilizers'", "badgeProvider: 'feo_utilizer_problem'",
    "'company.feo.costs'", "badgeProvider: 'feo_cost_problem'",
    "'company.production_calendar'", "badgeProvider: 'production_calendar_warning'",
] as $token) $must(str_contains($registry, $token), 'PageRegistry badge metadata missing: ' . $token);

foreach (['bank_attention','cash_attention','closing_documents_problem','trip_closing_blocked_unloaded','linear_trips_problem','departures_problem','trip_completion_problem','tn_review_pending','feo_freshness','feo_placement_problem','feo_map_problem','feo_utilizer_problem','feo_cost_problem','production_calendar_warning'] as $counter) {
    $must(str_contains($service, "'{$counter}'"), 'NavigationCounterService missing counter ' . $counter);
}
$must(str_contains($service, 'currentPlacementProblemSummary($tenant)'), 'FEO utilizer badge must use canonical classifier.');
$must(str_contains($service, 'WasteTripParityService::attentionCount($tenant)'), 'Departures badge must use the same parity source as the page warning.');
$must(str_contains($service, "['integrity']['block_count']"), 'FEO cost badge must use existing integrity block count.');
$must(str_contains($service, "['is_consistent']"), 'FEO placement badge must use existing consistency state.');
$must(str_contains($service, 'missing_coordinates_count') && str_contains($service, 'invalid_coordinates_count') && str_contains($service, 'unmatched_utilizer_count') && str_contains($service, 'inactive_utilizer_count'), 'FEO map badge must use existing map diagnostics.');
$must(substr_count($registry, "badgeDisplay: 'self_parent'") >= 5, 'all FEO children must render own badge and contribute to parent');
$must(str_contains($layout, "if (\$count <= 0) return '';"), 'zero badges must remain hidden');
$must(str_contains($service, "WHERE status = 'applied'") && str_contains($service, 'INTERVAL 7 DAY'), 'FEO freshness must preserve factual seven-day rule.');
$must(str_contains($service, 'ProductionCalendarService::isYearReady($tenant, $nextYear)'), 'calendar badge must use readiness service.');
$must(!str_contains($service, 'register_shutdown_function') && !str_contains($service, 'data-trip-nav-count'), 'navigation service must not inject badge DOM.');

$must(str_contains($layout, '$effectiveAccess->isActive()'), 'counters must run for active memberships, not only owner.');
$must(str_contains($layout, "|| \$roleCode === 'company_owner'"), 'feature-off owner must retain historical finance counters.');
$must(!str_contains($layout, '$effectiveAccess->isSystemOwner() && (int)$companyId'), 'counter collection must not exclude active logist roles.');
$must(str_contains($layout, "'production_calendar_warning'") && str_contains($layout, "? '!'"), 'calendar historical red ! marker must be preserved.');
$must(str_contains($layout, 'navigationGroupBadgeCount'), 'group badge sum must be server-rendered.');

$must(str_contains($feoRoutes, '!\\App\\Security\\AccessRuntime::current()->isActive()'), 'legacy FEO navigation helper must be feature-off only.');
$must(str_contains($feoRoutes, 'registerFeoFreshnessNavigation($config, $db);'), 'feature-off FEO compatibility fallback must remain.');
$must(str_contains($hotfix, 'Cash is a canonical visible finance page again'), 'cash compatibility code must preserve canonical cash navigation.');

echo "NAVIGATION_REGISTRY_BADGES_CONTRACT=PASS\n";
echo "FEO_BADGES_REGISTRY_OWNED=PASS\n";
echo "FEO_CHILD_BADGES_CONTRACT=PASS\n";
echo "FEO_PARENT_BADGE_SUM_CONTRACT=PASS\n";
echo "FEO_ZERO_BADGE_HIDDEN_CONTRACT=PASS\n";
echo "CALENDAR_BADGE_REGISTRY_OWNED=PASS\n";
echo "CASH_BADGE_PRESERVED=PASS\n";
echo "LEGACY_FEO_HELPER_FEATURE_OFF_ONLY=PASS\n";
