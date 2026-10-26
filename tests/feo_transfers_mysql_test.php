<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Core/Database.php';
foreach (['FeoXlsxParser','FeoRequestService','FeoRequestInteractionService','FeoLocationOverrideService','FeoTransferService','YandexGeocodingService','MaxEventCatalog','MaxEventNotificationService','FeoWeightChangeNotificationService'] as $class) require_once BASE_PATH.'/app/Service/'.$class.'.php';
require_once BASE_PATH.'/app/Http/Controllers/Company/FeoTransferController.php';
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
    $reject(fn()=>$s->deleteReturned($pdo,$b['batch_id']),'Active transfer cannot be deleted');
    $pdo->exec("UPDATE feo_transfer_batches SET status='reverted' WHERE id=".(int)$b['batch_id']);
    $reject(fn()=>$s->deleteReturned($pdo,$b['batch_id']),'Block with active override cannot be deleted even if status is inconsistent');
    $pdo->exec("UPDATE feo_transfer_batches SET status='applied' WHERE id=".(int)$b['batch_id']);
    $ok($pdo->query("SELECT mno_adres_pogruzki FROM feo_requests WHERE zayavka_id='100001'")->fetchColumn()==='Адрес назначения','Conflict after first row rolls back entire batch');
    $s->revert($pdo,$b['batch_id'],$actor);
    $beforeDeletion=$pdo->query('SELECT zayavka_id,mno_adres_pogruzki,mno_sh,mno_d FROM feo_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $deleted=$s->deleteReturned($pdo,$b['batch_id']);
    $ok($deleted['batch_id']===$b['batch_id'] && !$deleted['already_deleted'],'Returned block is logically deleted');
    $ok($s->deleteReturned($pdo,$b['batch_id'])['already_deleted'],'Deletion retry is idempotent');
    $ok($pdo->query('SELECT status FROM feo_transfer_batches WHERE id='.(int)$b['batch_id'])->fetchColumn()==='deleted','Deleted status is retained in existing batch row');
    $ok(!in_array($b['batch_id'],array_column($s->batches($pdo,1),'id')),'Deleted block is absent from history listing');
    $ok(count($s->items($pdo,$b['batch_id']))===1,'Deleted block keeps request snapshots');
    $ok((int)$s->items($pdo,$b['batch_id'])[0]['previous_batch_id']===$a['batch_id'],'Deleted block retains previous-batch chain');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM feo_transfer_outbox')->fetchColumn()===3,'Deletion keeps immutable transfer and return outbox without new event');
    $ok($pdo->query('SELECT zayavka_id,mno_adres_pogruzki,mno_sh,mno_d FROM feo_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$beforeDeletion,'Deletion does not change request locations');
    $reject(fn()=>$s->revert($pdo,$b['batch_id'],$actor),'Deleted transfer cannot be returned again');
    $reject(fn()=>$s->create($pdo,$bPreview,$actor,hash('sha256','b'),'Пакет Б',''),'Deleted operation cannot be replayed');
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
    $bulkMessages=$bulkQuery->fetchAll(PDO::FETCH_COLUMN);
    foreach($bulkMessages as $message) $ok(mb_strlen((string)$message)<=4000,'Every weight message fits MAX limit');
    $bulkText=implode("\n",$bulkMessages);
    foreach($bulkChanges as $change) $ok(str_contains($bulkText,'**'.$change['request_id'].'**:'),'Split weight messages retain each bold request ID');
    $pdo->prepare("DELETE FROM max_event_deliveries WHERE id IN ($bulkMarks)")->execute($bulkIds);
    $blockChange=FeoWeightChangeNotificationService::change(
        ['zayavka_id'=>'310500','mass_netto'=>'1.000','mass_brutto'=>'1.100'],
        ['zayavka_id'=>'310500','mass_netto'=>'1.200','mass_brutto'=>'1.300']
    );
    $missingChange=FeoWeightChangeNotificationService::change(
        ['zayavka_id'=>'310501','mass_netto'=>null,'mass_brutto'=>'0'],
        ['zayavka_id'=>'310501','mass_netto'=>'0','mass_brutto'=>null]
    );
    $singleApply=FeoWeightChangeNotificationService::change(
        ['zayavka_id'=>'314308','mass_netto'=>'1.000','mass_brutto'=>'1.075'],
        ['zayavka_id'=>'314308','mass_netto'=>'111.000','mass_brutto'=>'111.075']
    );
    $singleRevert=FeoWeightChangeNotificationService::change(
        ['zayavka_id'=>'314308','mass_netto'=>'111.000','mass_brutto'=>'111.075'],
        ['zayavka_id'=>'314308','mass_netto'=>'1.000','mass_brutto'=>'1.075']
    );
    $pdo->beginTransaction();
    $createdWeightId=$weightNotifications->enqueue($pdo,[$blockChange,$missingChange],'batch:5:apply','Изменение веса, блок #5',$company,$weightActor)[0];
    $singleApplyId=$weightNotifications->enqueue($pdo,[$singleApply],'batch:6:apply','Изменение веса, блок #6',$company,$weightActor)[0];
    $revertedWeightId=$weightNotifications->enqueue($pdo,[$singleRevert],'batch:6:revert','Возврат веса, блок #6',$company,$weightActor)[0];
    $pdo->commit();
    $createdMessage=$pdo->query('SELECT message_text FROM max_event_deliveries WHERE id='.$createdWeightId)->fetchColumn();
    $singleApplyDelivery=$pdo->query('SELECT message_text,message_format FROM max_event_deliveries WHERE id='.$singleApplyId)->fetch(PDO::FETCH_ASSOC);
    $revertedMessage=$pdo->query('SELECT message_text FROM max_event_deliveries WHERE id='.$revertedWeightId)->fetchColumn();
    $ok(str_contains($createdMessage,"ИЗМЕНЕНИЕ ВЕСА ЗАЯВОК\nБлок \\#5\nЗАЯВКИ\n**310500**:"),'Weight block creation follows compact MAX standard without duplicate ID list');
    $ok($singleApplyDelivery['message_format']==='markdown' && $singleApplyDelivery['message_text']==="**ИЗМЕНЕНИЕ ВЕСА ЗАЯВКИ**\n**314308**\nнетто 1 000 → 111 000 кг (+110 000 кг);\nбрутто 1 075 → 111 075 кг (+110 000 кг)",'Single apply uses the exact approved MAX copy');
    $ok($revertedMessage==="**ВОЗВРАТ ЗАЯВОК К ИСХОДНОМУ ВЕСУ**\n**314308**\nнетто 111 000 → 1 000 кг (-110 000 кг);\nбрутто 111 075 → 1 075 кг (-110 000 кг)",'Single revert uses the exact restored weights and approved title');
    foreach ([$singleApplyDelivery['message_text'],$revertedMessage] as $singleMessage) $ok(!str_contains($singleMessage,'Блок #') && !str_contains($singleMessage,"\nЗАЯВКИ\n") && !str_contains($singleMessage,'СТАЛО'),'Single weight MAX message has no multi-request headings');
    $ok(str_contains($createdMessage,'**310501**: нетто — → 0 кг; брутто 0 → — кг'),'Missing weights remain distinct from real zero');
    $ok(!str_contains($createdMessage,'Ответственный:') && !str_contains($createdMessage,'Общая масса:'),'Standard MAX message omits legacy metadata and misleading totals');
    $pdo->exec('DELETE FROM max_event_deliveries WHERE id IN ('.$createdWeightId.','.$singleApplyId.','.$revertedWeightId.')');
    $events->saveRule(['event_key'=>FeoWeightChangeNotificationService::EVENT_KEY,'is_enabled'=>1,'chat_config_id'=>1,'message_template'=>'OWNER {{changes}}','message_format'=>'plain'],11);
    $pdo->beginTransaction();
    $customWeightId=$weightNotifications->enqueue($pdo,[$blockChange],'manual:310500:custom','Ручное редактирование заявки',$company,$weightActor)[0];
    $pdo->commit();
    $customWeight=$pdo->query('SELECT message_text,message_format FROM max_event_deliveries WHERE id='.$customWeightId)->fetch(PDO::FETCH_ASSOC);
    $ok(str_starts_with($customWeight['message_text'],'OWNER №310500:') && $customWeight['message_format']==='plain','Custom weight text and format keep their original request marker');
    $pdo->exec('DELETE FROM max_event_deliveries WHERE id='.$customWeightId);
    $events->saveRule(['event_key'=>FeoWeightChangeNotificationService::EVENT_KEY,'is_enabled'=>1,'chat_config_id'=>1,'message_template'=>$weightEvent['template'],'message_format'=>'plain'],11);
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
    foreach ($weightDeliveries as $delivery) $ok(str_contains($delivery['message_text'],'**100003**:') && str_contains($delivery['message_text'],'кг'),'Weight delivery contains request and kilogram delta');
    $ok(str_contains($weightDeliveries[0]['message_text'],"Ручное редактирование заявки №100003\nЗАЯВКИ"),'Manual change keeps its source');
    $ok(str_contains($weightDeliveries[1]['message_text'],"Применение импорта ФЭО \\#"),'Import change keeps its source');

    $transferController=(new ReflectionClass(\App\Http\Controllers\Company\FeoTransferController::class))->newInstanceWithoutConstructor();
    $enqueueMain=new ReflectionMethod($transferController,'enqueueMain');
    $largeItems=[];
    for($i=0;$i<600;$i++) {
        $number=(string)(310000+$i);
        $largeItems[]=['request_number'=>$number,
            'before'=>['mno_adres_pogruzki'=>str_repeat('Исходный адрес ',8).$number],
            'after'=>['mno_adres_pogruzki'=>'Липецкая область, Липецк, улица Передельческая, 5Б']];
    }
    $largeResult=['batch_id'=>900,'count'=>count($largeItems),'destination'=>[
        'address'=>'Липецкая область, Липецк, улица Передельческая, 5Б',
        'quality'=>'Точное совпадение дома','source_request_id'=>'345356'], 'items'=>$largeItems];
    // Existing production rules were saved with CRLF and legacy HTML/plain formats.
    // They must take the approved split/Markdown path without changing the stored rows.
    foreach (['created'=>['Создан пакет переноса','html','ПЕРЕМЕЩЕНИЕ ЗАЯВОК'],
              'reverted'=>['Возвращён пакет переноса','plain','ВОЗВРАТ ЗАЯВОК НА ИЗНАЧАЛЬНЫЕ АДРЕСА']] as $action=>[$oldTitle,$oldFormat,$newTitle]) {
        $key='feo.transfer.'.$action;
        $legacy=$oldTitle." №{{batch_id}}: {{batch_name}}\r\nЗаявок: {{request_count}}\r\nАдрес: {{address}}\r\nКачество координат: {{quality}}\r\nАвтор: {{author}}\r\n{{company}} · {{created_at}}\r\n{{comment}}";
        $pdo->prepare('UPDATE max_event_rules SET message_template=?,message_format=? WHERE event_key=?')->execute([$legacy,$oldFormat,$key]);
        $result=$largeResult; $result['batch_id']=$action==='created'?910:911; $result['count']=1; $result['items']=[$largeItems[0]];
        $pdo->beginTransaction();
        $ids=$enqueueMain->invoke($transferController,$pdo,$events,$key,$result,'Перемещение заявок','',$company,$weightActor);
        $pdo->commit();
        $ok(count($ids)===1,'Existing CRLF legacy '.$action.' rule produces one delivery');
        $q=$pdo->prepare('SELECT message_text,message_format FROM max_event_deliveries WHERE id=?'); $q->execute([$ids[0]]); $delivery=$q->fetch(PDO::FETCH_ASSOC);
        $ok(str_starts_with($delivery['message_text'],$newTitle) && !str_contains($delivery['message_text'],$oldTitle),'Existing CRLF legacy '.$action.' rule renders approved text');
        $ok($delivery['message_format']==='markdown','Existing CRLF legacy '.$action.' rule sends Markdown');
        $q=$pdo->prepare('SELECT message_template,message_format FROM max_event_rules WHERE event_key=?'); $q->execute([$key]); $stored=$q->fetch(PDO::FETCH_ASSOC);
        $ok($stored['message_template']===$legacy && $stored['message_format']===$oldFormat,'Existing CRLF legacy '.$action.' settings remain untouched');
        $events->saveRule(['event_key'=>$key,'is_enabled'=>1,'chat_config_id'=>1,
            'message_template'=>MaxEventCatalog::event($key)['template'],'message_format'=>'plain'],11);
    }
    $pdo->beginTransaction();
    $mainIds=$enqueueMain->invoke($transferController,$pdo,$events,'feo.transfer.created',$largeResult,'','',$company,$weightActor);
    $pdo->commit();
    $ok(count($mainIds)>1,'Large transfer splits into MAX messages');
    $marks=implode(',',array_fill(0,count($mainIds),'?'));
    $q=$pdo->prepare("SELECT message_text,message_format FROM max_event_deliveries WHERE id IN ($marks) ORDER BY id");
    $q->execute($mainIds); $mainDeliveries=$q->fetchAll(PDO::FETCH_ASSOC);
    $allText=implode("\n",array_column($mainDeliveries,'message_text'));
    foreach ($mainDeliveries as $delivery) $ok(mb_strlen($delivery['message_text'])<=4000 && $delivery['message_format']==='markdown','Every main transfer part uses bounded Markdown');
    foreach ($largeItems as $item) $ok(str_contains($allText,(string)$item['request_number']),'Every transferred request appears in MAX');
    $longAddress=str_repeat('А',1000).str_repeat('Б',1000).str_repeat('В',1000).str_repeat('Г',500);
    $longResult=$largeResult; $longResult['batch_id']=903; $longResult['count']=1;
    $longResult['items']=[$largeItems[0]]; $longResult['destination']['address']=$longAddress;
    $pdo->beginTransaction();
    $longIds=$enqueueMain->invoke($transferController,$pdo,$events,'feo.transfer.created',$longResult,'','',$company,$weightActor);
    $pdo->commit();
    $ok(count($longIds)===4,'Full 3500-character entered address has deterministic MAX continuations');
    $marks=implode(',',array_fill(0,count($longIds),'?'));
    $q=$pdo->prepare("SELECT entity_key,message_text FROM max_event_deliveries WHERE id IN ($marks) ORDER BY id");
    $q->execute($longIds); $longRows=$q->fetchAll(PDO::FETCH_ASSOC);
    $ok(array_column($longRows,'entity_key')===['903','b903:main:p1','b903:main:p2','b903:main:p3'],'Long address continuation keys are deterministic');
    foreach (['А'=>1000,'Б'=>1000,'В'=>1000,'Г'=>500] as $character=>$length) {
        $ok(str_contains(implode("\n",array_column($longRows,'message_text')),str_repeat($character,$length)),'No entered-address segment is lost');
    }
    $largeResult['batch_id']=901;
    $pdo->beginTransaction();
    $returnIds=$enqueueMain->invoke($transferController,$pdo,$events,'feo.transfer.reverted',$largeResult,'','',$company,$weightActor);
    $pdo->commit();
    $ok(count($returnIds)>1,'Large return splits into MAX messages');
    $marks=implode(',',array_fill(0,count($returnIds),'?'));
    $q=$pdo->prepare("SELECT message_text FROM max_event_deliveries WHERE id IN ($marks) ORDER BY id");
    $q->execute($returnIds); $returnTexts=$q->fetchAll(PDO::FETCH_COLUMN);
    $allText=implode("\n",$returnTexts);
    foreach ($returnTexts as $text) $ok(mb_strlen((string)$text)<=4000,'Every return part fits MAX');
    foreach ($largeItems as $item) $ok(str_contains($allText,(string)$item['request_number'].':') && str_contains($allText,(string)$item['after']['mno_adres_pogruzki']),'Every returned request and restored address appears in MAX');

    $events->saveRule(['event_key'=>'feo.transfer.created','is_enabled'=>1,'chat_config_id'=>1,
        'message_template'=>'CUSTOM: {{request_ids}}','message_format'=>'plain'],11);
    $customResult=$largeResult; $customResult['batch_id']=902; $customResult['count']=1;
    $customResult['items']=[$largeItems[0]];
    $pdo->beginTransaction();
    $customIds=$enqueueMain->invoke($transferController,$pdo,$events,'feo.transfer.created',$customResult,'','',$company,$weightActor);
    $pdo->commit();
    $ok(count($customIds)===1,'OWNER custom template remains one original delivery');
    $q=$pdo->prepare('SELECT message_text,message_format FROM max_event_deliveries WHERE id=?'); $q->execute($customIds); $customDelivery=$q->fetch(PDO::FETCH_ASSOC);
    $ok($customDelivery['message_text']==='CUSTOM: '.$largeItems[0]['request_number'] && $customDelivery['message_format']==='plain','OWNER custom template and format preserved');

    $pdo->exec('DELETE FROM max_chats WHERE id=1');
    $pdo->beginTransaction();
    $failedId=$events->enqueuePrepared('feo.transfer.created','stale-chat-test','Сохранённое сообщение','markdown',11);
    $pdo->commit();
    $q=$pdo->prepare('SELECT status,error_message FROM max_event_deliveries WHERE id=?'); $q->execute([$failedId]); $failedDelivery=$q->fetch(PDO::FETCH_ASSOC);
    $ok($failedDelivery['status']==='failed' && str_contains($failedDelivery['error_message'],'Чат события MAX не найден'),'Missing MAX chat records failure without rolling back business transaction');
    echo "FEO_TRANSFER_MYSQL=PASS ($count assertions)\n";
} finally { cleanCompanyPoolDatabase($pdo); }
