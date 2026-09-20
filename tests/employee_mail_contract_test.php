<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$ok=static function(bool $b,string $m):void {if(!$b)throw new RuntimeException($m);};
$transport=file_get_contents($root.'/app/Service/EmployeeMailTransport.php');
foreach (['imap.spaceweb.ru','smtp.spaceweb.ru',"'verify_peer'=>true","'verify_peer_name'=>true",'AUTH LOGIN','LOGOUT','QUIT'] as $s) $ok(str_contains($transport,$s),'Transport missing guard: '.$s);
foreach (['MAIL FROM','RCPT TO','DATA\\r','imap_search','imap_fetch','error_log('] as $s) $ok(!str_contains($transport,$s),'Connection test must not send/read/log: '.$s);
$workflow=str_replace("\r\n","\n",file_get_contents($root.'/.github/workflows/employee_mail_016_production_migration.yml'));
foreach (["on:\n  workflow_dispatch:",'APPLY_CENTRAL_016_EMPLOYEE_MAIL','test "$marker" = "$SOURCE_SHA"','actions/workflows/ci.yml/runs?head_sha=$SOURCE_SHA&event=push','group: erpv2-controlled-deploy','CENTRAL_016_INVARIANTS_PASS=YES'] as $s) $ok(str_contains($workflow,$s),'Migration guard absent: '.$s);
$ok(!preg_match('/\n  (push|pull_request|schedule|workflow_run):/',$workflow),'Automatic migration forbidden');
$routes=file_get_contents($root.'/app/Http/Routes/company_roles_access.php');
foreach (['profiles','save','check'] as $method) $ok(str_contains($routes,"[\$employeeMailController, '$method'], \$rolesAccessPage"),'Missing owner route gate');
echo "EMPLOYEE_MAIL_CONTRACT=PASS\n";
