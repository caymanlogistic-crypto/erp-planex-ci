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
$fixedDb = trim((string) getenv('PLANEX_TEST_FIXED_DB'));
$fixedAllowlist = array_filter(array_map('trim', explode(',', (string) getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($fixedDb !== '' && !in_array($fixedDb, $fixedAllowlist, true)) {
    throw new RuntimeException('Fixed DB is not present in PLANEX_TEST_FIXED_DB_ALLOWLIST.');
}
$fixedMode = $fixedDb !== '';
$dbName = $fixedMode ? $fixedDb : 'planex_schema_docs_' . getmypid() . '_' . bin2hex(random_bytes(4));
$adminDsn = "mysql:host={$host};port={$port}" . ($fixedMode ? ";dbname={$dbName}" : '') . ';charset=utf8mb4';
$admin = new PDO($adminDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$quotedDb = '`' . str_replace('`', '``', $dbName) . '`';
if ($fixedMode) {
    require_once BASE_PATH . '/app/Support/company_database.php';
    cleanCompanyPoolDatabase($admin);
} else {
    $admin->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

$slug = static function (string $value): string {
    $value = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? 'DOMAIN');
    return trim($value, '_') ?: 'DOMAIN';
};

$chunkSections = static function (array $sections, int $maxBytes = 12000): array {
    $chunks = [];
    $current = [];
    $currentBytes = 0;
    foreach ($sections as $section) {
        $bytes = strlen($section['section']);
        if ($current !== [] && $currentBytes + $bytes > $maxBytes) {
            $chunks[] = $current;
            $current = [];
            $currentBytes = 0;
        }
        $current[] = $section;
        $currentBytes += $bytes;
    }
    if ($current !== []) {
        $chunks[] = $current;
    }
    return $chunks;
};

$buildSchemaFiles = static function (array $catalog) use ($slug, $chunkSections): array {
    $full = TenantSchemaCatalog::renderMarkdown($catalog);
    $parts = preg_split('/(?=^## `)/m', $full) ?: [$full];
    $header = array_shift($parts) ?? '';
    $byDomain = [];
    foreach ($parts as $section) {
        if (!preg_match('/^## `([^`]+)`.*?^\*\*Domain:\*\* (.+?)<br>$/ms', $section, $m)) {
            throw new RuntimeException('Cannot classify generated schema section.');
        }
        $byDomain[$m[2]][] = ['table' => $m[1], 'section' => $section];
    }
    ksort($byDomain, SORT_STRING);

    $files = [];
    $links = [];
    foreach ($byDomain as $domain => $sections) {
        $chunks = $chunkSections($sections);
        foreach ($chunks as $index => $chunk) {
            $suffix = count($chunks) > 1 ? sprintf('_%02d', $index + 1) : '';
            $name = 'TENANT_SCHEMA_' . $slug($domain) . $suffix . '.md';
            $tables = array_column($chunk, 'table');
            $body = '# ERP PLANEX — Tenant Physical Schema — ' . $domain;
            if (count($chunks) > 1) {
                $body .= ' — Part ' . ($index + 1);
            }
            $body .= "\n\n> GENERATED FILE. Do not edit manually. Physical source of truth: `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.\n\n";
            $body .= '**Schema fingerprint:** `' . TenantSchemaCatalog::fingerprint($catalog) . "`\n\n";
            $body .= implode('', array_column($chunk, 'section'));
            $files[$name] = $body;
            $links[] = '- [' . $domain . (count($chunks) > 1 ? ' — part ' . ($index + 1) : '') . '](' . $name . '): ' . implode(', ', array_map(static fn(string $t): string => '`' . $t . '`', $tables));
        }
    }

    $index = rtrim($header) . "\n\n## Detailed generated catalog\n\n";
    $index .= "Each linked file below is generated from the same disposable-MySQL `information_schema` snapshot. Columns, NULL/defaults, PK/UNIQUE/indexes and physical FKs are maintained there, not manually in Notion.\n\n";
    $index .= implode("\n", $links) . "\n";
    $files = ['TENANT_SCHEMA.md' => $index] + $files;
    return $files;
};

$writeFiles = static function (string $dir, array $files): void {
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create schema docs directory: ' . $dir);
    }
    foreach ($files as $name => $content) {
        file_put_contents($dir . '/' . $name, $content);
    }
};

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    LocalMigrationService::apply($pdo);
    $catalog = TenantSchemaCatalog::capture($pdo);
    $files = $buildSchemaFiles($catalog);
    $files['TENANT_ERD.md'] = TenantSchemaCatalog::renderErd($catalog);
    foreach ($files as $name => $content) {
        $files[$name] = rtrim($content) . "\n";
    }
    ksort($files, SORT_STRING);

    $repoDir = BASE_PATH . '/docs/database';
    $candidateDir = getenv('PLANEX_SCHEMA_CANDIDATE_DIR') ?: sys_get_temp_dir() . '/planex-tenant-schema-docs';
    $writeFiles($candidateDir, $files);

    if ($mode === 'write') {
        $writeFiles($repoDir, $files);
        foreach (glob($repoDir . '/TENANT_SCHEMA_*.md') ?: [] as $existing) {
            $name = basename($existing);
            if (!array_key_exists($name, $files)) {
                unlink($existing);
            }
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
        foreach (glob($repoDir . '/TENANT_SCHEMA_*.md') ?: [] as $existing) {
            $name = basename($existing);
            if (!array_key_exists($name, $files)) {
                $drift[] = 'STALE:' . $name;
            }
        }
        sort($drift, SORT_STRING);
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
    if ($fixedMode) {
        cleanCompanyPoolDatabase($admin);
    } else {
        $admin->exec("DROP DATABASE IF EXISTS {$quotedDb}");
    }
}
