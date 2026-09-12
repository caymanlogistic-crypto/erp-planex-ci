<?php
$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/erpv2_agent_db_migration_request.yml');
$manifest = json_decode((string) file_get_contents($root . '/.github/agent_db_migration_request.json'), true);
if (!is_string($workflow) || !is_array($manifest)) { fwrite(STDERR, "missing workflow/manifest\n"); exit(1); }
$needles = ['migrate/erpv2-agent','actions: write','CANONICAL_CI_WORKFLOW: ci.yml','Validate exact canonical HEAD','Wait for exact canonical ERP PLANEX CI','read_state workflow_dispatch','AGENT_DB_MIGRATION_CANONICAL_CI_DISPATCHED=YES','actions/workflows/$CANONICAL_CI_WORKFLOW/dispatches','Validate migration manifest and target workflow','Reconfirm canonical HEAD immediately before dispatch','Dispatch approved task-specific migration workflow',"'ref': os.environ['CANONICAL_BRANCH']"];
foreach ($needles as $needle) if (strpos($workflow,$needle)===false) { fwrite(STDERR,"missing contract token: $needle\n"); exit(1); }
foreach (["\n  workflow_run:","\n  pull_request:","\n  schedule:"] as $forbidden) if(strpos($workflow,$forbidden)!==false){fwrite(STDERR,"forbidden request trigger: $forbidden\n");exit(1);}
$required = ['migration_id','workflow','source_sha_input','confirmation_input','confirmation_value'];
foreach ($required as $key) if (!isset($manifest[$key]) || !is_string($manifest[$key]) || $manifest[$key]==='') { fwrite(STDERR,"invalid manifest field: $key\n"); exit(1); }
$id=$manifest['migration_id'];
if (!preg_match('/^\d{3}$/D',$id)) { fwrite(STDERR,"migration id must be exactly 3 digits\n"); exit(1); }
$targetName=$manifest['workflow'];
if (!preg_match('/^[a-z0-9_]+_production_migration\.yml$/D',$targetName)) { fwrite(STDERR,"target workflow naming contract invalid\n"); exit(1); }
foreach (['source_sha_input','confirmation_input'] as $key) if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$manifest[$key])) { fwrite(STDERR,"invalid input name: $key\n"); exit(1); }
$sql=glob($root.'/database/migrations-local/'.$id.'_*.sql') ?: [];
if (count($sql)!==1) { fwrite(STDERR,"expected exactly one migration SQL for $id\n"); exit(1); }
$targetPath=$root.'/.github/workflows/'.$targetName;
$target=is_file($targetPath)?file_get_contents($targetPath):false;
if (!is_string($target) || strpos($target,"\n  workflow_dispatch:")===false) { fwrite(STDERR,"target workflow must be manual-only\n"); exit(1); }
foreach (["\n  push:","\n  workflow_run:","\n  pull_request:","\n  schedule:"] as $forbidden) if(strpos($target,$forbidden)!==false){fwrite(STDERR,"forbidden target trigger: $forbidden\n");exit(1);}
if (strpos($target,$manifest['confirmation_value'])===false) { fwrite(STDERR,"target confirmation value missing\n"); exit(1); }
echo "AGENT_DB_MIGRATION_REQUEST_CONTRACT=PASS migration={$id} target={$targetName}\n";
