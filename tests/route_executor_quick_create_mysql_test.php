<?php

declare(strict_types=1);

use App\Core\Database;
use App\Security\AccessRequestContext;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;
use App\Security\PdoAccessStateProvider;
use App\Service\CompanyRoleService;
use App\Service\ContractorService;
use App\Service\DriverService;
use App\Service\LocalMigrationService;
use App\Service\RouteExecutorQuickCreateService;

$root = dirname(__DIR__);
require_once $root . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/entrypoint_dependencies.php';
require_once BASE_PATH . '/app/Service/CompanyRoleService.php';
require_once BASE_PATH . '/app/Service/RouteExecutorQuickCreateService.php';

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dbName = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($dbName === '' || !in_array($dbName, $allow, true) || (string)getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Guarded fixed runner DB is required.');
}

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$ok = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

cleanCompanyPoolDatabase($pdo);
try {
    LocalMigrationService::apply($pdo);
    $pdo->exec("CREATE TABLE companies(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),status VARCHAR(20)) ENGINE=InnoDB");
    $pdo->exec((string)file_get_contents(BASE_PATH . '/database/migrations/013_create_roles_access.sql'));
    $pdo->exec("INSERT INTO companies VALUES(7,'Quick create QA','active')");
    $hash = str_repeat('b', 64);
    $ready = $pdo->prepare("INSERT INTO company_access_readiness(company_id,state,before_sha256,after_sha256,comparison_sha256,verified_at,activated_at) VALUES(7,'ready',?,?,?,NOW(),NOW())");
    $ready->execute([$hash, $hash, $hash]);
    $pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,system_key,is_system) VALUES(1,7,'Руководитель','owner','company_owner',1)");
    $pdo->exec("INSERT INTO role_page_permissions(role_id,page_key,is_allowed) VALUES(1,'company.route_executors',1)");
    $scope = $pdo->prepare('INSERT INTO role_data_scopes(role_id,entity_type,scope) VALUES(1,?,\'all\')');
    foreach (CompanyRoleService::regulatedEntities() as $type) $scope->execute([$type]);
    $pdo->exec("INSERT INTO user_accounts(id,full_name,login,normalized_login,password_hash,status) VALUES(101,'Quick Owner','quick-owner','quick-owner','x','active')");
    $pdo->exec("INSERT INTO company_memberships(id,account_id,company_id,role_id,status) VALUES(11,101,7,1,'active')");
    $pdo->exec("INSERT INTO users(id,membership_id,full_name,login,password_hash,role_code,status) VALUES(201,11,'Quick Owner','quick-owner','x','company_owner','active')");

    $access = (new EffectiveAccessResolver(new PageRegistry(), new PdoAccessStateProvider($pdo)))
        ->resolve(new AccessRequestContext(101, 7));
    AccessRuntime::setCurrent($access);
    $_SESSION['user_id'] = 201;
    $_SESSION['role_code'] = 'company_owner';

    $database = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
    $contractorService = new ContractorService([], $database);
    $driverService = new DriverService([], $database);

    $newInput = [
        'contractor' => [
            'name' => 'ООО Быстрый подрядчик',
            'inn' => '7701234567',
            'kpp' => '770101001',
            'ogrn' => '1027700123456',
            'contractor_type' => 'legal_entity',
            'contacts' => [
                ['contact_person' => 'Анна', 'phone' => '+7 900 111-22-33', 'email' => 'docs@quick.test', 'comment' => 'Документы', 'is_primary' => '1', 'is_document_email' => '1'],
                ['contact_person' => 'Игорь', 'phone' => '+7 900 111-22-44', 'email' => 'dispatch@quick.test', 'comment' => 'Диспетчер'],
            ],
        ],
        'driver' => [
            'full_name' => 'Иванов Иван Иванович',
            'phone' => '+7 900 000-00-01',
            'passport_number' => '4500 123456',
            'license_number' => '7700 123456',
            'passport_department_code' => '770-001',
            'passport_issue_date' => '01.02.2020',
            'license_issue_date' => '03.04.2021',
            'snils' => '123-456-789 01',
            'extra_phones' => ['+7 900 000-00-11'],
            'extra_phone_comments' => ['Запасной'],
        ],
        'vehicle' => [
            'set_type' => 'coupling',
            'primary' => ['plate_number' => 'А001АА77', 'brand' => 'КамАЗ', 'model' => '5490', 'vin' => '', 'capacity_tons' => '', 'volume_m3' => '', 'diagnostic_card_number' => '123456789012345', 'diagnostic_card_date' => '05.06.2025'],
            'secondary' => ['plate_number' => 'В002ВВ77', 'brand' => 'Тонар', 'model' => '', 'vin' => '', 'capacity_tons' => '20', 'volume_m3' => '82'],
        ],
    ];
    $first = RouteExecutorQuickCreateService::create($pdo, 7, $newInput, [], 'new_contractor', 201, 'company_owner', $contractorService, $driverService);
    $ok($first['crew_id'] > 0, 'New-contractor flow did not create a route executor.');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn() === 1, 'New contractor was not created exactly once.');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM vehicle_units')->fetchColumn() === 2, 'Coupling must create two vehicle units.');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM crew_drivers')->fetchColumn() === 1, 'Primary crew driver relation is missing.');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM contractor_contacts')->fetchColumn() === 2, 'Additional contractor contacts were not stored.');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM contractor_contacts WHERE is_primary=1 AND is_document_email=1")->fetchColumn() === 1, 'Primary/document contractor contact flags were not stored.');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM driver_phones WHERE is_main=0')->fetchColumn() === 1, 'Additional driver phone was not stored.');
    $ok((string)$pdo->query('SELECT comment FROM driver_phones WHERE is_main=0 LIMIT 1')->fetchColumn() === 'Запасной', 'Additional driver phone comment was not stored.');
    $ok((string)$pdo->query("SELECT diagnostic_card_number FROM vehicle_units WHERE plate_number='А001АА77'")->fetchColumn() === '123456789012345', 'Vehicle diagnostic card number was not stored.');
    $ok((string)$pdo->query("SELECT diagnostic_card_date FROM vehicle_units WHERE plate_number='А001АА77'")->fetchColumn() === '2025-06-05', 'Vehicle diagnostic card date was not normalized and stored.');
    $ok((int)$pdo->query("SELECT COUNT(*) FROM entity_assignments WHERE membership_id=11 AND assignment_kind='creator_auto'")->fetchColumn() === 4, 'Creator assignments for contractor, driver and two units are incomplete.');

    $existingInput = [
        'contractor_id' => $first['contractor_id'],
        'driver' => ['full_name' => 'Петров Петр Петрович', 'phone' => '+7 900 000-00-02'],
        'vehicle' => [
            'set_type' => 'single',
            'primary' => ['plate_number' => 'С003СС77', 'brand' => 'ГАЗ', 'model' => 'Next', 'vin' => '', 'capacity_tons' => '3.5', 'volume_m3' => '18'],
            'secondary' => [],
        ],
    ];
    $second = RouteExecutorQuickCreateService::create($pdo, 7, $existingInput, [], 'existing_contractor', 201, 'company_owner', $contractorService, $driverService);
    $ok($second['crew_id'] > $first['crew_id'], 'Existing-contractor flow did not create a second route executor.');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM contractors')->fetchColumn() === 1, 'Existing-contractor flow created a duplicate contractor.');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM drivers')->fetchColumn() === 2, 'Both flows must create one new driver each.');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM crews')->fetchColumn() === 2, 'Both quick-create flows must create crews.');

    echo "ROUTE_EXECUTOR_QUICK_CREATE_MYSQL=PASS\n";
} finally {
    AccessRuntime::clear();
    cleanCompanyPoolDatabase($pdo);
}
