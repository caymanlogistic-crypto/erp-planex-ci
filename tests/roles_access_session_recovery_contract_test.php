<?php

declare(strict_types=1);
$root=dirname(__DIR__);
$fail=static function(string $m):never{fwrite(STDERR,$m.PHP_EOL);exit(1);};
$need=static function(string $h,string $n,string $label)use($fail):void{if(!str_contains($h,$n))$fail("missing {$label}: {$n}");};
$service=(string)file_get_contents($root.'/app/Service/LegacySessionIdentityUpgradeService.php');
$index=(string)file_get_contents($root.'/public/index.php');
$deps=(string)file_get_contents($root.'/app/Support/entrypoint_dependencies.php');
$httpRuntime=(string)file_get_contents($root.'/app/Support/http_runtime.php');
$qa=(string)file_get_contents($root.'/.github/roles-access/production_browser_qa.js');
$workflow=(string)file_get_contents($root.'/.github/workflows/roles_access_production_browser_qa.yml');
$qaSession=(string)file_get_contents($root.'/.github/roles-access/qa_session.php');

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
$bridgePos=strpos($index,'recoverLegacyRolesAccessSession(', $flagPos === false ? 0 : $flagPos);
$enablePos=strpos($index,'$router->enableCompanyAccess(', $flagPos === false ? 0 : $flagPos);
if($flagPos===false||$bridgePos===false||$enablePos===false||!($flagPos<$bridgePos&&$bridgePos<$enablePos))$fail('session bridge must run only inside feature-enabled block and before ACL resolution');
foreach (['session_regenerate_id(true)',"redirect_to('/login')",'LegacySessionIdentityUpgradeService::INVALID','enforceUsableCompanyAccessSession','missing_account_or_company','inactive_or_missing_membership','invalid_role'] as $needle) $need($httpRuntime,$needle,'runtime invalidation');
$barrierPos=strpos($index,'enforceUsableCompanyAccessSession();', $enablePos === false ? 0 : $enablePos);
$compatPos=strpos($index,'$compatibilityRoleCode =', $enablePos === false ? 0 : $enablePos);
if($barrierPos===false||$compatPos===false||!($enablePos<$barrierPos&&$barrierPos<$compatPos))$fail('identity-deny barrier must run after ACL resolution and before compatibility metadata');
foreach (['loadMembershipAccess($accountId, $companyId)','nestedCompanyId','nestedLegacyUserId'] as $needle) $need($service,$needle,'session-shape validation');


foreach (["url: base",'ROLES_ACCESS_LEGACY_PROTECTED_STATUS='] as $needle) $need($qa,$needle,'host-only legacy browser continuity');
if(str_contains($qa,"domain: 'plan-ex.ru'"))$fail('synthetic legacy cookies must be host-only so PHP session rotation replaces the same cookie');
foreach (['roles_access_legacy_browser.txt','roles_access_real_login_browser.txt',"ROLES_ACCESS_LEGACY_PROTECTED_STATUS=200"] as $needle) $need($workflow,$needle,'split browser evidence');
if(str_contains($workflow,'roles_access_session_browser.txt'))$fail('stale combined browser evidence filename must not be used');

foreach (['P12_CREDENTIALS_B64','QA_REAL_LOGIN_AVAILABLE','input[name="login"]','input[name="password"]','/company/dashboard','/company/clients','ROLES_ACCESS_REAL_LOGIN_PASS=YES','ROLES_ACCESS_LEGACY_BROWSER_PASS=YES','ROLES_ACCESS_LEGACY_DASHBOARD_HTTP_STATUS=200','ROLES_ACCESS_DASHBOARD_HTTP_STATUS=200','ROLES_ACCESS_PROTECTED_PAGE_HTTP_STATUS=200','ROLES_ACCESS_LEGACY_SESSION_UPGRADE_PASS=YES','ROLES_ACCESS_INVALID_LEGACY_RELOGIN_PASS=YES'] as $needle) $need($qa,$needle,'real browser QA');
$legacyPos=strpos($qa,'// A. Real pre-activation owner session');
$realPos=strpos($qa,'// C. REAL normal login');
if($legacyPos===false||$realPos===false||$legacyPos>$realPos)$fail('legacy production session proof must execute before optional plaintext login');
foreach (['workflow_dispatch:',"push:\n    branches:\n      - qa/roles-access-agent",'inputs.source_sha || github.sha','P11_RUNTIME_CREDENTIALS_B64','Validate exact canonical and deployed SHA','diagnose-credentials','QA_REAL_LOGIN_AVAILABLE','Legacy-session production browser acceptance','Real login dashboard protected-page acceptance','ROLES_ACCESS_REAL_LOGIN_BLOCKER=NO_CURRENT_VERIFIED_CREDENTIAL','QA_MODE: legacy','QA_MODE: real','create-legacy-owner','create-invalid-owner'] as $needle) $need($workflow,$needle,'postdeploy QA workflow');
foreach (['diagnose-credentials','stream_get_contents(STDIN)','AccountAuthenticationService','authenticate($login, $password)','company_access_readiness','selected_index','ready_company','usable'] as $needle) $need($qaSession,$needle,'sanitized current credential diagnostic');
if(str_contains($workflow,'P11_runtime_credentials.enc.json')||str_contains($workflow,'P11_decrypt_credentials.py'))$fail('stale encrypted credential fallback must not be used');
foreach (["\n  schedule:","\n  pull_request:","\n  workflow_run:"] as $forbidden) if(str_contains($workflow,$forbidden))$fail('production browser QA must not have unrelated automatic triggers');
if(str_contains($workflow,'UPDATE ')||str_contains($workflow,'INSERT ')||str_contains($workflow,'DELETE FROM'))$fail('postdeploy QA workflow must not mutate production DB');
if(str_contains($qaSession,'UPDATE ')||str_contains($qaSession,'INSERT ')||str_contains($qaSession,'DELETE FROM'))$fail('QA session helper must remain read-only for production DB');

echo "roles access session recovery contract: PASS\n";
