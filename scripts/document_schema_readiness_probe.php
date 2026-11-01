<?php
declare(strict_types=1);

/** Metadata-only tenant document schema check; never runs migrations or reads business rows. */
final class DocumentSchemaReadinessProbe
{
    private const MIGRATIONS = [
        '007_create_company_documents.sql',
        '008_add_ownership_columns.sql',
        '024_create_document_types.sql',
        '025_add_document_type_id.sql',
        '039_add_soft_delete_columns.sql',
    ];
    private const COLUMNS = [
        'documents' => ['id', 'entity_type', 'entity_id', 'document_type', 'document_type_id',
            'original_name', 'stored_name', 'relative_path', 'mime_type', 'file_size', 'status',
            'uploaded_by_user_id', 'uploaded_by_role', 'created_by_user_id', 'created_by_role', 'deleted_at'],
        'document_types' => ['id', 'name', 'code', 'entity_type', 'sort_order'],
    ];

    public static function inspect(PDO $pdo): array
    {
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            $journal = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $journal->execute(['schema_migrations']);
            $missingMigrations = self::MIGRATIONS;
            if ((int) $journal->fetchColumn() === 1) {
                $names = $pdo->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
                $missingMigrations = array_values(array_diff(self::MIGRATIONS, $names));
            }
            $columns = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $missingColumns = [];
            foreach (self::COLUMNS as $table => $required) {
                $columns->execute([$table]);
                $present = $columns->fetchAll(PDO::FETCH_COLUMN);
                foreach (array_diff($required, $present) as $column) {
                    $missingColumns[] = "$table.$column";
                }
            }
            return [
                'ready' => $missingMigrations === [] && $missingColumns === [],
                'missing_migrations' => $missingMigrations,
                'missing_columns' => $missingColumns,
            ];
        } finally {
            $pdo->rollBack();
        }
    }
}

if (!defined('PLANEX_DOCUMENT_SCHEMA_PROBE_LIBRARY_ONLY')) {
    try {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('CLI_REQUIRED');
        }
        $root = rtrim((string) getenv('PLANEX_ROOT'), '/');
        if ($root !== '/home/s/spugovxsim/planexp/public_html/erpv2') {
            throw new RuntimeException('ROOT_SCOPE');
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
            $companies = $central->query("SELECT id,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id")->fetchAll();
            if (count($companies) < 1 || count($companies) > 200) {
                throw new RuntimeException('COMPANY_SCOPE');
            }
            $allReady = true;
            foreach ($companies as $company) {
                $tenant = $connect(companyDatabaseConfig($config, $company));
                $state = DocumentSchemaReadinessProbe::inspect($tenant);
                $allReady = $allReady && $state['ready'];
                echo json_encode(['company_id' => (int) $company['id']] + $state,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            }
            echo 'DOCUMENT_SCHEMA_READ_ONLY_'.($allReady ? 'READY' : 'MISSING').' companies='.count($companies)."\n";
            $resultCode = $allReady ? 0 : 2;
        } finally {
            $central->rollBack();
        }
        exit($resultCode);
    } catch (Throwable $error) {
        $safe = in_array($error->getMessage(), ['CLI_REQUIRED', 'ROOT_SCOPE', 'COMPANY_SCOPE'], true)
            ? $error->getMessage() : 'INTERNAL_ERROR';
        fwrite(STDERR, "DOCUMENT_SCHEMA_READ_ONLY_INCOMPLETE reason=$safe\n");
        exit(1);
    }
}
