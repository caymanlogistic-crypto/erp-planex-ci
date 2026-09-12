<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$fail=static fn(string $m)=>throw new RuntimeException($m);
$ok=static function(bool $v,string $m)use($fail){if(!$v)$fail($m);};
$has=static function(string $s,string $n,string $m)use($fail){if(!str_contains($s,$n))$fail($m);};

$request=@file_get_contents($root.'/.github/workflows/feo_placement_author_backfill_agent_request.yml');
$target=@file_get_contents($root.'/.github/workflows/feo_placement_author_backfill_production.yml');
$ok(is_string($request)&&$request!=='','request workflow missing');
$ok(is_string($target)&&$target!=='','target workflow missing');

foreach([
    ['backfill/feo-placement-author-agent','dedicated request branch'],
    ['actions: write','actions permission'],
    ['contents: read','read-only contents permission'],
    ['statuses: read','status read permission'],
    ['chatgpt/production-stabilization-20260802','canonical branch'],
    ['test "$GITHUB_SHA" = "$canonical_sha"','exact canonical source'],
    ['REPOSITORY_OWNER: ${{ github.repository_owner }}','dynamic repository owner'],
    ['"$REPOSITORY_OWNER"|chatgpt-codex-connector|chatgpt-connector','authorized actors'],
    ['actions/workflows/ci.yml/runs?head_sha=$canonical_sha&event=push','exact ERP CI query'],
    ["r.get('head_sha')==os.environ['CANONICAL_SHA']",'exact CI SHA'],
    ["run.get('conclusion')!='success'",'successful CI requirement'],
    ['/commits/$canonical_sha/status','exact commit status query'],
    ["states.get('erpv2/deploy')!='success'",'exact deploy status requirement'],
    ['feo_placement_author_backfill_production.yml/dispatches','guarded target dispatch'],
    ["'source_sha': sys.argv[1]",'source SHA input'],
    ["'confirm_backfill': 'APPLY_FEO_PLACEMENT_AUTHOR_BACKFILL'",'confirmation token'],
] as [$needle,$message]) $has($request,$needle,$message);

$ok(!str_contains($request,'ERPV2_SSH_PRIVATE_KEY'),'request workflow must not have SSH private key');
$ok(!str_contains($request,'public_html/erpv2'),'request workflow must not write production');
$ok(!str_contains($request,'feo_placement_author_backfill.php'),'request workflow must not run backfill directly');
$ok(!str_contains($request,'PLANEX_ROOT='),'request workflow must not execute production scripts');

$has($target,"workflow_dispatch:\n",'target remains manual-only');
foreach(["\n  push:","\n  pull_request:","\n  workflow_run:","\n  schedule:"] as $forbidden) {
    $ok(!str_contains($target,$forbidden),'target production workflow gained automatic trigger');
}
$has($target,'confirm_backfill:','target confirmation input');
$has($target,'APPLY_FEO_PLACEMENT_AUTHOR_BACKFILL','target confirmation token');
$has($target,'erpv2/feo-placement-author-backfill','target status context');

echo "FEO_PLACEMENT_AUTHOR_BACKFILL_AGENT_REQUEST_CONTRACT=PASS\n";
