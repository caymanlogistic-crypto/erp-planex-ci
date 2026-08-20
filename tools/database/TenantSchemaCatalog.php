<?php

declare(strict_types=1);

namespace Planex\Tools\Database;

use PDO;

final class TenantSchemaCatalog
{
    public static function capture(PDO $pdo): array
    {
        $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($db === '') {
            throw new \RuntimeException('Tenant schema introspection requires a selected database.');
        }

        $tablesStmt = $pdo->prepare(
            "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT
               FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
              ORDER BY TABLE_NAME"
        );
        $tablesStmt->execute([$db]);
        $tableRows = $tablesStmt->fetchAll(PDO::FETCH_ASSOC);

        $catalog = [
            'format_version' => 1,
            'source_of_truth' => [
                'migrations' => 'database/migrations-local/*.sql',
                'migration_service' => 'app/Service/LocalMigrationService.php',
            ],
            'tables' => [],
        ];

        foreach ($tableRows as $tableRow) {
            $table = (string) $tableRow['TABLE_NAME'];
            $catalog['tables'][$table] = [
                'domain' => self::domainFor($table),
                'purpose' => self::purposeFor($table),
                'engine' => $tableRow['ENGINE'],
                'collation' => $tableRow['TABLE_COLLATION'],
                'comment' => (string) ($tableRow['TABLE_COMMENT'] ?? ''),
                'columns' => self::columns($pdo, $db, $table),
                'indexes' => self::indexes($pdo, $db, $table),
                'foreign_keys' => self::foreignKeys($pdo, $db, $table),
            ];
        }

        ksort($catalog['tables'], SORT_STRING);
        return $catalog;
    }

