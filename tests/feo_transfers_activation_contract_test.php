<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$w=file_get_contents($root.'/.github/workflows/feo_transfers_production_activation.yml');
$s=file_get_contents($root.'/scripts/feo_transfers_activate.php');
foreach (['workflow_dispatch:','CONFIGURE_FEO_TRANSFERS','erpv2-controlled-deploy','git ls-remote','Successful deployed SHA required','Exact canonical CI PASS required','FEO_TRANSFER_YANDEX_KEY','StrictHostKeyChecking=yes','Cleanup transport'] as $token) if(!str_contains($w,$token)) throw new RuntimeException('Missing workflow boundary: '.$token);
if(preg_match('/^  (push|schedule|pull_request):/m',$w)) throw new RuntimeException('Activation must be manual only');
foreach (['stream_get_contents(STDIN)','cryptoEncrypt($key)','cryptoDecrypt((string)$saved)','hash_equals','messages_sent','Business data changed','SELECT DATABASE()','FEO_ACTIVATION_CONFIRM'] as $token) if(!str_contains($s,$token)) throw new RuntimeException('Missing activation boundary: '.$token);
if(str_contains($s,'->dispatch(')||str_contains($s,'->sendToChat('))throw new RuntimeException('Activation must not send MAX messages');
echo "FEO_ACTIVATION_CONTRACT=PASS\n";
