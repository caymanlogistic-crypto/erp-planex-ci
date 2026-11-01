<?php
declare(strict_types=1);

$schema = (string) getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV') !== 'test' || !preg_match('/^planex_t_[a-f0-9]{24}$/D', $schema)
    || getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST') !== $schema) {
    throw new RuntimeException('Unique disposable test schema required');
}
define('PLANEX_DOCUMENT_SCHEMA_PROBE_LIBRARY_ONLY', true);
require dirname(__DIR__).'/scripts/document_schema_readiness_probe.php';

$pdo = new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$schema.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$missing = DocumentSchemaReadinessProbe::inspect($pdo);
$assert(!$missing['ready'] && count($missing['missing_migrations']) === 5
    && in_array('documents.id', $missing['missing_columns'], true), 'Absent schema must fail closed');

$pdo->exec('CREATE TABLE schema_migrations (migration VARCHAR(255) PRIMARY KEY)');
$pdo->exec('CREATE TABLE documents (
    id INT, entity_type VARCHAR(20), entity_id INT, document_type VARCHAR(100), document_type_id INT,
    original_name VARCHAR(500), stored_name VARCHAR(255), relative_path VARCHAR(1000),
    mime_type VARCHAR(100), file_size BIGINT, status VARCHAR(20), uploaded_by_user_id INT,
    uploaded_by_role VARCHAR(50), created_by_user_id INT, created_by_role VARCHAR(20), deleted_at DATETIME NULL)');
$pdo->exec('CREATE TABLE document_types (id INT, name VARCHAR(200), code VARCHAR(50), entity_type VARCHAR(20), sort_order INT)');
$insert = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
foreach (['007_create_company_documents.sql', '008_add_ownership_columns.sql', '024_create_document_types.sql',
    '025_add_document_type_id.sql', '039_add_soft_delete_columns.sql'] as $migration) $insert->execute([$migration]);

$ready = DocumentSchemaReadinessProbe::inspect($pdo);
$assert($ready === ['ready' => true, 'missing_migrations' => [], 'missing_columns' => []],
    'Journal and required schema must pass');
$pdo->exec("DELETE FROM schema_migrations WHERE migration='025_add_document_type_id.sql'");
$unlogged = DocumentSchemaReadinessProbe::inspect($pdo);
$assert(!$unlogged['ready'] && $unlogged['missing_migrations'] === ['025_add_document_type_id.sql'],
    'Unjournaled schema must fail closed');
$pdo->exec('ALTER TABLE documents DROP COLUMN created_by_role');
$drifted = DocumentSchemaReadinessProbe::inspect($pdo);
$assert(!$drifted['ready'] && in_array('documents.created_by_role', $drifted['missing_columns'], true),
    'Missing runtime column must fail closed');

echo "DOCUMENT_SCHEMA_READINESS_MYSQL=PASS absent=REJECTED ready=PASS unjournaled=REJECTED drifted=REJECTED\n";
