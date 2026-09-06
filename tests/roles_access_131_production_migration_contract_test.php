<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/roles_access_131_production_migration.yml';
$scriptPath = $root . '/scripts/roles_access_production_migrate.php';
$workflow = is_file($workflowPath) ? (string) file_get_contents($workflowPath) : '';
$script = is_file($scriptPath) ? (string) file_get_contents($scriptPath) : '';
$fail = static function (string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); };
$need = static function (string $haystack, string $needle, string $label) use ($fail): void {
    if (!str_contains($haystack, $needle)) $fail("missing {$label}: {$needle}");
};
if ($workflow === '' || $script === '') $fail('roles access production migration artifacts missing');

foreach ([
    "\n  workflow_dispatch:",
    "APPLY_ROLES_ACCESS_131",
    "database/migrations/013_create_roles_access.sql",
    "database/migrations-local/131_create_roles_access_projection.sql",
    "SSH_CONTROL_DIR=\"/tmp/roles131_mux_\${GITHUB_RUN_ID}\"",
    'test "${#SSH_CONTROL_PATH}" -lt 90',
    'StrictHostKeyChecking=yes',
    'ControlMaster=auto',
    'ControlPersist=600',
    'ControlPath="$SSH_CONTROL_PATH"',
    'Fail-closed feature rollback after post-activation failure',
    'Install Playwright Chromium for production UI acceptance',
    'Production browser UI acceptance',
    'Resolve OWNER runtime credentials for real login QA',
    'Create valid and invalid pre-activation browser sessions',
    'Cleanup temporary browser sessions',
    'ROLES_ACCESS_BROWSER_UI_PASS=YES',
    'erpv2/roles-access-migration131',
] as $needle) $need($workflow, $needle, 'workflow token');

foreach (['P11_RUNTIME_CREDENTIALS_B64','P12_CREDENTIALS_B64','create-legacy-owner','create-invalid-owner','ROLES_ACCESS_REAL_LOGIN_PASS=YES','ROLES_ACCESS_DASHBOARD_HTTP_STATUS=200','ROLES_ACCESS_PROTECTED_PAGE_HTTP_STATUS=200','ROLES_ACCESS_LEGACY_SESSION_UPGRADE_PASS=YES','ROLES_ACCESS_INVALID_LEGACY_RELOGIN_PASS=YES'] as $needle) $need($workflow, $needle, 'real login QA workflow token');

foreach (["\n  push:","\n  workflow_run:","\n  pull_request:","\n  schedule:"] as $forbidden) {
    if (str_contains($workflow, $forbidden)) $fail('production migration workflow must be manual-only');
}
if (str_contains($workflow, 'SentinelX') || str_contains($script, 'SentinelX')) $fail('production migration must not route through SentinelX');

$order = [
    'BACKUP and BEFORE snapshot and identity preflight',
    'Apply CENTRAL 013 and TENANT 131 factual migration',
    'AFTER snapshot and strict BEFORE AFTER parity',
    'Independent Roles Access regression after parity',
    'READINESS gate',
    'FEATURE ACTIVATION only after readiness',
    'Production ACL and projection acceptance',
    'Production browser UI acceptance',
];
$previous = -1;
foreach ($order as $label) {
    $position = strpos($workflow, $label);
    if ($position === false || $position <= $previous) $fail('migration gate ordering invalid at ' . $label);
    $previous = $position;
}

foreach ([
    "'prepare' => prepare(",
    "'apply' => applyMigration(",
    "'after' => afterParity(",
    "'ready' => markReady(",
    "'activate' => activateFeature(",
    "'accept' => acceptProduction(",
    "require_once \$root . '/app/Support/crypto_helper.php';",
    "if (\$command === 'deactivate') {",
    'assertFeatureOff($root);',
    'MigrationProof::identityPlan($rows)',
    'MigrationProof::compare($before, $after, [])',
    'assertActiveLegacyLeaders',
    'assertActiveMigratedLeaders',
    'assertProjectionIsolation',
    "'company_owner' => \$all",
    "'senior_logist' => \$operational",
    "'logist' => \$operational",
    "'senior_logist' => ['pages' => \$pages['senior_logist'], 'scopes' => \$allScopes]",
    "'logist' => ['pages' => \$pages['logist'], 'scopes' => \$ownScopes]",
    "ERP_ROLES_ACCESS_ENABLED=true",
    'env_before_activation',
    'ROLES_ACCESS_FEATURE_ROLLBACK_PASS=YES',
    'Duplicate account/company memberships detected.',
    'Cross-company role membership detected.',
    'assertProductionRuntimeSemantics',
    'ROLES_ACCESS_DYNAMIC_ROLE_PASS=YES',
    'ROLES_ACCESS_PAGE_OVERRIDE_PASS=YES',
    'ROLES_ACCESS_DATA_SCOPE_PASS=YES',
    'ROLES_ACCESS_MANY_TO_MANY_PASS=YES',
    'ROLES_ACCESS_AUTO_ASSIGN_CREATOR_PASS=YES',
    'ROLES_ACCESS_LAST_LEADER_PASS=YES',
] as $needle) $need($script, $needle, 'script token');

// No production exception channel: strict parity is zero-diff only.
if (str_contains($script, 'owner_exceptions') && !str_contains($script, "'owner_exceptions' => []")) {
    $fail('owner exception channel must remain empty for production rollout');
}

