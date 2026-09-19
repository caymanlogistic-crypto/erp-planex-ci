<?php

declare(strict_types=1);

$paths = [
    'service' => __DIR__ . '/../app/Service/ProductionCalendarService.php',
    'controller' => __DIR__ . '/../app/Http/Controllers/Company/ProductionCalendarController.php',
    'route' => __DIR__ . '/../app/Http/Routes/company_production_calendar.php',
    'view' => __DIR__ . '/../app/View/pages/company_production_calendar.php',
    'entrypoint' => __DIR__ . '/../public/index.php',
    'deps' => __DIR__ . '/../app/Support/entrypoint_dependencies.php',
    'registry' => __DIR__ . '/../app/Security/PageRegistry.php',
    'layout' => __DIR__ . '/../app/View/layouts/main.php',
    'migration' => __DIR__ . '/../database/migrations-local/070_production_calendar.sql',
    'runner' => __DIR__ . '/../scripts/p90_apply_production_calendar_migration.php',
];
foreach ($paths as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Missing {$name}: {$path}\n");
        exit(1);
    }
}
$content=[];
foreach($paths as $name=>$path){$content[$name]=(string)file_get_contents($path);}
$assert=static function(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};

$assert(str_contains($content['service'], "OFFICIAL_2026_SOURCE_TITLE"), 'official source metadata must be present');
$assert(str_contains($content['service'], "2026-01-09"), 'Jan 9 transferred day off must be seeded');
$assert(str_contains($content['service'], "2026-12-31"), 'Dec 31 transferred day off must be seeded');
$assert(str_contains($content['service'], "\$working !== 247 || \$off !== 118"), 'official 2026 control totals must be enforced');
$assert(str_contains($content['service'], "format('n') < 12"), 'reminder must start only in December');
$assert(str_contains($content['service'], "format('Y') + 1"), 'reminder must check next calendar year');
$assert(str_contains($content['service'], 'public static function isWorkingDay'), 'calendar must expose reusable working-day API');
$assert(str_contains($content['service'], 'public static function addWorkingDays'), 'calendar must expose reusable add-working-days API');

$assert(str_contains($content['deps'], "require_once base_path('app/Service/ProductionCalendarService.php');"), 'public runtime must load production calendar service');
$assert(str_contains($content['entrypoint'], "company_production_calendar.php"), 'public entrypoint must load production calendar routes');
$assert(str_contains($content['route'], '/company/misc/production-calendar'), 'calendar route must be registered in MISC namespace');
$assert(str_contains($content['controller'], "requireRole(['company_owner'])"), 'calendar management must be owner-only');

$assert(str_contains($content['registry'], "'company.production_calendar'") && str_contains($content['registry'], "'Настройки'"), 'calendar must belong to Settings in PageRegistry');
$assert(str_contains($content['registry'], "badgeProvider: 'production_calendar_warning'") && str_contains($content['registry'], 'ownerOnly: true'), 'calendar must stay owner-only with registry-owned warning badge');
$assert(!str_contains($content['registry'], "'Производственный календарь', 'Прочее'"), 'legacy MISC section must be removed from PageRegistry');
$assert(str_contains($content['layout'], '<div class="nav-section-label">НАСТРОЙКИ</div>') && str_contains($content['layout'], '<span class="nav-label">Производственный календарь</span>'), 'owner fallback must render calendar inside Settings');
$assert(str_contains($content['layout'], 'productionCalendarWarningCount') && str_contains($content['layout'], 'nav-count is-alert'), 'owner fallback reminder must use the registry counter and standard alert badge');

$assert(str_contains($content['view'], 'Двойной щелчок по дню — редактировать'), 'day editing must be discoverable');
$assert(str_contains($content['view'], "addEventListener('dblclick'"), 'double click must open day editor');
$assert(str_contains($content['view'], 'Подтвердить календарь'), 'year confirmation must be available');
$assert(str_contains($content['view'], 'pc-warning'), 'page must expose red missing-next-year warning');
$assert(str_contains($content['view'], 'Источник и подтверждение календаря'), 'source metadata must be editable');

$assert(str_contains($content['migration'], 'production_calendar_years'), 'year table must exist');
$assert(str_contains($content['migration'], 'production_calendar_days'), 'day table must exist');
$assert(str_contains($content['migration'], 'UNIQUE KEY uk_production_calendar_date'), 'calendar date must be unique');
$assert(str_contains($content['runner'], "070_production_calendar.sql"), 'P90 must apply migration 070');
$assert(str_contains($content['runner'], 'seedOfficial2026'), 'P90 must seed official 2026 data');
$assert(str_contains($content['runner'], 'P90_PRODUCTION_CALENDAR_OK'), 'P90 must provide deterministic success marker');

echo "PRODUCTION_CALENDAR_ARCHITECTURE_OK\n";
