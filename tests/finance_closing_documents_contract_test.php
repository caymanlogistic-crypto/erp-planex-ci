<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/ClosingDocumentService.php';

use App\Service\ClosingDocumentService;
use PDO;
use RuntimeException;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('ASSERT FAILED: ' . $message);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('ASSERT FAILED: ' . $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

$profiles = ClosingDocumentService::profiles();
assertSameValue(['agreement'], $profiles['legal_entity'][0]['accepted'], 'ЮЛ требует договор');
assertSameValue(['act', 'upd'], $profiles['legal_entity'][1]['accepted'], 'ЮЛ допускает акт или УПД');
assertSameValue(['agreement'], $profiles['individual'][0]['accepted'], 'ИП требует договор');
assertSameValue(['act', 'upd'], $profiles['individual'][1]['accepted'], 'ИП допускает акт или УПД');
assertSameValue(['agreement'], $profiles['self_employed'][0]['accepted'], 'НПД требует договор');
assertSameValue(['act'], $profiles['self_employed'][1]['accepted'], 'НПД требует акт');
assertSameValue(['npd_receipt'], $profiles['self_employed'][2]['accepted'], 'НПД требует чек');
assertSameValue(['gph_agreement'], $profiles['private_person'][0]['accepted'], 'Физлицо требует ГПХ');
assertSameValue(['act'], $profiles['private_person'][1]['accepted'], 'Физлицо требует акт');

$host = getenv('PLANEX_TEST_MYSQL_HOST') ?: '127.0.0.1';
$user = getenv('PLANEX_TEST_MYSQL_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_TEST_MYSQL_PASSWORD') ?: 'planex_ci';
$port = (int) (getenv('PLANEX_TEST_MYSQL_PORT') ?: 3306);
$root = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$dbA = 'planex_closing_a_' . getmypid();
$dbB = 'planex_closing_b_' . getmypid();
$root->exec("CREATE DATABASE `{$dbA}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$root->exec("CREATE DATABASE `{$dbB}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

function tenantPdo(string $host, int $port, string $db, string $user, string $pass): PDO
{
    return new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function setupBase(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE bank_transactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        account_id INT UNSIGNED NOT NULL DEFAULT 1,
        operation_date DATE NOT NULL,
        document_number VARCHAR(100) NULL,
        operation_type VARCHAR(30) NULL,
        counterparty_name VARCHAR(255) NULL,
        counterparty_inn VARCHAR(20) NULL,
        debit_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        credit_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        purpose TEXT NULL,
        classification_status VARCHAR(30) NOT NULL DEFAULT 'UNALLOCATED',
        is_internal_transfer TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE finance_employee_movements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bank_transaction_id INT UNSIGNED NULL,
        UNIQUE KEY uk_employee_bank_tx (bank_transaction_id),
        CONSTRAINT fk_employee_bank_tx FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE clients (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        inn VARCHAR(20) NULL,
        entity_type VARCHAR(20) NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE contractors (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        inn VARCHAR(20) NULL,
        contractor_type VARCHAR(20) NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE document_types (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NULL,
        code VARCHAR(100) NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE documents (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(20) NOT NULL,
        entity_id INT UNSIGNED NOT NULL,
        document_type VARCHAR(100) NULL,
        document_type_id INT UNSIGNED NULL,
        original_name VARCHAR(500) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        relative_path VARCHAR(1000) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        file_size BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'uploaded',
        deleted_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $migration = file_get_contents(__DIR__ . '/../database/migrations-local/077_finance_closing_documents.sql');
    if ($migration === false) throw new RuntimeException('migration file missing');
    $pdo->exec($migration);
    // Forward migration is intentionally idempotent for tenant provisioning.
    $pdo->exec($migration);
    assertTrue(ClosingDocumentService::schemaReady($pdo), 'migration 077 creates closing schema');
}

function addClient(PDO $pdo, string $name, string $inn, ?string $type): int
{
    $stmt = $pdo->prepare('INSERT INTO clients(name,inn,entity_type) VALUES(?,?,?)');
    $stmt->execute([$name, $inn, $type]);
    return (int) $pdo->lastInsertId();
}

function addTx(PDO $pdo, string $inn, string $name = 'Контрагент'): int
{
    $stmt = $pdo->prepare("INSERT INTO bank_transactions(operation_date,document_number,operation_type,counterparty_name,counterparty_inn,debit_amount,purpose) VALUES(CURDATE(), ?, 'PAYMENT', ?, ?, 1000, 'Тест')");
    $stmt->execute([uniqid('TX', false), $name, $inn]);
    return (int) $pdo->lastInsertId();
}

function addDoc(PDO $pdo, string $entityType, int $entityId, string $name): int
{
    $stmt = $pdo->prepare("INSERT INTO documents(entity_type,entity_id,document_type,original_name,stored_name,relative_path,mime_type,file_size,status) VALUES(?,?,?,?,?,?,?,?,'uploaded')");
    $stmt->execute([$entityType, $entityId, $name, $name . '.pdf', uniqid('doc_', false) . '.pdf', 'test/' . uniqid('', false), 'application/pdf', 100]);
    return (int) $pdo->lastInsertId();
}

function linkDoc(PDO $pdo, int $tx, int $doc, string $code): void
{
    $stmt = $pdo->prepare('INSERT INTO finance_closing_document_links(bank_transaction_id,document_id,requirement_code) VALUES(?,?,?)');
    $stmt->execute([$tx, $doc, $code]);
}

function complete(PDO $pdo, int $tx, string $type, int $entityId, ?int $sharedAgreement = null): array
{
    $created = [];
    if ($type === 'private_person') {
        $doc = addDoc($pdo, 'client', $entityId, 'ГПХ');
        linkDoc($pdo, $tx, $doc, 'gph_agreement');
        $created['agreement'] = $doc;
    } else {
        $doc = $sharedAgreement ?: addDoc($pdo, 'client', $entityId, 'Договор');
        linkDoc($pdo, $tx, $doc, 'agreement');
        $created['agreement'] = $doc;
    }
    $act = addDoc($pdo, 'client', $entityId, 'Акт');
    linkDoc($pdo, $tx, $act, 'act');
    $created['act'] = $act;
    if ($type === 'self_employed') {
        $receipt = addDoc($pdo, 'client', $entityId, 'Чек НПД');
        linkDoc($pdo, $tx, $receipt, 'npd_receipt');
        $created['receipt'] = $receipt;
    }
    return $created;
}

$pdoA = tenantPdo($host, $port, $dbA, $user, $pass);
$pdoB = tenantPdo($host, $port, $dbB, $user, $pass);
setupBase($pdoA);
setupBase($pdoB);

$legal = addClient($pdoA, 'ООО Тест', '7700000001', 'legal_entity');
$ip = addClient($pdoA, 'ИП Тест', '7700000002', 'individual');
$npd = addClient($pdoA, 'НПД Тест', '7700000003', 'self_employed');
$person = addClient($pdoA, 'Физлицо Тест', '7700000004', 'private_person');
$unclassified = addClient($pdoA, 'Без типа', '7700000005', null);

$txLegal = addTx($pdoA, '7700000001'); complete($pdoA, $txLegal, 'legal_entity', $legal);
$txIp = addTx($pdoA, '7700000002'); complete($pdoA, $txIp, 'individual', $ip);
$txNpd = addTx($pdoA, '7700000003'); complete($pdoA, $txNpd, 'self_employed', $npd);
$txPerson = addTx($pdoA, '7700000004'); complete($pdoA, $txPerson, 'private_person', $person);

$txMissingOne = addTx($pdoA, '7700000001');
$agreementForMissingOne = addDoc($pdoA, 'client', $legal, 'Договор');
linkDoc($pdoA, $txMissingOne, $agreementForMissingOne, 'agreement');

$txMissingSeveral = addTx($pdoA, '7700000003');
$txUnknown = addTx($pdoA, '7999999999', 'Неизвестный');
$txNoProfile = addTx($pdoA, '7700000005');

// Optional documents do not participate in the profile. A complete required set remains complete.
$txOptional = addTx($pdoA, '7700000001'); complete($pdoA, $txOptional, 'legal_entity', $legal);

$txEmployee = addTx($pdoA, '7700000001');
$pdoA->prepare('INSERT INTO finance_employee_movements(bank_transaction_id) VALUES(?)')->execute([$txEmployee]);

// One existing agreement is reused by two separate bank operations without copying the file.
$sharedAgreement = addDoc($pdoA, 'client', $legal, 'Рамочный договор');
$txReuse1 = addTx($pdoA, '7700000001'); complete($pdoA, $txReuse1, 'legal_entity', $legal, $sharedAgreement);
$txReuse2 = addTx($pdoA, '7700000001'); complete($pdoA, $txReuse2, 'legal_entity', $legal, $sharedAgreement);

$allA = ClosingDocumentService::reportRows($pdoA, false);
$problemA = ClosingDocumentService::reportRows($pdoA, true);
$byId = [];
foreach ($allA as $row) $byId[(int) $row['id']] = $row;

foreach ([$txLegal, $txIp, $txNpd, $txPerson, $txOptional, $txReuse1, $txReuse2] as $id) {
    assertTrue(isset($byId[$id]) && !$byId[$id]['is_problem'], 'complete required set is not problematic tx=' . $id);
}
assertTrue($byId[$txMissingOne]['is_problem'], 'missing one required document is problem');
assertSameValue(1, count($byId[$txMissingOne]['missing_requirements']), 'exactly one missing requirement');
assertTrue($byId[$txMissingSeveral]['is_problem'], 'missing several required documents is problem');
assertSameValue(3, count($byId[$txMissingSeveral]['missing_requirements']), 'NPD missing several requirements');
assertTrue($byId[$txUnknown]['is_problem'], 'unknown counterparty is problem');
assertSameValue('Контрагент не определён', $byId[$txUnknown]['problem_reason'], 'unknown counterparty reason');
assertTrue($byId[$txNoProfile]['is_problem'], 'missing classification/profile is problem');
assertSameValue('Не определён профиль обязательных документов', $byId[$txNoProfile]['problem_reason'], 'missing profile reason');
assertTrue(!isset($byId[$txEmployee]), 'employee money flow is excluded from report');
assertSameValue(3, count($problemA), 'problematic operations count operations, not missing files');
assertSameValue(count($problemA), ClosingDocumentService::problemCount($pdoA), 'badge source equals problematic filter count');

$reuseStmt = $pdoA->prepare('SELECT COUNT(DISTINCT bank_transaction_id) FROM finance_closing_document_links WHERE document_id = ? AND requirement_code = ?');
$reuseStmt->execute([$sharedAgreement, 'agreement']);
assertSameValue(2, (int) $reuseStmt->fetchColumn(), 'same existing agreement is reusable across operations');

// Tenant B has its own schema/data and cannot affect tenant A count.
addTx($pdoB, '7888888888', 'Tenant B unknown');
assertSameValue(1, ClosingDocumentService::problemCount($pdoB), 'tenant B isolated count');
assertSameValue(3, ClosingDocumentService::problemCount($pdoA), 'tenant A count unchanged by tenant B');

$navigationSource = file_get_contents(__DIR__ . '/../app/Service/NavigationCounterService.php');
assertTrue(str_contains((string) $navigationSource, 'ClosingDocumentService::problemCount($tenant)'), 'sidebar counter calls the shared problem source');
$layoutSource = file_get_contents(__DIR__ . '/../app/View/layouts/main.php');
assertTrue(str_contains((string) $layoutSource, 'nav-count is-alert'), 'closing badge reuses system nav-count is-alert pattern');
assertTrue(str_contains((string) $layoutSource, '$closingDocumentsProblemCount > 999 ? \'999+\' : $closingDocumentsProblemCount'), 'closing badge preserves 999+ cap');
assertTrue(str_contains((string) $layoutSource, 'Закрывающие документы'), 'closing documents menu item exists');

$root->exec("DROP DATABASE `{$dbA}`");
$root->exec("DROP DATABASE `{$dbB}`");

echo "finance_closing_documents_contract_test: PASS\n";
echo "fixture_problem_count=3\n";
