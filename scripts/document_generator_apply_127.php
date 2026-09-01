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
$tenantMigrationPath = (string) (getenv('PLANEX_TENANT_MIGRATION_PATH') ?: ($argv[2] ?? ($root . '/database/migrations-local/127_create_document_generator.sql')));
$controlMigrationPath = (string) (getenv('PLANEX_CONTROL_MIGRATION_PATH') ?: ($argv[3] ?? ($root . '/database/migrations/012_add_company_bank_requisites.sql')));
if (!is_file($tenantMigrationPath) || !is_file($controlMigrationPath)) {
    throw new RuntimeException('Document generator migration files missing');
}
$tenantSql = file_get_contents($tenantMigrationPath);
$controlSql = file_get_contents($controlMigrationPath);
if (!is_string($tenantSql) || trim($tenantSql) === '' || !is_string($controlSql) || trim($controlSql) === '') {
    throw new RuntimeException('Document generator migration file empty');
}
$tenantChecksum = hash('sha256', $tenantSql);
$tenantName = '127_create_document_generator.sql';

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Helpers/company_bank_guard.php';

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() === 1;
};
$columnCount = static function (PDO $pdo, string $table, array $columns): int {
    $marks = implode(',', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (' . $marks . ')');
    $stmt->execute(array_merge([$table], $columns));
    return (int) $stmt->fetchColumn();
};
$indexExists = static function (PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
};
$fkExists = static function (PDO $pdo, string $table, string $constraint, string $referenced): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
        . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND REFERENCED_TABLE_NAME = ?'
    );
    $stmt->execute([$table, $constraint, $referenced]);
    return (int) $stmt->fetchColumn() === 1;
};

$controlColumns = ['bank_account', 'bank_name', 'bank_bik', 'bank_corr_account'];
if (!str_contains($controlSql, 'add_company_bank_column_if_not_exists')) {
    throw new RuntimeException('Control migration 012 contract token missing');
}
foreach ($controlColumns as $column) {
    if (!str_contains($controlSql, $column)) {
        throw new RuntimeException('Control migration 012 missing ' . $column);
    }
}

$centralLock = 'planex_docgen_127_control';
$lockStmt = $central->prepare('SELECT GET_LOCK(?, 10)');
$lockStmt->execute([$centralLock]);
if ((int) $lockStmt->fetchColumn() !== 1) {
    throw new RuntimeException('Control DB lock failed');
}
try {
    $beforeColumns = $columnCount($central, 'companies', $controlColumns);
    ensureCompanyBankColumns($central);
    if ($columnCount($central, 'companies', $controlColumns) !== count($controlColumns)) {
        throw new RuntimeException('Control migration 012 verification failed');
    }
    printf("DOCGEN_127_CONTROL_OK state=%s columns=%d\n", $beforeColumns === count($controlColumns) ? 'existing' : 'applied', count($controlColumns));
} finally {
    $releaseStmt = $central->prepare('SELECT RELEASE_LOCK(?)');
    $releaseStmt->execute([$centralLock]);
}

