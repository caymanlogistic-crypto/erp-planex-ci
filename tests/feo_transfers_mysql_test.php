<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Core/Database.php';
foreach (['FeoXlsxParser','FeoRequestService','FeoRequestInteractionService','FeoLocationOverrideService','FeoTransferService','YandexGeocodingService','MaxEventCatalog','MaxEventNotificationService','FeoWeightChangeNotificationService'] as $class) require_once BASE_PATH.'/app/Service/'.$class.'.php';
use App\Service\FeoTransferService;
use App\Service\FeoRequestService;
use App\Service\FeoRequestInteractionService;
use App\Service\FeoXlsxParser;
use App\Service\MaxEventCatalog;
use App\Service\MaxEventNotificationService;
use App\Service\FeoWeightChangeNotificationService;

$db=trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow=array_filter(array_map('trim',explode(',',(string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if (getenv('APP_ENV')!=='test' || $db==='' || !in_array($db,$allow,true)) throw new RuntimeException('Guarded fixed test DB required.');
$pdo=new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT')?:3306).';dbname='.$db.';charset=utf8mb4',getenv('PLANEX_SCHEMA_DB_USER'),getenv('PLANEX_SCHEMA_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$count=0;
$ok=static function(bool $value,string $message) use (&$count): void { if (!$value) throw new RuntimeException($message); $count++; };
$reject=static function(callable $action,string $message) use ($ok): void { $caught=false; try{$action();}catch(RuntimeException){$caught=true;} $ok($caught,$message); };
cleanCompanyPoolDatabase($pdo);
try {
    $s=new FeoTransferService(); $ok(!$s->ready($pdo),'Pre-migration rollout');
    foreach (['122_create_feo_requests.sql','133_create_max_integration.sql','136_create_max_event_notifications.sql','139_create_feo_transfers.sql'] as $file) $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/'.$file));
    $ok($s->ready($pdo),'Schema ready');
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mno_adres_pogruzki,mno_sh,mno_d,mass_netto,mno_region) VALUES('100001','Первый адрес','37.1','55.1',12.34,'Регион'),('100002','Второй адрес',NULL,NULL,56.78,'Регион'),('100003','Адрес назначения','38.2','56.2',90,'Образец')");
    $original=$pdo->query('SELECT * FROM feo_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $actor=['created_by_user_id'=>11,'created_by_role'=>null,'created_by_display_name'=>'Менеджер'];
    $ids=['100001','100002'];
    $reject(fn()=>$s->preview($pdo,['100001','999999'],'request','100003'),'Missing source rejects whole batch');
    $reject(fn()=>$s->preview($pdo,$ids,'request','999999'),'Missing destination rejects');
    $reject(fn()=>$s->preview($pdo,$ids,'request','100001'),'Destination in sources rejects');
    $p=$s->preview($pdo,$ids,'request','100003');
    $ok($p['rows'][0]['net_kg']===12340.0 && $p['rows'][1]['net_kg']===56780.0,'Preview exposes each current net weight in kilograms');
    $pdo->exec("UPDATE feo_requests SET mass_netto=NULL WHERE zayavka_id='100001'");
    $pdo->exec("UPDATE feo_requests SET mass_netto=0 WHERE zayavka_id='100002'");
    $weightsChanged=$s->preview($pdo,$ids,'request','100003');
    $ok($weightsChanged['rows'][0]['net_kg']===null && $weightsChanged['rows'][1]['net_kg']===0.0,'Missing net weight and known zero remain distinct');
    $ok($weightsChanged['state_hash']===$p['state_hash'],'Weight edits do not change address stale guard');
    $pdo->exec("UPDATE feo_requests SET mass_netto=12.34 WHERE zayavka_id='100001'");
    $pdo->exec("UPDATE feo_requests SET mass_netto=56.78 WHERE zayavka_id='100002'");
    $pdo->exec("UPDATE feo_requests SET mno_sh='38.3' WHERE zayavka_id='100003'");
    $reject(fn()=>$s->create($pdo,$p,$actor,hash('sha256','stale'),'Тест',''),'Preview destination drift rejects');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM feo_transfer_batches')->fetchColumn()===0,'No partial batch');
    $pdo->exec("UPDATE feo_requests SET mno_sh='38.2',updated_at='2020-01-02 03:04:05' WHERE zayavka_id='100003'");
    // The deliberate destination-drift setup is outside the transaction under test.
    // Its updated_at legitimately differs; snapshot the actual state before the failing operation.
    $original=$pdo->query('SELECT * FROM feo_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $p=$s->preview($pdo,$ids,'request','100003');
    $reject(fn()=>$s->create($pdo,$p,$actor,hash('sha256','rollback'),'Тест','',static function(){throw new RuntimeException('Event storage failed');}),'Transactional callback failure');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM feo_transfer_outbox')->fetchColumn()===0,'Rollback removes outbox');
    $ok($pdo->query('SELECT * FROM feo_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$original,'Rollback restores all rows');
    $a=$s->create($pdo,$p,$actor,hash('sha256','a'),'Пакет А','Причина');
    $again=$s->create($pdo,$p,$actor,hash('sha256','a'),'Пакет А','Причина');
    $ok($again['batch_id']===$a['batch_id'] && $again['already_applied'],'Idempotent network retry');
    $index=$s->batchIndexItems($pdo,$s->batches($pdo,1));
    $itemWeights=$s->items($pdo,$a['batch_id']);
    $ok($itemWeights[0]['net_kg']===12340.0 && $itemWeights[1]['net_kg']===56780.0,'History exposes each current net weight, not a share of block total');
    $ok($index[$a['batch_id']]['ids']===['100001','100002'],'History index has every persisted request ID');
    $ok($index[$a['batch_id']]['net_kg']===69120.0 && $index[$a['batch_id']]['gross_kg']===null,'History mass uses current tonnes converted to kilograms and exposes missing gross');
    $rows=$pdo->query('SELECT * FROM feo_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $ok($rows[0]['mno_sh']==='38.2' && $rows[0]['mno_d']==='56.2','Preserve inverted client coordinate fields');
    foreach ($rows as $i=>$row) foreach ($row as $field=>$value) if (!in_array($field,['mno_adres_pogruzki','mno_sh','mno_d','updated_at'],true)) $ok($value===$original[$i][$field],'Unrelated field changed: '.$field);
    $ok($rows[2]===$original[2],'Destination unchanged');
    $interaction=new FeoRequestInteractionService();
    $reject(fn()=>$interaction->updateRequest($pdo,(int)$rows[0]['id'],['mno_adres_pogruzki'=>'Override']),'Direct edit cannot bypass active transfer');
    $requestService=new FeoRequestService([],new App\Core\Database([]));
    $importRow=array_fill_keys(FeoXlsxParser::fields(),null);
    $importRow=array_replace($importRow,['zayavka_id'=>'100001','mno_adres_pogruzki'=>'Old external address','mno_sh'=>'77','mno_d'=>'66','mass_netto'=>'42.0000']);
    $import=$requestService->applyImport($pdo,[$importRow],'after-transfer.xlsx',hash('sha256','import'),['user_id'=>11,'role_code'=>'account']);
    $row=$pdo->query("SELECT * FROM feo_requests WHERE zayavka_id='100001'")->fetch(PDO::FETCH_ASSOC);
    $ok($row['mno_adres_pogruzki']==='Адрес назначения' && (float)$row['mass_netto']===42.0,'Import preserves moved location and updates other fields');
    $ok($s->batchIndexItems($pdo,$s->batches($pdo,1))[$a['batch_id']]['net_kg']===98780.0,'History reflects current weight without rewriting transfer history');
    $ok($s->items($pdo,$a['batch_id'])[0]['net_kg']===42000.0,'History detail reflects current request weight after import');
    $requestService->rollbackLatest($pdo,$import['import_id'],['user_id'=>11]);
    $ok($pdo->query("SELECT mno_adres_pogruzki FROM feo_requests WHERE zayavka_id='100001'")->fetchColumn()==='Адрес назначения','Import rollback preserves override');
    $requestService->restoreRolledBackImport($pdo,$import['import_id'],['user_id'=>11]);
    $ok($pdo->query("SELECT mno_adres_pogruzki FROM feo_requests WHERE zayavka_id='100001'")->fetchColumn()==='Адрес назначения','Import restore preserves override');
    $geo=['query'=>'Введённый адрес','address'=>'Нормализованный адрес Яндекса','longitude'=>'40.000','latitude'=>'60.000','precision'=>'street','quality'=>'Только улица, дом не определён','provider'=>'yandex'];
    $bPreview=$s->preview($pdo,['100002'],'address','',$geo);
    $ok($bPreview['destination']['address']==='Введённый адрес' && $bPreview['destination']['longitude']==='40.000' && $bPreview['destination']['latitude']==='60.000','Address preview retains entered text and Yandex coordinates');
    $b=$s->create($pdo,$bPreview,$actor,hash('sha256','b'),'Пакет Б','');
    $ok($pdo->query("SELECT mno_adres_pogruzki FROM feo_requests WHERE zayavka_id='100002'")->fetchColumn()==='Введённый адрес','Address transfer stores entered text');
    $savedDestination=json_decode($pdo->query('SELECT destination_json FROM feo_transfer_batches WHERE id='.(int)$b['batch_id'])->fetchColumn(),true);
    $savedAfter=json_decode($pdo->query('SELECT after_json FROM feo_transfer_items WHERE batch_id='.(int)$b['batch_id'])->fetchColumn(),true);
    $ok($savedDestination['address']==='Введённый адрес' && $savedAfter['mno_adres_pogruzki']==='Введённый адрес' && $savedAfter['mno_sh']==='40.000' && $savedAfter['mno_d']==='60.000','History and legacy payload source use entered text with Yandex coordinates');
    $savedOutbox=json_decode($pdo->query("SELECT payload_json FROM feo_transfer_outbox WHERE batch_id=".(int)$b['batch_id']." AND operation='transfer'")->fetchColumn(),true);
    $ok($savedOutbox['items'][0]['after']['mno_adres_pogruzki']==='Введённый адрес','Legacy outbox retains entered address');
    $reject(fn()=>$s->revert($pdo,$a['batch_id'],$actor),'Reject older intersecting batch revert');
    $ok($pdo->query("SELECT mno_adres_pogruzki FROM feo_requests WHERE zayavka_id='100001'")->fetchColumn()==='Адрес назначения','Conflict after first row rolls back entire batch');
    $s->revert($pdo,$b['batch_id'],$actor);
    $s->revert($pdo,$a['batch_id'],$actor);
    $rows=$pdo->query('SELECT * FROM feo_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ([0,1] as $i) foreach (['mno_adres_pogruzki','mno_sh','mno_d'] as $field) $ok($rows[$i][$field]===$original[$i][$field],'Exact original location restored including nulls');
    $ok((float)$rows[0]['mass_netto']===42.0,'Transfer revert preserves later non-geographic edits');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM feo_location_overrides')->fetchColumn()===0,'Override chain removed');
    $again=$s->revert($pdo,$a['batch_id'],$actor); $ok($again['already_reverted'],'Revert idempotent');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM feo_transfer_outbox')->fetchColumn()===4,'One immutable transport event per operation');
    $out=json_decode($pdo->query('SELECT payload_json FROM feo_transfer_outbox ORDER BY id LIMIT 1')->fetchColumn(),true);
    $ok($out['coordinate_storage']['mno_sh']==='longitude' && count($out['items'])===2,'Legacy payload explicit semantics');
    $newRow=array_replace($importRow,['zayavka_id'=>'100004']);
    $newImport=$requestService->applyImport($pdo,[$newRow],'new.xlsx',hash('sha256','new'),['user_id'=>11]);
    $p=$s->preview($pdo,['100004'],'request','100003'); $s->create($pdo,$p,$actor,hash('sha256','c'),'Защита удаления','');
    $reject(fn()=>$requestService->rollbackLatest($pdo,$newImport['import_id'],['user_id'=>11]),'Cannot delete actively transferred imported request');
    $events=new MaxEventNotificationService($pdo);
    $pdo->exec("INSERT INTO max_bots(id,name,token_encrypted,webhook_secret_encrypted,is_active) VALUES(1,'Test','synthetic','synthetic',1)");
    $pdo->exec("INSERT INTO max_chats(id,bot_id,chat_id,name,is_active,max_status) VALUES(1,1,'-100','Test',1,'active')");
    foreach (['created','reverted'] as $operation) {
        $key='feo.transfer.'.$operation; $event=MaxEventCatalog::event($key);
        $events->saveRule(['event_key'=>$key,'is_enabled'=>1,'chat_config_id'=>1,'message_template'=>$event['template'],'message_format'=>'plain'],11);
        $pdo->beginTransaction(); $id=$events->enqueue($key,'999',MaxEventCatalog::example($key),11); $pdo->commit();
        $ok($id>0,'MAX event enqueued');
    }
    $ok((int)$pdo->query('SELECT COUNT(*) FROM max_event_deliveries')->fetchColumn()===2,'Created and reverted notifications');

    $weightEvent=MaxEventCatalog::event(FeoWeightChangeNotificationService::EVENT_KEY);
    $events->saveRule(['event_key'=>FeoWeightChangeNotificationService::EVENT_KEY,'is_enabled'=>1,'chat_config_id'=>1,'message_template'=>$weightEvent['template'],'message_format'=>'plain'],11);
    $weightNotifications=new FeoWeightChangeNotificationService();
    $company=['name'=>'Тестовая компания'];
    $weightActor=['created_by_user_id'=>11,'created_by_display_name'=>'Менеджер'];
    $bulkChanges=[];
    for($i=1;$i<=70;$i++) $bulkChanges[]=['request_id'=>str_pad((string)$i,64,'9',STR_PAD_LEFT),'before_kg'=>1000.0,'after_kg'=>1001.0,'difference_kg'=>1.0];
    $pdo->beginTransaction();
    $bulkIds=$weightNotifications->enqueue($pdo,$bulkChanges,'bulk:test','Проверка большого пакета',$company,$weightActor);
    $pdo->commit();
    $ok(count($bulkIds)>1,'Large weight batch splits into several MAX deliveries');
    $bulkMarks=implode(',',array_fill(0,count($bulkIds),'?'));
    $bulkQuery=$pdo->prepare("SELECT message_text FROM max_event_deliveries WHERE id IN ($bulkMarks)");
    $bulkQuery->execute($bulkIds);
    foreach($bulkQuery->fetchAll(PDO::FETCH_COLUMN) as $message) $ok(mb_strlen((string)$message)<=4000,'Every weight message fits MAX limit');
    $pdo->prepare("DELETE FROM max_event_deliveries WHERE id IN ($bulkMarks)")->execute($bulkIds);
    $targetId=(int)$pdo->query("SELECT id FROM feo_requests WHERE zayavka_id='100003'")->fetchColumn();
    $reject(fn()=>$interaction->updateRequest($pdo,$targetId,['mass_netto'=>'91'],static function(): void { throw new RuntimeException('Synthetic event failure'); }),'Manual weight event failure rolls back request');
    $ok((float)$pdo->query("SELECT mass_netto FROM feo_requests WHERE zayavka_id='100003'")->fetchColumn()===90.0,'Manual weight rollback is atomic');

    $manualIds=[];
    $interaction->updateRequest($pdo,$targetId,['mass_netto'=>'91'],function(array $changes) use($weightNotifications,$pdo,$company,$weightActor,&$manualIds): void {
        $manualIds=$weightNotifications->enqueue($pdo,$changes,'manual:100003:test','Ручное редактирование заявки №100003',$company,$weightActor);
    });
    $ok(count($manualIds)===1,'Manual weight change queued in transaction');

    $weightImportRow=array_fill_keys(FeoXlsxParser::fields(),null);
    $weightImportRow=array_replace($weightImportRow,['zayavka_id'=>'100003','mno_adres_pogruzki'=>'Адрес назначения','mno_sh'=>'38.2','mno_d'=>'56.2','mass_netto'=>'92.0000','mno_region'=>'Образец']);
    $importIds=[];
    $weightImport=$requestService->applyImport($pdo,[$weightImportRow],'weight.xlsx',hash('sha256','weight-import'),['user_id'=>11,'role_code'=>'account'],true,
        function(array $changes,int $importId) use($weightNotifications,$pdo,$company,$weightActor,&$importIds): void {
            $importIds=array_merge($importIds,$weightNotifications->enqueue($pdo,$changes,'import:'.$importId.':apply','Применение импорта ФЭО #'.$importId,$company,$weightActor));
        });
    $requestService->rollbackLatest($pdo,$weightImport['import_id'],['user_id'=>11],
        function(array $changes,int $importId) use($weightNotifications,$pdo,$company,$weightActor,&$importIds): void {
            $importIds=array_merge($importIds,$weightNotifications->enqueue($pdo,$changes,'import:'.$importId.':rollback','Откат импорта ФЭО #'.$importId,$company,$weightActor));
        });
    $requestService->restoreRolledBackImport($pdo,$weightImport['import_id'],['user_id'=>11],
        function(array $changes,int $importId) use($weightNotifications,$pdo,$company,$weightActor,&$importIds): void {
            $importIds=array_merge($importIds,$weightNotifications->enqueue($pdo,$changes,'import:'.$importId.':restore','Возврат импорта ФЭО #'.$importId,$company,$weightActor));
        });
    $requestService->deleteLatestImport($pdo,$weightImport['import_id'],['user_id'=>11],
        function(array $changes,int $importId) use($weightNotifications,$pdo,$company,$weightActor,&$importIds): void {
            $importIds=array_merge($importIds,$weightNotifications->enqueue($pdo,$changes,'import:'.$importId.':delete','Удаление импорта ФЭО #'.$importId,$company,$weightActor));
        });
    $ok(count($importIds)===4,'Apply, rollback, restore and delete each queue one weight event');
    $ok((float)$pdo->query("SELECT mass_netto FROM feo_requests WHERE zayavka_id='100003'")->fetchColumn()===91.0,'Deleting applied import restores pre-import weight');
    $weightDeliveries=$pdo->query("SELECT entity_key,message_text FROM max_event_deliveries WHERE event_key='feo.request.weight_changed' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $ok(count($weightDeliveries)===5,'All weight mutation paths create deliveries');
    foreach ($weightDeliveries as $delivery) $ok(str_contains($delivery['message_text'],'№100003:') && str_contains($delivery['message_text'],'кг'),'Weight delivery contains request and kilogram delta');
    echo "FEO_TRANSFER_MYSQL=PASS ($count assertions)\n";
} finally { cleanCompanyPoolDatabase($pdo); }
