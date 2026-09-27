<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root.'/app/Security/PageRegistry.php';
$registry = new App\Security\PageRegistry();
$page = $registry->page('company.statistics.cargo_balance');
if (($page['label'] ?? '') !== 'Баланс заявок'
    || ($page['section'] ?? '') !== 'Операции'
    || ($page['group'] ?? '') !== 'Статистика'
    || ($page['path'] ?? '') !== '/company/statistics/cargo-balance'
    || !in_array('company.statistics.cargo_balance', $registry->grantablePageKeys(), true)) {
    throw new RuntimeException('Cargo balance role and navigation registration failed');
}
$service = (string)file_get_contents($root.'/app/Service/CargoBalanceStatisticsService.php');
$js = (string)file_get_contents($root.'/public/assets/js/cargo-balance-statistics.js');
$view = (string)file_get_contents($root.'/app/View/pages/company_cargo_balance_statistics.php');
if (!str_contains($service, "wt.status IN ('in_progress','completed')")
    || !str_contains($service, 'wt.deleted_at IS NULL')
    || !str_contains($service, 'GROUP BY fr.zayavka_id')
    || !str_contains($service, 'feo_placement_current')) {
    throw new RuntimeException('Cargo balance source or distinct started requests changed');
}
if (!str_contains($js, 'fmt(r.available-r.carried)')
    || !str_contains($js, 'Без движения')
    || str_contains($js, 'Реальные данные · срез БД')) {
    throw new RuntimeException('Cargo balance approved display changed');
}
if (!str_contains($view, 'cargo-balance-data')) throw new RuntimeException('Cargo balance data binding missing');
echo "cargo balance statistics contract: PASS\n";