    public static function fingerprint(array $catalog): string
    {
        $copy = $catalog;
        unset($copy['fingerprint']);
        return hash('sha256', json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function withFingerprint(array $catalog): array
    {
        $catalog['fingerprint'] = self::fingerprint($catalog);
        return $catalog;
    }

    public static function renderJson(array $catalog): string
    {
        return json_encode(self::withFingerprint($catalog), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function renderMarkdown(array $catalog): string
    {
        $catalog = self::withFingerprint($catalog);
        $out = [];
        $out[] = '# ERP PLANEX — Tenant Physical Schema';
        $out[] = '';
        $out[] = '> GENERATED FILE. Do not maintain physical columns manually. Regenerate with `php tools/database/generate_schema_docs.php --write` against a disposable MySQL instance.';
        $out[] = '';
        $out[] = '**Physical source of truth:** `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.';
        $out[] = '';
        $out[] = '**Schema fingerprint:** `' . $catalog['fingerprint'] . '`';
        $out[] = '';
        $out[] = 'Foreign keys below are **DB-enforced physical relations only**. Logical/application relations are intentionally not inferred: if the database has no FK, this catalog will not invent one. Use owning application code/Notion semantic records for logical relations.';
        $out[] = '';
        $out[] = 'Introducing migration is not inferred from final `information_schema`, because later migrations can rename/rebuild objects. Use Git history/migration history when provenance is needed.';
        $out[] = '';
        $out[] = '## Domain index';
        $out[] = '';
        foreach (self::groupByDomain($catalog) as $domain => $tables) {
            $out[] = '- **' . $domain . '**: ' . implode(', ', array_map(fn(string $t): string => '`' . $t . '`', $tables));
        }
        $out[] = '';

        foreach ($catalog['tables'] as $table => $meta) {
            $out[] = '## `' . $table . '`';
            $out[] = '';
            $out[] = '**Domain:** ' . $meta['domain'] . '  ';
            $out[] = '**Purpose:** ' . $meta['purpose'];
            $out[] = '';
            $out[] = '| Column | Type | Null | Default | Extra | Key |';
            $out[] = '|---|---|---|---|---|---|';
            foreach ($meta['columns'] as $column) {
                $default = $column['default'] === null ? 'NULL' : '`' . str_replace('`', '\\`', (string) $column['default']) . '`';
                $out[] = sprintf('| `%s` | `%s` | %s | %s | %s | %s |',
                    $column['name'], $column['type'], $column['nullable'] ? 'YES' : 'NO', $default,
                    $column['extra'] !== '' ? '`' . $column['extra'] . '`' : '—',
                    $column['key'] !== '' ? '`' . $column['key'] . '`' : '—'
                );
            }
            $out[] = '';
            $out[] = '### Indexes / constraints';
            $out[] = '';
            if ($meta['indexes'] === []) {
                $out[] = '- None.';
            } else {
                foreach ($meta['indexes'] as $index) {
                    $kind = $index['primary'] ? 'PRIMARY' : ($index['unique'] ? 'UNIQUE' : 'INDEX');
                    $out[] = '- **' . $kind . '** `' . $index['name'] . '` (' . implode(', ', array_map(fn(string $c): string => '`' . $c . '`', $index['columns'])) . ')';
                }
            }
            $out[] = '';
            $out[] = '### Physical foreign keys';
            $out[] = '';
            if ($meta['foreign_keys'] === []) {
                $out[] = '- None enforced by MySQL.';
            } else {
                foreach ($meta['foreign_keys'] as $fk) {
                    $out[] = sprintf('- `%s`: (`%s`) → `%s`.`%s` ON UPDATE %s ON DELETE %s', $fk['name'], implode('`, `', $fk['columns']), $fk['referenced_table'], implode('`, `', $fk['referenced_columns']), $fk['update_rule'], $fk['delete_rule']);
                }
            }
            $out[] = '';
        }
        return implode("\n", $out) . "\n";
    }

    public static function renderErd(array $catalog): string
    {
        $catalog = self::withFingerprint($catalog);
        $groups = self::groupByDomain($catalog);
        $out = [
            '# ERP PLANEX — Tenant ERD', '',
            '> GENERATED from final disposable-MySQL `information_schema`. Only physical FK edges are drawn.', '',
            '**Schema fingerprint:** `' . $catalog['fingerprint'] . '`', '',
            '## Architecture overview', '',
            '```mermaid', 'flowchart LR',
            '  Central["Central DB<br/>companies / pool reservations / platform identity"] --> Tenant["Tenant DB<br/>erp_company_{id}"]',
        ];
        $ids = [];
        foreach (array_keys($groups) as $domain) {
            $id = 'D' . substr(hash('sha1', $domain), 0, 8);
            $ids[$domain] = $id;
            $out[] = '  Tenant --> ' . $id . '["' . str_replace('"', '', $domain) . '"]';
        }
        $out[] = '```';
        $out[] = '';
        $out[] = 'The Central DB box is conceptual navigation only; this generator introspects the Tenant DB. Physical tenant columns live in `TENANT_SCHEMA.md` / `tenant-schema.json`.';
        $out[] = '';

        foreach ($groups as $domain => $tables) {
            $out[] = '## ' . $domain;
            $out[] = '';
            $out[] = '```mermaid';
            $out[] = 'erDiagram';
            $hasEdge = false;
            foreach ($tables as $table) {
                foreach ($catalog['tables'][$table]['foreign_keys'] as $fk) {
                    $ref = $fk['referenced_table'];
                    if (!isset($catalog['tables'][$ref])) {
                        continue;
                    }
                    $out[] = '  ' . self::mermaidId($ref) . ' ||--o{ ' . self::mermaidId($table) . ' : "' . str_replace('"', '', $fk['name']) . '"';
                    $hasEdge = true;
                }
            }
            if (!$hasEdge) {
                foreach ($tables as $table) {
                    $out[] = '  ' . self::mermaidId($table) . ' {';
                    $out[] = '    int _schema_catalog_anchor';
                    $out[] = '  }';
                }
            }
            $out[] = '```';
            $out[] = '';
            $out[] = 'Tables: ' . implode(', ', array_map(fn(string $t): string => '`' . $t . '`', $tables));
            $out[] = '';
        }

        return implode("\n", $out) . "\n";
    }

    private static function columns(PDO $pdo, string $db, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLLATION_NAME, COLUMN_COMMENT
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$db, $table]);
        return array_map(static fn(array $r): array => [
            'name' => (string) $r['COLUMN_NAME'], 'type' => (string) $r['COLUMN_TYPE'],
            'nullable' => $r['IS_NULLABLE'] === 'YES', 'default' => $r['COLUMN_DEFAULT'],
            'extra' => (string) $r['EXTRA'], 'key' => (string) $r['COLUMN_KEY'],
            'collation' => $r['COLLATION_NAME'], 'comment' => (string) ($r['COLUMN_COMMENT'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function indexes(PDO $pdo, string $db, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $stmt->execute([$db, $table]);
        $indexes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = (string) $r['INDEX_NAME'];
            $indexes[$name] ??= ['name' => $name, 'primary' => $name === 'PRIMARY', 'unique' => (int) $r['NON_UNIQUE'] === 0, 'columns' => []];
            $col = (string) $r['COLUMN_NAME'];
            if ($r['SUB_PART'] !== null) $col .= '(' . (int) $r['SUB_PART'] . ')';
            $indexes[$name]['columns'][] = $col;
        }
        ksort($indexes, SORT_STRING);
        return array_values($indexes);
    }

    private static function foreignKeys(PDO $pdo, string $db, string $table): array
    {
        $stmt = $pdo->prepare(
            "SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                    k.ORDINAL_POSITION, r.UPDATE_RULE, r.DELETE_RULE
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION"
        );
        $stmt->execute([$db, $table]);
        $fks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = (string) $r['CONSTRAINT_NAME'];
            $fks[$name] ??= [
                'name' => $name, 'columns' => [], 'referenced_table' => (string) $r['REFERENCED_TABLE_NAME'],
                'referenced_columns' => [], 'update_rule' => (string) $r['UPDATE_RULE'], 'delete_rule' => (string) $r['DELETE_RULE'],
            ];
            $fks[$name]['columns'][] = (string) $r['COLUMN_NAME'];
            $fks[$name]['referenced_columns'][] = (string) $r['REFERENCED_COLUMN_NAME'];
        }
        ksort($fks, SORT_STRING);
        return array_values($fks);
    }

    private static function groupByDomain(array $catalog): array
    {
        $groups = [];
        foreach ($catalog['tables'] as $table => $meta) $groups[$meta['domain']][] = $table;
        ksort($groups, SORT_STRING);
        foreach ($groups as &$tables) sort($tables, SORT_STRING);
        return $groups;
    }

    private static function domainFor(string $table): string
    {
        if ($table === 'schema_migrations') return 'System / Migration metadata';
        if (str_starts_with($table, 'finance_') || str_starts_with($table, 'bank_') || str_contains($table, 'invoice') || str_contains($table, 'obligation')) return 'Finance';
        if (str_starts_with($table, 'linear_route') || in_array($table, ['cargo_types', 'production_calendar'], true)) return 'Operations / Routes';
        if (str_contains($table, 'vehicle') || str_contains($table, 'driver') || $table === 'crews') return 'Transport';
        if (str_contains($table, 'document')) return 'Documents';
        if (str_contains($table, 'access') || str_contains($table, 'grant') || $table === 'users') return 'Access / Security';
        if (str_contains($table, 'client') || str_contains($table, 'contractor') || str_contains($table, 'assignment')) return 'CRM';
        return 'Other tenant domain';
    }

    private static function purposeFor(string $table): string
    {
        return match ($table) {
            'schema_migrations' => 'Applied local-migration journal and checksums.',
            'users' => 'Tenant company users.',
            'clients' => 'Customer/client master records.',
            'contractors' => 'Carrier/contractor master records.',
            'drivers' => 'Driver master records.',
            'vehicle_units' => 'Physical vehicle units.',
            'vehicle_sets' => 'Vehicle-unit combinations/sets.',
            'crews' => 'Operational crews.',
            'documents' => 'Tenant document metadata.',
            'document_types' => 'Document type catalog.',
            'linear_routes' => 'Linear route/trip operational records.',
            'finance_invoices' => 'Finance invoice records.',
            default => 'Physical tenant table; semantics follow owning application module.',
        };
    }

    private static function mermaidId(string $table): string
    {
        return 'T_' . preg_replace('/[^A-Za-z0-9_]/', '_', $table);
    }
}
