<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$fail=static fn(string $m)=>throw new RuntimeException($m);
$has=static function(string $s,string $n,string $m)use($fail){if(!str_contains($s,$n))$fail($m);};
$wf=(string)file_get_contents($root.'/.github/workflows/document_generator_template_v3_production.yml');
$script=(string)file_get_contents($root.'/scripts/document_generator_template_v3.php');
$trait=(string)file_get_contents($root.'/app/Service/DocumentGeneratorTemplateTrait.php');
$agent=(string)file_get_contents($root.'/.github/workflows/document_generator_template_v3_agent_request.yml');
if($wf===''||$script===''||$trait===''||$agent==='')$fail('v3 rollout source unavailable');
$has($wf,'workflow_dispatch:','v3 workflow manual dispatch missing');
foreach(['pull_request:','schedule:','workflow_run:']as$n)if(str_contains($wf,$n))$fail('v3 production workflow must be workflow_dispatch only: '.$n);
foreach([
    'APPLY_DOCUMENT_GENERATOR_TEMPLATE_V3','erpv2/template-v3','P07_erpv2_deployed_commit.txt',
    'Snapshot before and stage exact candidate','Create and activate immutable template v3','Snapshot after and verify immutable transition',
    'PLANEX_EXPECTED_V3_SHA','PLANEX_TEMPLATE_CANDIDATE_PATH','candidate_staged','STAGED_PRODUCTION_V3_SHA',
    '.candidate.docx','planned_sha','expected exactly one proven production-v2 transition',
    '7b3b86b00f49521a95a41a107dcdaf63e969c36264befcac372e83c2d53a8d56'
]as$n)$has($wf,$n,'v3 workflow contract '.$n);
foreach([
    'DocumentGeneratorService::createTemplateVersionFromFile','GET_LOCK','DOCGEN_PRODUCTION_V2_SHA','DOCGEN_BUILTIN_V3_SHA',
    '7b3b86b00f49521a95a41a107dcdaf63e969c36264befcac372e83c2d53a8d56',
    'PLANEX_EXPECTED_V3_SHA','PLANEX_TEMPLATE_CANDIDATE_PATH','transformProductionV2','validateV3Candidate',
    'Expected exactly one vehicle paragraph','candidate_staged',
    '{{vehicle.label}}: {{vehicle.model}}, гос. номер {{vehicle.plate}}, VIN {{vehicle.vin}}{{trailer.display}}',
    'Staged production v3 SHA does not match bound planned SHA','Staged production v3 candidate SHA changed',
    'Old immutable production v2 changed','Unexpected/custom active vehicle-lease template SHA',
    'Apply requires exactly one proven production-v2 template','Snapshot staging requires exactly one proven production-v2 template'
]as$n)$has($script,$n,'v3 script contract '.$n);
if(str_contains($script,'UPDATE document_templates SET active_version_id'))$fail('v3 rollout must use canonical template versioning service');
if(str_contains($script,"if(\$rows===[])throw"))$fail('tenant without active vehicle-lease template must remain a valid no-op state');
if(str_contains($script,"hash_equals(DOCGEN_BUILTIN_V3_SHA,(string)\$after['sha256'])"))$fail('production v3 must be bound to staged transformed production SHA, not repo built-in SHA');
$applyPos=strpos($script,"if(\$action==='apply')");
$versionPos=strpos($script,'DocumentGeneratorService::createTemplateVersionFromFile');
$boundPos=strpos($script,'Staged production v3 SHA does not match bound planned SHA');
if($applyPos===false||$versionPos===false||$boundPos===false||!($applyPos<$boundPos&&$boundPos<$versionPos))$fail('staged candidate SHA must fail closed before canonical versioning write');
foreach(['contract_vehicle_lease_crew_v3.docx','87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2']as$n)$has($trait,$n,'built-in v3 contract '.$n);
foreach(['/tmp/erp-ci-runs.json','/tmp/document-generator-runs.json','/tmp/document-generator-google-vehicle-runs.json','document_generator_google_vehicle_regression.yml','template/document-generator-v3-agent']as$n)$has($agent,$n,'v3 agent gate '.$n);
echo "DOCUMENT_GENERATOR_TEMPLATE_V3_PRODUCTION_CONTRACT=PASS\n";
