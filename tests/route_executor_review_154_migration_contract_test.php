<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$script=(string)file_get_contents($root.'/scripts/route_executor_review_migration_154.php');
$workflow=(string)file_get_contents($root.'/.github/workflows/route_executor_review_154_production_migration.yml');
$migration=(string)file_get_contents($root.'/database/migrations-local/154_route_executor_review_cards.sql');
$check=static function(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); };
foreach (['PLANEX_SOURCE_SHA','PLANEX_DEPLOY_MARKER','PLANEX_TENANT_SHA256','APPLY_ROUTE_EXECUTOR_154',
    'GET_LOCK','schema_migrations','151_route_executor_review_sends.sql','Unjournaled'] as $guard)
    $check(str_contains($script,$guard),'Missing migration guard '.$guard);
$check(substr_count($migration,'CREATE TABLE')===1 && substr_count($migration,';')===1,'Migration SQL scope');
$check(str_contains($workflow,'workflow_dispatch:') && !str_contains($workflow,"\n  push:")
    && str_contains($workflow,'group: erpv2-controlled-deploy'),'Migration workflow dispatch and serialization');
$check(!str_contains($workflow,'scp ') && !str_contains($workflow,'SCP='),'Migration uses the deployed exact-SHA script');
foreach (['source_sha','confirm_migration','APPLY_ROUTE_EXECUTOR_154','PLANEX_TENANT_SHA256',
    'ROUTE_EXECUTOR_154_APPLY_OK','ROUTE_EXECUTOR_154_READ_ONLY_OK'] as $guard)
    $check(str_contains($workflow,$guard),'Missing workflow guard '.$guard);
echo "route_executor_review_154_migration_contract_test: PASS\n";
