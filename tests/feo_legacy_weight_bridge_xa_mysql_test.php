<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/FeoXlsxParser.php';
require_once BASE_PATH.'/app/Service/FeoImportTransaction.php';

use App\Service\FeoImportTransaction;

if (PHP_OS_FAMILY === 'Windows' || getenv('APP_ENV') !== 'test') throw new RuntimeException('Isolated POSIX test required.');
$db=(string)getenv('PLANEX_TEST_FIXED_DB');
if (!preg_match('/^[A-Za-z0-9_]+$/D',$db) || !in_array($db,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true)) {
    throw new RuntimeException('Allowlisted disposable database required.');
}
final class FeoWeightFaultPdo extends PDO
{
    public bool $interruptCommit=false;
    public function exec(string $statement): int|false
    {
        if ($this->interruptCommit && str_starts_with($statement,'XA COMMIT')) {
            $this->interruptCommit=false;
            throw new RuntimeException('Synthetic interruption between XA confirmations');
        }
        return parent::exec($statement);
    }
}
$dsn='mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$db.';charset=utf8mb4';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$connect=static fn()=>new FeoWeightFaultPdo($dsn,getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),$options);
$pdo=$connect();
$previous=[];
foreach (['FEO_LEGACY_SYNC_TENANT_DATABASE','FEO_LEGACY_SYNC_DATABASE','FEO_LEGACY_SYNC_CONNECTION_B64','FEO_LEGACY_SYNC_JOURNAL_DIR'] as $key) $previous[$key]=getenv($key);
$journal=sys_get_temp_dir().'/feo-weight-xa-'.bin2hex(random_bytes(10));
if (!mkdir($journal,0700)) throw new RuntimeException('Cannot create XA journal');
$legacyConnection=['host'=>(string)getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>(int)getenv('PLANEX_SCHEMA_DB_PORT'),
    'username'=>(string)(getenv('PLANEX_TEST_LEGACY_USER')?:getenv('PLANEX_SCHEMA_DB_USER')),
    'password'=>(string)(getenv('PLANEX_TEST_LEGACY_PASSWORD')?:getenv('PLANEX_SCHEMA_DB_PASSWORD'))];
