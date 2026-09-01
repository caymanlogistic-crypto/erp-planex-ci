<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$ok = static function (bool $value, string $message) use ($fail): void {
    if (!$value) $fail($message);
};
$eq = static function (mixed $actual, mixed $expected, string $message) use ($fail): void {
    if ($actual !== $expected) {
        $fail($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
};
$contains = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (!str_contains($haystack, $needle)) $fail($message);
};
$notContains = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (str_contains($haystack, $needle)) $fail($message);
};
$source = static function (string $relative) use ($root, $fail): string {
    $value = @file_get_contents($root . '/' . $relative);
    if (!is_string($value)) $fail('cannot read ' . $relative);
    return $value;
};
$removeTree = function (string $path) use (&$removeTree): void {
    if (!file_exists($path)) return;
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $name) {
        $removeTree($path . DIRECTORY_SEPARATOR . $name);
    }
    @rmdir($path);
};

/* Canonical wiring / policy contracts. */
$workflowSource = $source('app/Service/DocumentGeneratorWorkflowTrait.php');
$templateSource = $source('app/Service/DocumentTemplateService.php');
$viewSource = $source('app/View/pages/company_document_generator.php');
$controllerSource = $source('app/Http/Controllers/Company/DocumentGeneratorController.php');
$contractorSource = $source('app/Service/ContractorService.php');

foreach ([
    ['new FeoRequestInteractionService()', 'canonical FEO parser wiring'],
    ['extractRequestIds($requestInput)', 'canonical request-id extraction'],
    ['$missing !== []', 'missing requests fail closed'],
    ['count($groups) > 1', 'mixed unload fail closed'],
    ['* 1000', 'tons-to-kg conversion'],
    ['generated_document_requests', 'per-request snapshots'],
    ['copySeedFromGenerated', 'repeat from snapshot seed'],
    ["hash_file('sha256'", 'SHA-256 integrity'],
] as [$needle, $label]) {
    $contains($workflowSource, $needle, $label);
}
foreach ([
    ["str_starts_with(\$entry, '/')", 'archive path guard'],
    ['DOCTYPE|ENTITY', 'XXE/DTD guard'],
    ['Неизвестная переменная', 'placeholder whitelist'],
    ['expandRequestRows', 'repeat-row expansion'],
    ["htmlspecialchars(\$node['text'], ENT_QUOTES | ENT_XML1, 'UTF-8')", 'XML escaping'],
] as [$needle, $label]) {
    $contains($templateSource, $needle, $label);
}
$contains($viewSource, 'Ручной параметр: отдельного canonical поля банка в ERP сейчас нет.', 'manual bank-INN help');
$contains($controllerSource, "requireRole(['company_owner']);", 'template administration owner-only contract');
$ok(!str_contains($contractorSource, 'bank_inn'), 'canonical contractor model unexpectedly contains bank_inn');
echo "DOCUMENT_GENERATOR_CANONICAL_WIRING=PASS\n";

require_once $root . '/bootstrap/app.php';
require_once $root . '/app/Service/AccessControlService.php';
require_once $root . '/app/Service/DocumentService.php';
require_once $root . '/app/Service/DocumentTemplateService.php';
require_once $root . '/app/Service/FeoRequestInteractionService.php';
require_once $root . '/app/Service/DocumentGeneratorService.php';

/* Canonical FEO parser behaviour. */
$parser = new \App\Service\FeoRequestInteractionService();
$eq($parser->extractRequestIds('300001'), ['300001'], 'single FEO id');
$eq($parser->extractRequestIds('300001 300002,300003' . "\n" . '300004'), ['300001', '300002', '300003', '300004'], 'mixed FEO separators');
$eq($parser->extractRequestIds('300001 300002 300001'), ['300001', '300002'], 'FEO parser dedupe/order');
$eq($parser->extractRequestIds('| 300003 | 399999 |' . "\n" . "300004\t399998"), ['300003', '300004'], 'FEO structured first-column parsing');
$eq($parser->extractRequestIds('abc 12345 1234567'), [], 'invalid FEO ids ignored');
echo "DOCUMENT_GENERATOR_FEO_PARSER=PASS\n";

