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
$seedPath = (string) (getenv('PLANEX_FEO_STAGE1_SEED_PATH') ?: ($argv[2] ?? (__DIR__ . '/feo_stage1_utilizer_seed_data.php')));
if (!is_file($seedPath)) {
    throw new RuntimeException('Stage 1 utilizer seed data missing');
}
$targetCompanyId = (int) (getenv('PLANEX_TARGET_COMPANY_ID') ?: 0);
$targetDbIdentifier = trim((string) getenv('PLANEX_TARGET_DB_IDENTIFIER'));
if ($targetCompanyId !== 25 || $targetDbIdentifier !== 'spugovxsim_1') {
    throw new RuntimeException('Production seed target must be exact PLANEX company 25 / spugovxsim_1');
}

$config = require $root . '/bootstrap/app.php';
require_once $root . '/app/Support/crypto_helper.php';
require_once $root . '/app/Support/company_database.php';
require_once $root . '/app/Service/FeoUtilizerService.php';

$seed = require $seedPath;
$utilizerSeed = is_array($seed['utilizers'] ?? null) ? $seed['utilizers'] : [];
$ruleSeed = is_array($seed['rules'] ?? null) ? $seed['rules'] : [];
if (count($utilizerSeed) !== 8 || count($ruleSeed) !== 38) {
    throw new RuntimeException('Unexpected Stage 1 seed cardinality');
}

$centralCfg = $config['database'];
$central = new PDO(
    'mysql:host=' . $centralCfg['host'] . ';port=' . ($centralCfg['port'] ?? 3306) . ';dbname=' . $centralCfg['database'] . ';charset=utf8mb4',
    $centralCfg['username'],
    $centralCfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$q = $central->prepare(
    "SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password
       FROM companies WHERE id=? LIMIT 1"
);
$q->execute([$targetCompanyId]);
$company = $q->fetch(PDO::FETCH_ASSOC);
if (!$company || (string) $company['status'] !== 'active' || (string) $company['db_identifier'] !== $targetDbIdentifier) {
    throw new RuntimeException('Exact PLANEX target company is not active or DB identity changed');
}

$cfg = companyDatabaseConfig($config, $company);
$pdo = new PDO(
    'mysql:host=' . $cfg['host'] . ';port=' . ($cfg['port'] ?? 3306) . ';dbname=' . $cfg['database'] . ';charset=utf8mb4',
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $targetDbIdentifier) {
    throw new RuntimeException('Unexpected tenant database');
}

$requiredTables = ['feo_requests', 'feo_placement_current', 'feo_utilizers', 'feo_utilizer_match_rules', 'feo_request_utilizer_overrides', 'schema_migrations'];
$marks = implode(',', array_fill(0, count($requiredTables), '?'));
$q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $marks . ')');
$q->execute($requiredTables);
if ((int) $q->fetchColumn() !== count($requiredTables)) {
    throw new RuntimeException('Stage 1 schema prerequisites missing');
}
$q = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');
$q->execute(['125_create_feo_map_utilizers.sql']);
if ($q->fetchColumn() === false) {
    throw new RuntimeException('Migration 125 is not journaled in target tenant');
}

$lockName = 'planex_feo_stage1_seed_' . substr(hash('sha256', $targetDbIdentifier), 0, 20);
$q = $pdo->prepare('SELECT GET_LOCK(?,10)');
$q->execute([$lockName]);
if ((int) $q->fetchColumn() !== 1) {
    throw new RuntimeException('Stage 1 seed lock failed');
}

$utilizerIds = [];
$createdUtilizers = 0;
$existingUtilizers = 0;
$createdRules = 0;
$existingRules = 0;

$equalUtilizer = static function (array $row, array $expected): bool {
    $sameFloat = static fn($a, $b): bool => abs((float) $a - (float) $b) < 0.0000001;
    return (string) $row['full_name'] === (string) $expected['full_name']
        && (string) ($row['address'] ?? '') === (string) $expected['address']
        && (string) ($row['fkko_classification'] ?? '') === (string) $expected['fkko_classification']
        && (int) $row['adr'] === (int) $expected['adr']
        && $sameFloat($row['latitude'], $expected['latitude'])
        && $sameFloat($row['longitude'], $expected['longitude'])
        && strtoupper((string) $row['color_hex']) === strtoupper((string) $expected['color_hex'])
        && (int) $row['is_active'] === 1
        && (int) $row['sort_order'] === (int) $expected['sort_order'];
};

try {
    $pdo->beginTransaction();

    foreach ($utilizerSeed as $u) {
        $short = (string) $u['short_name'];
        $q = $pdo->prepare('SELECT * FROM feo_utilizers WHERE short_name=? FOR UPDATE');
        $q->execute([$short]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (!$equalUtilizer($row, $u)) {
                throw new RuntimeException('Existing utilizer drift detected for ' . $short . '; refusing to overwrite');
            }
            $utilizerIds[$short] = (int) $row['id'];
            $existingUtilizers++;
            continue;
        }

        $q = $pdo->prepare(
            'INSERT INTO feo_utilizers
             (full_name,short_name,address,latitude,longitude,fkko_classification,adr,color_hex,is_active,sort_order,created_by_user_id,updated_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,1,?,NULL,NULL)'
        );
        $q->execute([
            $u['full_name'], $short, $u['address'], $u['latitude'], $u['longitude'],
            $u['fkko_classification'], $u['adr'], strtoupper((string) $u['color_hex']), $u['sort_order'],
        ]);
        $utilizerIds[$short] = (int) $pdo->lastInsertId();
        $createdUtilizers++;
    }

    foreach ($ruleSeed as $rule) {
        $short = (string) $rule['utilizer'];
        if (!isset($utilizerIds[$short])) {
            throw new RuntimeException('Rule references unknown utilizer ' . $short);
        }
        $normalized = App\Service\FeoUtilizerService::normalizeText((string) $rule['pattern']);
        if ($normalized === '') {
            throw new RuntimeException('Empty normalized seed rule');
        }
        $q = $pdo->prepare(
            'SELECT * FROM feo_utilizer_match_rules WHERE source_field=? AND match_type=? AND normalized_pattern=? FOR UPDATE'
        );
        $q->execute([$rule['source_field'], $rule['match_type'], $normalized]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ((int) $row['utilizer_id'] !== $utilizerIds[$short]
                || (string) $row['pattern'] !== (string) $rule['pattern']
                || (int) $row['priority'] !== (int) $rule['priority']
                || (int) $row['is_active'] !== 1) {
                throw new RuntimeException('Existing matching rule drift detected for ' . $short . '; refusing to overwrite');
            }
            $existingRules++;
            continue;
        }
        $q = $pdo->prepare(
            'INSERT INTO feo_utilizer_match_rules
             (utilizer_id,source_field,match_type,pattern,normalized_pattern,priority,is_active,created_by_user_id,updated_by_user_id)
             VALUES (?,?,?,?,?,?,1,NULL,NULL)'
        );
        $q->execute([
            $utilizerIds[$short], $rule['source_field'], $rule['match_type'], $rule['pattern'], $normalized, $rule['priority'],
        ]);
        $createdRules++;
    }

    $pdo->commit();
    printf(
        "FEO_STAGE1_SEED_OK company_id=%d db=%s utilizers_created=%d utilizers_existing=%d rules_created=%d rules_existing=%d\n",
        $targetCompanyId, $targetDbIdentifier, $createdUtilizers, $existingUtilizers, $createdRules, $existingRules
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
} finally {
    $q = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $q->execute([$lockName]);
}
