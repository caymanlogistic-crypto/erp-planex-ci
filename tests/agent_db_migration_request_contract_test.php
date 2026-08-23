<?php
$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/erpv2_agent_db_migration_request.yml');
$manifest = json_decode(file_get_contents($root . '/.github/agent_db_migration_request.json'), true);
if (!is_string($workflow) || !is_array($manifest)) { fwrite(STDERR, "missing workflow/manifest\n"); exit(1); }
$needles = ['migrate/erpv2-agent','actions: write','Validate exact canonical HEAD','Wait for exact canonical ERP PLANEX CI','Validate migration manifest and target workflow','Reconfirm canonical HEAD immediately before dispatch','Dispatch approved task-specific migration workflow',"'ref': os.environ['CANONICAL_BRANCH']"];
foreach ($needles as $needle) if (strpos($workflow,$needle)===false) { fwrite(STDERR,"missing contract token: $needle\n"); exit(1); }
foreach (["\n  workflow_run:","\n  pull_request:","\n  schedule:"] as $forbidden) if(strpos($workflow,$forbidden)!==false){fwrite(STDERR,"forbidden trigger: $forbidden\n");exit(1);}
if (($manifest['migration_id'] ?? null) !== '079') { fwrite(STDERR,"unexpected migration manifest id\n"); exit(1); }
if (($manifest['workflow'] ?? null) !== 'finance_closing_079_production_migration.yml') { fwrite(STDERR,"unexpected target workflow\n"); exit(1); }
if (($manifest['confirmation_value'] ?? null) !== 'APPLY_FINANCE_079') { fwrite(STDERR,"unexpected confirmation value\n"); exit(1); }
$target = file_get_contents($root . '/.github/workflows/finance_closing_079_production_migration.yml');
if (!is_string($target) || strpos($target,"\n  workflow_dispatch:")===false || strpos($target,"APPLY_FINANCE_079")===false) { fwrite(STDERR,"079 target workflow contract invalid\n"); exit(1); }
echo "AGENT_DB_MIGRATION_REQUEST_CONTRACT=PASS migration=079\n";