$companies = $central->query(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password\n"
    . "FROM companies WHERE status='active' AND db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);
if ($companies === []) {
    throw new RuntimeException('No active tenant databases found');
}

$splitSqlStatements = static function (string $sql): array {
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $quote = null;
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($ch === "\n") {
                $lineComment = false;
                $buffer .= $ch;
            }
            continue;
        }
        if ($blockComment) {
            if ($ch === '*' && $next === '/') {
                $blockComment = false;
                $i++;
            }
            continue;
        }
        if ($quote !== null) {
            $buffer .= $ch;
            if ($ch === '\\' && $quote !== '`' && $i + 1 < $length) {
                $buffer .= $sql[++$i];
                continue;
            }
            if ($ch === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $i++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }
        if (($ch === '-' && $next === '-') && ($i === 0 || ctype_space($sql[$i - 1]))) {
            $lineComment = true;
            $i++;
            continue;
        }
        if ($ch === '#') {
            $lineComment = true;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $blockComment = true;
            $i++;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $buffer .= $ch;
            continue;
        }
        if ($ch === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }
        $buffer .= $ch;
    }
    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    return $statements;
};
$tenantStatements = $splitSqlStatements($tenantSql);
if (count($tenantStatements) < 9) {
    throw new RuntimeException('Tenant migration 127 statement split failed');
}

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
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== (string) $company['db_identifier']) {
        throw new RuntimeException('Unexpected tenant DB for company ' . $company['id']);
    }
    if (!$tableExists($pdo, 'schema_migrations') || !$tableExists($pdo, 'crews')) {
        throw new RuntimeException('Tenant migration prerequisites missing for company ' . $company['id']);
    }
    $prereq = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE migration = ?');
    $prereq->execute(['126_create_feo_utilizer_aliases.sql']);
    if ((int) $prereq->fetchColumn() !== 1) {
        throw new RuntimeException('Migration 126 journal prerequisite missing for company ' . $company['id']);
    }

    $lockName = 'planex_docgen_127_' . substr(hash('sha256', (string) $company['db_identifier']), 0, 20);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute([$lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('Tenant lock failed for company ' . $company['id']);
    }

    $state = '';
    try {
        $journal = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration = ?');
        $journal->execute([$tenantName]);
        $stored = $journal->fetchColumn();
        if ($stored !== false) {
            if (!hash_equals((string) $stored, $tenantChecksum)) {
                throw new RuntimeException('Migration 127 checksum mismatch for company ' . $company['id']);
            }
            $state = 'existing';
            $existing++;
        } else {
            $newer = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE migration REGEXP '^[0-9]{3}_' AND CAST(LEFT(migration,3) AS UNSIGNED) > 127")->fetchColumn();
            if ($newer > 0) {
                throw new RuntimeException('Newer tenant migration exists for company ' . $company['id']);
            }
            foreach (['document_templates', 'document_template_versions', 'generated_documents', 'generated_document_requests'] as $table) {
                if ($tableExists($pdo, $table)) {
                    throw new RuntimeException('Unjournaled document generator schema exists for company ' . $company['id']);
                }
            }
            foreach ($tenantStatements as $statement) {
                $pdo->exec($statement);
            }
            $insert = $pdo->prepare('INSERT INTO schema_migrations(migration, checksum) VALUES(?, ?)');
            $insert->execute([$tenantName, $tenantChecksum]);
            $state = 'applied';
            $applied++;
        }

        $expectedColumns = [
            'document_templates' => ['id','name','document_type','status','active_version_id','created_by_user_id','created_by_role','updated_by_user_id','updated_by_role','created_at','updated_at'],
            'document_template_versions' => ['id','template_id','version_no','original_name','stored_path','file_size','sha256','placeholders_json','created_by_user_id','created_by_role','created_at'],
            'generated_documents' => ['id','document_type','document_number','document_date','template_id','template_version_id','route_executor_id','request_ids_json','total_mass_kg','unload_address','parameters_json','snapshot_json','stored_path','original_name','sha256','created_by_user_id','created_by_role','created_at'],
            'generated_document_requests' => ['id','generated_document_id','request_id','position','snapshot_json','created_at'],
        ];
        foreach ($expectedColumns as $table => $columns) {
            if (!$tableExists($pdo, $table) || $columnCount($pdo, $table, $columns) !== count($columns)) {
                throw new RuntimeException('Migration 127 table/column verification failed for ' . $table . ' company ' . $company['id']);
            }
        }
        foreach ([
            ['document_templates','idx_document_templates_type_status'],
            ['document_template_versions','uk_document_template_versions'],
            ['generated_documents','idx_generated_documents_executor'],
            ['generated_document_requests','uk_generated_document_request_position'],
        ] as [$table, $index]) {
            if (!$indexExists($pdo, $table, $index)) {
                throw new RuntimeException('Migration 127 index verification failed for company ' . $company['id'] . ': ' . $index);
            }
        }
        foreach ([
            ['document_template_versions','fk_document_template_versions_template','document_templates'],
            ['generated_documents','fk_generated_documents_template','document_templates'],
            ['generated_documents','fk_generated_documents_template_version','document_template_versions'],
            ['generated_documents','fk_generated_documents_route_executor','crews'],
            ['generated_document_requests','fk_generated_document_requests_document','generated_documents'],
            ['document_templates','fk_document_templates_active_version','document_template_versions'],
        ] as [$table, $constraint, $referenced]) {
            if (!$fkExists($pdo, $table, $constraint, $referenced)) {
                throw new RuntimeException('Migration 127 FK verification failed for company ' . $company['id'] . ': ' . $constraint);
            }
        }
        printf("DOCGEN_127_COMPANY_OK id=%d db=%s state=%s\n", (int) $company['id'], $company['db_identifier'], $state);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

printf("DOCGEN_127_OK tenants=%d applied=%d existing=%d checksum=%s\n", count($companies), $applied, $existing, $tenantChecksum);
