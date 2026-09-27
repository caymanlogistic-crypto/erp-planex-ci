<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$script=(string)file_get_contents($root.'/scripts/employee_mail_default_019_migration.php');
$workflow=(string)file_get_contents($root.'/.github/workflows/employee_mail_default_019_production_migration.yml');
$migration=(string)file_get_contents($root.'/database/migrations/019_employee_mail_default_sender.sql');
$check=static function(bool $condition,string $message):void {if (!$condition) throw new RuntimeException($message);};
foreach (['PLANEX_SOURCE_SHA','PLANEX_DEPLOY_MARKER','PLANEX_MIGRATION_SHA256','APPLY_EMPLOYEE_MAIL_DEFAULT_019','GET_LOCK','schema_migrations','018_report_email_sender.sql','Unjournaled'] as $guard)
    $check(str_contains($script,$guard),'Missing migration guard '.$guard);
$check(substr_count($migration,'ADD COLUMN')===1 && substr_count($migration,'ALTER TABLE employee_mail_profiles')===1 && substr_count($migration,';')===1,'Central migration scope');
$check(str_contains($workflow,'workflow_dispatch:') && !str_contains($workflow,"\n  push:") && str_contains($workflow,'group: erpv2-controlled-deploy'),'Migration workflow must be manual and serialized');
foreach (['source_sha','confirm_migration','APPLY_EMPLOYEE_MAIL_DEFAULT_019','PLANEX_MIGRATION_SHA256','EMPLOYEE_MAIL_DEFAULT_019_APPLY_OK','EMPLOYEE_MAIL_DEFAULT_019_READ_ONLY_OK'] as $guard)
    $check(str_contains($workflow,$guard),'Missing workflow guard '.$guard);
echo "EMPLOYEE_MAIL_DEFAULT_019_MIGRATION_CONTRACT=PASS\n";