// Migration 131 is a historical manual-only rollout contract. The generic
// request manifest is intentionally allowed to advance to newer migrations and
// is governed independently by agent_db_migration_request_contract_test.php.
if (count(glob($root . '/database/migrations-local/131_*.sql') ?: []) !== 1) $fail('migration 131 tenant SQL must be unique');
$tenantMigration = (string) file_get_contents($root . '/database/migrations-local/131_create_roles_access_projection.sql');
foreach (['legacy_access_projections','idx_vehicle_sets_creator_membership'] as $token) $need($tenantMigration, $token, 'tenant 131 compatibility projection token');

// BEFORE must be written before either DDL call appears in apply execution flow.
$preparePos = strpos($script, "function prepare(");
$applyPos = strpos($script, "function applyMigration(");
if ($preparePos === false || $applyPos === false || $preparePos >= $applyPos) $fail('prepare must be defined before apply');
$need($script, "writeJson(\$evidenceDir . '/before_access.json'", 'immutable BEFORE evidence');
$need($script, "hash_file('sha256', \$evidenceDir . '/before_access.json')", 'BEFORE hash revalidation');
$need($script, "identityPreflight(\$central, \$companies, \$tenants);", 'identity preflight before mutation');
$need($script, 'ROLES_ACCESS_IDENTITY_CONFLICT=', 'sanitized identity conflict evidence marker');
$need($script, 'MigrationProof::identityConflictEvidence', 'identity conflict evidence sanitizer wiring');
$beforeWritePos = strpos($script, "writeJson(\$evidenceDir . '/before_access.json'");
$prepareIdentityPos = strpos($script, 'identityPreflight($central, $companies, $tenants);', $preparePos);
if ($beforeWritePos === false || $prepareIdentityPos === false || $beforeWritePos >= $prepareIdentityPos || $prepareIdentityPos >= $applyPos) {
    $fail('prepare ordering must be BACKUP/BEFORE then identity preflight, all before apply');
}
$executor = (string) file_get_contents($root . '/tools/roles_access/LegacyAccessMigrationExecutor.php');
$need($executor, 'MigrationProof::historicalAccessPlan($grants, $tenantUserMap)', 'factual historical grant migration planner');
$need($executor, 'insertLegacyAccessProjections', 'immutable historical route/set projection');
$need($executor, "['tenant_user_ids']", 'tenant alias projection for mirrored memberships');
$need($script, 'MigrationProof::identityPlan($rows)', 'identity planner remains the production preflight authority');
if (str_contains($executor, '$creatorLinks[]')) $fail('historical creator links must not be invented as assignments');


$qaSession = (string) file_get_contents($root . '/.github/roles-access/qa_session.php');
$browserQa = (string) file_get_contents($root . '/.github/roles-access/production_browser_qa.js');
foreach (['legacy_source','central_company_user','create-legacy-owner','create-invalid-owner',"'role_code' => 'company_owner'"] as $needle) $need($qaSession, $needle, 'legacy browser session token');
if (str_contains($qaSession, "'account_id' =>")) $fail('legacy browser QA session must intentionally omit account_id');
foreach (['P12_CREDENTIALS_B64','/login','/company/dashboard','/company/clients','input[name="login"]','ROLES_ACCESS_REAL_LOGIN_PASS=YES','ROLES_ACCESS_DASHBOARD_HTTP_STATUS=200','ROLES_ACCESS_LEGACY_SESSION_UPGRADE_PASS=YES','ROLES_ACCESS_INVALID_LEGACY_RELOGIN_PASS=YES'] as $needle) {
    $need($browserQa, $needle, 'browser QA token');
}

// Runtime bootstrap regression: pre-activation rollback must not initialize DB.
$sourceSha = str_repeat('a', 40);
$base = sys_get_temp_dir() . '/roles_access_cli_' . bin2hex(random_bytes(6));
$live = $base . '/planexp/public_html/erpv2';
$evidence = $base . '/planexp/deploy_backups/roles_access_' . $sourceSha . '_test';
@mkdir($live . '/bootstrap', 0700, true);
@mkdir($base . '/planexp/deploy', 0700, true);
@mkdir($evidence, 0700, true);
file_put_contents($live . '/bootstrap/app.php', "<?php return [];\n");
file_put_contents($live . '/.env', "ERP_ROLES_ACCESS_ENABLED=false\n");
file_put_contents($base . '/planexp/deploy/P07_erpv2_deployed_commit.txt', $sourceSha . "\n");

$cmd = sprintf(
    'PLANEX_ROOT=%s PLANEX_SOURCE_SHA=%s PLANEX_EVIDENCE_DIR=%s %s %s deactivate 2>&1',
    escapeshellarg($live),
    escapeshellarg($sourceSha),
    escapeshellarg($evidence),
    escapeshellarg(PHP_BINARY),
    escapeshellarg($root . '/scripts/roles_access_production_migrate.php'),
);
exec($cmd, $output, $rc);
$joined = implode("\n", $output);

$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $remove($path . '/' . $name);
    }
    @rmdir($path);
};
$remove($base);

if ($rc !== 0 || !str_contains($joined, 'ROLES_ACCESS_FEATURE_ROLLBACK_NOT_REQUIRED=YES')) {
    fwrite(STDERR, "roles access CLI deactivate bootstrap failed rc={$rc}: {$joined}\n");
    exit(1);
}
if (str_contains($joined, 'cryptoDecrypt') || str_contains($joined, 'Database connection')) {
    fwrite(STDERR, "deactivate unexpectedly initialized DB/runtime: {$joined}\n");
    exit(1);
}

echo "ROLES_ACCESS_PRODUCTION_CLI_BOOTSTRAP=PASS\n";

echo "ROLES_ACCESS_131_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
