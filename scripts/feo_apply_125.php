<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$root = rtrim((string) (getenv('PLANEX_ROOT') ?: ($argv[1] ?? '')), '/');
if ($root === '' || !is_file($root . '/.env') || !is_file($root . '/bootstrap/app.php')) {
    throw new RuntimeException('PLANEX_ROOT invalid');
}

$migrationPath = (string) (getenv('PLANEX_MIGRATION_PATH') ?: ($argv[2] ?? ($root . '/database/migrations-local/125_create_feo_map_utilizers.sql')));
if (!is_file($migrationPath)) {
    throw new RuntimeException('Migration 125 missing');
}
$sql = file_get_contents($migrationPath);
if (!is_string($sql) || trim($sql) === '') {
    throw new RuntimeException('Migration 125 empty');
}
$checksum = hash('sha256', $sql);
$name = '125_create_feo_map_utilizers.sql';

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$companies = $central->query(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password
       FROM companies
      WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>''
      ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) {
    throw new RuntimeException('No active tenant databases found');
}

$tableCount = static function (PDO $pdo, array $tables): int {
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $placeholders . ')'
    );
    $stmt->execute($tables);
    return (int) $stmt->fetchColumn();
};
$columnCount = static function (PDO $pdo, string $table, array $columns): int {
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME IN (' . $placeholders . ')'
    );
    $stmt->execute(array_merge([$table], $columns));
    return (int) $stmt->fetchColumn();
};
$indexExists = static function (PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'
    );
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
};
$foreignKeyExists = static function (PDO $pdo, string $table, string $constraint, string $referencedTable, string $deleteRule): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?
            AND REFERENCED_TABLE_NAME=? AND DELETE_RULE=?'
    );
    $stmt->execute([$table, $constraint, $referencedTable, $deleteRule]);
    return (int) $stmt->fetchColumn() === 1;
};

