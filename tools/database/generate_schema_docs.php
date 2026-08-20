#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Service\LocalMigrationService;
use Planex\Tools\Database\TenantSchemaCatalog;

require_once dirname(__DIR__, 2) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Service/LocalMigrationService.php';
require_once __DIR__ . '/TenantSchemaCatalog.php';

$mode = in_array('--write', $argv, true) ? 'write' : (in_array('--check', $argv, true) ? 'check' : 'stdout');
$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$dbName = 'planex_schema_docs_' . getmypid() . '_' . bin2hex(random_bytes(4));
$admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$quotedDb = '`' . str_replace('`', '``', $dbName) . '`';
$admin->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    LocalMigrationService::apply($pdo);
    $catalog = TenantSchemaCatalog::capture($pdo);
    $files = [
        'TENANT_SCHEMA.md' => TenantSchemaCatalog::renderMarkdown($catalog),
        'TENANT_ERD.md' => TenantSchemaCatalog::renderErd($catalog),
    ];

    $repoDir = BASE_PATH . '/docs/database';
    $candidateDir = getenv('PLANEX_SCHEMA_CANDIDATE_DIR') ?: sys_get_temp_dir() . '/planex-tenant-schema-docs';
    if (!is_dir($candidateDir) && !mkdir($candidateDir, 0777, true) && !is_dir($candidateDir)) {
        throw new RuntimeException('Cannot create candidate dir.');
    }
    foreach ($files as $name => $content) {
        file_put_contents($candidateDir . '/' . $name, $content);
    }

    if ($mode === 'write') {
        if (!is_dir($repoDir) && !mkdir($repoDir, 0777, true) && !is_dir($repoDir)) {
            throw new RuntimeException('Cannot create docs dir.');
        }
        foreach ($files as $name => $content) {
            file_put_contents($repoDir . '/' . $name, $content);
        }
        fwrite(STDOUT, "TENANT_SCHEMA_DOCS=WRITTEN\nSCHEMA_FINGERPRINT=" . TenantSchemaCatalog::fingerprint($catalog) . "\n");
        exit(0);
    }

    if ($mode === 'check') {
        $drift = [];
        foreach ($files as $name => $content) {
            $path = $repoDir . '/' . $name;
            if (!is_file($path) || file_get_contents($path) !== $content) {
                $drift[] = $name;
            }
        }
        if ($drift !== []) {
            fwrite(STDERR, 'TENANT_SCHEMA_DOCS_DRIFT=' . implode(',', $drift) . "\n");
            fwrite(STDERR, 'Candidates: ' . $candidateDir . "\n");
            exit(1);
        }
        fwrite(STDOUT, "TENANT_SCHEMA_DOCS_DRIFT=0\nSCHEMA_FINGERPRINT=" . TenantSchemaCatalog::fingerprint($catalog) . "\n");
        exit(0);
    }

    fwrite(STDOUT, $files['TENANT_SCHEMA.md']);
} finally {
    $admin->exec("DROP DATABASE IF EXISTS {$quotedDb}");
}
