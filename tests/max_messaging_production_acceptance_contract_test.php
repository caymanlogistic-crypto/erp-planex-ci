<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$workflow=(string)file_get_contents($root.'/.github/workflows/max_messaging_production_acceptance.yml');
$request=(string)file_get_contents($root.'/.github/workflows/max_messaging_acceptance_agent_request.yml');
$identity=(string)file_get_contents($root.'/.github/max-messaging/qa_identity.php');
$browser=(string)file_get_contents($root.'/.github/max-messaging/production_acceptance.js');
$ok=static function(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);};

$ok(str_contains($workflow,"\n  workflow_dispatch:"),'MAX acceptance must be manual workflow_dispatch');
foreach(["\n  push:","\n  workflow_run:","\n  pull_request:","\n  schedule:"]as$f)$ok(!str_contains($workflow,$f),'MAX acceptance has forbidden trigger '.$f);
foreach(['Exact current canonical SHA already migrated and deployed','test "$marker" = "$SHA"','erpv2/deploy','erpv2/migration133','Read back production migration 133','Provision minimal temporary MAX QA identities','Run exact-SHA authenticated MAX acceptance','MAX_QA_RESIDUE=0','erpv2/max-messaging-acceptance','playwright@1.54.2']as$t)$ok(str_contains($workflow,$t),'MAX acceptance workflow missing '.$t);
$ok(str_contains($workflow,'group: erpv2-controlled-deploy'),'MAX acceptance must serialize against deploy/migration');
$ok(str_contains($workflow,'::add-mask::$cred'),'MAX acceptance credentials must be masked');
$ok(str_contains($request,"\n  push:\n    branches:\n      - qa/max-messaging-acceptance-agent"),'MAX acceptance request must use dedicated push trigger');
foreach(["\n  workflow_run:","\n  pull_request:","\n  schedule:"]as$f)$ok(!str_contains($request,$f),'MAX acceptance request has forbidden trigger '.$f);
foreach(['actions: write','statuses: read','Validate exact canonical HEAD','Require exact canonical CI and production prerequisites','erpv2/migration133','erpv2/deploy','Reconfirm canonical HEAD immediately before dispatch','Dispatch guarded MAX production acceptance','max_messaging_production_acceptance.yml/dispatches','MAX_ACCEPTANCE_DISPATCHED=YES']as$t)$ok(str_contains($request,$t),'MAX acceptance request missing '.$t);
foreach(['ERPV2_SSH_PRIVATE_KEY','ERPV2_SSH_HOST','public_html/erpv2','PLANEX_ROOT','secrets.','ssh -p','scp -P']as$t)$ok(!str_contains($request,$t),'MAX acceptance request must not own production access: '.$t);
foreach(['random_bytes','CompanyMembershipService','CompanyRoleService','UserSyncService','PageRegistry','grantablePageKeys','company.dashboard','max_bots','max_chats','max_message_logs','MAX_QA_IDENTITY_CREATED=YES','MAX_QA_ACCOUNT_RESIDUE=0','MAX_QA_ROLE_RESIDUE=0','MAX_QA_TENANT_PROJECTION_RESIDUE=0','MAX_QA_RESIDUE=0']as$t)$ok(str_contains($identity,$t),'MAX QA identity missing '.$t);
$ok(!str_contains($identity,'INSERT INTO max_bots')&&!str_contains($identity,'INSERT INTO max_chats'),'MAX QA identity must not create fake MAX bot/chat data');
foreach(['/login','/company/max/settings','/company/max/messages','owner MAX settings final path=','owner MAX messages final path=','h1.page-title','h3.panel-head-title','Тестовая отправка','MAX_SETTINGS_OWNER=200_PASS','MAX_MESSAGES_OWNER=200_PASS','MAX_SETTINGS_MEMBER=403_PASS','MAX_MESSAGES_MEMBER=403_PASS','MAX_AUTHORIZATION_BOUNDARY=PASS','MAX_PRODUCTION_FUNCTIONAL_ACCEPTANCE=PASS']as$t)$ok(str_contains($browser,$t),'MAX browser acceptance missing '.$t);
$ok(!str_contains($browser,"locator('body').innerText"),'MAX acceptance must prove page-local DOM instead of sidebar/body text');
foreach(['page.route(','route.fulfill(','route.abort(','mock','intercept']as$t)$ok(!str_contains(strtolower($browser),strtolower($t)),'MAX acceptance must not mock/intercept production HTTP: '.$t);

echo "MAX_PRODUCTION_ACCEPTANCE_CONTRACT=PASS\n";
