<?php
declare(strict_types=1);

/** Central HTTP schema metadata only. This probe never reads business rows or writes. */
final class CentralHttpSchemaReadinessProbe
{
    public static function inspect(PDO $pdo): array
    {
        $pdo->exec('SET TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        try {
            $columns = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $required = [
                'companies' => ['bank_account', 'bank_name', 'bank_bik', 'bank_corr_account'],
                'company_db_pool_usage' => ['id', 'db_identifier', 'company_id', 'created_at', 'released_at', 'note'],
            ];
            $missing = [];
            foreach ($required as $table => $names) {
                $columns->execute([$table]);
                $present = $columns->fetchAll(PDO::FETCH_COLUMN);
                foreach (array_diff($names, $present) as $name) $missing[] = "$table.$name";
            }
            $journal = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='schema_migrations'");
            $journal->execute();
            $bankMigration = false;
            if ((int)$journal->fetchColumn() === 1) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
                $check->execute(['012_add_company_bank_requisites.sql']);
                $bankMigration = (int)$check->fetchColumn() === 1;
            }
            return ['ready' => $missing === [] && $bankMigration, 'bank_migration' => $bankMigration, 'missing_columns' => $missing];
        } finally {
            $pdo->rollBack();
        }
    }
}

if (!defined('PLANEX_CENTRAL_HTTP_SCHEMA_PROBE_LIBRARY_ONLY')) {
    try {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI_REQUIRED');
        $root = rtrim((string)getenv('PLANEX_ROOT'), '/');
        if ($root !== '/home/s/spugovxsim/planexp/public_html/erpv2') throw new RuntimeException('ROOT_SCOPE');
        $config = require $root.'/bootstrap/app.php';
        $db = $config['database'];
        $pdo = new PDO('mysql:host='.$db['host'].';port='.($db['port'] ?? 3306).';dbname='.$db['database'].';charset=utf8mb4',
            $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $state = CentralHttpSchemaReadinessProbe::inspect($pdo);
        echo json_encode(['ready'=>$state['ready'], 'bank_migration'=>$state['bank_migration'],
            'missing_column_checks'=>count($state['missing_columns'])], JSON_THROW_ON_ERROR)."\n";
        echo $state['ready'] ? "CENTRAL_HTTP_SCHEMA_READ_ONLY_READY\n" : "CENTRAL_HTTP_SCHEMA_READ_ONLY_MISSING\n";
        exit($state['ready'] ? 0 : 2);
    } catch (Throwable $error) {
        fwrite(STDERR, "CENTRAL_HTTP_SCHEMA_READ_ONLY_INCOMPLETE\n");
        exit(1);
    }
}
