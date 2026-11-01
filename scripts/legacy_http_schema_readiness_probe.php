<?php
declare(strict_types=1);

/** Metadata-only prerequisites for legacy HTTP DDL removal; no business rows or writes. */
final class LegacyHttpSchemaReadinessProbe
{
    private const COLUMNS = [
        'users' => ['id', 'created_by_user_id', 'created_by_role', 'position'],
        'clients' => ['id', 'created_by_user_id', 'created_by_role'],
        'contractors' => ['id', 'created_by_user_id', 'created_by_role'],
        'drivers' => ['id', 'created_by_user_id', 'created_by_role'],
        'vehicle_units' => ['id', 'created_by_user_id', 'created_by_role'],
        'crews' => ['id', 'created_by_user_id', 'created_by_role'],
        'documents' => ['id', 'document_type_id', 'created_by_user_id', 'created_by_role'],
        'entity_access_grants' => ['id', 'entity_type', 'entity_id', 'granted_to_user_id'],
        'contractor_contacts' => ['id', 'contractor_id'],
        'client_contacts' => ['id', 'client_id'],
        'vehicle_sets' => ['id', 'primary_vehicle_unit_id', 'created_by_user_id', 'created_by_role'],
        'driver_vehicle_blocks' => ['id', 'driver_id', 'vehicle_set_id'],
        'document_types' => ['id', 'name', 'entity_type'],
        'linear_route_financial_terms' => ['id', 'payment_due_type'],
        'linear_route_payments' => ['id', 'payment_due_type'],
    ];
    private const NULLABLE = [
        'linear_route_financial_terms' => ['payment_due_type'],
        'linear_route_payments' => ['payment_due_type'],
    ];
    private const SCOPES = [
        'logists' => [
            'migrations' => ['001_create_company_users.sql', '008_add_ownership_columns.sql',
                '009_create_entity_access_grants.sql', '010_add_position_to_users.sql'],
            'tables' => ['users', 'entity_access_grants'],
        ],
        'clients' => [
            'migrations' => ['002_create_company_clients.sql', '007_create_company_documents.sql',
                '008_add_ownership_columns.sql', '009_create_entity_access_grants.sql',
                '024_create_document_types.sql', '025_add_document_type_id.sql',
                '031_create_client_contacts.sql'],
            'tables' => ['clients', 'documents', 'entity_access_grants', 'client_contacts', 'document_types'],
        ],
        'transport' => [
            'migrations' => ['003_create_company_contractors.sql', '004_create_company_drivers.sql',
                '005_create_company_vehicles.sql', '006_create_company_crews.sql',
                '007_create_company_documents.sql', '008_add_ownership_columns.sql',
                '009_create_entity_access_grants.sql', '012_create_contractor_contacts.sql',
                '016_rename_vehicles_to_vehicle_units.sql', '017_create_vehicle_sets.sql',
                '018_create_driver_vehicle_blocks.sql', '024_create_document_types.sql',
                '025_add_document_type_id.sql'],
            'tables' => ['contractors', 'drivers', 'vehicle_units', 'crews', 'documents',
                'entity_access_grants', 'contractor_contacts', 'vehicle_sets',
                'driver_vehicle_blocks', 'document_types'],
        ],
        'linear' => [
            'migrations' => ['007_create_company_documents.sql', '008_add_ownership_columns.sql',
                '024_create_document_types.sql', '025_add_document_type_id.sql',
                '040_create_linear_routes.sql', '041_linear_route_repeatable_principals_payments.sql',
                '059_relax_legacy_linear_route_payment_terms.sql',
                '060_relax_linear_route_payment_legacy_due_fields.sql',
                '061_force_relax_all_legacy_route_due_fields.sql'],
            'tables' => ['documents', 'document_types', 'linear_route_financial_terms', 'linear_route_payments'],
        ],
    ];

