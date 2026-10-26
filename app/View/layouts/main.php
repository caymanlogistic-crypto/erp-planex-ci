<?php

/**
 * Base ERP PLANEX layout with dynamic sidebar and topbar.
 *
 * Variables expected:
 *   $config     — app config
 *   $pageTitle  — string, page title for topbar crumbs
 *   $pageContext — string, page context for topbar crumbs
 */

$appName = $config['app']['app_name'] ?? 'ERP PLANEX';
$roleCode = $_SESSION['role_code'] ?? '';
$companyId = $_SESSION['company_id'] ?? 0;
$requestPath = current_app_path();
$effectiveAccess = class_exists(\App\Security\AccessRuntime::class)
    ? \App\Security\AccessRuntime::current()
    : null;
$registryNavigationActive = $roleCode !== 'superadmin'
    && $effectiveAccess !== null
    && $effectiveAccess->isActive();
$registryNavigationTree = [];
$activeNavigationKey = '';
$activeNavigationPathLength = -1;
if ($registryNavigationActive) {
    $registry = new \App\Security\PageRegistry();
    foreach ($registry->navigationTree() as $section => $groups) {
        foreach ($groups as $group => $pages) {
            foreach ($pages as $page) {
                if ($effectiveAccess->canAccessPage((string) $page['key'])) {
                    $registryNavigationTree[$section][$group][] = $page;
                    $candidatePath = (string) $page['path'];
                    if (($requestPath === $candidatePath || str_starts_with($requestPath, rtrim($candidatePath, '/') . '/'))
                        && strlen($candidatePath) > $activeNavigationPathLength) {
                        $activeNavigationKey = (string) $page['key'];
                        $activeNavigationPathLength = strlen($candidatePath);
                    }
                }
            }
        }
    }
}
$clientsActive = str_starts_with($requestPath, '/company/clients');
$tripsActive = str_starts_with($requestPath, '/company/trips');
$linearTripsActive = str_starts_with($requestPath, '/company/trips/linear');
$departuresActive = str_starts_with($requestPath, '/company/trips/departures');
$gpsCoverageActive = str_starts_with($requestPath, '/company/trips/gps-coverage');
$warehouseStockActive = str_starts_with($requestPath, '/company/warehouses/stock');
$warehouseAddressesActive = str_starts_with($requestPath, '/company/warehouses/addresses');
$warehousesOpen = $warehouseStockActive || $warehouseAddressesActive;
$managerStatisticsActive = str_starts_with($requestPath, '/company/statistics/managers');
$feoShareStatisticsActive = str_starts_with($requestPath, '/company/statistics/feo-share');
$cargoBalanceStatisticsActive = str_starts_with($requestPath, '/company/statistics/cargo-balance');
$statisticsOpen = $managerStatisticsActive || $feoShareStatisticsActive || $cargoBalanceStatisticsActive;
$routeExecutorsActive = str_starts_with($requestPath, '/company/route-executors');
$documentGeneratorOpen = str_starts_with($requestPath, '/company/document-generator');
$documentGeneratorCreateActive = $requestPath === '/company/document-generator';
$documentGeneratorHistoryActive = $requestPath === '/company/document-generator/history';
$documentGeneratorTemplatesActive = $requestPath === '/company/document-generator/templates'
    || str_starts_with($requestPath, '/company/document-generator/templates/');
$contractorsActive = str_starts_with($requestPath, '/company/contractors');
$driversActive = str_starts_with($requestPath, '/company/drivers');
$vehicleSetsActive = str_starts_with($requestPath, '/company/vehicle-sets');
$logistsActive = str_starts_with($requestPath, '/company/logists');
$responsibleAssignmentsActive = str_starts_with($requestPath, '/company/responsible-assignments');
$maxSettingsActive = str_starts_with($requestPath, '/company/max/settings');
$productionCalendarActive = str_starts_with($requestPath, '/company/misc/production-calendar');
$financeActive = str_starts_with($requestPath, '/company/finance');
$bankAccountsActive = str_starts_with($requestPath, '/company/finance/bank-accounts') || str_starts_with($requestPath, '/company/finance/settings/matching-rules');
$closingDocumentsActive = str_starts_with($requestPath, '/company/finance/closing-documents');
$ddsCategoriesActive = str_starts_with($requestPath, '/company/finance/settings/dds-categories');
$matchingRulesActive = str_starts_with($requestPath, '/company/finance/settings/matching-rules');
    $paymentCalendarActive = str_starts_with($requestPath, '/company/finance/payment-calendar');
    $cashFlowReportActive = str_starts_with($requestPath, '/company/finance/reports/cash-flow');
    $managementBalanceActive = str_starts_with($requestPath, '/company/finance/reports/management-balance');
    $paymentPlanFactActive = str_starts_with($requestPath, '/company/finance/reports/payment-plan-fact');
    $invoicesActive = str_starts_with($requestPath, '/company/finance/invoices');
    $receivedPaidActive = str_starts_with($requestPath, '/company/finance/received-paid');
$financeDashboardActive = str_starts_with($requestPath, '/company/finance/dashboard');
$bankStatementSettingsActive = str_starts_with($requestPath, '/company/bank-statement-settings');
$feoRequestCostsActive = str_starts_with($requestPath, '/company/feo/requests/costs');
$feoRequestsActive = str_starts_with($requestPath, '/company/feo/requests') && !$feoRequestCostsActive;

