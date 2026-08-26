<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$workflow=file_get_contents($root.'/.github/workflows/erpv2_agent_release_bootstrap.yml')?:'';
$script=file_get_contents($root.'/tools/agent_release_bootstrap.py')?:'';
$pass=0;$fail=0;
$ok=static function(string $n,bool $v)use(&$pass,&$fail):void{if($v){$pass++;return;}$fail++;fwrite(STDERR,"FAIL: $n\n");};
$ok('workflow-run only',str_contains($workflow,"\n  workflow_run:\n")&&!str_contains($workflow,"\n  push:\n")&&!str_contains($workflow,"\n  workflow_dispatch:\n"));
$ok('pinned owner rerun',str_contains($workflow,'workflow_run.id == 32979801695')&&str_contains($workflow,'run_attempt >= 3')&&str_contains($workflow,'triggering_actor.login == github.repository_owner'));
$ok('no production secrets in bridge',str_contains($workflow,'contents: read')&&str_contains($workflow,'actions: write')&&!str_contains($workflow,'ERPV2_SSH_'));
$ok('exact push CI required',str_contains($script,"workflow_runs(source, 'push')")&&str_contains($script,'ERP_PLANEX_CI=PASS'));
$ok('fixed migration 123 target',str_contains($script,"EXPECTED_MIGRATION = '123'")&&str_contains($script,"EXPECTED_MIGRATION_WORKFLOW = 'feo_123_production_migration.yml'")&&str_contains($script,"EXPECTED_MIGRATION_CONFIRM = 'APPLY_FEO_123'"));
$ok('manual-only migration target enforced',str_contains($script,'migration target must remain manual-only')&&str_contains($script,'automatic migration trigger forbidden'));
$ok('migration precedes deploy',strpos($script,"wait_dispatched(source, manifest['workflow']")<strpos($script,"dispatch(DEPLOY_WORKFLOW"));
$ok('canonical reconfirmed before writes',str_contains($script,"require_same_canonical(source, 'migration dispatch')")&&str_contains($script,"require_same_canonical(source, 'deploy dispatch')"));
$ok('controlled deploy confirmation fixed',str_contains($script,"'confirm_deploy': 'DEPLOY_ERPV2'")&&str_contains($script,"DEPLOY_WORKFLOW = 'erpv2_controlled_deploy.yml'"));
$ok('live acceptance required',str_contains($script,'https://plan-ex.ru/erpv2/login')&&str_contains($script,'ERPV2_RELEASE_BOOTSTRAP=PASS'));
printf("AGENT_RELEASE_BOOTSTRAP_CONTRACT: %d passed, %d failed\n",$pass,$fail);
exit($fail===0?0:1);