/* Built-in templates: each repository part is independently base64-encoded. */
$canonical = [
    'contract_vehicle_lease_crew_v1.docx' => '0e3e7a3ca4d81face23fa070bcabffacc86636d08affdda91faa3c725d4e8adc',
    'waybill_driver_assignment_v1.docx' => '642e38737380446a6aa89d9314fc9ceafb3c4f2b48197e1f8b7d7f42ba34325c',
];
$files = [];
foreach ($canonical as $name => $sha) {
    $parts = glob($root . '/resources/document-templates/' . $name . '.b64.part*') ?: [];
    sort($parts, SORT_STRING);
    $ok($parts !== [], 'missing canonical template ' . $name);
    $bytes = '';
    foreach ($parts as $part) {
        $encoded = preg_replace('/\s+/', '', (string) file_get_contents($part)) ?? '';
        $decoded = base64_decode($encoded, true);
        $ok(is_string($decoded) && $decoded !== '', 'template part decode ' . basename($part));
        $bytes .= $decoded;
    }
    $ok($bytes !== '', 'template materialization ' . $name);
    $eq(hash('sha256', $bytes), $sha, 'template SHA ' . $name);
    $tmp = tempnam(sys_get_temp_dir(), 'docgen_');
    $ok(is_string($tmp), 'temp file for ' . $name);
    $docx = $tmp . '.docx';
    @unlink($tmp);
    $ok(file_put_contents($docx, $bytes) === strlen($bytes), 'write template ' . $name);
    $files[$name] = $docx;
}
echo "DOCUMENT_GENERATOR_CANONICAL_TEMPLATE_SHA=PASS\n";

