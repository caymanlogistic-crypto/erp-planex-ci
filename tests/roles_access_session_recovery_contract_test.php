<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$fail=static function(string $m):never{fwrite(STDERR,$m.PHP_EOL);exit(1);};
$need=static function(string $h,string $n,string $label)use($fail):void{if(!str_contains($h,$n))$fail("missing {$label}: {$n}");};
$service=(string)file_get_contents($root.'/app/Service/LegacySessionIdentityUpgradeService.php');
$index=(string)file_get_contents($root.'/public/index.php');
$deps=(string)file_get_contents($root.'/app/Support/entrypoint_dependencies.php');
$qa=(string)file_get_contents($root.'/.github/roles-access/production_browser_qa.js');
$workflow=(string)file_get_contents($root.'/.github/workflows/roles_access_production_browser_qa.yml');

foreach ([
    "'company_owner' => 'central_company_user'",
    "'senior_logist', 'logist' => 'tenant_user'",
    'm.legacy_source = ?',
    'm.legacy_source_id = ?',
    'count($rows) !== 1',
    "unset(\n            \$session['role_code']",
] as $needle) $need($service,$needle,'legacy upgrade');
$need($deps,"LegacySessionIdentityUpgradeService.php",'runtime dependency');
$flagPos=strpos($index,'if ($rolesAccessEnabled) {');
$bridgePos=strpos($index,'LegacySessionIdentityUpgradeService(', $flagPos === false ? 0 : $flagPos);
$enablePos=strpos($index,'$router->enableCompanyAccess(', $flagPos === false ? 0 : $flagPos);
if($flagPos===false||$bridgePos===false||$enablePos===false||!($flagPos<$bridgePos&&$bridgePos<$enablePos))$fail('session bridge must run only inside feature-enabled block and before ACL resolution');
foreach (['session_regenerate_id(true)',"redirect_to('/login')",'LegacySessionIdentityUpgradeService::INVALID'] as $needle) $need($index,$needle,'runtime invalidation');

foreach (['P12_CREDENTIALS_B64','input[name="login"]','input[name="password"]','/company/dashboard','/company/clients','ROLES_ACCESS_REAL_LOGIN_PASS=YES','ROLES_ACCESS_DASHBOARD_HTTP_STATUS=200','ROLES_ACCESS_PROTECTED_PAGE_HTTP_STATUS=200','ROLES_ACCESS_LEGACY_SESSION_UPGRADE_PASS=YES','ROLES_ACCESS_INVALID_LEGACY_RELOGIN_PASS=YES'] as $needle) $need($qa,$needle,'real browser QA');
foreach (['workflow_dispatch:','P11_RUNTIME_CREDENTIALS_B64','Validate exact canonical and deployed SHA','create-legacy-owner','create-invalid-owner','Real login and legacy-session production browser acceptance'] as $needle) $need($workflow,$needle,'postdeploy QA workflow');
foreach (["\n  push:","\n  schedule:","\n  pull_request:","\n  workflow_run:"] as $forbidden) if(str_contains($workflow,$forbidden))$fail('production browser QA must be manual-only');
if(str_contains($workflow,'UPDATE ')||str_contains($workflow,'INSERT ')||str_contains($workflow,'DELETE FROM'))$fail('postdeploy QA workflow must not mutate production DB');

echo "roles access session recovery contract: PASS\n";
