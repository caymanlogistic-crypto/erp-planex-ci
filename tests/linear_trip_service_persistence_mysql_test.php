<?php

namespace App\Service {
    // Persistence regression isolates the service-line storage contract from finance allocation tables.
    // The production finance sync has its own regression suite; here we assert service/payment CRUD identity.
    final class LinearTripServiceFinanceService
    {
        public static function syncRoute(\PDO $pdo, int $routeId): void
        {
        }
    }
}

namespace {
    require_once __DIR__ . '/../app/Service/DateCalculationService.php';
    require_once __DIR__ . '/../app/Service/RoutePaymentStatusService.php';
    require_once __DIR__ . '/../app/Service/LinearRouteService.php';
    require_once __DIR__ . '/../app/Service/LinearTripServiceLineService.php';

    use App\Service\LinearTripServiceLineService;

    $host = getenv('PLANEX_TEST_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('PLANEX_TEST_MYSQL_PORT') ?: '3306';
    $user = getenv('PLANEX_TEST_MYSQL_USER') ?: 'root';
    $pass = getenv('PLANEX_TEST_MYSQL_PASSWORD') ?: 'root';
    $dbName = 'planex_linear_services_regression';

    $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $server->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec((string) file_get_contents(__DIR__ . '/../database/migrations-local/120_create_linear_route_services.sql'));
    $pdo->exec('CREATE TABLE contractors (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(190) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec("INSERT INTO contractors (id,name) VALUES (77,'Кран-Сервис')");

    $assert = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $userContext = ['user_id' => 501, 'role_code' => 'company_owner'];
    $dates = [
        'planned_loading_date' => '2026-08-10',
        'planned_unloading_date' => '2026-08-20',
        'actual_loading_date' => '2026-08-10',
        'actual_unloading_date' => '2026-08-20',
    ];

    $create = [[
        'id' => 0,
        'contractor_id' => 77,
        'service_name' => 'Кран',
        'description' => '25 тонн, 4 часа',
        'payments' => [
            [
                'id' => 0,
                'amount' => '12500.00',
                'payment_type' => 'Без НДС',
                'payment_method' => 'cashless',
                'vat_rate' => null,
                'condition_type' => 'after_end',
                'days_count' => 5,
                'days_kind' => 'working',
                'specific_due_date' => null,
                'condition_comment' => 'После выгрузки',
            ],
            [
                'id' => 0,
                'amount' => '2500.00',
                'payment_type' => 'Без НДС',
                'payment_method' => 'cashless',
                'vat_rate' => null,
                'condition_type' => 'specific_date',
                'days_count' => null,
                'days_kind' => null,
                'specific_due_date' => '2026-09-01',
                'condition_comment' => null,
            ],
        ],
    ]];

    LinearTripServiceLineService::replaceForRoute($pdo, 42, $create, $userContext, $dates, true);

    $service = $pdo->query('SELECT * FROM linear_route_services WHERE linear_route_id=42 AND deleted_at IS NULL')->fetch();
    $assert(is_array($service), 'service must be inserted');
    $serviceId = (int) $service['id'];
    $assert($service['service_name'] === 'Кран', 'service name must persist');
    $assert($service['description'] === '25 тонн, 4 часа', 'service description must persist');

    $payments = $pdo->query("SELECT * FROM linear_route_service_payments WHERE linear_route_service_id={$serviceId} AND deleted_at IS NULL ORDER BY sort_order,id")->fetchAll();
    $assert(count($payments) === 2, 'two service payments must persist');
    $firstPaymentId = (int) $payments[0]['id'];
    $secondPaymentId = (int) $payments[1]['id'];
    $assert((int) $payments[0]['days_count'] === 5 && $payments[0]['days_kind'] === 'working', 'days count/kind must persist');
    $assert($payments[0]['calculated_due_date'] !== null, 'calculated due date must be stored');
    $assert($payments[1]['specific_due_date'] === '2026-09-01', 'specific due date must persist');

    $edit = [[
        'id' => $serviceId,
        'contractor_id' => 77,
        'service_name' => 'Кран-манипулятор',
        'description' => '25 тонн, 5 часов',
        'payments' => [[
            'id' => $firstPaymentId,
            'amount' => '15000.00',
            'payment_type' => 'Без НДС',
            'payment_method' => 'cashless',
            'vat_rate' => null,
            'condition_type' => 'after_end',
            'days_count' => 3,
            'days_kind' => 'calendar',
            'specific_due_date' => null,
            'condition_comment' => 'Изменено',
        ]],
    ]];

    LinearTripServiceLineService::replaceForRoute($pdo, 42, $edit, $userContext, $dates, true);

    $serviceAfterEdit = $pdo->query("SELECT * FROM linear_route_services WHERE id={$serviceId}")->fetch();
    $assert($serviceAfterEdit['service_name'] === 'Кран-манипулятор', 'service edit must update the existing row');
    $assert($serviceAfterEdit['description'] === '25 тонн, 5 часов', 'description edit must persist');

    $firstAfterEdit = $pdo->query("SELECT * FROM linear_route_service_payments WHERE id={$firstPaymentId}")->fetch();
    $assert((int) $firstAfterEdit['id'] === $firstPaymentId, 'edited payment identity must remain stable');
    $assert($firstAfterEdit['amount'] === '15000.00', 'edited payment amount must persist');
    $assert((int) $firstAfterEdit['days_count'] === 3 && $firstAfterEdit['days_kind'] === 'calendar', 'edited due fields must persist');

    $secondAfterEdit = $pdo->query("SELECT * FROM linear_route_service_payments WHERE id={$secondPaymentId}")->fetch();
    $assert($secondAfterEdit['deleted_at'] !== null, 'removed payment must be soft-deleted');

    $fetched = LinearTripServiceLineService::fetchByRouteIds($pdo, [42], true);
    $assert(count($fetched[42] ?? []) === 1, 'service must reopen through fetchByRouteIds');
    $assert(($fetched[42][0]['service_name'] ?? '') === 'Кран-манипулятор', 'reopened service must contain edited data');
    $assert(count($fetched[42][0]['payments'] ?? []) === 1, 'reopened service must contain the active payment only');
    $assert((int) ($fetched[42][0]['payments'][0]['id'] ?? 0) === $firstPaymentId, 'reopened payment must keep its ID');

    LinearTripServiceLineService::replaceForRoute($pdo, 42, [], $userContext, $dates, true);
    $deletedService = $pdo->query("SELECT * FROM linear_route_services WHERE id={$serviceId}")->fetch();
    $assert($deletedService['deleted_at'] !== null, 'removed service must be soft-deleted');
    $activePayments = (int) $pdo->query("SELECT COUNT(*) FROM linear_route_service_payments WHERE linear_route_service_id={$serviceId} AND deleted_at IS NULL")->fetchColumn();
    $assert($activePayments === 0, 'removing service must leave no active service payments');

    $server->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    fwrite(STDOUT, "linear_trip_service_persistence_mysql: PASS\n");
}