$navigationCounters = [
    'bank_attention' => 0, 'cash_attention' => 0, 'closing_documents_problem' => 0, 'trip_closing_blocked_unloaded' => 0,
    'linear_trips_problem' => 0, 'departures_problem' => 0, 'warehouse_stock_problem' => 0, 'trip_completion_problem' => 0, 'tn_review_pending' => 0,
    'feo_freshness' => 0, 'feo_placement_problem' => 0, 'feo_map_problem' => 0, 'feo_utilizer_problem' => 0, 'feo_cost_problem' => 0, 'production_calendar_warning' => 0,
];
if ((($effectiveAccess !== null && $effectiveAccess->isActive()) || $roleCode === 'company_owner') && (int)$companyId > 0) {
    $navigationCounters = \App\Service\NavigationCounterService::forCompany(
        $config,
        $db,
        (int)$companyId,
        (isset($company) && is_array($company)) ? $company : null
    );
}
$bankAttentionCount = max(0, (int)($navigationCounters['bank_attention'] ?? 0));
$closingDocumentsProblemCount = max(0, (int)($navigationCounters['closing_documents_problem'] ?? 0));
$productionCalendarWarningCount = max(0, (int)($navigationCounters['production_calendar_warning'] ?? 0));
$navigationBadgeCount = static function (array $page) use ($navigationCounters): int {
    $provider = trim((string)($page['badge_provider'] ?? ''));
    return $provider !== '' ? max(0, (int)($navigationCounters[$provider] ?? 0)) : 0;
};
$navigationBadgeTitle = static function (array $page): string {
    return match ((string)($page['badge_provider'] ?? '')) {
        'bank_attention' => 'Требуют разнесения',
        'cash_attention' => 'Неразнесённые позиции кассы',
        'closing_documents_problem' => 'Требуют закрывающих документов',
        'trip_closing_blocked_unloaded' => 'Выгруженные рейсы: оплата заблокирована из-за документов',
        'linear_trips_problem' => 'Требуется актуализировать дату выгрузки',
        'departures_problem' => 'Ошибки в рейсах отходов',
        'warehouse_stock_problem' => 'Расхождения складских рейсов со старой базой',
        'trip_completion_problem' => 'Рейсов в работе',
        'tn_review_pending' => 'Рейсов на проверке',
        'feo_freshness' => 'Требуется обновить заявки ФЭО',
        'feo_placement_problem' => 'Расхождения размещения со старой базой или блоками',
        'feo_map_problem' => 'Проблемы данных карты ФЭО',
        'feo_utilizer_problem' => 'Не определён утилизатор',
        'feo_cost_problem' => 'Расхождения в блоках стоимости',
        'production_calendar_warning' => 'Не загружен производственный календарь на следующий год',
        default => 'Требует внимания',
    };
};
$navigationBadgeHtml = static function (array $page) use ($navigationBadgeCount, $navigationBadgeTitle): string {
    $count = $navigationBadgeCount($page);
    if ($count <= 0) return '';
    $label = (string)($page['badge_provider'] ?? '') === 'production_calendar_warning'
        ? '!'
        : ($count > 999 ? '999+' : (string)$count);
    return '<span class="nav-count is-alert" title="' . e($navigationBadgeTitle($page)) . '">' . e($label) . '</span>';
};
$wideWorkspacePrefixes = [
    '/company/clients',
    '/company/max/settings',
    '/company/roles-access',
    '/company/document-generator',
    '/company/trips/linear',
    '/company/trips/departures',
    '/company/trips/gps-coverage',
    '/company/trips/closing-documents',
    '/company/logistics/closing-documents',
    '/company/warehouses/stock',
    '/company/warehouses/addresses',
    '/company/statistics/managers',
    '/company/statistics/work',
    '/company/statistics/feo-share',
    '/company/documents',
    '/company/logistics-assistant',
    '/company/tn-review',
    '/company/route-executors',
    '/company/feo/requests',
    '/company/contractors',
    '/company/drivers',
    '/company/vehicle-sets',
    '/company/finance/operations',
    '/company/finance/invoices',
    '/company/finance/received-paid',
    '/company/finance/cash',
    '/company/finance/bank-accounts',
    '/company/finance/closing-documents',
    '/company/finance/dashboard',
];
$wideWorkspaceActive = false;
foreach ($wideWorkspacePrefixes as $wideWorkspacePrefix) {
    if (str_starts_with($requestPath, $wideWorkspacePrefix)) {
        $wideWorkspaceActive = true;
        break;
    }
}

// Вычисление — раскрыта ли группа «Справочники» (любой подпункт активен)
$directoriesOpen = ($roleCode !== 'superadmin') && (
    $contractorsActive
    || $driversActive
    || $vehicleSetsActive
);
$tripsOpen = ($roleCode !== 'superadmin') && $tripsActive;
$feoRequestsOpen = ($roleCode !== 'superadmin') && ($feoRequestsActive || $feoRequestCostsActive);

$userName = $_SESSION['user_name'] ?? '';
$roleLabel = match($roleCode) {
    'superadmin' => 'Суперадминистратор',
    'company_owner' => 'Руководитель',
    'senior_logist' => 'Логист+',
    'logist' => 'Логист',
    default => '',
};
if ($roleLabel === '' && $effectiveAccess !== null && $effectiveAccess->isSystemOwner()) {
    $roleLabel = 'Руководитель';
} elseif ($roleLabel === '' && $registryNavigationActive) {
    $roleLabel = 'Сотрудник';
}

