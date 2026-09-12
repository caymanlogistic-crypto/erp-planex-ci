<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = (string)file_get_contents($root . '/.github/workflows/feo_placement_author_backfill_production.yml');
$apply = (string)file_get_contents($root . '/scripts/feo_placement_author_backfill.php');
$verify = (string)file_get_contents($root . '/scripts/feo_placement_author_verify.php');
$ok = static function (bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };

$ok(str_contains($workflow, "\n  workflow_dispatch:"), 'backfill workflow must be manual-only');
foreach (["\n  push:","\n  workflow_run:","\n  pull_request:","\n  schedule:"] as $forbidden) $ok(!str_contains($workflow, $forbidden), 'backfill workflow has forbidden trigger ' . $forbidden);
foreach (['APPLY_FEO_PLACEMENT_AUTHOR_BACKFILL','group: erpv2-controlled-deploy','Validate exact canonical source and task tooling','Require successful exact canonical ERP PLANEX CI','Require exact deployed SHA and upload task tooling','test "$DEPLOYED" = "$SOURCE_SHA"','Snapshot BEFORE','Apply one-time existing-block backfill','Snapshot AFTER and verify invariants','FEO_AUTHOR_BACKFILL_INVARIANTS_PASS=YES','FEO_AUTHOR_BACKFILL_SCOPE=CREATOR_FIELDS_ONLY','erpv2/feo-placement-author-backfill'] as $needle) $ok(str_contains($workflow, $needle), 'backfill workflow missing ' . $needle);
$ok(str_contains($workflow, "grep -qi ' login=spugov ' feo_author_apply.txt"), 'backfill workflow must accept canonical Spugov login case-insensitively');
$ok(!str_contains($workflow, 'git push') && !str_contains($workflow, 'rsync'), 'backfill workflow must not deploy or push code');

foreach (['Spugov','Expected exactly one active company with placement blocks and login Spugov','GET_LOCK','beginTransaction()','SELECT COALESCE(MAX(id),0) FROM feo_placement_blocks','UPDATE feo_placement_blocks SET created_by_user_id=?,created_by_role=? WHERE id<=?','schema_migrations','feo_placement_author_backfill_20260912_max_','Backfill verification failed inside transaction','FEO_AUTHOR_BACKFILL_OK'] as $needle) $ok(str_contains($apply, $needle), 'backfill apply missing ' . $needle);
$ok(!preg_match('/UPDATE\s+feo_placement_(?:block_requests|current)/i', $apply), 'backfill must not mutate placement membership/current tables');
$ok(!preg_match('/(?:DELETE|INSERT)\s+(?:FROM\s+|INTO\s+)?feo_placement_blocks/i', $apply), 'backfill must not create/delete placement blocks');

foreach (['Spugov','FEO_AUTHOR_BACKFILL_SNAPSHOT=','FEO_AUTHOR_BACKFILL_READ_ONLY_OK','placement_semantic_hash','author_distribution','marker_boundary','historical_author_mismatch_count','SELECT block_id,zayavka_id,position_no FROM feo_placement_block_requests','SELECT zayavka_id,source_block_id FROM feo_placement_current'] as $needle) $ok(str_contains($verify, $needle), 'backfill verifier missing ' . $needle);
$ok(!preg_match('/\b(?:UPDATE|DELETE|INSERT|REPLACE|ALTER|DROP|CREATE)\b/i', preg_replace('/\$snapshot\s*=\s*\[.*$/s', '', $verify) ?? ''), 'read-only verifier contains a SQL mutation token');
$ok(str_contains($workflow, "'placement_semantic_hash'") && str_contains($workflow, "before[key]!=after[key]") && str_contains($workflow, "after['historical_author_mismatch_count']!=0"), 'workflow must prove unchanged placement semantics and complete attribution');

echo "FEO_PLACEMENT_AUTHOR_BACKFILL_CONTRACT=PASS\n";
