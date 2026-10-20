<?php
declare(strict_types=1);

$config = require dirname(__DIR__, 2) . '/bootstrap/app.php';
require BASE_PATH . '/app/Support/entrypoint_dependencies.php';

$state = $argv[1] ?? 'populated';
$_SERVER['REQUEST_URI'] = '/company/feo/requests/map-placement';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SESSION = ['role_code' => 'company_owner', 'company_id' => 7, 'user_id' => 11, 'account_id' => 3, 'user_name' => 'Тестовый пользователь', 'membership_id' => 11];
\App\Security\AccessRuntime::setCurrent(\App\Security\EffectiveAccess::systemOwner(11, 7, array_column(\App\Security\PageRegistry::companyPages(), 'key')));

$company = ['id' => 7, 'name' => 'ООО «ПЛАНЭКС»', 'status' => 'active'];
$db = new class([]) extends \App\Core\Database { public function connection(): PDO { throw new RuntimeException('Fixture has no database'); } };
$pageTitle = 'Размещение на карте';
$pageContext = 'Заявки ФЭО › Размещение на карте';
$pageStylesheets = ['/assets/css/registry-components.css'];
$pageScripts = ['/assets/js/registry-components.js'];
$dbError = $state === 'error' ? 'Проверочная ошибка загрузки.' : null;
$successFlash = null;
$errorFlash = null;
$consistency = ['is_consistent' => true, 'actual_count' => 3, 'expected_count' => 3];
$legacyParity = ['available' => true, 'mismatch_count' => 0];
$current = ['request_count' => 3, 'total_mass_kg' => 1850.5];
$blocks = [
    ['id' => 74, 'name' => 'Площадка Сосновый Бор — южный сектор', 'block_type' => 'addition', 'request_count' => 2, 'total_mass_kg' => 1230.5, 'source_type' => 'manual', 'request_ids_text' => '302545, 285664', 'created_at' => '2026-10-17 10:45:00', 'created_by_login' => 'owner', 'created_by_display_name' => 'Анна П.', 'status' => 'applied'],
    ['id' => 75, 'name' => 'Корректировка по улице Заводской', 'block_type' => 'exclusion', 'request_count' => 1, 'total_mass_kg' => 380, 'source_type' => 'xlsx', 'source_filename' => 'исключение-заводская.xlsx', 'created_at' => '2026-10-18 09:20:00', 'created_by_login' => 'owner', 'created_by_display_name' => 'Анна П.', 'status' => 'applied'],
    ['id' => 76, 'name' => 'Резервный перечень', 'block_type' => 'addition', 'request_count' => 1, 'total_mass_kg' => 620, 'source_type' => 'manual', 'request_ids_text' => '281861', 'created_at' => '2026-10-18 13:05:00', 'created_by_login' => 'logist', 'created_by_display_name' => 'Иван К.', 'status' => 'disabled'],
];
$locations = ['Северная промзона', 'Улица Лесная', 'Площадка Заречная', 'Квартал Восточный', 'Проезд Строителей', 'Складской тупик', 'Посёлок Озёрный', 'Проспект Мира'];
$longName = 'Северная промзона — дополнительный блок размещения с длинным названием для проверки полного текста';
$manyIds = implode(', ', array_map(static fn(int $n): string => (string)(310000 + $n), range(1, 50)));
$longFile = '1 - 28.06 - финал по заявкам Мурманск Карелия Архангельск МГ Логистик корректировка 3 (1).xlsx';
for ($i = 0; $i < 67; $i++) {
    $blocks[] = ['id' => 77 + $i, 'name' => $i === 0 ? $longName : $locations[$i % count($locations)] . ' · ' . ['утренний вывоз', 'обновление адреса', 'возврат в резерв'][$i % 3],
        'block_type' => $i % 5 === 0 ? 'exclusion' : 'addition', 'request_count' => 1 + $i % 3, 'total_mass_kg' => 320 + 14.5 * $i,
        'source_type' => $i === 1 ? 'xlsx' : 'manual', 'source_filename' => $i === 1 ? $longFile : null, 'request_ids_text' => $i === 0 ? $manyIds : (string)(330001 + $i), 'created_at' => '2026-10-16 11:30:00',
        'created_by_login' => 'logist', 'created_by_display_name' => 'Иван К.', 'status' => $i % 7 === 0 ? 'disabled' : 'applied'];
}
if ($state === 'empty') $blocks = [];

ob_start();
require BASE_PATH . '/app/View/pages/company_feo_map_placement.php';
$content = (string) ob_get_clean();
require BASE_PATH . '/app/View/layouts/main.php';
