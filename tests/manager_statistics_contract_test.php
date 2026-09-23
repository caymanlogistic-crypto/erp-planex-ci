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
$check(($page['section'] ?? '') === 'Статистика', 'statistics section in role tree');
$check(in_array('company.statistics.managers', $registry->grantablePageKeys(), true), 'statistics page is grantable');

$route = $read('app/Http/Routes/company_statistics.php');
$controller = $read('app/Http/Controllers/Company/ManagerStatisticsController.php');
$service = $read('app/Service/WasteTripStartStatisticsService.php');
$tripService = $read('app/Service/WasteTripService.php');
$view = $read('app/View/pages/company_manager_statistics.php');
$css = $read('public/assets/css/manager-statistics.css');
$migration = $read('database/migrations-local/141_create_waste_trip_start_statistics.sql');

$check(str_contains($route, "'/company/statistics/managers'") && str_contains($route, "'company.statistics.managers'"), 'page route and access key');
$check(str_contains($controller, 'getSessionCompanyId()') && str_contains($controller, 'companyDatabaseConfig'), 'current company tenant only');
$check(str_contains($controller, 'schemaReady($local)') && !str_contains($controller, 'LocalMigrationService::apply'), 'GET is migration-free');
$check(str_contains($service, "effectiveDataScope('waste_trip')") && str_contains($service, "RegulatedDataScopeService::condition('waste_trip'"), 'waste trip data scope enforced');
$check(substr_count($tripService, 'WasteTripStartStatisticsService::synchronizeTrip') >= 6, 'all waste trip mutation paths synchronize facts');
$check(str_contains($migration, 'responsible_name_snapshot') && str_contains($migration, 'ON DUPLICATE KEY UPDATE'), 'historical manager attribution and idempotent backfill');
$check(
    str_contains($controller, 'eligibleLogists($central, $companyId)')
    && str_contains($service, "r.system_key='logist'")
    && str_contains($service, "r.normalized_name='логист'"),
    'exact active central Logist role supports system and historical role shapes'
);
$check(str_contains($view, 'Рейсы поставлены на погрузку') && str_contains($view, 'Точные значения по периодам') && str_contains($view, 'Итоги каждого логиста') && str_contains($view, 'Из чего сложился результат'), 'decision layers are rendered');
$check(str_contains($view, "'week'=>'Неделя'") && str_contains($view, "'month'=>'Месяц'") && str_contains($view, "'year'=>'Год'") && str_contains($view, "'custom'=>'Свой период'"), 'required calendar period modes');
$check(str_contains($view, 'Динамика компании и логистов') && str_contains($service, "'period_rows'=>self::periodRows"), 'company and logist dynamics with exact period values');
$check(str_contains($view, "'trips'=>") && str_contains($view, "'requests'=>") && str_contains($view, "'weight'=>"), 'required metric modes');
$check(str_contains($css, '#C07830') && str_contains($css, '#4A7A9B') && str_contains($css, '#3A8278'), 'approved chart palette');
$check(str_contains($css, '@media(max-width:1180px)') && str_contains($css, '@media(max-width:760px)'), 'responsive dashboard contract');

echo "MANAGER_STATISTICS_CONTRACT=PASS\n";
