<?php

declare(strict_types=1);

if (!function_exists('rolesAccessCentral013SchemaProof')) {
    /**
     * Prove the factual central schema introduced by migration 013 without
     * inventing a historical schema_migrations row. Later migrations may add
     * objects, but every 013-owned object below must still exist.
     *
     * @return array{complete:bool,missing:list<string>}
     */
    function rolesAccessCentral013SchemaProof(PDO $pdo): array
    {
        $missing = [];

        $tableExists = static function (string $table) use ($pdo): bool {
            $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute([$table]);
            return (int) $q->fetchColumn() === 1;
        };
        $columnExists = static function (string $table, string $column) use ($pdo): bool {
            $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $q->execute([$table, $column]);
            return (int) $q->fetchColumn() === 1;
        };
        $indexExists = static function (string $table, string $index) use ($pdo): bool {
            $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
            $q->execute([$table, $index]);
            return (int) $q->fetchColumn() > 0;
        };

        $requiredColumns = [
            'user_accounts' => ['id', 'full_name', 'login', 'normalized_login', 'email', 'phone', 'password_hash', 'status', 'created_at', 'updated_at'],
            'company_roles' => ['id', 'company_id', 'name', 'normalized_name', 'system_key', 'is_system', 'created_at', 'updated_at'],
            'company_memberships' => ['id', 'account_id', 'company_id', 'role_id', 'status', 'legacy_source', 'legacy_source_id', 'created_at', 'updated_at'],
            'role_page_permissions' => ['role_id', 'page_key', 'is_allowed'],
            'role_data_scopes' => ['role_id', 'entity_type', 'scope'],
            'membership_page_overrides' => ['membership_id', 'page_key', 'effect'],
            'membership_data_scope_overrides' => ['membership_id', 'entity_type', 'scope'],
            'company_access_readiness' => ['company_id', 'state', 'before_sha256', 'after_sha256', 'comparison_sha256', 'migration_key', 'verified_at', 'activated_at', 'updated_at'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            if (!$tableExists($table)) {
                $missing[] = 'table:' . $table;
                continue;
            }
            foreach ($columns as $column) {
                if (!$columnExists($table, $column)) {
                    $missing[] = 'column:' . $table . '.' . $column;
                }
            }
        }

        $requiredIndexes = [
            'user_accounts' => ['PRIMARY', 'uk_user_accounts_normalized_login'],
            'company_roles' => ['PRIMARY', 'uk_company_roles_id_company', 'uk_company_roles_name', 'uk_company_roles_system'],
            'company_memberships' => ['PRIMARY', 'uk_membership_account_company', 'uk_membership_legacy_source', 'idx_membership_company_role_status'],
            'role_page_permissions' => ['PRIMARY'],
            'role_data_scopes' => ['PRIMARY'],
            'membership_page_overrides' => ['PRIMARY'],
            'membership_data_scope_overrides' => ['PRIMARY'],
            'company_access_readiness' => ['PRIMARY'],
        ];

        foreach ($requiredIndexes as $table => $indexes) {
            if (!$tableExists($table)) {
                continue;
            }
            foreach ($indexes as $index) {
                if (!$indexExists($table, $index)) {
                    $missing[] = 'index:' . $table . '.' . $index;
                }
            }
        }

        return ['complete' => $missing === [], 'missing' => $missing];
    }
}
