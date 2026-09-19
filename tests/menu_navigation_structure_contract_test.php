<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Security/PageRegistry.php';
$fail = static fn(string $message): never => throw new RuntimeException($message);
$ok = static function (bool $value, string $message) use ($fail): void { if (!$value) $fail($message); };

$registry = new App\Security\PageRegistry();
$navKeys = array_column($registry->navigationPages(), 'key');
$grantableKeys = $registry->grantablePageKeys();

foreach (['company.max_messages','company.finance.cash','company.finance.operations'] as $key) {
    $ok(!in_array($key, $navKeys, true), $key . ' must be hidden from navigation');
}
$ok(!in_array('company.max_messages', $grantableKeys, true), 'MAX messages must stay owner-only/non-grantable');
foreach (['company.finance.cash','company.finance.operations'] as $key) {
    $ok(in_array($key, $grantableKeys, true), $key . ' must remain manageable in roles-access');
    $ok(($registry->page($key)['nav_visible'] ?? true) === false, $key . ' hidden flag missing');
}

$calendar = $registry->page('company.production_calendar');
$ok(($calendar['section'] ?? '') === 'Настройки', 'production calendar must be in Settings');
$ok(!isset($registry->navigationTree()['Прочее']), 'Прочее section must not exist');

$feo = $registry->navigationTree()['Операции']['Заявки ФЭО'] ?? [];
$feoKeys = array_column($feo, 'key');
$placementIndex = array_search('company.feo.map_placement', $feoKeys, true);
$costIndex = array_search('company.feo.costs', $feoKeys, true);
$ok(is_int($placementIndex) && $costIndex === $placementIndex + 1, 'request costs must follow map placement');

$js = (string)file_get_contents($root . '/public/assets/js/roles-access.js');
$layout = (string)file_get_contents($root . '/app/View/layouts/main.php');
$ok(str_contains($js, 'Скрыто из меню') && str_contains($js, 'page.nav_visible === false'), 'roles-access hidden menu marker missing');
$ok(!str_contains($layout, '<span class="nav-label">Сообщения MAX</span>'), 'fallback MAX messages link remains');
$ok(!str_contains($layout, '<span class="nav-label">Касса</span>'), 'fallback cash link remains');
$ok(!str_contains($layout, '<span class="nav-label">Операции</span>'), 'fallback finance operations link remains');
$ok(str_contains($layout, '<div class="nav-section-label">НАСТРОЙКИ</div>') && str_contains($layout, '<span class="nav-label">Производственный календарь</span>'), 'fallback Settings calendar missing');

echo "MENU_NAVIGATION_STRUCTURE_CONTRACT=PASS\n";
