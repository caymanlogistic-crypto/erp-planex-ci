<?php

declare(strict_types=1);

if (!class_exists('PDO')) {
    class PDO { public const FETCH_ASSOC=2; public function prepare(string $q,array $o=[]): PDOStatement|false{return false;} }
    class PDOStatement { public function execute(?array $p=null): bool{return true;} public function fetchAll(int $m=0,mixed ...$a): array{return [];} }
}

final class LegacySessionPdo extends PDO
{
    public function __construct(private $responder) {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new LegacySessionStmt($query, $this->responder); }
}
final class LegacySessionStmt extends PDOStatement
{
    private array $rows=[];
    public function __construct(private string $query, private $responder) {}
    public function execute(?array $params=null): bool { $this->rows=($this->responder)($this->query,$params??[]); return true; }
    public function fetchAll(int $mode=PDO::FETCH_ASSOC,mixed ...$args): array { return $this->rows; }
}

require_once __DIR__ . '/../app/Security/AccessStateProvider.php';
require_once __DIR__ . '/../app/Service/LegacySessionIdentityUpgradeService.php';

use App\Security\AccessStateProvider;
use App\Security\MembershipAccessSnapshot;
use App\Service\LegacySessionIdentityUpgradeService as Bridge;

final class ReadyProvider implements AccessStateProvider
{
    public bool $ready=true;
    public function isCompanyAccessReady(int $companyId): bool { return $this->ready; }
    public function loadMembershipAccess(int $accountId,int $companyId): ?MembershipAccessSnapshot { return null; }
}
function ok(bool $v,string $m): void { if(!$v) throw new RuntimeException($m); }

$provider=new ReadyProvider();
$rowsByKey=[
    '7|central_company_user|101' => [['membership_id'=>11,'account_id'=>1001,'company_id'=>7,'membership_status'=>'active','account_status'=>'active','company_status'=>'active']],
    '7|tenant_user|201' => [['membership_id'=>12,'account_id'=>1002,'company_id'=>7,'membership_status'=>'active','account_status'=>'active','company_status'=>'active']],
    '7|tenant_user|202' => [['membership_id'=>13,'account_id'=>1003,'company_id'=>7,'membership_status'=>'active','account_status'=>'active','company_status'=>'active']],
    '7|tenant_user|999' => [
        ['membership_id'=>14,'account_id'=>1004,'company_id'=>7,'membership_status'=>'active','account_status'=>'active','company_status'=>'active'],
        ['membership_id'=>15,'account_id'=>1005,'company_id'=>7,'membership_status'=>'active','account_status'=>'active','company_status'=>'active'],
    ],
];
$pdo=new LegacySessionPdo(static function(string $q,array $p) use (&$rowsByKey): array {
    ok(str_contains($q,'legacy_source_id'), 'bridge must resolve migration provenance');
    return $rowsByKey[implode('|',array_map('strval',$p))] ?? [];
});
$bridge=new Bridge($pdo,$provider);

foreach ([
    ['role'=>'company_owner','legacy'=>101,'account'=>1001],
    ['role'=>'logist','legacy'=>201,'account'=>1002],
    ['role'=>'senior_logist','legacy'=>202,'account'=>1003],
] as $case) {
    $session=['user_id'=>$case['legacy'],'role_code'=>$case['role'],'company_id'=>7,'membership_id'=>999,'permissions'=>['stale']];
    ok($bridge->upgrade($session)===Bridge::UPGRADED, $case['role'].' legacy session did not upgrade');
    ok($session['account_id']===$case['account'] && $session['user_id']===$case['account'], $case['role'].' global identity not hydrated');
    ok(!isset($session['role_code'],$session['membership_id'],$session['permissions']), $case['role'].' stale derived authority survived upgrade');
    ok(($session['user']['account_id']??0)===$case['account'], $case['role'].' nested identity not hydrated');
}

$current=['account_id'=>55,'user_id'=>9,'company_id'=>7,'role_code'=>'logist'];
ok($bridge->upgrade($current)===Bridge::CURRENT && $current['account_id']===55, 'current session must not be remapped from legacy user_id');
$anonymous=[];
ok($bridge->upgrade($anonymous)===Bridge::ANONYMOUS, 'anonymous request must remain anonymous');
$super=['user_id'=>1,'role_code'=>'superadmin','company_id'=>null];
ok($bridge->upgrade($super)===Bridge::CURRENT, 'technical superadmin must not require company account mapping');
$unsupported=['user_id'=>1,'role_code'=>'custom_role','company_id'=>7];
ok($bridge->upgrade($unsupported)===Bridge::INVALID, 'unsupported legacy role must fail closed');
$missing=['user_id'=>404,'role_code'=>'logist','company_id'=>7];
ok($bridge->upgrade($missing)===Bridge::INVALID, 'missing legacy mapping must fail closed');
$ambiguous=['user_id'=>999,'role_code'=>'logist','company_id'=>7];
ok($bridge->upgrade($ambiguous)===Bridge::INVALID, 'ambiguous legacy mapping must fail closed');
$provider->ready=false;
$notReady=['user_id'=>201,'role_code'=>'logist','company_id'=>7];
ok($bridge->upgrade($notReady)===Bridge::INVALID, 'not-ready company must fail closed to relogin');

echo "roles access legacy session upgrade: PASS\n";
