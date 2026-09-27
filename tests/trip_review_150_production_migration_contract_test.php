<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$script=(string)file_get_contents($root.'/scripts/trip_review_migration_150.php');
$workflow=(string)file_get_contents($root.'/.github/workflows/trip_review_150_production_migration.yml');
$central=(string)file_get_contents($root.'/database/migrations/018_report_email_sender.sql');
$tenant=(string)file_get_contents($root.'/database/migrations-local/150_create_waste_trip_completion_reviews.sql');
$check=static function(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); };
foreach (['PLANEX_SOURCE_SHA','PLANEX_DEPLOY_MARKER','PLANEX_CENTRAL_SHA256','PLANEX_TENANT_SHA256','APPLY_TRIP_REVIEW_150','GET_LOCK','schema_migrations','149_waste_trip_legacy_metadata.sql','017_registration_journal_and_report_recipients.sql','Unjournaled'] as $guard)
    $check(str_contains($script,$guard),'Missing migration guard '.$guard);
$check(substr_count($central,'ADD COLUMN')===1 && str_starts_with($central,'ALTER TABLE company_report_email_settings'),'Central migration scope');
$check(substr_count($tenant,'CREATE TABLE')===1 && str_starts_with($tenant,'ALTER TABLE waste_trip_registration_journal MODIFY waste_trip_request_id BIGINT UNSIGNED NULL;') && str_contains($tenant,'CREATE TABLE waste_trip_completion_reviews'),'Tenant migration scope');
$check(str_contains($workflow,'workflow_dispatch:') && !str_contains($workflow,"\n  push:") && str_contains($workflow,"group: erpv2-controlled-deploy"),'Migration workflow must be manual and serialized');
foreach (['source_sha','confirm_migration','APPLY_TRIP_REVIEW_150','PLANEX_CENTRAL_SHA256','PLANEX_TENANT_SHA256','TRIP_REVIEW_150_APPLY_OK','TRIP_REVIEW_150_READ_ONLY_OK'] as $guard)
    $check(str_contains($workflow,$guard),'Missing workflow guard '.$guard);
echo "TRIP_REVIEW_150_MIGRATION_CONTRACT=PASS\n";
