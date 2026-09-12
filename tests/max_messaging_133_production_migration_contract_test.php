<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/max_messaging_133_production_migration.yml');
$apply=(string)file_get_contents($root.'/scripts/max_messaging_apply_133.php');
$verify=(string)file_get_contents($root.'/scripts/max_messaging_verify_133.php');
$sql=(string)file_get_contents($root.'/database/migrations-local/133_create_max_integration.sql');
$manifest=json_decode((string)file_get_contents($root.'/.github/agent_db_migration_request.json'),true);
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};

$ok(is_array($manifest),'migration request manifest invalid');
$ok(($manifest['migration_id']??'')==='133','agent migration request must target 133');
$ok(($manifest['workflow']??'')==='max_messaging_133_production_migration.yml','agent migration workflow target mismatch');
$ok(($manifest['confirmation_value']??'')==='APPLY_MAX_MESSAGING_133','agent migration confirmation mismatch');
$ok(str_contains($workflow,"\n  workflow_dispatch:"),'MAX 133 migration must be workflow_dispatch');
foreach(["\n  push:","\n  workflow_run:","\n  pull_request:","\n  schedule:"]as$f)$ok(!str_contains($workflow,$f),'MAX 133 migration has forbidden trigger '.$f);
foreach(['APPLY_MAX_MESSAGING_133','Validate exact canonical source and tooling','Require successful exact canonical ERP PLANEX CI','Require deployed marker as successful canonical ancestor','MAX_133_DEPLOYED_ANCESTOR_PASS','Snapshot BEFORE','Apply tenant migration 133','Snapshot AFTER and verify invariants','MAX_133_INVARIANTS_PASS=YES','MAX_133_BUSINESS_DATA_MUTATION=0','MAX_133_DEPLOY_MARKER_UNCHANGED=YES','erpv2/migration133','max_messaging_apply_133.php','max_messaging_verify_133.php','133_create_max_integration.sql']as$t)$ok(str_contains($workflow,$t),'MAX 133 workflow missing '.$t);
$ok(str_contains($workflow,'group: erpv2-controlled-deploy'),'MAX 133 migration must serialize with controlled deploy');
$ok(!str_contains($workflow,'git push')&&!str_contains($workflow,'rsync'),'MAX 133 migration must not deploy application code');
foreach(['133_create_max_integration.sql','132_split_assignment_and_responsibility.sql','GET_LOCK','schema_migrations','Unjournaled MAX 133 schema','max_bots','max_chats','max_message_logs','uk_max_chat_per_bot','fk_max_chats_bot','fk_max_message_logs_chat','MAX_133_OK']as$t)$ok(str_contains($apply,$t),'MAX 133 apply missing '.$t);
foreach(['MAX_133_SNAPSHOT=','MAX_133_READ_ONLY_OK','migration_132','migration_133','migration_133_checksum','max_bots_rows','max_chats_rows','max_message_logs_rows','chat_unique','chat_bot_fk','log_chat_fk']as$t)$ok(str_contains($verify,$t),'MAX 133 verifier missing '.$t);
foreach(['CREATE TABLE IF NOT EXISTS `max_bots`','CREATE TABLE IF NOT EXISTS `max_chats`','CREATE TABLE IF NOT EXISTS `max_message_logs`','`token_encrypted`','`webhook_secret_encrypted`']as$t)$ok(str_contains($sql,$t),'MAX 133 SQL missing '.$t);

echo "MAX_133_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
