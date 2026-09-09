<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$fail=static fn(string $m)=>throw new RuntimeException($m);
$ok=static function(bool $v,string $m)use($fail){if(!$v)$fail($m);};
$has=static function(string $s,string $n,string $m)use($fail){if(!str_contains($s,$n))$fail($m);};

$requestPath=$root.'/.github/workflows/document_generator_template_v2_agent_request.yml';
$targetPath=$root.'/.github/workflows/document_generator_template_v2_production.yml';
$request=@file_get_contents($requestPath);
$target=@file_get_contents($targetPath);
$ok(is_string($request)&&$request!=='','request workflow missing');
$ok(is_string($target)&&$target!=='','target workflow missing');

foreach([
    ['template/document-generator-v2-agent','dedicated request branch'],
    ['actions: write','actions permission'],
    ['contents: read','read-only contents permission'],
    ['chatgpt/production-stabilization-20260802','canonical branch'],
    ['test "$GITHUB_SHA" = "$canonical_sha"','exact canonical source'],
    ['REPOSITORY_OWNER: ${{ github.repository_owner }}','dynamic repository owner'],
    ['"$REPOSITORY_OWNER"|chatgpt-codex-connector|chatgpt-connector','authorized actors'],
    ['actions/workflows/ci.yml/runs?','exact ERP CI query'],
    ['document_generator_regression.yml/runs?','exact Document Generator CI query'],
    ['/tmp/erp-ci-runs.json','bounded ERP CI JSON file'],
    ['/tmp/document-generator-runs.json','bounded Document Generator JSON file'],
    ["run.get('head_sha') == sha",'exact CI SHA'],
    ["run.get('conclusion') == 'success'",'successful CI'],
    ['document_generator_template_v2_production.yml/dispatches','guarded target dispatch'],
    ["'source_sha': sys.argv[1]",'source SHA input'],
    ["'confirm_template_update': 'APPLY_DOCUMENT_GENERATOR_TEMPLATE_V2'",'confirmation token'],
] as [$needle,$message]) $has($request,$needle,$message);

$ok(!str_contains($request,'RUNS_JSON='),'large Actions JSON must not be passed through environment');
$ok(!str_contains($request,'planexerp|'),'request workflow must not hardcode former repository owner');
$ok(!str_contains($request,'ERPV2_SSH_PRIVATE_KEY'),'request workflow must not have SSH private key');
$ok(!str_contains($request,'public_html/erpv2'),'request workflow must not write production');
$ok(!str_contains($request,'document_generator_template_v2.php'),'request workflow must not run production script directly');

$has($target,"workflow_dispatch:",'target remains manual-only');
foreach(["\n  push:","\n  pull_request:","\n  workflow_run:","\n  schedule:"] as $forbidden) {
    $ok(!str_contains($target,$forbidden),'target production workflow gained automatic trigger');
}
$has($target,'confirm_template_update:','target confirmation input');
$has($target,'APPLY_DOCUMENT_GENERATOR_TEMPLATE_V2','target confirmation token');

echo "DOCUMENT_GENERATOR_TEMPLATE_V2_AGENT_REQUEST_CONTRACT=PASS\n";