$switchCompanies = [];
$accountId = filter_var($_SESSION['account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($accountId !== false && $roleCode !== 'superadmin') {
    try {
        require_once base_path('app/Service/CompanyMembershipService.php');
        $switchCompanies = (new \App\Service\CompanyMembershipService($db->connection()))
            ->activeCompaniesForAccount((int) $accountId);
    } catch (\Throwable) {
        $switchCompanies = [];
    }
}

$uiTextSlice = static function (string $value, int $start, ?int $length = null): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, $start, $length, 'UTF-8');
    }
    if (preg_match_all('/./us', $value, $characters) !== false) {
        return implode('', array_slice($characters[0], $start, $length));
    }
    return $length === null ? substr($value, $start) : substr($value, $start, $length);
};
$uiTextUpper = static function (string $value): string {
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($value, 'UTF-8');
    }
    return strtr(strtoupper($value), [
        'а' => 'А', 'б' => 'Б', 'в' => 'В', 'г' => 'Г', 'д' => 'Д', 'е' => 'Е', 'ё' => 'Ё',
        'ж' => 'Ж', 'з' => 'З', 'и' => 'И', 'й' => 'Й', 'к' => 'К', 'л' => 'Л', 'м' => 'М',
        'н' => 'Н', 'о' => 'О', 'п' => 'П', 'р' => 'Р', 'с' => 'С', 'т' => 'Т', 'у' => 'У',
        'ф' => 'Ф', 'х' => 'Х', 'ц' => 'Ц', 'ч' => 'Ч', 'ш' => 'Ш', 'щ' => 'Щ', 'ъ' => 'Ъ',
        'ы' => 'Ы', 'ь' => 'Ь', 'э' => 'Э', 'ю' => 'Ю', 'я' => 'Я',
    ]);
};

$initials = '';
if ($userName !== '') {
    $words = array_filter(explode(' ', trim($userName)));
    $initials = implode('', array_map(fn($w) => $uiTextSlice($w, 0, 1), $words));
    $initials = $uiTextUpper($uiTextSlice($initials, 0, 2));
}

$crumbTitle = (string)($pageTitle ?? 'ERP PLANEX');
$crumbContext = trim((string)($pageContext ?? ''));
if ($crumbContext !== '') {
    $contextLead = trim(explode('›', $crumbContext, 2)[0]);
    if ($contextLead === $crumbTitle) {
        $crumbTitle = '';
    }
}

