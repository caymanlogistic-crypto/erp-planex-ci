<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string)file_get_contents($root . '/.github/workflows/company_deletion_021_production_migration.yml');
$script = (string)file_get_contents($root . '/scripts/company_deletion_021_migration.php');
$sql = (string)file_get_contents($root . '/database/migrations/021_company_deletion_runs.sql');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$assert(str_contains($workflow, 'workflow_dispatch:') && !preg_match('/^  (push|pull_request|schedule|workflow_run):/m', $workflow), 'Production migration must be manual-only');
$assert(str_contains($workflow, "inputs.confirm_migration == 'APPLY_COMPANY_DELETION_021'"), 'Task-specific confirmation missing');
$assert(str_contains($workflow, 'git ls-remote origin refs/heads/${CANONICAL_BRANCH}')
    && str_contains($workflow, 'Exact canonical ERP PLANEX CI is not green')
    && str_contains($workflow, 'cat \'$DEPLOY_MARKER\''), 'Exact canonical/CI/deployment guards missing');
$assert(str_contains($script, "getenv('COMPANY_DELETION_021_CONFIRM')")
    && str_contains($script, "getenv('PLANEX_MIGRATION_SHA256')")
    && str_contains($script, "'planex_migrate_central_021'")
    && str_contains($script, 'Unjournaled or missing company deletion journal table'), 'Runtime migration guards missing');
$assert(substr_count($sql, ';') === 1 && str_contains($sql, 'CREATE TABLE IF NOT EXISTS company_deletion_runs')
    && !preg_match('/\b(DROP|DELETE|INSERT|TRUNCATE)\b/i', $sql), 'Migration extends only the journal schema');
echo "COMPANY_DELETION_021_MIGRATION_CONTRACT=PASS\n";
