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

foreach (['Spugov','central company_owner login Spugov','company_users','GET_LOCK','beginTransaction()','SELECT COALESCE(MAX(id),0) FROM feo_placement_blocks','UPDATE feo_placement_blocks SET created_by_user_id=?,created_by_role=?,updated_at=updated_at WHERE id<=?','schema_migrations','feo_placement_author_backfill_20260912_max_','feo_placement_author_backfill_20260912_v2_max_','identity=central_company_user','existing-v1-boundary','Backfill verification failed inside transaction','FEO_AUTHOR_BACKFILL_OK'] as $needle) $ok(str_contains($apply, $needle), 'backfill apply missing ' . $needle);
$ok(!preg_match('/UPDATE\s+feo_placement_(?:block_requests|current)/i', $apply), 'backfill must not mutate placement membership/current tables');
$ok(!preg_match('/(?:DELETE|INSERT)\s+(?:FROM\s+|INTO\s+)?feo_placement_blocks/i', $apply), 'backfill must not create/delete placement blocks');

foreach (['Spugov','company_users','central_company_user','spugov_display_name','Владимир С.','FEO_AUTHOR_BACKFILL_SNAPSHOT=','FEO_AUTHOR_BACKFILL_READ_ONLY_OK','placement_semantic_hash','author_distribution','legacy_marker_boundary','historical_boundary','marker_boundary','historical_author_mismatch_count','ui_author_mismatch_count','FeoPlacementAuthorService','SELECT block_id,zayavka_id,position_no FROM feo_placement_block_requests','SELECT zayavka_id,source_block_id FROM feo_placement_current'] as $needle) $ok(str_contains($verify, $needle), 'backfill verifier missing ' . $needle);
$ok(!preg_match('/\b(?:UPDATE|DELETE|INSERT|REPLACE|ALTER|DROP|CREATE)\b/i', preg_replace('/\$snapshot\s*=\s*\[.*$/s', '', $verify) ?? ''), 'read-only verifier contains a SQL mutation token');
$ok(str_contains($workflow, "'placement_semantic_hash'") && str_contains($workflow, "before[key]!=after[key]") && str_contains($workflow, "after['historical_author_mismatch_count']!=0") && str_contains($workflow, "after['ui_author_mismatch_count']!=0") && str_contains($workflow, "after['spugov_identity_type']!='central_company_user'") && str_contains($workflow, "after['spugov_display_name']!='Владимир С.'"), 'workflow must prove unchanged placement semantics, central identity and UI attribution');

$ok(str_contains($apply, 'feo_placement_author_backfill_20260915_v3_max_') && str_contains($verify, 'feo_placement_author_backfill_20260915_v3_max_'), 'v3 apply/verify must share the new frozen scope');
$ok(str_contains($apply, 'FEO_AUTHOR_EXPECTED_BOUNDARY') && str_contains($workflow, "FEO_AUTHOR_EXPECTED_BOUNDARY='$" . 'FEO_AUTHOR_EXPECTED_BOUNDARY' . "'"), 'apply must use the read-only snapshot boundary');
$ok(str_contains($apply, 'planex_feo_placement_') && str_contains($apply, 'updated_at=updated_at'), 'backfill must share the placement lock and preserve timestamps');
$ok(str_contains($verify, 'created_at,updated_at FROM feo_placement_blocks'), 'semantic fingerprint must include both timestamps');
echo "FEO_PLACEMENT_AUTHOR_BACKFILL_CONTRACT=PASS\n";
