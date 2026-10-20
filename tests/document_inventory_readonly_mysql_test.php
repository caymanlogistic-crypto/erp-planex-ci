<?php
declare(strict_types=1);
define('PLANEX_DOCUMENT_INVENTORY_LIBRARY_ONLY', true);
require dirname(__DIR__).'/scripts/registry_document_inventory_readonly_probe.php';
$db=(string)getenv('PLANEX_TEST_FIXED_DB');
if (getenv('APP_ENV')!=='test' || !in_array($db,explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST')),true) || $db==='')
    throw new RuntimeException('Unique disposable test schema required');
$pdo=new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_HOST'),getenv('PLANEX_SCHEMA_DB_PORT'),$db),
    getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$assert=static function(bool $ok,string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; };
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'planex-document-inventory-'.bin2hex(random_bytes(8));
mkdir($root); $card='00000000-0000-0000-0000-000000000001';
$dir=$root.'/companies/7/route-executor-review/'.$card; mkdir($dir,0700,true);
file_put_contents($dir.'/first.jpg','first file'); file_put_contents($dir.'/second.jpg','second file');
try {
    foreach (RegistryDocumentInventoryReadOnly::TABLES as $table=>$cols) {
        $columns=[];
        $pk=str_starts_with($table,'route_executor_review_')?'card_id':'id';
        foreach ($cols as $col) $columns[]="`$col` ".($col==='id'?'BIGINT':($col==='card_id'?'VARCHAR(36) CHARACTER SET ascii COLLATE ascii_bin':'MEDIUMTEXT')).($col===$pk?' NOT NULL':' NULL');
        $pdo->exec("CREATE TABLE `$table` (".implode(',',$columns).",PRIMARY KEY(`$pk`)) ENGINE=InnoDB");
    }
    $files=[];
    foreach (['first.jpg','second.jpg'] as $file) $files[]=['id'=>$file,'formId'=>'lab-driver-form','name'=>'predef_doc[passport][]','index'=>0,
        'original_name'=>$file,'path'=>"companies/7/route-executor-review/$card/$file",'mime'=>'image/jpeg','size'=>filesize($dir.'/'.$file)];
    $snapshot=['selected_entities'=>['driver'=>66],'controls'=>[
        ['formId'=>'lab-driver-form','name'=>'passport_number','value'=>'PRIVATE-MUST-NOT-EXPORT'],
        ['formId'=>'lab-driver-form','name'=>'predef_doc_type[passport]','value'=>'Паспорт']], 'removed_documents'=>[42]];
    $q=$pdo->prepare('INSERT INTO route_executor_review_cards(card_id,snapshot_json,files_json,deleted_at) VALUES(?,?,?,?)');
    $q->execute([$card,json_encode($snapshot),json_encode($files),'2026-10-19 13:49:08']);
    // Cross a keyset page boundary and require the explicit empty end page.
    for ($i=2;$i<=251;$i++) $q->execute([sprintf('00000000-0000-0000-0000-%012d',$i),'{}','[]',null]);
    $pdo->exec("INSERT INTO documents(id,entity_type,entity_id,document_type,original_name,relative_path,file_size,deleted_at) VALUES
        (1,'driver',66,'passport','second.jpg','companies/7/documents/driver/66/missing.jpg',11,NULL),
        (2,'driver',66,'Паспорт','old.jpg','companies/7/documents/driver/66/old.jpg',11,'2026-10-19'),
        (3,'other',1,'PRIVATE-MUST-NOT-EXPORT','PRIVATE-MUST-NOT-EXPORT','private/path',11,NULL)");
    RegistryDocumentInventoryReadOnly::snapshot($pdo);
    $blocked=false;
    try { $pdo->exec("UPDATE documents SET document_type='changed' WHERE id=1"); } catch (PDOException $e) { $blocked=(int)($e->errorInfo[1]??0)===1792; }
    $assert($blocked,'actual read-only snapshot rejects DML');
    $lines=[];
    $manifest=RegistryDocumentInventoryReadOnly::export($pdo,7,$root,static function($r) use (&$lines) {$lines[]=$r;},microtime(true)+30);
    $assert($manifest['completed'] && $manifest['table_counts']['route_executor_review_cards']['exported']===251,'complete keyset across pages');
    $assert($manifest['table_counts']['route_executor_review_cards']['pages']===3,'explicit empty terminal page');
    $cards=array_values(array_filter($lines,fn($l)=>($l['table']??'')==='route_executor_review_cards' && $l['kind']==='row'));
    $assert($cards[0]['row']['deleted_at']!==null && count($cards[0]['row']['files_json'])===2,'deleted card and both source occurrences retained');
    $assert(!str_contains(json_encode($lines),'PRIVATE-MUST-NOT-EXPORT'),'sensitive unrelated fields omitted');
    $assert($cards[0]['row']['files_json'][0]['inspection']['sha256']===hash('sha256','first file'),'actual source bytes hashed');
    $assert($manifest['table_counts']['documents']['exported']===3,'all document categories counted including soft-delete');
    $assert($manifest['inspections']['missing']===2,'missing destination bytes detected');
    $pdo->rollBack();
    $assert(RegistryDocumentInventoryReadOnly::inspect($root,7,'companies/7/route-executor-review/'.$card.'/first.jpg','route-executor-review/'.$card,microtime(true)+10)['status']==='present_stable','valid file positive');
    foreach (['../secret','companies/8/route-executor-review/'.$card.'/first.jpg','companies/7/route-executor-review/'.$card.'/../first.jpg','C:/secret','companies\\7\\secret'] as $bad)
        $assert(RegistryDocumentInventoryReadOnly::inspect($root,7,$bad,'route-executor-review/'.$card,microtime(true)+10)['status']==='rejected_path','path containment negative');
    $pdo->exec('ALTER TABLE documents DROP COLUMN relative_path');
    RegistryDocumentInventoryReadOnly::snapshot($pdo); $failed=false;
    try { RegistryDocumentInventoryReadOnly::export($pdo,7,$root,static fn($r)=>null,microtime(true)+30); }
    catch (RuntimeException $e) { $failed=$e->getMessage()==='REQUIRED_COLUMN_UNAVAILABLE'; }
    $assert($failed,'missing required schema fails closed'); $pdo->rollBack();
    echo "DOCUMENT_INVENTORY_MYSQL_PASS\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Exact random synthetic root only; no product or shared storage cleanup.
    if (is_dir($root)) {
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());
        rmdir($root);
    }
}