$check=static function(bool $value,string $message): void { if (!$value) throw new RuntimeException($message); };
$weight=static function(PDO $connection,string $table,string $id): array {
    $q=$connection->prepare('SELECT mass_netto,mass_brutto FROM '.$table.' WHERE zayavka_id=?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
};
cleanCompanyPoolDatabase($pdo);
try {
    $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/122_create_feo_requests.sql'));
    $ddl=(string)$pdo->query('SHOW CREATE TABLE feo_requests')->fetchColumn(1);
    $ddl=preg_replace('/,\n\s+CONSTRAINT[^\n]+/','',$ddl);
    $pdo->exec(str_replace('CREATE TABLE `feo_requests`','CREATE TABLE `feo`',$ddl));
    $pdo->exec('ALTER TABLE feo DROP COLUMN current_import_id, MODIFY mass_netto DECIMAL(10,3), MODIFY mass_brutto DECIMAL(5,2) UNSIGNED, ADD legacy_note VARCHAR(50) NULL');
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto,mass_brutto) VALUES ('100001',1.0000,2.0000),('100002',3.0000,4.0000)");
    $pdo->exec("INSERT INTO feo(zayavka_id,mass_netto,mass_brutto,legacy_note) VALUES ('100001',1.000,2.00,'Сохранить'),('100002',3.000,4.00,'Сохранить')");
    putenv('FEO_LEGACY_SYNC_TENANT_DATABASE='.$db);
    putenv('FEO_LEGACY_SYNC_DATABASE='.$db);
    putenv('FEO_LEGACY_SYNC_CONNECTION_B64='.base64_encode(json_encode($legacyConnection,JSON_THROW_ON_ERROR)));
    putenv('FEO_LEGACY_SYNC_JOURNAL_DIR='.$journal);

    $snapshot=FeoImportTransaction::runWithLegacy($pdo,static function($legacy) use ($pdo): array {
        $pdo->exec("UPDATE feo_requests SET mass_netto=1.2345,mass_brutto=2.3456 WHERE zayavka_id='100001'");
        return $legacy->writeWeight('100001',['mass_netto'=>'1.2345','mass_brutto'=>'2.3456']);
    });
    $check($snapshot['before']===['mass_netto'=>'1.000','mass_brutto'=>'2.00'],'Exact legacy before snapshot');
    $check($snapshot['after']===['mass_netto'=>'1.234','mass_brutto'=>'2.34'],'Exact legacy after snapshot with schema precision');
    $check($weight($pdo,'feo_requests','100001')['mass_netto']==='1.2345','Tenant precision retained');
    $check($pdo->query("SELECT legacy_note FROM feo WHERE zayavka_id='100001'")->fetchColumn()==='Сохранить','Unrelated legacy field retained');

    $pdo->exec("UPDATE feo SET mass_brutto=9.99 WHERE zayavka_id='100001'");
    try {
        FeoImportTransaction::runWithLegacy($pdo,static function($legacy) use ($pdo,$snapshot): void {
            $pdo->exec("UPDATE feo_requests SET mass_netto=1.0000 WHERE zayavka_id='100001'");
            $legacy->revertWeight('100001',$snapshot);
        });
        throw new LogicException('Expected changed-weight conflict');
    } catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'изменён'),'Late edit rejected'); }
    $check($weight($pdo,'feo_requests','100001')['mass_netto']==='1.2345','Tenant rollback after conflict');
    $pdo->exec("UPDATE feo SET mass_brutto=2.34,legacy_note='Внешняя правка' WHERE zayavka_id='100001'");
    $reverse=FeoImportTransaction::runWithLegacy($pdo,static function($legacy) use ($pdo,$snapshot): array {
        $pdo->exec("UPDATE feo_requests SET mass_netto=1.0000,mass_brutto=2.0000 WHERE zayavka_id='100001'");
        return $legacy->revertWeight('100001',$snapshot);
    });
    $check($reverse['before']===$snapshot['after'] && $reverse['after']===$snapshot['before'],'Reverse legacy snapshots returned');
    $check($weight($pdo,'feo','100001')===$snapshot['before'],'Exact legacy weights restored');
    $check($pdo->query("SELECT legacy_note FROM feo WHERE zayavka_id='100001'")->fetchColumn()==='Внешняя правка','Unrelated late edit retained');

    try { FeoImportTransaction::runWithLegacy($pdo,static fn($legacy)=>$legacy->writeWeight('missing',['mass_netto'=>'1','mass_brutto'=>'2']));
        throw new LogicException('Expected missing row');
    } catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'не найдена'),'Missing legacy row rejected'); }

    $pdo->interruptCommit=true;
    try {
        FeoImportTransaction::runWithLegacy($pdo,static function($legacy) use ($pdo): void {
            $pdo->exec("UPDATE feo_requests SET mass_netto=5.5555,mass_brutto=6.6666 WHERE zayavka_id='100002'");
            $legacy->writeWeight('100002',['mass_netto'=>'5.5555','mass_brutto'=>'6.6666']);
        });
        throw new LogicException('Expected interrupted confirmation');
    } catch (RuntimeException $e) { $check(str_contains($e->getMessage(),'подтверждение'),'Interrupted confirmation reported'); }
    $check(count(glob($journal.'/*.json')?:[])===1,'Durable decision remains after interruption');
    unset($pdo);
    $pdo=$connect();
    FeoImportTransaction::recoverPending($pdo);
    $check($weight($pdo,'feo_requests','100002')['mass_netto']==='5.5555','Tenant confirmation recovered');
    $check($weight($pdo,'feo','100002')===['mass_netto'=>'5.555','mass_brutto'=>'6.66'],'Legacy confirmation retained');
    $check((glob($journal.'/*.json')?:[])===[],'Resolved decision removed');
    echo "FEO_LEGACY_WEIGHT_BRIDGE_XA=PASS\n";
} finally {
    foreach ($previous as $key=>$value) putenv($value===false?$key:$key.'='.$value);
    cleanCompanyPoolDatabase($pdo);
    foreach (glob($journal.'/*')?:[] as $file) unlink($file);
    rmdir($journal);
}