$applied = 0;
$existing = 0;
foreach ($companies as $company) {
    $cfg = companyDatabaseConfig($config, $company);
    $pdo = new PDO(
        'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
        $cfg['username'],
        $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $actualDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actualDb !== (string) $company['db_identifier']) {
        throw new RuntimeException('Unexpected tenant DB for company ' . $company['id']);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_migrations(
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            checksum VARCHAR(64) NOT NULL,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if ($tableCount($pdo, ['feo_requests', 'feo_imports', 'feo_import_changes']) !== 3) {
        throw new RuntimeException('FEO migration 122 prerequisite missing for company ' . $company['id']);
    }
    if ($tableCount($pdo, ['feo_placement_blocks', 'feo_placement_block_requests', 'feo_placement_current']) !== 3) {
        throw new RuntimeException('FEO migration 123 prerequisite missing for company ' . $company['id']);
    }
    if ($tableCount($pdo, ['feo_request_cost_blocks', 'feo_request_cost_block_requests']) !== 2) {
        throw new RuntimeException('FEO migration 124 prerequisite missing for company ' . $company['id']);
    }
    foreach (['122_create_feo_requests.sql', '123_create_feo_map_placement.sql', '124_create_feo_request_costs.sql'] as $prerequisite) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration=?');
        $q->execute([$prerequisite]);
        if ((int) $q->fetchColumn() !== 1) {
            throw new RuntimeException('Missing prerequisite journal ' . $prerequisite . ' for company ' . $company['id']);
        }
    }

    $lockName = 'planex_feo_125_' . substr(hash('sha256', (string) $company['db_identifier']), 0, 20);
    $lock = $pdo->prepare('SELECT GET_LOCK(?,10)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Lock failed for company ' . $company['id']);
    }

    $state = '';
    try {
        $q = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
        $q->execute([$name]);
        $stored = $q->fetchColumn();
        if ($stored !== false) {
            if (!hash_equals((string) $stored, $checksum)) {
                throw new RuntimeException('Checksum mismatch for company ' . $company['id']);
            }
            $state = 'existing';
            $existing++;
        } else {
            $newer = (int) $pdo->query(
                "SELECT COUNT(*) FROM schema_migrations
                  WHERE migration REGEXP '^[0-9]{3}_'
                    AND CAST(LEFT(migration,3) AS UNSIGNED)>125"
            )->fetchColumn();
            if ($newer > 0) {
                throw new RuntimeException('Newer tenant migration exists for company ' . $company['id']);
            }
            $partial = $tableCount($pdo, ['feo_utilizers', 'feo_utilizer_match_rules', 'feo_request_utilizer_overrides']);
            if ($partial !== 0) {
                throw new RuntimeException('Unjournaled FEO map-utilizer schema exists for company ' . $company['id']);
            }

            $pdo->exec($sql);
            $q = $pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');
            $q->execute([$name, $checksum]);
            $state = 'applied';
            $applied++;
        }

        if ($tableCount($pdo, ['feo_utilizers', 'feo_utilizer_match_rules', 'feo_request_utilizer_overrides']) !== 3) {
            throw new RuntimeException('FEO 125 tables verification failed for company ' . $company['id']);
        }
        if ($columnCount($pdo, 'feo_utilizers', ['id','full_name','short_name','address','latitude','longitude','fkko_classification','adr','color_hex','is_active','sort_order','created_by_user_id','updated_by_user_id','created_at','updated_at']) !== 15) {
            throw new RuntimeException('FEO 125 utilizer columns verification failed for company ' . $company['id']);
        }
        if ($columnCount($pdo, 'feo_utilizer_match_rules', ['id','utilizer_id','source_field','match_type','pattern','normalized_pattern','priority','is_active','created_by_user_id','updated_by_user_id','created_at','updated_at']) !== 12) {
            throw new RuntimeException('FEO 125 rule columns verification failed for company ' . $company['id']);
        }
        if ($columnCount($pdo, 'feo_request_utilizer_overrides', ['zayavka_id','utilizer_id','created_by_user_id','updated_by_user_id','created_at','updated_at']) !== 6) {
            throw new RuntimeException('FEO 125 override columns verification failed for company ' . $company['id']);
        }
        foreach ([
            ['feo_utilizers','uk_feo_utilizers_short_name'],
            ['feo_utilizers','idx_feo_utilizers_active_order'],
            ['feo_utilizer_match_rules','uk_feo_utilizer_rule_canonical'],
            ['feo_utilizer_match_rules','idx_feo_utilizer_rule_match'],
            ['feo_utilizer_match_rules','idx_feo_utilizer_rule_utilizer'],
            ['feo_request_utilizer_overrides','idx_feo_request_utilizer_override_utilizer'],
        ] as [$table, $index]) {
            if (!$indexExists($pdo, $table, $index)) {
                throw new RuntimeException('FEO 125 index missing (' . $table . '.' . $index . ') for company ' . $company['id']);
            }
        }
        foreach ([
            ['feo_utilizer_match_rules','fk_feo_utilizer_rule_utilizer','feo_utilizers','RESTRICT'],
            ['feo_request_utilizer_overrides','fk_feo_request_utilizer_override_request','feo_requests','CASCADE'],
            ['feo_request_utilizer_overrides','fk_feo_request_utilizer_override_utilizer','feo_utilizers','RESTRICT'],
        ] as [$table, $constraint, $referenced, $deleteRule]) {
            if (!$foreignKeyExists($pdo, $table, $constraint, $referenced, $deleteRule)) {
                throw new RuntimeException('FEO 125 FK verification failed (' . $constraint . ') for company ' . $company['id']);
            }
        }

        printf("FEO_125_COMPANY_OK id=%d db=%s state=%s\n", (int) $company['id'], $company['db_identifier'], $state);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

printf("FEO_125_OK tenants=%d applied=%d existing=%d checksum=%s\n", count($companies), $applied, $existing, $checksum);
