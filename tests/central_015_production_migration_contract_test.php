<?php
declare(strict_types=1);

$workflowPath=dirname(__DIR__).'/.github/workflows/central_user_max_id_015_production_migration.yml';
$scriptPath=dirname(__DIR__).'/scripts/central_015_production_migrate.php';
$migrationPath=dirname(__DIR__).'/database/migrations/015_add_user_accounts_max_user_id.sql';

$workflow=(string)file_get_contents($workflowPath);
$script=(string)file_get_contents($scriptPath);
$migration=(string)file_get_contents($migrationPath);
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};

$header=strstr($workflow,"permissions:",true);
$ok(is_string($header),'workflow permissions boundary missing');
$ok(str_contains($header,"on:\n  workflow_dispatch:"),'workflow must be manual-only');
foreach(["\n  push:","\n  pull_request:","\n  workflow_run:","\n  schedule:"] as $forbidden) {
    $ok(!str_contains($header,$forbidden),'automatic workflow trigger forbidden: '.trim($forbidden));
}

$required=[
    "APPLY_CENTRAL_015_MAX_USER_ID",
    "group: erpv2-controlled-deploy",
    "CANONICAL_BRANCH: chatgpt/production-stabilization-20260802",
    "git ls-remote origin",
    'actions/workflows/ci.yml/runs?head_sha=$SOURCE_SHA&event=push',
    "git merge-base --is-ancestor",
    "context')=='erpv2/deploy'",
    'cat \'$DEPLOY_MARKER\'',
    'PLANEX_DEPLOYED_SHA=\'$DEPLOYED_SHA\'',
    "scripts/central_015_production_migrate.php",
    "database/migrations/015_add_user_accounts_max_user_id.sql",
    "CENTRAL_015_INSPECT=(READY|ALREADY_APPLIED)",
    "CENTRAL_015_APPLY=(PASS|EXISTING)",
    "CENTRAL_015_INVARIANTS_PASS=YES",
    "ROLLBACK_CENTRAL_015_MAX_USER_ID",
    "CENTRAL_015_WAS_ABSENT == 'YES'",
    "context':'erpv2/migration015'",
    "https://plan-ex.ru/erpv2/login",
];
foreach($required as $needle)$ok(str_contains($workflow,$needle),'workflow contract missing: '.$needle);
$ok(!str_contains($workflow,'database/migrations-local/'),'central 015 workflow must not target tenant migrations');
$ok(!str_contains($workflow,'ALTER TABLE'),'DDL must live only in migration SQL, not workflow');

$scriptRequired=[
    "PLANEX_DEPLOY_MARKER",
    "PLANEX_DEPLOYED_SHA",
    "PLANEX_BACKUP_ROOT",
    "Exact deployed marker mismatch.",
    "015_add_user_accounts_max_user_id.sql",
    "Central migration 014 journal prerequisite missing.",
    "Newer central migration exists while 015 is absent.",
    "GET_LOCK",
    "schema_migrations",
    "user_accounts_before.json",
    "CENTRAL_015_APPLY=PASS",
    "CENTRAL_015_ROLLBACK=PASS",
    "Rollback blocked because MAX IDs already exist.",
];
foreach($scriptRequired as $needle)$ok(str_contains($script,$needle),'migration runner contract missing: '.$needle);

$expected='fcf1b858f58f356fbd82acaf3afee69749c9a13cd7ffff9d1d62900e615ca58a';
$ok(hash('sha256',$migration)===$expected,'migration 015 checksum drift');

echo "CENTRAL_015_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
