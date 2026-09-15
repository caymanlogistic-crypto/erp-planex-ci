<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=file_get_contents($root.'/.github/workflows/max_events_136_production_migration.yml');
$script=file_get_contents($root.'/scripts/max_events_migration_136.php');
$sql=file_get_contents($root.'/database/migrations-local/136_create_max_event_notifications.sql');
$ok=static function(bool $v,string $m):void {if(!$v)throw new RuntimeException($m);};
foreach(['workflow_dispatch:','APPLY_MAX_EVENTS_136','group: erpv2-controlled-deploy','Validate exact canonical source and tooling','Require successful exact canonical ERP PLANEX CI','git merge-base --is-ancestor','MAX_136_BEFORE_PASS','MAX_136_INVARIANTS_PASS','MAX_136_DEPLOY_MARKER_UNCHANGED','erpv2/migration136','MAX_EVENTS_MIGRATION_MODE=apply','MAX_EVENTS_MIGRATION_CONFIRM=APPLY_MAX_EVENTS_136'] as $token) $ok(str_contains($workflow,$token),'Missing migration guard: '.$token);
foreach(["\n  push:","\n  schedule:","\n  workflow_run:",'rsync','git push'] as $token) $ok(!str_contains($workflow,$token),'Forbidden migration trigger/action');
foreach(['MAX_136_READ_ONLY_OK','MAX_136_APPLY_OK','MAX_EVENTS_MIGRATION_CONFIRM','GET_LOCK','Unjournaled MAX events schema','checksum mismatch','Newer migration exists','133_create_max_integration.sql'] as $token) $ok(str_contains($script,$token),'Missing script guard: '.$token);
$ok(substr_count($sql,'CREATE TABLE IF NOT EXISTS')===2 && !preg_match('/(?:^|;)\s*(INSERT|UPDATE|DELETE|DROP|ALTER)\s/i',$sql),'Migration must only create two event tables');
$routes=file_get_contents($root.'/app/Http/Routes/company_max_messaging.php');
foreach(['saveEvent','previewEvent','retryEvent'] as $action) $ok((bool)preg_match('/\[\$maxMessagingController, \''.$action.'\'\], \$maxSettingsPage/',$routes),'Owner page gate missing');
$controller=file_get_contents($root.'/app/Http/Controllers/Company/MaxMessagingController.php');
foreach(['saveEvent','previewEvent','retryEvent'] as $action) $ok((bool)preg_match('/function '.$action.'\([^)]*\): void\s*\{\s*requireRole\(\[\'company_owner\'\]\);\s*verifyCsrfRequest\(\);/',$controller),'Owner/CSRF guard missing');
$feo=file_get_contents($root.'/app/Http/Controllers/Company/FeoPlacementController.php');
$ok(str_contains($feo,"enqueue('feo.placement.block_created'") && str_contains($feo,'$notifications->dispatch($deliveryId)'),'FEO integration missing');
$ok(strpos($feo,'unset($_SESSION[\'feo_placement_previews\'][$token]);',strpos($feo,'public function commitCreate'))<strpos($feo,'$notifications->dispatch($deliveryId)'),'Preview must be consumed before sending');
echo "MAX_EVENTS_136_PRODUCTION_CONTRACT=PASS\n";
