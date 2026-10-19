<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/app.php';
require BASE_PATH . '/app/Support/entrypoint_dependencies.php';
$kind = $argv[1] ?? 'contractors';
$state = $argv[2] ?? 'populated';
$role = $argv[3] ?? 'company_owner';
$_SERVER['REQUEST_URI'] = '/company/' . $kind;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SESSION = ['role_code' => $role, 'company_id' => 7, 'user_id' => 11, 'account_id' => 3, 'user_name' => 'Тестовый пользователь'];
$_SESSION['membership_id'] = $role === 'company_owner' ? 11 : 12;
\App\Security\AccessRuntime::setCurrent($role === 'company_owner'
    ? \App\Security\EffectiveAccess::systemOwner(11, 7, array_column(\App\Security\PageRegistry::companyPages(), 'key'))
    : \App\Security\EffectiveAccess::member(12, 7, ['company.contractors'=>true,'company.vehicle_sets'=>true], ['contractor'=>'own','vehicle_unit'=>'own'], 'logist'));
$company = ['name' => 'ООО «ПЛАНЭКС»', 'status' => $state === 'inactive' ? 'blocked' : 'active'];
$db = new class([]) extends \App\Core\Database { public function connection(): PDO { throw new RuntimeException('Fixture has no database'); } };
$pageTitle = $kind === 'contractors' ? 'Перевозчики' : 'Транспорт';
$pageContext = $pageTitle . ' › Компания';
$pageStylesheets = ['/assets/css/registry-components.css', '/assets/css/' . ($kind === 'contractors' ? 'contractors-registry.css' : 'vehicle-sets-registry.css')];
$pageScripts = ['/assets/js/table-reference-select.js', '/assets/js/registry-components.js'];
$contractors = [];
$vehicleSets = [];
$names = ['ООО «Восточный маршрут»', 'ИП Крылов', 'ТК «Северный ветер»'];
for ($i = 0; $i < 35; $i++) {
    $contractors[] = [
        'id' => 40 + $i, 'name' => $names[$i % 3], 'inn' => '7701234567',
        'contractor_type' => ['legal_entity', 'individual_entrepreneur', 'individual'][$i % 3], 'primary_contact_person' => $i === 1 ? '' : 'Мария Волкова',
        'primary_contact_phone' => $i === 1 ? '' : '+7 900 123-45-67',
        'doc_email' => 'contact@example.test', 'created_by_role' => 'company_owner',
        'created_by_user_id' => 11, 'created_by_name' => 'Анна Петрова',
        'status' => $i === 1 ? 'inactive' : 'active',
    ];
    $vehicleSets[] = [
        'id' => 40 + $i, 'set_type' => $i % 2 ? 'single' : 'coupling',
        'primary_brand' => 'КамАЗ', 'primary_model' => '5490', 'primary_plate' => 'А123АА77',
        'primary_volume_m3' => $i === 1 ? null : '88.5', 'primary_capacity_tons' => '20.0',
        'has_sts' => $i % 2, 'has_diagnostic_card_doc' => ($i + 1) % 2, 'has_photo' => $i % 2,
        'secondary_vehicle_unit_id' => $i % 2 ? null : 200 + $i,
        'secondary_brand' => $i === 1 ? 'СКРЫТАЯ-МАРКА-ЕДИНИЦЫ' : 'Тонар', 'secondary_model' => '9888', 'secondary_plate' => 'В456ВВ77',
        'secondary_volume_m3' => '90.0', 'secondary_capacity_tons' => '25.0',
        'has_sts_sec' => ($i + 1) % 2, 'has_diagnostic_card_doc_sec' => $i % 2, 'has_photo_sec' => ($i + 1) % 2,
    ];
}
if ($state === 'empty') { $contractors = []; $vehicleSets = []; }
if ($state === 'error') $dbError = 'Не удалось подключиться к базе данных компании.';
ob_start();
require BASE_PATH . '/app/View/pages/company_' . ($kind === 'contractors' ? 'contractors' : 'vehicle_sets') . '.php';
$content = (string)ob_get_clean();
require BASE_PATH . '/app/View/layouts/main.php';
