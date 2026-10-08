<?php
declare(strict_types=1);

use App\Http\Controllers\Company\WasteTripController;
use App\Service\WasteTripCustomerPaymentService;

$root = dirname(__DIR__);
require_once $root . '/app/Service/DateCalculationService.php';
require_once $root . '/app/Service/LinearRouteService.php';
require_once $root . '/app/Service/WasteTripCustomerPaymentService.php';
require_once $root . '/app/Http/Controllers/Company/WasteTripController.php';
require_once $root . '/app/Support/company_database.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$ok = static function (bool $value, string $message) use ($fail): void {
    if (!$value) $fail($message);
};

$migration = (string) file_get_contents($root . '/database/migrations-local/134_create_waste_trip_customer_payments.sql');
$controller = (string) file_get_contents($root . '/app/Http/Controllers/Company/WasteTripController.php');
$page = (string) file_get_contents($root . '/app/View/pages/company_waste_trips.php');
$viewModal = (string) file_get_contents($root . '/app/View/partials/company_waste_trip_modal_view.php');
$paymentPartial = (string) file_get_contents($root . '/app/View/partials/company_waste_trip_customer_payments.php');
$routeBootstrap = (string) file_get_contents($root . '/app/Http/Routes/company_linear_trips.php');

foreach ([
    'CREATE TABLE IF NOT EXISTS `waste_trip_customer_payments`',
    '`waste_trip_id` BIGINT UNSIGNED NOT NULL',
    '`amount` DECIMAL(12,2) NOT NULL',
    '`condition_type` VARCHAR(50) NOT NULL',
    'FOREIGN KEY (`waste_trip_id`) REFERENCES `waste_trips` (`id`) ON DELETE RESTRICT',
] as $needle) $ok(str_contains($migration, $needle), 'migration contract missing ' . $needle);
foreach (['customerPaymentsForSession', "==='company_owner'", 'WasteTripCustomerPaymentService::parseRows', '$isFinanceRealm'] as $needle) {
    $ok(str_contains($controller, $needle), 'finance role gate missing ' . $needle);
}
foreach (['ПРОДАЖА', 'СЕБЕС, РУБ', 'customer_payments', 'company_waste_trip_customer_payments.php', 'company_waste_trip_carrier_payments.php'] as $needle) {
    $ok(str_contains($page, $needle), 'registry/create UI missing ' . $needle);
}
foreach (['Продажа · оплата клиента', 'WasteTripCustomerPaymentService::total', 'WasteTripCustomerPaymentService::detail'] as $needle) {
    $ok(str_contains($viewModal, $needle), 'view modal finance block missing ' . $needle);
}
foreach (['data-payment-container="customer_payments"', 'data-add-payment-row="customer_payments"', 'Срок оплаты', 'Способ оплаты', 'НДС'] as $needle) {
    $ok(str_contains($paymentPartial, $needle), 'shared payment form missing ' . $needle);
}
$ok(str_contains($paymentPartial, 'data-default-days="4" data-default-days-kind="working"') && str_contains($paymentPartial, '4 рабочих дня после выгрузки'), 'new customer form default and guidance');
$ok(
    str_contains($routeBootstrap, "require_once base_path('app/Service/WasteTripCustomerPaymentService.php');"),
    'Waste Trip route bootstrap must load customer payment service before owner registry finance access'
);

$validRows = [[
    'amount' => '125 000',
    'payment_method' => 'cashless',
    'vat_rate' => '',
    'condition_type' => 'after_end',
    'days_count' => '5',
    'days_kind' => 'calendar',
    'specific_due_date' => '',
    'condition_comment' => 'По оригиналам документов',
]];
$parsed = WasteTripCustomerPaymentService::parseRows($validRows, true);
$defaultParsed = WasteTripCustomerPaymentService::parseRows([['amount'=>'125000']], true);
$ok($defaultParsed[0]['condition_type']==='after_end' && $defaultParsed[0]['days_count']===4 && $defaultParsed[0]['days_kind']==='working', 'new customer row defaults to four working days');
$ok(count($parsed) === 1, 'valid payment row count');
$ok($parsed[0]['amount'] === '125000.00', 'amount normalization');
$ok($parsed[0]['condition_type'] === 'after_end' && $parsed[0]['days_count'] === 5 && $parsed[0]['days_kind'] === 'calendar', 'payment term normalization');
$ok(WasteTripCustomerPaymentService::total($parsed) === '125 000 ₽', 'customer total format');
$ok(str_contains(WasteTripCustomerPaymentService::detail($parsed[0]), '5 БД'), 'payment detail must show calendar-day term');

