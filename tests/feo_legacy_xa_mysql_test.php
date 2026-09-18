<?php
declare(strict_types=1);

// Reuse the entire business contract with two independently authenticated connections.
if (getenv('APP_ENV') !== 'test') throw new RuntimeException('Test only');
$journal=sys_get_temp_dir().'/planex-feo-xa-'.bin2hex(random_bytes(8));
$connection=['host'=>getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306),
    'username'=>getenv('PLANEX_SCHEMA_DB_USER'),'password'=>getenv('PLANEX_SCHEMA_DB_PASSWORD')];
$legacyConnection=$connection;
if (getenv('PLANEX_TEST_LEGACY_USER')) {
    $legacyConnection['username']=getenv('PLANEX_TEST_LEGACY_USER');
    $legacyConnection['password']=getenv('PLANEX_TEST_LEGACY_PASSWORD');
}
putenv('FEO_LEGACY_SYNC_CONNECTION_B64='.base64_encode(json_encode($legacyConnection,JSON_THROW_ON_ERROR)));
putenv('FEO_LEGACY_SYNC_JOURNAL_DIR='.$journal);
require __DIR__.'/feo_legacy_import_sync_mysql_test.php';

use App\Service\FeoImportTransaction;

final class FeoXaFaultPdo extends PDO
{
    public string $failAt='';
    public function exec(string $statement): int|false
    {
        if($this->failAt!=='' && str_starts_with($statement,$this->failAt)) {
            $this->failAt='';
            throw new RuntimeException('Synthetic interrupted XA operation');
        }
        return parent::exec($statement);
    }
}
$db=(string)getenv('PLANEX_TEST_FIXED_DB');
$dsn='mysql:host='.$connection['host'].';port='.$connection['port'].';dbname='.$db.';charset=utf8mb4';
$connect=static fn()=>new FeoXaFaultPdo($dsn,$connection['username'],$connection['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p=$connect();
$p->exec('CREATE TABLE feo_xa_probe(id INT PRIMARY KEY,value INT NOT NULL) ENGINE=InnoDB');
$p->exec('INSERT INTO feo_xa_probe VALUES(1,0),(2,0)');
putenv('FEO_LEGACY_SYNC_TENANT_DATABASE='.$db);
putenv('FEO_LEGACY_SYNC_DATABASE='.$db);
putenv('FEO_LEGACY_SYNC_CONNECTION_B64='.base64_encode(json_encode($connection,JSON_THROW_ON_ERROR)));
$assert=static function(bool $pass,string $message): void { if(!$pass) throw new RuntimeException($message); };
try {
    // Legacy commit succeeds, tenant confirmation fails: next connection MUST commit it, never undo legacy.
    $tx=FeoImportTransaction::begin($p);
    $p->exec('UPDATE feo_xa_probe SET value=1 WHERE id=1');
    $tx->sync()->connection()->exec('UPDATE feo_xa_probe SET value=1 WHERE id=2');
    $p->failAt='XA COMMIT';
    try { $tx->commit(); throw new LogicException('Expected interrupted commit'); } catch(RuntimeException $e) {
        $assert(str_contains($e->getMessage(),'подтверждение'), 'Pending confirmation is explicit');
    }
    $tx->rollback(); // No rollback is legal after the durable commit decision.
    unset($tx,$p);
    $p=$connect();
    $tx=FeoImportTransaction::begin($p); // Resume a prepared transaction on another connection, without XA RECOVER.
    $assert($p->query('SELECT SUM(value) FROM feo_xa_probe')->fetchColumn()==2,'Recover both committed participants');
    $tx->rollback(); unset($tx);
    $assert(glob($journal.'/*.json')===[],'Resolved decision removed');

    // Failure before the second PREPARE rolls back both independently authenticated branches.
    $tx=FeoImportTransaction::begin($p);
    $p->exec('UPDATE feo_xa_probe SET value=2 WHERE id=1');
    $tx->sync()->connection()->exec('UPDATE feo_xa_probe SET value=2 WHERE id=2');
    $p->failAt='XA PREPARE';
    try { $tx->commit(); throw new LogicException('Expected prepare failure'); } catch(RuntimeException) {}
    $tx->rollback(); unset($tx);
    $assert($p->query('SELECT SUM(value) FROM feo_xa_probe')->fetchColumn()==2,'Preparation failure rolls both back');
    $assert(glob($journal.'/*.json')===[],'Aborted decision resolved');
    // Process disappearance after only legacy PREPARE: durable abort survives disconnect.
    $tx=FeoImportTransaction::begin($p);
    $p->exec('UPDATE feo_xa_probe SET value=3 WHERE id=1');
    $old=$tx->sync()->connection();
    $old->exec('UPDATE feo_xa_probe SET value=3 WHERE id=2');
    $path=glob($journal.'/*.json')[0];
    $record=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    $xid=$old->quote($record['xid']).",'legacy',1178947377";
    $old->exec('XA END '.$xid); $old->exec('XA PREPARE '.$xid);
    unset($tx,$old,$p);
    $p=$connect();
    FeoImportTransaction::recoverPending($p);
    $assert($p->query('SELECT SUM(value) FROM feo_xa_probe')->fetchColumn()==2,'Crash before decision aborts prepared and disconnected active participants');
    $assert(!is_file($path),'Recovery-only does not begin another transaction');
    // A corrupt decision must never be guessed or silently removed.
    file_put_contents($path,'{incomplete');
    $rejected=false;
    try { FeoImportTransaction::begin($p); } catch(Throwable) { $rejected=true; }
    $assert($rejected && is_file($path),'Corrupt journal blocks mutation and is preserved');
    unlink($path);
    echo "FEO_LEGACY_XA_RECOVERY: 8 passed\n";
} finally {
    $p->exec('DROP TABLE feo_xa_probe');
    foreach(glob($journal.'/*.lock')?:[] as $file) unlink($file);
    if (glob($journal.'/*')===[]) rmdir($journal);
    putenv('FEO_LEGACY_SYNC_CONNECTION_B64'); putenv('FEO_LEGACY_SYNC_JOURNAL_DIR');
    putenv('FEO_LEGACY_SYNC_TENANT_DATABASE'); putenv('FEO_LEGACY_SYNC_DATABASE');
}