/* Local preflight may legitimately lack DOCX/MySQL; GitHub Actions may not. */
$zipReady = class_exists(\ZipArchive::class);
$mysqlReady = in_array('mysql', \PDO::getAvailableDrivers(), true);
$admin = null;
$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
if ($mysqlReady) {
    try {
        $admin = new \PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $user,
            $pass,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (\Throwable) {
        $mysqlReady = false;
    }
}
$strict = getenv('GITHUB_ACTIONS') === 'true' || getenv('PLANEX_DOCGEN_REQUIRE_RUNTIME') === '1';
if (!$zipReady || !$mysqlReady) {
    foreach ($files as $file) @unlink($file);
    $missing = implode(',', array_filter([$zipReady ? null : 'ZIPARCHIVE', $mysqlReady ? null : 'PDO_MYSQL/MYSQL_SERVER']));
    if ($strict) $fail('full runtime required: ' . $missing);
    echo "DOCUMENT_GENERATOR_RUNTIME=REMOTE_REQUIRED:{$missing}\n";
    echo "DOCUMENT_GENERATOR_REGRESSION=PASS_WITH_REMOTE_GATE\n";
    exit(0);
}

/* Template engine structural and security behaviour. */
foreach ($files as $name => $file) {
    $validation = \App\Service\DocumentTemplateService::validateDocxFile($file);
    $ok(($validation['ok'] ?? false) === true, 'canonical DOCX validate ' . $name . ': ' . implode('; ', $validation['errors'] ?? []));
    $eq((int) ($validation['repeat_rows'] ?? -1), 1, 'canonical repeat row ' . $name);
}

$bad = $files['contract_vehicle_lease_crew_v1.docx'] . '.bad.docx';
copy($files['contract_vehicle_lease_crew_v1.docx'], $bad);
$zip = new \ZipArchive();
$ok($zip->open($bad) === true, 'open traversal DOCX');
$zip->addFromString('../escape.xml', '<x/>');
$zip->close();
$ok(!(\App\Service\DocumentTemplateService::validateDocxFile($bad)['ok'] ?? true), 'path traversal must reject');
@unlink($bad);

$bad = $files['contract_vehicle_lease_crew_v1.docx'] . '.xxe.docx';
copy($files['contract_vehicle_lease_crew_v1.docx'], $bad);
$zip = new \ZipArchive();
$ok($zip->open($bad) === true, 'open XXE DOCX');
$xml = (string) $zip->getFromName('word/document.xml');
$xml = preg_replace('/<\?xml[^>]*\?>/i', '', $xml, 1) ?? $xml;
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><!DOCTYPE w:document [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $xml);
$zip->close();
$ok(!(\App\Service\DocumentTemplateService::validateDocxFile($bad)['ok'] ?? true), 'XXE/DTD must reject');
@unlink($bad);

$bad = $files['contract_vehicle_lease_crew_v1.docx'] . '.unknown.docx';
copy($files['contract_vehicle_lease_crew_v1.docx'], $bad);
$zip = new \ZipArchive();
$ok($zip->open($bad) === true, 'open unknown-placeholder DOCX');
$xml = (string) $zip->getFromName('word/document.xml');
$zip->addFromString('word/document.xml', str_replace('</w:body>', '<w:p><w:r><w:t>{{unknown.forbidden}}</w:t></w:r></w:p></w:body>', $xml));
$zip->close();
$validation = \App\Service\DocumentTemplateService::validateDocxFile($bad);
$ok(!($validation['ok'] ?? true) && str_contains(implode(' ', $validation['errors'] ?? []), 'unknown.forbidden'), 'unknown placeholder must reject');
@unlink($bad);
foreach ($files as $file) @unlink($file);
echo "DOCUMENT_GENERATOR_DOCX_SECURITY=PASS\n";

/* Isolated tenant database: no production data, no production writes. */
$dbName = 'planex_docgen_' . getmypid() . '_' . bin2hex(random_bytes(3));
$quotedDb = '`' . $dbName . '`';
$admin->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$companyId = 77001;
$companyStorage = storage_path('companies/' . $companyId . '/document-generator');

try {
    $pdo = new \PDO(
        "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",
        $user,
        $pass,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]
    );

    $schema = [
        "CREATE TABLE contractors(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),inn VARCHAR(32),kpp VARCHAR(32),ogrn VARCHAR(32),contractor_type VARCHAR(32),legal_address TEXT,physical_address TEXT,bank_account VARCHAR(64),bank_name VARCHAR(255),bank_bik VARCHAR(32),bank_corr_account VARCHAR(64),director_full_name VARCHAR(255),director_position VARCHAR(255),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE drivers(id INT UNSIGNED PRIMARY KEY,full_name VARCHAR(255),passport_number VARCHAR(64),passport_issued_by VARCHAR(255),passport_issue_date DATE,license_number VARCHAR(64),license_issue_date DATE,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE vehicle_units(id INT UNSIGNED PRIMARY KEY,brand VARCHAR(255),model VARCHAR(255),plate_number VARCHAR(64),created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE vehicle_sets(id INT UNSIGNED PRIMARY KEY,primary_vehicle_unit_id INT UNSIGNED,secondary_vehicle_unit_id INT UNSIGNED,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE driver_vehicle_blocks(id INT UNSIGNED PRIMARY KEY,driver_id INT UNSIGNED,vehicle_set_id INT UNSIGNED,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE crews(id INT UNSIGNED PRIMARY KEY,contractor_id INT UNSIGNED,driver_vehicle_block_id INT UNSIGNED,created_by_user_id INT,status VARCHAR(32) DEFAULT 'active',deleted_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE crew_drivers(crew_id INT UNSIGNED,driver_id INT UNSIGNED,PRIMARY KEY(crew_id,driver_id)) ENGINE=InnoDB",
        "CREATE TABLE entity_access_grants(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,entity_type VARCHAR(64),entity_id INT UNSIGNED,granted_to_user_id INT UNSIGNED,access_level VARCHAR(16),revoked_at DATETIME NULL) ENGINE=InnoDB",
        "CREATE TABLE feo_requests(zayavka_id VARCHAR(64) PRIMARY KEY,mass_netto DECIMAL(18,6),naim_oo_gruzootpravitel VARCHAR(255),mno_adres_pogruzki TEXT,kontakt_tel VARCHAR(64),tel_dopolnitelnyy VARCHAR(64),kontakt_email VARCHAR(255),adres_vygruzki_otkhodov TEXT) ENGINE=InnoDB",
    ];
    foreach ($schema as $sql) $pdo->exec($sql);

    $migration = $source('database/migrations-local/127_create_document_generator.sql');
    foreach (preg_split('/;\s*(?:\R|$)/', $migration) ?: [] as $sql) {
        $sql = trim($sql);
        if ($sql !== '') $pdo->exec($sql);
    }
    $ok(\App\Service\DocumentGeneratorService::schemaReady($pdo), 'migration 127 schema');

    $pdo->exec("INSERT INTO contractors VALUES
        (1,'ООО Исполнитель & Партнер','7701234567','770101001','1027700000001','legal','Москва','Москва','40702810900000000001','Банк','044525225','30101810400000000225','Петров Петр Петрович','Директор',101,'active',NULL),
        (2,'ООО Чужой','7801234567','780101001','1027800000001','legal','СПб','СПб','40702810900000000002','Банк2','044030653','30101810500000000653','Сидоров Сидор Сидорович','Директор',202,'active',NULL)");
    $pdo->exec("INSERT INTO drivers VALUES
        (1,'Иванов Иван Иванович','4510 123456','ОВД','2020-01-02','77 11 123456','2021-02-03',101,'active',NULL),
        (2,'Чужой Водитель','4510 654321','ОВД','2020-03-04','78 22 654321','2021-04-05',202,'active',NULL)");
    $pdo->exec("INSERT INTO vehicle_units VALUES
        (1,'КАМАЗ','5490','А123АА77',101,'active',NULL),
        (2,'МАЗ','5440','В456ВВ78',202,'active',NULL)");
    $pdo->exec("INSERT INTO vehicle_sets VALUES(1,1,NULL,101,'active',NULL),(2,2,NULL,202,'active',NULL)");
    $pdo->exec("INSERT INTO driver_vehicle_blocks VALUES(1,1,1,101,'active',NULL),(2,2,2,202,'active',NULL)");
    $pdo->exec("INSERT INTO crews VALUES(1,1,1,101,'active',NULL),(2,2,2,202,'active',NULL)");
    $pdo->exec("INSERT INTO crew_drivers VALUES(1,1),(2,2)");
    $pdo->exec("INSERT INTO feo_requests VALUES
        ('300001',1.250000,'Отправитель & 1','Москва <1>','+79990000001',NULL,'one@example.test','Воскресенск'),
        ('300002',0.500000,'Отправитель 2','Москва 2','+79990000002','+79990000003','two@example.test','Воскресенск'),
        ('300003',0.250000,'Отправитель 3','Москва 3','+79990000004',NULL,'three@example.test','Коломна'),
        ('300004',0.100000,NULL,'Москва 4','+79990000005',NULL,'four@example.test','Воскресенск')");

    $company = [
        'id' => $companyId,
        'name' => 'ООО «ПЛАНЭКС ТЕСТ»',
        'inn' => '5005061234',
        'kpp' => '500501001',
        'ogrn' => '1205000000001',
        'legal_address' => 'Воскресенск',
        'physical_address' => 'Воскресенск',
        'bank_name' => 'Банк ПЛАНЭКС ТЕСТ',
        'bank_account' => '40702810000000000001',
        'bank_corr_account' => '30101810000000000001',
        'bank_bik' => '044525001',
        'contact_phone' => '+7 495 000-00-00',
        'contact_email' => 'info@example.test',
        'director_full_name' => 'Гладских Карина Владимировна',
        'director_position' => 'Генеральный директор',
    ];

    /* Clean-tenant seeding proves the built-in resource path is executable. */
    \App\Service\DocumentGeneratorService::seedDefaultTemplates($pdo, $companyId, 1, 'company_owner');
    $templates = \App\Service\DocumentGeneratorService::listTemplates($pdo, true);
    $eq(count($templates), 2, 'two canonical templates seeded');
    $byType = [];
    foreach ($templates as $row) $byType[$row['document_type']] = $row;
    $eq($byType['vehicle_lease_crew']['sha256'], $canonical['contract_vehicle_lease_crew_v1.docx'], 'contract template SHA');
    $eq($byType['waybill_driver_assignment']['sha256'], $canonical['waybill_driver_assignment_v1.docx'], 'waybill template SHA');

    /* Executor/access isolation. */
    $eq(array_map('intval', array_column(\App\Service\DocumentGeneratorService::listAccessibleExecutors($pdo, 101, 'logist'), 'crew_id')), [1], 'logist own executor scope');
    $ok(\App\Service\DocumentGeneratorService::loadAccessibleExecutor($pdo, 999, 101, 'company_owner') === null, 'missing executor');
    $ok(\App\Service\DocumentGeneratorService::loadAccessibleExecutor($pdo, 2, 101, 'logist') === null, 'foreign executor blocked');
    $pdo->exec("INSERT INTO entity_access_grants(entity_type,entity_id,granted_to_user_id,access_level) VALUES('crew',2,101,'view')");
    $ok(\App\Service\DocumentGeneratorService::loadAccessibleExecutor($pdo, 2, 101, 'logist') !== null, 'crew grant allows');
    $pdo->exec('DELETE FROM entity_access_grants');
    echo "DOCUMENT_GENERATOR_ACCESS_ISOLATION=PASS\n";

    $params = [
        'contract_number' => '26-DG-001',
        'contract_date' => '2026-09-01',
        'start_date' => '2026-09-03',
        'start_time' => '09:30',
        'finish_date' => '2026-09-10',
        'price' => '100000',
        'vat_mode' => 'без НДС',
        'executor_bank_inn' => '7707083893',
    ];

    /* Request/business validation. */
    $single = \App\Service\DocumentGeneratorService::prepare($pdo, $company, $companyId, 101, 'company_owner', (int) $byType['vehicle_lease_crew']['id'], 1, '300001', $params);
    $ok($single['ok'] ?? false, 'single request prepare: ' . implode('; ', $single['errors'] ?? []));
    $eq((float) $single['total_mass_kg'], 1250.0, 'single mass kg');
    $eq(count($single['request_values']), 1, 'single repeat row');

    $multi = \App\Service\DocumentGeneratorService::prepare($pdo, $company, $companyId, 101, 'company_owner', (int) $byType['vehicle_lease_crew']['id'], 1, "300001\n300002", $params);
    $ok($multi['ok'] ?? false, 'multi contract prepare: ' . implode('; ', $multi['errors'] ?? []));
    $eq((float) $multi['total_mass_kg'], 1750.0, 'multi mass kg');
    $eq((string) $multi['values']['executor.bank_inn'], '7707083893', 'manual bank INN');
    $eq(count($multi['request_values']), 2, 'multi repeat rows');
    $eq((string) $multi['unload_address'], 'Воскресенск', 'common unload allowed');

    $missing = \App\Service\DocumentGeneratorService::prepare($pdo, $company, $companyId, 101, 'company_owner', (int) $byType['vehicle_lease_crew']['id'], 1, "300001\n399999", $params);
    $ok(!($missing['ok'] ?? true) && ($missing['missing_request_ids'] ?? []) === ['399999'], 'missing request fail closed');

    $mixed = \App\Service\DocumentGeneratorService::prepare($pdo, $company, $companyId, 101, 'company_owner', (int) $byType['vehicle_lease_crew']['id'], 1, "300001\n300003", $params);
    $ok(!($mixed['ok'] ?? true) && str_contains(implode(' ', $mixed['errors'] ?? []), 'разные места выгрузки'), 'mixed unload fail closed');

    $incomplete = \App\Service\DocumentGeneratorService::prepare($pdo, $company, $companyId, 101, 'company_owner', (int) $byType['vehicle_lease_crew']['id'], 1, '300004', $params);
    $ok(!($incomplete['ok'] ?? true) && str_contains(implode(' ', $incomplete['errors'] ?? []), 'нет грузоотправителя'), 'incomplete request fail closed');
    echo "DOCUMENT_GENERATOR_DATA_VALIDATION=PASS\n";

    /* Real single-request generation. */
    $singleId = \App\Service\DocumentGeneratorService::generate($pdo, $companyId, 101, 'company_owner', $single);
    $singleRow = \App\Service\DocumentGeneratorService::getGenerated($pdo, $singleId);
    $ok(is_array($singleRow), 'single document history row');
    $eq((int) $pdo->query('SELECT COUNT(*) FROM generated_document_requests WHERE generated_document_id=' . $singleId)->fetchColumn(), 1, 'single request snapshot row');

    /* Real multi-request contract generation. */
    $contractId = \App\Service\DocumentGeneratorService::generate($pdo, $companyId, 101, 'company_owner', $multi);
    $contractRow = \App\Service\DocumentGeneratorService::getGenerated($pdo, $contractId);
    $ok(is_array($contractRow), 'contract history row');
    $contractPath = \App\Service\DocumentService::resolveStoredPath($companyId, (string) $contractRow['stored_path']);
    $ok(is_string($contractPath) && is_file($contractPath), 'contract stored');
    $eq(hash_file('sha256', $contractPath), $contractRow['sha256'], 'contract SHA');
    $ok(\App\Service\DocumentService::resolveStoredPath($companyId + 1, (string) $contractRow['stored_path']) === null, 'cross-tenant generated path blocked');
    $validation = \App\Service\DocumentTemplateService::validateDocxFile($contractPath);
    $ok($validation['ok'] ?? false, 'contract structural DOCX: ' . implode('; ', $validation['errors'] ?? []));
    $eq($validation['placeholders'] ?? [], [], 'contract placeholder leftovers');

    $zip = new \ZipArchive();
    $ok($zip->open($contractPath) === true, 'open generated contract');
    $contractXml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    $contractText = \App\Service\DocumentTemplateService::visibleText($contractXml);
    $eq(substr_count($contractText, '300001'), 1, 'contract request 300001 row count');
    $eq(substr_count($contractText, '300002'), 1, 'contract request 300002 row count');
    $contains($contractXml, '&amp;', 'contract XML ampersand escaping');
    $contains($contractXml, '&lt;1&gt;', 'contract XML angle-bracket escaping');
    $notContains($contractText, '{{', 'contract raw placeholders');
    $notContains($contractText, 'None', 'contract literal None');
    $notContains($contractText, '***', 'contract masked requisites');
    $notContains($contractText, 'Келян Артем Викторович', 'contract hard-coded example requisites');

    $snapshot = json_decode((string) $contractRow['snapshot_json'], true);
    $ok(is_array($snapshot), 'contract snapshot JSON');
    $eq((float) $snapshot['requests_summary']['total_mass_kg'], 1750.0, 'snapshot mass');
    $eq((int) $pdo->query('SELECT COUNT(*) FROM generated_document_requests WHERE generated_document_id=' . $contractId)->fetchColumn(), 2, 'multi request snapshot rows');
    $seed = \App\Service\DocumentGeneratorService::copySeedFromGenerated($contractRow);
    $eq($seed['request_input'], "300001\n300002", 'repeat snapshot IDs');
    $ok(\App\Service\DocumentGeneratorService::getAccessibleGenerated($pdo, $contractId, 202, 'logist') === null, 'foreign history blocked');
    $ok(\App\Service\DocumentGeneratorService::getAccessibleGenerated($pdo, $contractId, 101, 'logist') !== null, 'owned history allowed');

    /* Mutating master data must not mutate stored snapshot or file. */
    $beforeHash = hash_file('sha256', $contractPath);
    $beforeSnapshot = (string) $contractRow['snapshot_json'];
    $pdo->exec("UPDATE contractors SET name='ИЗМЕНЕННЫЙ ИСПОЛНИТЕЛЬ' WHERE id=1");
    $pdo->exec("UPDATE drivers SET full_name='ИЗМЕНЕННЫЙ ВОДИТЕЛЬ' WHERE id=1");
    $pdo->exec("UPDATE vehicle_units SET model='ИЗМЕНЕННАЯ МОДЕЛЬ' WHERE id=1");
    $pdo->exec("UPDATE feo_requests SET mass_netto=9.999 WHERE zayavka_id='300001'");
    $afterRow = \App\Service\DocumentGeneratorService::getGenerated($pdo, $contractId);
    $eq((string) $afterRow['snapshot_json'], $beforeSnapshot, 'immutable generation snapshot');
    $eq(hash_file('sha256', $contractPath), $beforeHash, 'immutable generated file');
    echo "DOCUMENT_GENERATOR_CONTRACT_DOCX=PASS\n";

    /* Restore master data required for second document generation. */
    $pdo->exec("UPDATE contractors SET name='ООО Исполнитель & Партнер' WHERE id=1");
    $pdo->exec("UPDATE drivers SET full_name='Иванов Иван Иванович' WHERE id=1");
    $pdo->exec("UPDATE vehicle_units SET model='5490' WHERE id=1");
    $pdo->exec("UPDATE feo_requests SET mass_netto=1.250000 WHERE zayavka_id='300001'");

    /* Real waybill generation with multiple requests. */
    $waybillPrepared = \App\Service\DocumentGeneratorService::prepare(
        $pdo,
        $company,
        $companyId,
        101,
        'company_owner',
        (int) $byType['waybill_driver_assignment']['id'],
        1,
        "300001\n300002",
        ['waybill_number' => 'ПЛ-001', 'waybill_date' => '2026-09-03']
    );
    $ok($waybillPrepared['ok'] ?? false, 'waybill prepare: ' . implode('; ', $waybillPrepared['errors'] ?? []));
    $waybillId = \App\Service\DocumentGeneratorService::generate($pdo, $companyId, 101, 'company_owner', $waybillPrepared);
    $waybillRow = \App\Service\DocumentGeneratorService::getGenerated($pdo, $waybillId);
    $waybillPath = \App\Service\DocumentService::resolveStoredPath($companyId, (string) $waybillRow['stored_path']);
    $ok(is_string($waybillPath) && is_file($waybillPath), 'waybill stored');
    $validation = \App\Service\DocumentTemplateService::validateDocxFile($waybillPath);
    $ok($validation['ok'] ?? false, 'waybill structural DOCX: ' . implode('; ', $validation['errors'] ?? []));
    $eq($validation['placeholders'] ?? [], [], 'waybill placeholder leftovers');

    $zip = new \ZipArchive();
    $ok($zip->open($waybillPath) === true, 'open generated waybill');
    $waybillXml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    $waybillText = \App\Service\DocumentTemplateService::visibleText($waybillXml);
    $eq(substr_count($waybillText, '300001'), 1, 'waybill request 300001 row count');
    $eq(substr_count($waybillText, '300002'), 1, 'waybill request 300002 row count');
    $notContains($waybillText, '{{', 'waybill raw placeholders');
    $notContains($waybillText, 'None', 'waybill literal None');
    $notContains($waybillText, '***', 'waybill masked requisites');
    echo "DOCUMENT_GENERATOR_WAYBILL_DOCX=PASS\n";

    /* Preserve generated artifacts for independent visual acceptance. */
    $artifactDir = trim((string) (getenv('PLANEX_DOCGEN_ARTIFACT_DIR') ?: ''));
    if ($artifactDir !== '') {
        if (!str_starts_with($artifactDir, DIRECTORY_SEPARATOR)) $artifactDir = $root . '/' . $artifactDir;
        if (!is_dir($artifactDir)) mkdir($artifactDir, 0775, true);
        $artifactMap = [
            'contract_vehicle_lease_crew_regression.docx' => $contractPath,
            'waybill_driver_assignment_regression.docx' => $waybillPath,
        ];
        $manifest = [];
        foreach ($artifactMap as $name => $sourcePath) {
            $target = rtrim($artifactDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            $ok(copy($sourcePath, $target), 'preserve ' . $name);
            $manifest[$name] = ['sha256' => hash_file('sha256', $target), 'bytes' => filesize($target)];
        }
        file_put_contents($artifactDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo "DOCUMENT_GENERATOR_DOCX_ARTIFACTS=PRESERVED\n";
    }

    echo "DOCUMENT_GENERATOR_REGRESSION=PASS\n";
} finally {
    $removeTree($companyStorage);
    try {
        $admin->exec("DROP DATABASE IF EXISTS {$quotedDb}");
    } catch (\Throwable) {
    }
}