$invalid = false;
try {
    WasteTripCustomerPaymentService::parseRows([], true);
} catch (RuntimeException $e) {
    $invalid = str_contains($e->getMessage(), 'Добавьте ставку заказчика');
}
$ok($invalid, 'company owner must not save an empty customer rate');

$ref = new ReflectionClass(WasteTripController::class);
$controllerObject = $ref->newInstanceWithoutConstructor();
$gate = $ref->getMethod('customerPaymentsForSession');
$logistResult = $gate->invoke($controllerObject, ['customer_payments' => $validRows], ['role_code' => 'logist']);
$ok($logistResult === null, 'logist POST must not mutate customer finance');
$ownerResult = $gate->invoke($controllerObject, ['customer_payments' => $validRows], ['role_code' => 'company_owner']);
$ok(is_array($ownerResult) && ($ownerResult[0]['amount'] ?? null) === '125000.00', 'company owner finance parsing failed');
echo "WASTE_TRIP_CUSTOMER_PAYMENT_STATIC=PASS\n";

if ((string) (getenv('PLANEX_SKIP_MYSQL') ?: '') === '1') exit(0);

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "pdo_mysql required for Waste Trip customer payment MySQL regression\n");
    exit(2);
}
$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$fixedDb = (string) (getenv('PLANEX_TEST_FIXED_DB') ?: '');
$fixedMode = $fixedDb !== '';
if ($fixedMode) {
    $allow = array_values(array_filter(array_map('trim', explode(',', (string) (getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST') ?: '')))));
    $ok((string) (getenv('APP_ENV') ?: '') === 'test', 'fixed DB requires APP_ENV=test');
    $ok(in_array($fixedDb, $allow, true), 'fixed DB is not OWNER-approved');
}
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$dbName = $fixedMode ? $fixedDb : 'planex_waste_customer_payment_' . getmypid();
if ($fixedMode) {
    $cleanup = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    cleanCompanyPoolDatabase($cleanup);
    $cleanup = null;
} else {
    $admin->exec("DROP DATABASE IF EXISTS `{$dbName}`; CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec('CREATE TABLE waste_trips (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY(id)) ENGINE=InnoDB');
    $pdo->exec($migration);
    $pdo->exec('INSERT INTO waste_trips VALUES (1)');
    $ctx = ['user_id' => 77, 'role_code' => 'company_owner'];
    WasteTripCustomerPaymentService::store($pdo, 1, $parsed, $ctx);
    $stored = WasteTripCustomerPaymentService::fetchForTrip($pdo, 1);
    $ok(count($stored) === 1, 'stored payment row missing');
    $ok((string) $stored[0]['amount'] === '125000.00', 'stored amount mismatch');
    $ok((string) $stored[0]['condition_type'] === 'after_end' && (int) $stored[0]['days_count'] === 5, 'stored payment term mismatch');
    $ok((int) $stored[0]['created_by_user_id'] === 77 && (string) $stored[0]['created_by_role'] === 'company_owner', 'payment audit actor mismatch');

    $replacement = WasteTripCustomerPaymentService::parseRows([[
        'amount' => '140000',
        'payment_method' => 'cashless',
        'vat_rate' => '20',
        'condition_type' => 'specific_date',
        'specific_due_date' => '30.09.2026',
        'condition_comment' => '',
    ]], true);
    WasteTripCustomerPaymentService::store($pdo, 1, $replacement, $ctx);
    $stored = WasteTripCustomerPaymentService::fetchForTrip($pdo, 1);
    $ok(count($stored) === 1 && (string) $stored[0]['amount'] === '140000.00', 'edit must replace prior customer payment rows atomically');
    $ok((string) $stored[0]['specific_due_date'] === '2026-09-30', 'specific due date normalization/persistence');
    $grouped = WasteTripCustomerPaymentService::fetchForTrips($pdo, [1]);
    $ok(count($grouped[1] ?? []) === 1, 'registry batch finance loading failed');
    echo "WASTE_TRIP_CUSTOMER_PAYMENT_MYSQL=PASS\n";
} finally {
    if ($fixedMode) {
        $cleanup = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        cleanCompanyPoolDatabase($cleanup);
    } else {
        $admin->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    }
}
