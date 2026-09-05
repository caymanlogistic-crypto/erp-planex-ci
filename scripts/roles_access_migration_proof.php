<?php

declare(strict_types=1);

use App\Core\Database;
use App\Security\PageRegistry;
use Planex\Tools\RolesAccess\AccessSnapshotCollector;
use Planex\Tools\RolesAccess\MigrationProof;

require_once dirname(__DIR__) . '/tools/roles_access/MigrationProof.php';
require_once dirname(__DIR__) . '/tools/roles_access/AccessSnapshotCollector.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$command = $argv[1] ?? '';
$read = static function (?string $path): array {
    if ($path === null || !is_file($path)) {
        throw new RuntimeException('Required JSON file is missing: ' . (string) $path);
    }
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
};
$snapshotDb = static function () use ($argv, $read): array {
    $companyId = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $phase = strtoupper((string) ($argv[3] ?? ''));
    $sourceHead = (string) ($argv[4] ?? '');
    if ($companyId === false || !in_array($phase, ['BEFORE', 'AFTER'], true)) {
        throw new RuntimeException('Usage: snapshot-db COMPANY_ID BEFORE|AFTER SOURCE_HEAD [LEGACY_PAGES_JSON]');
    }

    $config = require dirname(__DIR__) . '/bootstrap/app.php';
    require_once dirname(__DIR__) . '/app/Core/Database.php';
    require_once dirname(__DIR__) . '/app/Support/company_database.php';
    require_once dirname(__DIR__) . '/app/Security/PageRegistry.php';
    $central = (new Database($config['database']))->connection();
    $companyStmt = $central->prepare(
        'SELECT id,name,status,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE id=?'
    );
    $companyStmt->execute([(int) $companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company) {
        throw new RuntimeException('Company not found.');
    }
    $tenant = (new Database(companyDatabaseConfig($config, $company)))->connection();

    return $phase === 'BEFORE'
        ? AccessSnapshotCollector::before($central, $tenant, (int) $companyId, $sourceHead, $read($argv[5] ?? null))
        : AccessSnapshotCollector::after($central, $tenant, (int) $companyId, $sourceHead, (new PageRegistry())->pageKeys());
};

try {
    $result = match ($command) {
        'identity-plan' => MigrationProof::identityPlan($read($argv[2] ?? null)),
        'canonical-snapshot' => MigrationProof::canonicalSnapshot($read($argv[2] ?? null)),
        'compare' => MigrationProof::compare($read($argv[2] ?? null), $read($argv[3] ?? null), isset($argv[4]) ? $read($argv[4]) : []),
        'readiness' => MigrationProof::readiness($read($argv[2] ?? null)),
        'snapshot-db' => $snapshotDb(),
        default => throw new RuntimeException('Usage: identity-plan INPUT | canonical-snapshot SNAPSHOT | compare BEFORE AFTER [EXCEPTIONS] | readiness MANIFEST | snapshot-db COMPANY_ID BEFORE|AFTER SOURCE_HEAD [LEGACY_PAGES_JSON]'),
    };
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    if (($result['blocked'] ?? false) === true || ($result['accepted'] ?? true) === false || ($result['ready'] ?? true) === false) {
        exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
