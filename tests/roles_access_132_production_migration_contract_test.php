<?php
$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/roles_access_132_production_migration.yml');
$apply=(string)file_get_contents($root.'/scripts/roles_access_apply_132.php');
$verify=(string)file_get_contents($root.'/scripts/roles_access_verify_132.php');
$manifest=json_decode((string)file_get_contents($root.'/.github/agent_db_migration_request.json'),true);
$ok=static function(bool $v,string $m):void{if(!$v){fwrite(STDERR,$m."\n");exit(1);}};
foreach(['workflow_dispatch:','APPLY_ROLES_ACCESS_132','erpv2/migration132','014_add_role_default_page.sql','132_split_assignment_and_responsibility.sql','Require deployed marker as successful canonical ancestor','test "$DEPLOYED" != "$SOURCE_SHA"','ROLES_ACCESS_132_DEPLOY_MARKER_UNCHANGED=YES','Snapshot BEFORE','Apply central 014 and tenant 132','Snapshot AFTER and verify invariants','Rollback only on failed migration while deployed marker is unchanged','PLANEX_ROLES_132_ROLLBACK_CONFIRM','erpv2-controlled-deploy'] as $needle)$ok(str_contains($workflow,$needle),'workflow missing '.$needle);
foreach(["\n  push:","\n  pull_request:","\n  workflow_run:","\n  schedule:"] as $forbidden)$ok(!str_contains($workflow,$forbidden),'automatic trigger forbidden '.$forbidden);
foreach(['Central migration 013 prerequisite missing','Unjournaled partial central 014 schema','tenant migration 131 prerequisite missing','unjournaled partial tenant 132 schema','rollback blocked because responsibilities exist','rollback blocked because factual new provenance exists','rollback blocked because configurable role landing data exists','ROLLBACK_ROLES_ACCESS_132'] as $needle)$ok(str_contains($apply,$needle),'apply/rollback guard missing '.$needle);
foreach(['assignment_core_hash','role_core_hash','migration_014','migration_132','assignment_kind_column','responsibilities_rows','ROLES_ACCESS_132_READ_ONLY_OK'] as $needle)$ok(str_contains($verify,$needle),'verify snapshot missing '.$needle);
$ok(($manifest['migration_id']??null)==='132','manifest migration_id must be 132');
$ok(($manifest['workflow']??null)==='roles_access_132_production_migration.yml','manifest workflow must target 132');
$ok(($manifest['confirmation_value']??null)==='APPLY_ROLES_ACCESS_132','manifest confirmation mismatch');
echo "ROLES_ACCESS_132_PRODUCTION_MIGRATION_CONTRACT=PASS\n";
