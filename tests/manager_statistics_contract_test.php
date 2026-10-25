<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root.'/'.$path);
$check = static function(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

require_once $root.'/app/Security/PageRegistry.php';
$registry = new App\Security\PageRegistry();
$page = $registry->page('company.statistics.managers');
$check(is_array($page), 'statistics page is registered');
$check(($page['path'] ?? '') === '/company/statistics/managers', 'statistics page route');
$check(($page['label'] ?? '') === 'Работа логистов', 'statistics page role-management label');
$check(($page['section'] ?? '') === 'Операции' && ($page['group'] ?? '') === 'Статистика', 'statistics folder in role tree');
$check(in_array('company.statistics.managers', $registry->grantablePageKeys(), true), 'statistics page is grantable');

$route = $read('app/Http/Routes/company_statistics.php');
$controller = $read('app/Http/Controllers/Company/ManagerStatisticsController.php');
$service = $read('app/Service/WasteTripStartStatisticsService.php');
$tripService = $read('app/Service/WasteTripService.php');
$view = $read('app/View/pages/company_manager_statistics.php');
$css = $read('public/assets/css/manager-statistics.css');
$js = $read('public/assets/js/manager-statistics.js');
$migration = $read('database/migrations-local/141_create_waste_trip_start_statistics.sql');

$check(str_contains($route, "'/company/statistics/managers'") && str_contains($route, "'company.statistics.managers'"), 'page route and access key');
$check(str_contains($controller, 'getSessionCompanyId()') && str_contains($controller, 'companyDatabaseConfig'), 'current company tenant only');
$check(str_contains($controller, 'schemaReady($local)') && !str_contains($controller, 'LocalMigrationService::apply'), 'GET is migration-free');
$check(
    str_contains($controller, 'AccessRuntime::current()->isActive()')
    && str_contains($controller, "requireRole(['company_owner','senior_logist','logist'])"),
    'page grant is authoritative with roles/access enabled; legacy installations keep their role gate'
);
$check(
    !str_contains($service, "effectiveDataScope('waste_trip')")
    && !str_contains($service, "RegulatedDataScopeService::condition('waste_trip'")
    && !str_contains($view, 'Страница разрешена, но для рейсов')
    && !str_contains($view, 'Ограниченный набор данных.'),
    'granted report does not inherit trip data scope or show a misleading scope warning'
);
$check(substr_count($tripService, 'WasteTripStartStatisticsService::synchronizeTrip') >= 6, 'all waste trip mutation paths synchronize facts');
$check(str_contains($migration, 'responsible_name_snapshot') && str_contains($migration, 'ON DUPLICATE KEY UPDATE'), 'historical manager attribution and idempotent backfill');
$check(
    str_contains($controller, 'eligibleLogists($central, $companyId)')
    && str_contains($service, "r.system_key='logist'")
    && str_contains($service, "r.normalized_name='логист'"),
    'exact active central Logist role supports system and historical role shapes'
);
$check(str_contains($view, 'Рейсы поставлены на погрузку') && str_contains($view, 'Точные значения по периодам') && !str_contains($view, 'Сводка по логистам') && !str_contains($view, 'Из чего сложился результат'), 'accepted report sections are rendered');
$check(
    str_contains($view, "'week'=>'Недели'")
    && str_contains($view, "'month'=>'Месяцы'")
    && str_contains($view, "'year'=>'Год'")
    && !str_contains($view, 'Свой период')
    && !str_contains($view, '>Показать<')
    && !str_contains($view, 'Период и логисты')
    && !str_contains($view, 'Открыть реестр рейсов')
    && !str_contains($view, 'Открыть →'),
    'three instant rolling modes without removed controls or trip transitions'
);
$check(
    str_contains($service, "modify('-7 weeks')")
    && str_contains($service, "modify('-3 months')")
    && str_contains($service, "modify('-11 months')"),
    'rolling 8-week 4-month and 12-month windows'
);
$check(
    str_contains($view, "\$personalMode ? 'Компания' : 'Итого'")
    && str_contains($css, '.manager-stats__period-table .is-total { font-weight:800; }')
    && str_contains($css, '.manager-stats__period-table.is-all-metrics .is-series-colored')
    && str_contains($js, "style.setProperty('--period-series-color', color)"),
    'period columns use their graph series colors'
);
$check(str_contains($view, 'Динамика компании и логистов') && str_contains($service, "'period_rows'=>self::periodRows"), 'company and logist dynamics with exact period values');
$check(str_contains($view, "'trips'=>") && str_contains($view, "'requests'=>") && str_contains($view, "'weight'=>"), 'required metric modes');
$check(
    str_contains($view, "['#4A7A9B','#6A8A50','#B89228','#6D5B8C'")
    && str_contains($view, "\$companyChartColor = '#C07830'")
    && str_contains($css, '--chart-sage:#6A8A50'),
    'approved ordered company and manager chart palette'
);
$check(
    str_contains($view, "array_reverse(\$statistics['period_rows'])")
    && str_contains($view, "(int)(\$row['trips'] ?? 0) > 0")
    && str_contains($view, 'foreach($visiblePeriodRows as$row)'),
    'exact values are newest-first and independent of the chart metric'
);
$check(
    str_contains($view, "' C'.\$number(\$cp1x)")
    && str_contains($view, '$smooth=.82')
    && str_contains($view, 'manager-stats__chart-node')
    && !str_contains($view, 'manager-stats__node-tooltip')
    && !str_contains($view, '<title>')
    && str_contains($css, '@keyframes manager-stats-line-in')
    && str_contains($css, '@media(prefers-reduced-motion:reduce)'),
    'strongly curved animated chart with one shared tooltip'
);
$check(
    str_contains($controller, "\$pageScripts = ['/assets/js/manager-statistics.js']")
    && str_contains($js, "svg.addEventListener('pointermove'")
    && str_contains($js, "node.addEventListener('focus'")
    && str_contains($js, 'manager-stats__period-popup'),
    'plot hover and keyboard focus share one period summary'
);
$check(
    !str_contains($view, 'manager-stats__ranking')
    && !str_contains($view, 'manager-stats__table-card')
    && !str_contains($view, 'manager-stats__integrated-summary')
    && !str_contains($view, 'manager-stats__details')
    && str_contains($view, 'foreach($tableMeasures as$measure=>$label)'),
    'only chart and all-metric period table remain'
);
$check(
    str_contains($view, 'manager-stats__chart-controls')
    && str_contains($css, '.manager-stats__charts {')
    && str_contains($css, 'display: block;')
    && str_contains($css, '.manager-stats__period-popup'),
    'full-width chart with report controls and shared summary'
);
$check(str_contains($view, 'manager-stats-manager-area-') && str_contains($view, 'manager-stats__chart-area--manager'), 'each manager line has its own gradient');
$check(str_contains($css, '.manager-stats__field--mode { width:max-content; min-width:0; }'), 'report mode selector has no empty tail after Year');
$check(str_contains($css, '@media(max-width:1180px)') && str_contains($css, '@media(max-width:760px)'), 'responsive dashboard contract');

echo "MANAGER_STATISTICS_CONTRACT=PASS\n";