// Auto-generate breadcrumbs if not explicitly provided
if (empty($topbarCrumbs)) {
    require_once base_path('app/View/components/breadcrumbs.php');
    $superadminCompanyName = (isset($company) && is_array($company) && isset($company['name']))
        ? $company['name']
        : null;
    $topbarCrumbs = ui_breadcrumbs($requestPath, $superadminCompanyName, $company ?? null);
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="erp-base-path" content="<?= e(app_base_path()) ?>">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title><?= e($pageTitle ?? $appName) ?> — <?= e($appName) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%230f766e'/%3E%3Cpath d='M18 18h18c6.627 0 12 5.373 12 12s-5.373 12-12 12H30v12H18V18zm12 12h6a4 4 0 1 0 0-8h-6v8z' fill='white'/%3E%3C/svg%3E">
    <link rel="preload" href="<?= app_url('/assets/fonts/IBMPlexSans-Regular.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= app_url('/assets/fonts/IBMPlexSans-Medium.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= app_url('/assets/fonts/IBMPlexSans-SemiBold.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= app_url('/assets/fonts/IBMPlexSans-Bold.woff2') ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= app_url('/assets/css/app.css') ?>?v=<?= filemtime(base_path('public/assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= app_url('/assets/css/erp-ui.css') ?>?v=<?= filemtime(base_path('public/assets/css/erp-ui.css')) ?>">
    <link rel="stylesheet" href="<?= app_url('/assets/css/roles-access.css') ?>?v=<?= filemtime(base_path('public/assets/css/roles-access.css')) ?>">
    <?php foreach (($pageStylesheets ?? []) as $pageStylesheet):
        $pageStylesheet = (string)$pageStylesheet;
        $pageStylesheetFile = base_path('public/' . ltrim($pageStylesheet, '/'));
        if (!preg_match('~^/assets/[a-z0-9_./-]+\.css$~i', $pageStylesheet) || !is_file($pageStylesheetFile)) continue;
    ?>
    <link rel="stylesheet" href="<?= e(app_url($pageStylesheet)) ?>?v=<?= filemtime($pageStylesheetFile) ?>">
    <?php endforeach; ?>
</head>
<body>
    <div class="app">
        <header class="topbar">
            <div class="topbar-brand">
                <div class="logo-mark"></div>
                <span class="brand-name">ERP PLANEX</span>
                <span class="brand-tag">ERP</span>
            </div>
            <div class="topbar-crumbs">
                <?php if (!empty($topbarCrumbs)): ?>
                <?php foreach ($topbarCrumbs as $i => $crumb): ?>
                <?php if ($i > 0): ?><span class="sep">›</span><?php endif; ?>
                <?php if ($i === count($topbarCrumbs) - 1): ?>
                <b><?= e($crumb['label']) ?></b>
                <?php elseif (!empty($crumb['url'])): ?>
                <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
                <?php else: ?>
                <span><?= e($crumb['label']) ?></span>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php else: ?>
                <?php if ($crumbTitle !== ''): ?>
                <span><?= e($crumbTitle) ?></span>
                <?php endif; ?>
                <?php if ($crumbContext !== ''): ?>
                <?php if ($crumbTitle !== ''): ?>
                <span class="sep">›</span>
                <?php endif; ?>
                <b><?= e($crumbContext) ?></b>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="topbar-right">
                <?php if (!empty($_SESSION['user_id'])): ?>
                <?php if (count($switchCompanies) > 1): ?>
                <form method="post" action="<?= app_url('/company/switch') ?>" class="company-switch-form" aria-label="Переключение компании">
                    <label class="sr-only" for="active-company-switch">Компания</label>
                    <select id="active-company-switch" name="company_id" class="field-select company-switch-select" onchange="this.form.submit()">
                        <?php foreach ($switchCompanies as $switchCompany): ?>
                        <option value="<?= (int) $switchCompany['id'] ?>"<?= (int) $switchCompany['id'] === (int) $companyId ? ' selected' : '' ?>><?= e($switchCompany['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php endif; ?>
                <div class="avatar"><?= e($initials) ?></div>
                <div class="user-info">
                    <strong><?= e($userName) ?></strong>
                    <span><?= e($roleLabel) ?></span>
                </div>
                <form method="post" action="<?= app_url('/logout') ?>" class="inline-form">
                    <button type="submit" class="btn btn-ghost">Выйти</button>
                </form>
                <?php else: ?>
                <span class="text-muted">ERP PLANEX</span>
                <?php endif; ?>
            </div>
        </header>

        <aside class="sidebar" aria-label="Основная навигация">

            <?php if ($roleCode === 'superadmin'): ?>

            <div class="nav-group">
                <div class="nav-section-label">СИСТЕМА</div>
                <a class="nav-item<?= str_starts_with($requestPath, '/superadmin/companies') && !str_contains($requestPath, '/deleted') ? ' is-active' : '' ?>" href="<?= app_url('/superadmin/companies') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none"><rect x="2" y="4" width="12" height="11" rx="1" stroke="currentColor" stroke-width="1.4"/><path d="M5 15V11H11V15" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M2 7H14" stroke="currentColor" stroke-width="1.4"/><rect x="5" y="5" width="2" height="2" rx=".3" fill="currentColor"/><rect x="9" y="5" width="2" height="2" rx=".3" fill="currentColor"/><rect x="5" y="9" width="2" height="2" rx=".3" fill="currentColor"/><rect x="9" y="9" width="2" height="2" rx=".3" fill="currentColor"/></svg>
                    <span class="nav-label">Компании</span>
                </a>
                <a class="nav-item<?= str_starts_with($requestPath, '/superadmin/db-usage') ? ' is-active' : '' ?>" href="<?= app_url('/superadmin/db-usage') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none"><ellipse cx="8" cy="4" rx="6" ry="2" stroke="currentColor" stroke-width="1.3"/><path d="M2 4V8C2 9.1 4.7 10 8 10C11.3 10 14 9.1 14 8V4" stroke="currentColor" stroke-width="1.3"/><path d="M2 8V12C2 13.1 4.7 14 8 14C11.3 14 14 13.1 14 12V8" stroke="currentColor" stroke-width="1.3"/></svg>
                    <span class="nav-label">Использование БД</span>
                </a>
                <a class="nav-item<?= str_starts_with($requestPath, '/superadmin/deleted-data') ? ' is-active' : '' ?>" href="<?= app_url('/superadmin/deleted-data') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none"><path d="M2 4H14V13C14 13.6 13.6 14 13 14H3C2.4 14 2 13.6 2 13V4Z" stroke="currentColor" stroke-width="1.4"/><path d="M2 4L4 2H12L14 4" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M6 8L8 10L10 8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 10V6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <span class="nav-label">Удалённые данные</span>
                </a>
            </div>

            <?php elseif ($registryNavigationActive): ?>

            <?php foreach ($registryNavigationTree as $navigationSection => $navigationGroups): ?>
            <div class="nav-group">
                <div class="nav-section-label"><?= e($uiTextUpper((string) $navigationSection)) ?></div>
                <?php foreach ($navigationGroups as $navigationGroup => $navigationPages): ?>
                    <?php if ($navigationGroup === ''): ?>
                        <?php foreach ($navigationPages as $navigationPage):
                            $navigationPath = (string) $navigationPage['path'];
                            $navigationIsActive = $activeNavigationKey === (string) $navigationPage['key'];
                        ?>
                        <a class="nav-item<?= $navigationIsActive ? ' is-active' : '' ?>" href="<?= app_url($navigationPath) ?>">
                            <?php if (in_array((string) $navigationPage['key'], ['company.statistics.managers', 'company.statistics.feo_share', 'company.statistics.cargo_balance'], true)): ?>
                            <svg class="nav-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.5 13.5V9.5L5.5 6.5L8 9L12.5 4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 4.5H12.5V7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php else: ?>
                            <svg class="nav-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="2.5" y="2.5" width="11" height="11" rx="1" stroke="currentColor" stroke-width="1.3"/><path d="M5 6h6M5 9h6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                            <?php endif; ?>
                            <span class="nav-label"><?= e($navigationPage['label']) ?></span>
                            <?php if (in_array((string)($navigationPage['badge_display'] ?? 'self'), ['self','self_parent'], true)) echo $navigationBadgeHtml($navigationPage); ?>
                        </a>
                        <?php endforeach; ?>
                    <?php else:
                        $navigationGroupOpen = false;
                        $navigationGroupBadgeCount = 0;
                        foreach ($navigationPages as $navigationPage) {
                            if ($activeNavigationKey === (string) $navigationPage['key']) {
                                $navigationGroupOpen = true;
                            }
                            if (in_array((string)($navigationPage['badge_display'] ?? 'self'), ['parent','self_parent'], true)) {
                                $navigationGroupBadgeCount += $navigationBadgeCount($navigationPage);
                            }
                        }
                    ?>
                    <div class="nav-item is-parent<?= $navigationGroupOpen ? ' is-open is-active' : '' ?>">
                        <svg class="nav-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 4h4l1.5 2H14v7H2V4Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                        <span class="nav-label"><?= e($navigationGroup) ?></span>
                        <?php if ($navigationGroupBadgeCount > 0): ?><span class="nav-count is-alert" title="Требует внимания"><?= $navigationGroupBadgeCount > 999 ? '999+' : $navigationGroupBadgeCount ?></span><?php endif; ?>
                        <svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="nav-sub<?= $navigationGroupOpen ? ' is-open' : '' ?>">
                        <?php foreach ($navigationPages as $navigationPage):
                            $navigationPath = (string) $navigationPage['path'];
                            $navigationIsActive = $activeNavigationKey === (string) $navigationPage['key'];
                        ?>
                        <a class="nav-sub-item<?= $navigationIsActive ? ' is-active' : '' ?>" href="<?= app_url($navigationPath) ?>"><span><?= e($navigationPage['label']) ?></span><?php if (in_array((string)($navigationPage['badge_display'] ?? 'self'), ['self','self_parent'], true)) echo $navigationBadgeHtml($navigationPage); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <?php elseif ($roleCode === 'company_owner'): ?>

            <div class="nav-group">
                <div class="nav-section-label">ОПЕРАЦИИ</div>
                <a class="nav-item<?= $clientsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/clients') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none"><rect x="2" y="5.5" width="12" height="8.5" rx="1" stroke="currentColor" stroke-width="1.4"/><path d="M5 5.5V4C5 3.4 5.4 3 6 3H10C10.6 3 11 3.4 11 4V5.5" stroke="currentColor" stroke-width="1.4"/><path d="M2 9.5H14" stroke="currentColor" stroke-width="1.4"/><path d="M7.5 9.5V12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <span class="nav-label">Клиенты</span>
                </a>
                <div class="nav-item is-parent<?= $tripsOpen ? ' is-open is-active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M2 4.5H10.5L13.5 7.5V12.5C13.5 13.05 13.05 13.5 12.5 13.5H3.5C2.95 13.5 2.5 13.05 2.5 12.5V5C2.5 4.72 2.72 4.5 3 4.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M10.5 4.5V7.5H13.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M5 9H11" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="nav-label">Рейсы</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $tripsOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $linearTripsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/trips/linear') ?>">
                        <span>Линейные</span>
                    </a>
                    <a class="nav-sub-item<?= $departuresActive ? ' is-active' : '' ?>" href="<?= app_url('/company/trips/departures') ?>">
                        <span>Отходы</span>
                    </a>
                    <a class="nav-sub-item<?= $gpsCoverageActive ? ' is-active' : '' ?>" href="<?= app_url('/company/trips/gps-coverage') ?>"><span>Покрытие GPS</span></a>
                </div>
                <div class="nav-item is-parent<?= $warehousesOpen ? ' is-open is-active' : '' ?>"><svg class="nav-icon" viewBox="0 0 16 16" fill="none"><path d="M1.5 4.5h5l1.5 2h6v6.5H1.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg><span class="nav-label">Склады</span><?php if (($navigationCounters['warehouse_stock_problem'] ?? 0) > 0): ?><span class="nav-count is-alert"><?=(int)$navigationCounters['warehouse_stock_problem']?></span><?php endif; ?><svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4"/></svg></div>
                <div class="nav-sub<?= $warehousesOpen ? ' is-open' : '' ?>"><a class="nav-sub-item<?= $warehouseStockActive ? ' is-active' : '' ?>" href="<?=app_url('/company/warehouses/stock')?>"><span>Заявки на складе</span><?php if (($navigationCounters['warehouse_stock_problem'] ?? 0) > 0): ?><span class="nav-count is-alert"><?=(int)$navigationCounters['warehouse_stock_problem']?></span><?php endif; ?></a><a class="nav-sub-item<?= $warehouseAddressesActive ? ' is-active' : '' ?>" href="<?= app_url('/company/warehouses/addresses') ?>"><span>Адреса складов</span></a></div>
                <div class="nav-item is-parent<?= $feoRequestsOpen ? ' is-open is-active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M3 2.5H10L13 5.5V13.5H3V2.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M10 2.5V5.5H13" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M5.5 8H10.5M5.5 10.5H10.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="nav-label">Заявки ФЭО</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $feoRequestsOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $feoRequestsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/feo/requests') ?>">
                        <span>Все заявки</span>
                    </a>
                    <a class="nav-sub-item<?= $feoRequestCostsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/feo/requests/costs') ?>">
                        <span>Стоимость заявок</span>
                    </a>
                </div>
                <a class="nav-item<?= $routeExecutorsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/route-executors') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M1.5 3.5H5.5L7 5.5H14.5C15.05 5.5 15.5 5.95 15.5 6.5V12.5C15.5 13.05 15.05 13.5 14.5 13.5H1.5C0.95 13.5 0.5 13.05 0.5 12.5V4.5C0.5 3.95 0.95 3.5 1.5 3.5Z" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M8 8.5L10 9.5V11L8 10L6 11V9.5L8 8.5Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                    </svg>
                    <span class="nav-label">Исполнители рейса</span>
                </a>
                <div class="nav-item is-parent<?= $documentGeneratorOpen ? ' is-open is-active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M3 1.5H10L13 4.5V14.5H3V1.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M10 1.5V4.5H13" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M5.5 8H10.5M5.5 10.5H9.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="nav-label">Генератор договоров</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $documentGeneratorOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $documentGeneratorCreateActive ? ' is-active' : '' ?>" href="<?= app_url('/company/document-generator') ?>"><span>Создать документ</span></a>
                    <a class="nav-sub-item<?= $documentGeneratorHistoryActive ? ' is-active' : '' ?>" href="<?= app_url('/company/document-generator/history') ?>"><span>История документов</span></a>
                    <a class="nav-sub-item<?= $documentGeneratorTemplatesActive ? ' is-active' : '' ?>" href="<?= app_url('/company/document-generator/templates') ?>"><span>Шаблоны договоров</span></a>
                </div>
                <div class="nav-item is-parent<?= $directoriesOpen ? ' is-open' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="5" width="13" height="9.5" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M5 5V3.5C5 2.9 5.4 2.5 6 2.5H10C10.6 2.5 11 2.9 11 3.5V5" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M1.5 9H14.5" stroke="currentColor" stroke-width="1.4"/>
                        <rect x="6.5" y="6.5" width="3" height="3" rx=".5" stroke="currentColor" stroke-width="1.2"/>
                    </svg>
                    <span class="nav-label">Справочники</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $directoriesOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $contractorsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/contractors') ?>">
                        <span>Подрядчики</span>
                    </a>
                    <a class="nav-sub-item<?= $driversActive ? ' is-active' : '' ?>" href="<?= app_url('/company/drivers') ?>">
                        <span>Водители</span>
                    </a>
                    <a class="nav-sub-item<?= $vehicleSetsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/vehicle-sets') ?>">
                        <span>ТС</span>
                    </a>
                </div>
                <div class="nav-item is-parent<?= $statisticsOpen ? ' is-open is-active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 4h4l1.5 2H14v7H2V4Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                    <span class="nav-label">Статистика</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $statisticsOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $managerStatisticsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/statistics/managers') ?>"><span>Работа логистов</span></a>
                    <a class="nav-sub-item<?= $feoShareStatisticsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/statistics/feo-share') ?>"><span>Доля компаний в ФЭО</span></a>
                    <a class="nav-sub-item<?= $cargoBalanceStatisticsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/statistics/cargo-balance') ?>"><span>Баланс заявок</span></a>
                </div>
            </div>

            <div class="nav-group">
                <div class="nav-section-label">ЛОГИСТЫ</div>
                <a class="nav-item<?= $requestPath === '/company/logistics/closing-documents' ? ' is-active' : '' ?>" href="<?= app_url('/company/logistics/closing-documents') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 2h7l3 3v9H3zM10 2v3h3M5 8h6M5 11h6" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                    <span class="nav-label">Закрывающие документы</span>
                    <?php if (($navigationCounters['trip_closing_blocked_unloaded'] ?? 0) > 0): ?><span class="nav-count is-alert" title="Выгруженные рейсы: оплата заблокирована из-за документов"><?= (int)$navigationCounters['trip_closing_blocked_unloaded'] > 999 ? '999+' : (int)$navigationCounters['trip_closing_blocked_unloaded'] ?></span><?php endif; ?>
                </a>
            </div>

            <div class="nav-group">
                <div class="nav-section-label">ФИНАНСЫ</div>
                <a class="nav-item<?= $financeDashboardActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/dashboard') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="2" y="4" width="12" height="10" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M5 9L7 7L9 8.5L11 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2 6.5H14" stroke="currentColor" stroke-width="1.4"/>
                    </svg>
                    <span class="nav-label">Дашборд</span>
                </a>
                <a class="nav-item<?= $bankAccountsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/bank-accounts') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="4" width="13" height="10" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M5 10L7 12L11 7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M4 4V2.5C4 2.2 4.2 2 4.5 2H11.5C11.8 2 12 2.2 12 2.5V4" stroke="currentColor" stroke-width="1.4"/>
                    </svg>
                    <span class="nav-label">Выписки со счёта</span>
                    <?php if ($bankAttentionCount > 0): ?><span class="nav-count is-alert" title="Требуют разнесения"><?= $bankAttentionCount > 999 ? '999+' : $bankAttentionCount ?></span><?php endif; ?>
                </a>
                <a class="nav-item<?= $closingDocumentsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/closing-documents') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M2.5 1.5H10.5L13.5 4.5V13.5C13.5 14.05 13.05 14.5 12.5 14.5H3.5C2.95 14.5 2.5 14.05 2.5 13.5V2.5C2.5 1.95 2.95 1.5 3.5 1.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M10.5 1.5V4.5H13.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M5 8H11M5 10.5H11" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="nav-label">Закрывающие документы</span>
                    <?php if ($closingDocumentsProblemCount > 0): ?><span class="nav-count is-alert" title="Требуют закрывающих документов"><?= $closingDocumentsProblemCount > 999 ? '999+' : $closingDocumentsProblemCount ?></span><?php endif; ?>
                </a>
                <a class="nav-item<?= $invoicesActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/invoices') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M2.5 1.5H10.5L13.5 4.5V13.5C13.5 14.05 13.05 14.5 12.5 14.5H3.5C2.95 14.5 2.5 14.05 2.5 13.5V2.5C2.5 1.95 2.95 1.5 3.5 1.5H2.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M10.5 1.5V4.5H13.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M5.5 8H10.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                        <path d="M5.5 10.5H10.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                        <path d="M5.5 13H8.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="nav-label">Счета</span>
                </a>
                <a class="nav-item<?= $receivedPaidActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/received-paid') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="2.5" y="2.5" width="11" height="11" rx="1" stroke="currentColor" stroke-width="1.3"/><path d="M5 6h6M5 9h6" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                    <span class="nav-label">Получено/оплачено</span>
                </a>
                <a class="nav-item<?= $ddsCategoriesActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/settings/dds-categories') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="2" y="3" width="12" height="10" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M5 7L7 9L11 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="nav-label">Статьи ДДС</span>
                </a>
                <a class="nav-item<?= $paymentCalendarActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/payment-calendar') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="2" width="13" height="12" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M1.5 6H14.5" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M4.5 9H6.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                        <path d="M9.5 9H11.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                        <path d="M4.5 11.5H7.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                        <path d="M10.5 11.5H12" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                        <path d="M5 2V4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                        <path d="M11 2V4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="nav-label">Платёжный календарь</span>
                </a>
                <a class="nav-item<?= $cashFlowReportActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/reports/cash-flow') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="2" y="2" width="12" height="12" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M5 10L7 7L9 9L11 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2 8H14" stroke="currentColor" stroke-width="1.4"/>
                    </svg>
                    <span class="nav-label">БДДС</span>
                </a>
                <a class="nav-item<?= $managementBalanceActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/reports/management-balance') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="2" y="2" width="12" height="12" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M5 10L8 8L10 10L11 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2 4H14" stroke="currentColor" stroke-width="1.4"/>
                    </svg>
                    <span class="nav-label">Упр. баланс</span>
                </a>
                <a class="nav-item<?= $paymentPlanFactActive ? ' is-active' : '' ?>" href="<?= app_url('/company/finance/reports/payment-plan-fact') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="2" y="2" width="12" height="12" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M5 10L8 8L10 10L11 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M2 4H14" stroke="currentColor" stroke-width="1.4"/>
                    </svg>
                    <span class="nav-label">План-факт оплаты</span>
                </a>
            </div>

            <div class="nav-group">
                <div class="nav-section-label">НАСТРОЙКИ</div>
                <a class="nav-item<?= $bankStatementSettingsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/bank-statement-settings') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="5" width="13" height="9.5" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M5 5V3.5C5 2.9 5.4 2.5 6 2.5H10C10.6 2.5 11 2.9 11 3.5V5" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M1.5 8.5H14.5" stroke="currentColor" stroke-width="1.4"/>
                        <rect x="7" y="6.5" width="2" height="2" rx=".5" stroke="currentColor" stroke-width="1.2"/>
                    </svg>
                    <span class="nav-label">Настройки выписок</span>
                </a>
                <a class="nav-item<?= $logistsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/logists') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <circle cx="6" cy="4.5" r="2.5" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M1.5 13.5C1.5 10.5 4 8.5 6 8.5C8 8.5 10.5 10.5 10.5 13.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                        <path d="M12 3L14.5 4.5V7C14.5 9.5 12.8 11.3 12 12C11.2 11.3 9.5 9.5 9.5 7V4.5L12 3Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M10.8 7L11.5 8L13.2 5.8" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="nav-label">Логисты</span>
                </a>
                <a class="nav-item<?= $responsibleAssignmentsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/responsible-assignments') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M4 2H10L12 4V6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                        <rect x="1.5" y="6" width="13" height="8" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M4 10L6 12L10 8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="nav-label">Переназначение</span>
                </a>
                <a class="nav-item<?= $productionCalendarActive ? ' is-active' : '' ?>" href="<?= app_url('/company/misc/production-calendar') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none"><rect x="2" y="3.5" width="12" height="10.5" rx="1.5" stroke="currentColor" stroke-width="1.3"/><path d="M2 6.5H14M5 2V5M11 2V5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M5 9H6M8 9H9M11 9H12M5 11.5H6M8 11.5H9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                    <span class="nav-label">Производственный календарь</span>
                    <?php if ($productionCalendarWarningCount > 0): ?><span class="nav-count is-alert" title="Не загружен производственный календарь на следующий год">!</span><?php endif; ?>
                </a>
                <a class="nav-item<?= $maxSettingsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/max/settings') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none"><path d="M3 3h10v10H3V3Z" stroke="currentColor" stroke-width="1.4"/><path d="M5 6h6M5 8h6M5 10h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/></svg>
                    <span class="nav-label">MAX — интеграция</span>
                </a>
            </div>

            <?php elseif ($roleCode === 'logist' || $roleCode === 'senior_logist'): ?>

            <div class="nav-group">
                <div class="nav-section-label">ОПЕРАЦИИ</div>
                <a class="nav-item<?= $clientsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/clients') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none"><rect x="2" y="5.5" width="12" height="8.5" rx="1" stroke="currentColor" stroke-width="1.4"/><path d="M5 5.5V4C5 3.4 5.4 3 6 3H10C10.6 3 11 3.4 11 4V5.5" stroke="currentColor" stroke-width="1.4"/><path d="M2 9.5H14" stroke="currentColor" stroke-width="1.4"/><path d="M7.5 9.5V12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    <span class="nav-label">Клиенты</span>
                </a>
                <div class="nav-item is-parent<?= $tripsOpen ? ' is-open is-active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M2 4.5H10.5L13.5 7.5V12.5C13.5 13.05 13.05 13.5 12.5 13.5H3.5C2.95 13.5 2.5 13.05 2.5 12.5V5C2.5 4.72 2.72 4.5 3 4.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M10.5 4.5V7.5H13.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M5 9H11" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="nav-label">Рейсы</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $tripsOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $linearTripsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/trips/linear') ?>">
                        <span>Линейные</span>
                    </a>
                    <a class="nav-sub-item<?= $departuresActive ? ' is-active' : '' ?>" href="<?= app_url('/company/trips/departures') ?>">
                        <span>Отходы</span>
                    </a>
                    <a class="nav-sub-item<?= $gpsCoverageActive ? ' is-active' : '' ?>" href="<?= app_url('/company/trips/gps-coverage') ?>"><span>Покрытие GPS</span></a>
                </div>
                <div class="nav-item is-parent<?= $warehousesOpen ? ' is-open is-active' : '' ?>"><svg class="nav-icon" viewBox="0 0 16 16" fill="none"><path d="M1.5 4.5h5l1.5 2h6v6.5H1.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg><span class="nav-label">Склады</span><?php if (($navigationCounters['warehouse_stock_problem'] ?? 0) > 0): ?><span class="nav-count is-alert"><?=(int)$navigationCounters['warehouse_stock_problem']?></span><?php endif; ?><svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4"/></svg></div>
                <div class="nav-sub<?= $warehousesOpen ? ' is-open' : '' ?>"><a class="nav-sub-item<?= $warehouseStockActive ? ' is-active' : '' ?>" href="<?=app_url('/company/warehouses/stock')?>"><span>Заявки на складе</span><?php if (($navigationCounters['warehouse_stock_problem'] ?? 0) > 0): ?><span class="nav-count is-alert"><?=(int)$navigationCounters['warehouse_stock_problem']?></span><?php endif; ?></a><a class="nav-sub-item<?= $warehouseAddressesActive ? ' is-active' : '' ?>" href="<?= app_url('/company/warehouses/addresses') ?>"><span>Адреса складов</span></a></div>
                <div class="nav-item is-parent<?= $feoRequestsOpen ? ' is-open is-active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M3 2.5H10L13 5.5V13.5H3V2.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M10 2.5V5.5H13" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M5.5 8H10.5M5.5 10.5H10.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="nav-label">Заявки ФЭО</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $feoRequestsOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $feoRequestsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/feo/requests') ?>">
                        <span>Все заявки</span>
                    </a>
                    <a class="nav-sub-item<?= $feoRequestCostsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/feo/requests/costs') ?>">
                        <span>Стоимость заявок</span>
                    </a>
                </div>
                <a class="nav-item<?= $routeExecutorsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/route-executors') ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M1.5 3.5H5.5L7 5.5H14.5C15.05 5.5 15.5 5.95 15.5 6.5V12.5C15.5 13.05 15.05 13.5 14.5 13.5H1.5C0.95 13.5 0.5 13.05 0.5 12.5V4.5C0.5 3.95 0.95 3.5 1.5 3.5Z" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M8 8.5L10 9.5V11L8 10L6 11V9.5L8 8.5Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                    </svg>
                    <span class="nav-label">Исполнители рейса</span>
                </a>
                <div class="nav-item is-parent<?= $documentGeneratorOpen ? ' is-open is-active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <path d="M3 1.5H10L13 4.5V14.5H3V1.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M10 1.5V4.5H13" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                        <path d="M5.5 8H10.5M5.5 10.5H9.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="nav-label">Генератор договоров</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $documentGeneratorOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $documentGeneratorCreateActive ? ' is-active' : '' ?>" href="<?= app_url('/company/document-generator') ?>"><span>Создать документ</span></a>
                    <a class="nav-sub-item<?= $documentGeneratorHistoryActive ? ' is-active' : '' ?>" href="<?= app_url('/company/document-generator/history') ?>"><span>История документов</span></a>
                </div>
                <div class="nav-item is-parent<?= $directoriesOpen ? ' is-open' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none">
                        <rect x="1.5" y="5" width="13" height="9.5" rx="1" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M5 5V3.5C5 2.9 5.4 2.5 6 2.5H10C10.6 2.5 11 2.9 11 3.5V5" stroke="currentColor" stroke-width="1.4"/>
                        <path d="M1.5 9H14.5" stroke="currentColor" stroke-width="1.4"/>
                        <rect x="6.5" y="6.5" width="3" height="3" rx=".5" stroke="currentColor" stroke-width="1.2"/>
                    </svg>
                    <span class="nav-label">Справочники</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $directoriesOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $contractorsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/contractors') ?>">
                        <span>Подрядчики</span>
                    </a>
                    <a class="nav-sub-item<?= $driversActive ? ' is-active' : '' ?>" href="<?= app_url('/company/drivers') ?>">
                        <span>Водители</span>
                    </a>
                    <a class="nav-sub-item<?= $vehicleSetsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/vehicle-sets') ?>">
                        <span>ТС</span>
                    </a>
                </div>
                <div class="nav-item is-parent<?= $statisticsOpen ? ' is-open is-active' : '' ?>">
                    <svg class="nav-icon" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 4h4l1.5 2H14v7H2V4Z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                    <span class="nav-label">Статистика</span>
                    <svg class="nav-chevron" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M5 3L9 7L5 11" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="nav-sub<?= $statisticsOpen ? ' is-open' : '' ?>">
                    <a class="nav-sub-item<?= $managerStatisticsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/statistics/managers') ?>"><span>Работа логистов</span></a>
                    <a class="nav-sub-item<?= $feoShareStatisticsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/statistics/feo-share') ?>"><span>Доля компаний в ФЭО</span></a>
                    <a class="nav-sub-item<?= $cargoBalanceStatisticsActive ? ' is-active' : '' ?>" href="<?= app_url('/company/statistics/cargo-balance') ?>"><span>Баланс заявок</span></a>
                </div>
            </div>

            <?php endif; ?>

        </aside>

        <main class="content<?= $wideWorkspaceActive ? ' content--wide-workspace' : '' ?>">
            <?php require base_path('app/View/partials/trip_document_return.php'); ?>
            <?php if ($wideWorkspaceActive): ?>
            <div class="workspace-shell-1690">
                <?= $content ?>
            </div>
            <?php else: ?>
            <?= $content ?>
            <?php endif; ?>
        </main>
    </div>

    <script src="<?= app_url('/assets/js/csrf-fetch.js') ?>?v=<?= filemtime(base_path('public/assets/js/csrf-fetch.js')) ?>"></script>
    <script src="<?= app_url('/assets/js/modal-shell.js') ?>?v=<?= filemtime(base_path('public/assets/js/modal-shell.js')) ?>"></script>
    <script src="<?= app_url('/assets/js/contact-fields.js') ?>?v=<?= filemtime(base_path('public/assets/js/contact-fields.js')) ?>"></script>
    <script src="<?= app_url('/assets/js/legal-entity-documents.js') ?>?v=<?= filemtime(base_path('public/assets/js/legal-entity-documents.js')) ?>"></script>
    <script src="<?= app_url('/assets/js/legal-entity-inn.js') ?>?v=<?= filemtime(base_path('public/assets/js/legal-entity-inn.js')) ?>"></script>
    <script src="<?= app_url('/assets/js/legal-entity-modal.js') ?>?v=<?= filemtime(base_path('public/assets/js/legal-entity-modal.js')) ?>"></script>
    <script src="<?= app_url('/assets/js/app.js') ?>?v=<?= filemtime(base_path('public/assets/js/app.js')) ?>"></script>
    <script src="<?= app_url('/assets/js/entity-assignments.js') ?>?v=<?= filemtime(base_path('public/assets/js/entity-assignments.js')) ?>"></script>
    <?php foreach (($pageScripts ?? []) as $pageScript):
        $pageScript = (string)$pageScript;
        $pageScriptFile = base_path('public/' . ltrim($pageScript, '/'));
        if (!preg_match('~^/assets/[a-z0-9_./-]+\.js$~i', $pageScript) || !is_file($pageScriptFile)) continue;
    ?>
    <script src="<?= e(app_url($pageScript)) ?>?v=<?= filemtime($pageScriptFile) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
