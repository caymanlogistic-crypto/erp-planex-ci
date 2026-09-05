<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/roles_access_131_production_migration.yml';
$scriptPath = $root . '/scripts/roles_access_production_migrate.php';
$manifestPath = $root . '/.github/agent_db_migration_request.json';
$workflow = is_file($workflowPath) ? (string) file_get_contents($workflowPath) : '';
$script = is_file($scriptPath) ? (string) file_get_contents($scriptPath) : '';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$fail = static function (string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); };
$need = static function (string $haystack, string $needle, string $label) use ($fail): void {
    if (!str_contains($haystack, $needle)) $fail("missing {$label}: {$needle}");
};
if ($workflow === '' || $script === '' || !is_array($manifest)) $fail('roles access production migration artifacts missing');

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
    'Cleanup temporary browser session',
    'ROLES_ACCESS_BROWSER_UI_PASS=YES',
    'erpv2/roles-access-migration131',
] as $needle) $need($workflow, $needle, 'workflow token');
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
    "'deactivate' => deactivateFeature(",
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

$expectedManifest = [
    'migration_id' => '131',
    'workflow' => 'roles_access_131_production_migration.yml',
    'source_sha_input' => 'source_sha',
    'confirmation_input' => 'confirm_migration',
    'confirmation_value' => 'APPLY_ROLES_ACCESS_131',
];
foreach ($expectedManifest as $key => $value) {
    if (($manifest[$key] ?? null) !== $value) $fail("migration manifest mismatch: {$key}");
}
if (count(glob($root . '/database/migrations-local/131_*.sql') ?: []) !== 1) $fail('migration 131 tenant SQL must be unique');

// BEFORE must be written before either DDL call appears in apply execution flow.
$preparePos = strpos($script, "function prepare(");
$applyPos = strpos($script, "function applyMigration(");
if ($preparePos === false || $applyPos === false || $preparePos >= $applyPos) $fail('prepare must be defined before apply');
$need($script, "writeJson(\$evidenceDir . '/before_access.json'", 'immutable BEFORE evidence');
$need($script, "hash_file('sha256', \$evidenceDir . '/before_access.json')", 'BEFORE hash revalidation');
$need($script, "identityPreflight(\$central, \$companies, \$tenants);", 'identity preflight before mutation');
$beforeWritePos = strpos($script, "writeJson(\$evidenceDir . '/before_access.json'");
$prepareIdentityPos = strpos($script, 'identityPreflight($central, $companies, $tenants);', $preparePos);
if ($beforeWritePos === false || $prepareIdentityPos === false || $beforeWritePos >= $prepareIdentityPos || $prepareIdentityPos >= $applyPos) {
    $fail('prepare ordering must be BACKUP/BEFORE then identity preflight, all before apply');
}
$executor = (string) file_get_contents($root . '/tools/roles_access/LegacyAccessMigrationExecutor.php');
$need($executor, 'MigrationProof::assignmentPlan($grants, [], $tenantUserMap)', 'explicit-grant-only historical assignment migration');
if (str_contains($executor, '$creatorLinks[]')) $fail('historical creator links must not be invented as assignments');


$qaSession = (string) file_get_contents($root . '/.github/roles-access/qa_session.php');
$browserQa = (string) file_get_contents($root . '/.github/roles-access/production_browser_qa.js');
foreach (['account_id','company_access_readiness'] as $needle) $need($qaSession, $needle, 'browser session token');
if (str_contains($qaSession, "'role_code' =>")) $fail('browser QA session must resolve company role from central state, not inject it');
foreach (['popup-over-popup','scrollWidth','roles-access-role-modal-1440.png','roles-access-user-modal-1440.png','roles-access-assignments-1440.png'] as $needle) {
    $need($browserQa, $needle, 'browser QA token');
}

echo "ROLES_ACCESS_131_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
