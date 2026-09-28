<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$script=(string)file_get_contents($root.'/scripts/route_executor_review_recipients_020_migration.php');
$workflow=(string)file_get_contents($root.'/.github/workflows/route_executor_review_020_production_migration.yml');
$migration=(string)file_get_contents($root.'/database/migrations/020_route_executor_review_email_recipients.sql');
$check=static function(bool $condition,string $message):void {if (!$condition) throw new RuntimeException($message);};
foreach (['PLANEX_SOURCE_SHA','PLANEX_DEPLOY_MARKER','PLANEX_MIGRATION_SHA256','APPLY_ROUTE_EXECUTOR_REVIEW_020','GET_LOCK','schema_migrations','019_employee_mail_default_sender.sql','Unjournaled'] as $guard)
    $check(str_contains($script,$guard),'Missing migration guard '.$guard);
$check(substr_count($migration,'CREATE TABLE IF NOT EXISTS')===2 && substr_count($migration,';')===2,'Central migration scope');
$check(str_contains($workflow,'workflow_dispatch:') && !str_contains($workflow,"\n  push:") && str_contains($workflow,'group: erpv2-controlled-deploy'),'Migration workflow must be manual and serialized');
foreach (['source_sha','confirm_migration','APPLY_ROUTE_EXECUTOR_REVIEW_020','PLANEX_MIGRATION_SHA256','ROUTE_EXECUTOR_REVIEW_020_APPLY_OK','ROUTE_EXECUTOR_REVIEW_020_READ_ONLY_OK'] as $guard)
    $check(str_contains($workflow,$guard),'Missing workflow guard '.$guard);
echo "ROUTE_EXECUTOR_REVIEW_020_MIGRATION_CONTRACT=PASS\n";
