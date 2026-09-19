<?php
declare(strict_types=1);

use App\Service\CompanyMembershipService;
use App\Service\IdentityNormalizer;

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once BASE_PATH . '/app/Support/company_database.php';
require_once BASE_PATH . '/app/Service/IdentityNormalizer.php';
require_once BASE_PATH . '/app/Service/CompanyRoleService.php';
require_once BASE_PATH . '/app/Service/CompanyMembershipService.php';

$db = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if ($db === '' || !in_array($db,$allow,true) || getenv('APP_ENV') !== 'test') {
    throw new RuntimeException('Guarded fixed test DB required.');
}
$pdo = new PDO(
    'mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306).';dbname='.$db.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]
);
$ok = static function(bool $v,string $m): void { if(!$v) throw new RuntimeException($m); };
$throws = static function(callable $fn,string $needle) use ($ok): void {
    try {$fn();} catch(Throwable $e) {$ok(str_contains($e->getMessage(),$needle),'wrong error: '.$e->getMessage()); return;}
    throw new RuntimeException('expected exception');
};
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec("CREATE TABLE companies(id INT UNSIGNED PRIMARY KEY,name VARCHAR(255),status VARCHAR(20)) ENGINE=InnoDB");
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/013_create_roles_access.sql'));
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/014_add_role_default_page.sql'));
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations/015_add_user_accounts_max_user_id.sql'));
    $pdo->exec("INSERT INTO companies(id,name,status) VALUES(7,'QA','active')");
    $pdo->exec("INSERT INTO company_roles(id,company_id,name,normalized_name,system_key,is_system) VALUES(1,7,'Логист','logist',NULL,0)");

    $column = $pdo->query("SELECT IS_NULLABLE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND COLUMN_NAME='max_user_id'")->fetch(PDO::FETCH_ASSOC);
    $ok($column && $column['IS_NULLABLE']==='YES' && (int)$column['CHARACTER_MAXIMUM_LENGTH']>=19,'max_user_id schema');
    $index = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_accounts' AND INDEX_NAME='uk_user_accounts_max_user_id' AND NON_UNIQUE=0")->fetchColumn();
    $ok($index===1,'MAX ID unique index');

    $service = new CompanyMembershipService($pdo);
    $created = $service->add(7,1,'Иван Иванов','ivan','x',null,null,'active','123456789',true);
    $rows = $service->listForCompany(7,[]);
    $row = array_values(array_filter($rows,static fn(array $r): bool => (int)$r['id']===(int)$created['membership_id']))[0] ?? null;
    $ok(is_array($row) && ($row['max_user_id'] ?? null)==='123456789','MAX ID create/readback');
    $service->save(7,(int)$created['membership_id'],1,'active',[],[],[],'987654321',true);
    $rows = $service->listForCompany(7,[]);
    $row = array_values(array_filter($rows,static fn(array $r): bool => (int)$r['id']===(int)$created['membership_id']))[0] ?? null;
    $ok(($row['max_user_id'] ?? null)==='987654321','MAX ID edit/readback');

    // Omitted field must keep the central identity value.
    $service->save(7,(int)$created['membership_id'],1,'active',[],[],[]);
    $ok($pdo->query("SELECT max_user_id FROM user_accounts WHERE id=".(int)$created['account_id'])->fetchColumn()==='987654321','omitted MAX ID must not clear');

    // Explicit empty field clears it.
    $service->save(7,(int)$created['membership_id'],1,'active',[],[],[],'',true);
    $ok($pdo->query("SELECT max_user_id FROM user_accounts WHERE id=".(int)$created['account_id'])->fetchColumn()===null,'explicit empty MAX ID clears');

    $throws(fn()=>$service->save(7,(int)$created['membership_id'],1,'active',[],[],[],'abc',true),'положительным int64');
    $throws(fn()=>$service->save(7,(int)$created['membership_id'],1,'active',[],[],[],'9223372036854775808',true),'положительным int64');

    $service->save(7,(int)$created['membership_id'],1,'active',[],[],[],'111',true);
    $throws(fn()=>$service->add(7,1,'Пётр Петров','petr','x',null,null,'active','111',true),'уже используется');

    echo "USER_MAX_ID_MYSQL=PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cleanCompanyPoolDatabase($pdo);
}