    public static function inspect(PDO $pdo, string $scope): array
    {
        if (!isset(self::SCOPES[$scope])) throw new InvalidArgumentException('SCOPE');
        $selected = self::SCOPES[$scope];
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            $journal = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $journal->execute(['schema_migrations']);
            $missingMigrations = $selected['migrations'];
            if ((int) $journal->fetchColumn() === 1) {
                $names = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
                $missingMigrations = array_values(array_diff($selected['migrations'], $names));
            }
            $columns = $pdo->prepare('SELECT COLUMN_NAME,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $missingColumns = [];
            $nonNullableColumns = [];
            foreach ($selected['tables'] as $table) {
                $required = self::COLUMNS[$table];
                $columns->execute([$table]);
                $present = [];
                foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
                    $present[$column['COLUMN_NAME']] = $column['IS_NULLABLE'];
                }
                foreach ($required as $column) {
                    if (array_key_exists($column, $present)) continue;
                    $missingColumns[] = "$table.$column";
                }
                foreach (self::NULLABLE[$table] ?? [] as $column) {
                    if (($present[$column] ?? null) === 'NO') $nonNullableColumns[] = "$table.$column";
                }
            }
            return [
                'ready' => $missingMigrations === [] && $missingColumns === [] && $nonNullableColumns === [],
                'missing_migrations' => $missingMigrations,
                'missing_columns' => $missingColumns,
                'non_nullable_columns' => $nonNullableColumns,
            ];
        } finally {
            $pdo->rollBack();
        }
    }
}

if (!defined('PLANEX_LEGACY_HTTP_SCHEMA_PROBE_LIBRARY_ONLY')) {
    try {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('CLI_REQUIRED');
        }
        $root = rtrim((string) getenv('PLANEX_ROOT'), '/');
        if ($root !== '/home/s/spugovxsim/planexp/public_html/erpv2') {
            throw new RuntimeException('ROOT_SCOPE');
        }
        $scope = (string) getenv('PLANEX_SCHEMA_SCOPE');
        if (!in_array($scope, ['logists', 'clients', 'transport', 'linear'], true)) {
            throw new RuntimeException('SCOPE');
        }
        $config = require $root.'/bootstrap/app.php';
        require_once $root.'/app/Support/crypto_helper.php';
        require_once $root.'/app/Support/company_database.php';
        $connect = static function (array $db): PDO {
            return new PDO('mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'].';charset=utf8mb4',
                $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        };
        $central = $connect($config['database']);
        $central->exec('SET TRANSACTION READ ONLY');
        $central->beginTransaction();
        try {
            $companies = $central->query("SELECT id,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' ORDER BY id")->fetchAll();
            if (count($companies) < 1 || count($companies) > 200) {
                throw new RuntimeException('COMPANY_SCOPE');
            }
            $unready = 0;
            $missingMigrationChecks = 0;
            $missingColumnChecks = 0;
            $nonNullableChecks = 0;
            foreach ($companies as $company) {
                if (trim((string) $company['db_identifier']) === '') {
                    throw new RuntimeException('COMPANY_SCOPE');
                }
                $tenant = $connect(companyDatabaseConfig($config, $company));
                $state = LegacyHttpSchemaReadinessProbe::inspect($tenant, $scope);
                if (!$state['ready']) $unready++;
                $missingMigrationChecks += count($state['missing_migrations']);
                $missingColumnChecks += count($state['missing_columns']);
                $nonNullableChecks += count($state['non_nullable_columns']);
            }
            echo json_encode(['scope'=>$scope, 'active_tenants'=>count($companies), 'unready_tenants'=>$unready,
                'missing_migration_checks'=>$missingMigrationChecks, 'missing_column_checks'=>$missingColumnChecks,
                'non_nullable_checks'=>$nonNullableChecks],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            echo 'LEGACY_HTTP_SCHEMA_READ_ONLY_'.($unready === 0 ? 'READY' : 'MISSING')
                .' scope='.$scope.' companies='.count($companies)."\n";
            $resultCode = $unready === 0 ? 0 : 2;
        } finally {
            $central->rollBack();
        }
        exit($resultCode);
    } catch (Throwable $error) {
        $safe = in_array($error->getMessage(), ['CLI_REQUIRED', 'ROOT_SCOPE', 'COMPANY_SCOPE', 'SCOPE'], true)
            ? $error->getMessage() : 'INTERNAL_ERROR';
        fwrite(STDERR, "LEGACY_HTTP_SCHEMA_READ_ONLY_INCOMPLETE reason=$safe\n");
        exit(1);
    }
}
