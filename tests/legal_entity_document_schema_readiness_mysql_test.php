<?php
declare(strict_types=1);

$schema = (string) getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV') !== 'test' || !preg_match('/^planex_t_[a-f0-9]{24}$/D', $schema)
    || getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST') !== $schema) {
    throw new RuntimeException('Unique disposable test schema required');
}
require dirname(__DIR__).'/app/Support/legal_entity_document_upload.php';

$pdo = new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.getenv('PLANEX_SCHEMA_DB_PORT').';dbname='.$schema.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

try {
    ensureLegalEntityDocumentTables($pdo);
    throw new RuntimeException('Missing schema was accepted');
} catch (PDOException $expected) {
    if ($pdo->query('SHOW TABLES')->fetchAll() !== []) throw new RuntimeException('HTTP helper created schema');
}

$pdo->exec('CREATE TABLE documents (id INT, document_type_id INT, created_by_user_id INT, created_by_role VARCHAR(20))');
$pdo->exec('CREATE TABLE document_types (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200), code VARCHAR(50), entity_type VARCHAR(20), category VARCHAR(20), sort_order INT, created_by_user_id INT, created_by_role VARCHAR(20))');
ensureLegalEntityDocumentTables($pdo);
if ((int) $pdo->query("SELECT COUNT(*) FROM document_types WHERE entity_type='company'")->fetchColumn() !== 4) {
    throw new RuntimeException('Ready schema did not receive four company document types');
}
$pdo->exec('ALTER TABLE document_types DROP COLUMN sort_order');
try {
    ensureLegalEntityDocumentTables($pdo);
    throw new RuntimeException('Missing catalog column was accepted');
} catch (PDOException $expected) {
    if ((int) $pdo->query('SELECT COUNT(*) FROM document_types')->fetchColumn() !== 4) {
        throw new RuntimeException('Catalog changed after missing-column failure');
    }
}

echo "LEGAL_ENTITY_DOCUMENT_SCHEMA_READINESS_MYSQL=PASS absent=REJECTED ready=PASS drifted=REJECTED\n";
