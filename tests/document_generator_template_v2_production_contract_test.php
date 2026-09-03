<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$fail=static fn(string $m)=>throw new RuntimeException($m);
$has=static function(string $s,string $n,string $m)use($fail){if(!str_contains($s,$n))$fail($m);};
$wf=(string)file_get_contents($root.'/.github/workflows/document_generator_template_v2_production.yml');
$script=(string)file_get_contents($root.'/scripts/document_generator_template_v2.php');
$trait=(string)file_get_contents($root.'/app/Service/DocumentGeneratorTemplateTrait.php');
if($wf===''||$script===''||$trait==='')$fail('required source unavailable');
$has($wf,'workflow_dispatch:','manual-only workflow missing');
foreach(['pull_request:','push:','schedule:','workflow_run:']as$n)if(str_contains($wf,$n))$fail('production template workflow must be manual-only: '.$n);
foreach(['APPLY_DOCUMENT_GENERATOR_TEMPLATE_V2','erpv2/template-v2','Snapshot before','Create and activate immutable template v2','Snapshot after and verify invariants']as$n)$has($wf,$n,'workflow contract '.$n);
foreach(['DocumentGeneratorService::createTemplateVersionFromFile','GET_LOCK','executor.bank_inn','ИНН\\s+БАНКА','Old immutable template version changed','PLANEX_TEMPLATE_ACTION']as$n)$has($script,$n,'script contract '.$n);
if(str_contains($script,'UPDATE document_templates SET active_version_id'))$fail('template production script must not directly update active_version_id');
$has($trait,'contract_vehicle_lease_crew_v2.docx','built-in v2 seed missing');
$has($trait,'7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa','built-in v2 SHA missing');
echo "DOCUMENT_GENERATOR_TEMPLATE_V2_PRODUCTION_CONTRACT=PASS\n";
require __DIR__.'/document_generator_template_v2_agent_request_contract_test.php';
