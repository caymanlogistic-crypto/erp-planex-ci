<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/FeoXlsxParser.php';
require_once BASE_PATH.'/app/Service/FeoLocationOverrideService.php';
require_once BASE_PATH.'/app/Service/FeoTransferService.php';

use App\Service\FeoTransferService;

$db=trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow=explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'));
if (getenv('APP_ENV')!=='test' || !preg_match('/^[A-Za-z0-9_]+$/D',$db) || !in_array($db,$allow,true)) {
    throw new RuntimeException('Isolated fixed test database required.');
}
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$db.';charset=utf8mb4',
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$previous=[];
foreach (['FEO_LEGACY_SYNC_TENANT_DATABASE','FEO_LEGACY_SYNC_DATABASE','FEO_LEGACY_SYNC_CONNECTION_B64','FEO_LEGACY_TRANSFER_SYNC_ENABLED','FEO_LEGACY_SYNC_JOURNAL_DIR'] as $key) $previous[$key]=getenv($key);
$journal=null;
$assert=static function(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); };
$location=static function(PDO $db,string $table,string $id): array {
    $q=$db->prepare('SELECT mno_adres_pogruzki,mno_sh,mno_d FROM '.$table.' WHERE zayavka_id=?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
};
cleanCompanyPoolDatabase($pdo);
try {
    foreach (['122_create_feo_requests.sql','139_create_feo_transfers.sql'] as $file) $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/'.$file));
    $ddl=(string)$pdo->query('SHOW CREATE TABLE feo_requests')->fetchColumn(1);
    $ddl=preg_replace('/,\n\s+CONSTRAINT[^\n]+/','',$ddl);
    $pdo->exec(str_replace('CREATE TABLE `feo_requests`','CREATE TABLE `feo`',$ddl));
    $pdo->exec('ALTER TABLE feo DROP COLUMN current_import_id, ADD legacy_note VARCHAR(80) NULL');
    $rows="('100001','Первый адрес','37.1','55.1'),('100002','Второй адрес','37.2','55.2'),('100003','Адрес назначения','38.2','56.2')";
    $pdo->exec('INSERT INTO feo_requests(zayavka_id,mno_adres_pogruzki,mno_sh,mno_d) VALUES '.$rows);
    $pdo->exec('INSERT INTO feo(zayavka_id,mno_adres_pogruzki,mno_sh,mno_d) VALUES '.$rows);
    $pdo->exec("UPDATE feo SET legacy_note='Сохранить' WHERE zayavka_id='100001'");
    putenv('FEO_LEGACY_SYNC_TENANT_DATABASE='.$db);
    putenv('FEO_LEGACY_SYNC_DATABASE='.$db);
    if (getenv('PLANEX_TEST_FORCE_SEPARATE_XA')==='1') {
        if (PHP_OS_FAMILY==='Windows') throw new RuntimeException('Separate XA test requires local Linux.');
        $journal=sys_get_temp_dir().'/feo-transfer-xa-'.bin2hex(random_bytes(10));
        if (!mkdir($journal,0700)) throw new RuntimeException('Cannot create task XA journal.');
        putenv('FEO_LEGACY_SYNC_JOURNAL_DIR='.$journal);
        $connection=['host'=>(string)getenv('PLANEX_SCHEMA_DB_HOST'),'port'=>(int)getenv('PLANEX_SCHEMA_DB_PORT'),
            'username'=>(string)getenv('PLANEX_SCHEMA_DB_USER'),'password'=>(string)getenv('PLANEX_SCHEMA_DB_PASSWORD')];
        putenv('FEO_LEGACY_SYNC_CONNECTION_B64='.base64_encode(json_encode($connection,JSON_THROW_ON_ERROR)));
    } else {
        putenv('FEO_LEGACY_SYNC_CONNECTION_B64=');
    }
    putenv('FEO_LEGACY_TRANSFER_SYNC_ENABLED=1');

    $service=new FeoTransferService();
    $actor=['created_by_user_id'=>11,'created_by_role'=>null,'created_by_display_name'=>'Менеджер'];
    $preview=$service->preview($pdo,['100001','100002'],'request','100003');
    $created=$service->create($pdo,$preview,$actor,hash('sha256','mirrored-transfer'),'Зеркальный блок','');
    $assert($created['legacy_synced']===true,'Create must report mirror');
    foreach (['100001','100002'] as $id) $assert($location($pdo,'feo_requests',$id)===$location($pdo,'feo',$id),'Location must match in both databases');
    $assert($pdo->query("SELECT legacy_note FROM feo WHERE zayavka_id='100001'")->fetchColumn()==='Сохранить','Unrelated legacy column retained');
    $assert($pdo->query("SELECT status FROM feo_transfer_outbox WHERE operation='transfer'")->fetchColumn()==='mirrored','Outbox marked mirrored');
    $retry=$service->create($pdo,$preview,$actor,hash('sha256','mirrored-transfer'),'Зеркальный блок','');
    $assert(!empty($retry['already_applied']) && $retry['legacy_synced'],'Network retry is idempotent');

    $pdo->exec("UPDATE feo SET mno_adres_pogruzki='Внешняя правка' WHERE zayavka_id='100002'");
    try { $service->revert($pdo,$created['batch_id'],$actor); throw new RuntimeException('Expected legacy conflict'); }
    catch (RuntimeException $e) { $assert($e->getMessage()!=='Expected legacy conflict','Legacy conflict must reject revert'); }
    $assert($pdo->query('SELECT status FROM feo_transfer_batches WHERE id='.(int)$created['batch_id'])->fetchColumn()==='applied','Tenant revert rolled back');
    $assert($location($pdo,'feo_requests','100001')['mno_adres_pogruzki']==='Адрес назначения','Earlier tenant rows rolled back');
    $assert($location($pdo,'feo','100001')['mno_adres_pogruzki']==='Адрес назначения','Earlier legacy rows rolled back');
    $pdo->exec("UPDATE feo SET mno_adres_pogruzki='Адрес назначения' WHERE zayavka_id='100002'");
    $reverted=$service->revert($pdo,$created['batch_id'],$actor);
    $assert($reverted['legacy_synced']===true,'Revert must report mirror');
    $assert($location($pdo,'feo_requests','100001')===$location($pdo,'feo','100001'),'Revert restored both databases');
    $assert($location($pdo,'feo','100001')['mno_adres_pogruzki']==='Первый адрес','Original legacy address restored');
    $assert($pdo->query("SELECT status FROM feo_transfer_outbox WHERE operation='revert'")->fetchColumn()==='mirrored','Revert outbox marked mirrored');
    echo 'FEO_LEGACY_TRANSFER_MIRROR_MYSQL=PASS mode='.(getenv('PLANEX_TEST_FORCE_SEPARATE_XA')==='1'?'separate_xa':'shared')."\n";
} finally {
    foreach ($previous as $key=>$value) putenv($value===false?$key:$key.'='.$value);
    cleanCompanyPoolDatabase($pdo);
    if ($journal!==null) {
        foreach (glob($journal.'/*')?:[] as $file) unlink($file);
        rmdir($journal);
    }
}
