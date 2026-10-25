<?php

namespace App\Service;

require_once __DIR__.'/FeoLocationOverrideService.php';
require_once __DIR__.'/FeoImportTransaction.php';
require_once __DIR__.'/FeoPlacementInputParser.php';

use PDO;
use RuntimeException;

final class FeoTransferService
{
    public function ready(PDO $pdo): bool
    {
        return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('feo_transfer_batches','feo_transfer_items','feo_location_overrides','feo_transfer_outbox','geocoding_settings')")->fetchColumn() === 5;
    }

    public static function parseIds(string $raw): array
    {
        if (strlen($raw) > 100000) throw new RuntimeException('Слишком большой список заявок.');
        $parsed=(new FeoPlacementInputParser())->parseManual($raw);
        $ids=$parsed['ids'];
        if (count($ids) > 1000) throw new RuntimeException('В одном блоке допускается до 1000 заявок.');
        return ['ids'=>$ids,'duplicates'=>$parsed['duplicate_count']];
    }

    public function preview(PDO $pdo, array $ids, string $mode, string $sourceId, ?array $geocoded = null, bool $lock = false): array
    {
        if (!$this->ready($pdo)) throw new RuntimeException('Перемещение заявок ожидает обновления базы компании (139).');
        $ids = self::parseIds(implode("\n", $ids))['ids'];
        if (!in_array($mode,['request','address'],true)) throw new RuntimeException('Выберите способ перемещения.');
        if ($mode==='request' && !preg_match('/^\d{6}$/D',$sourceId)) throw new RuntimeException('Введите шестизначный номер заявки с нужным адресом.');
        if ($mode === 'request' && in_array($sourceId,$ids,true)) throw new RuntimeException('Заявка с нужным адресом не должна входить в блок перемещения.');
        $lookupIds = $mode === 'request' ? array_values(array_unique([...$ids,$sourceId])) : $ids;
        sort($lookupIds,SORT_STRING);
        $marks = implode(',',array_fill(0,count($lookupIds),'?'));
        $q = $pdo->prepare("SELECT id,zayavka_id,mass_netto,mno_adres_pogruzki,mno_sh,mno_d FROM feo_requests WHERE zayavka_id IN ($marks) ORDER BY zayavka_id" . ($lock ? ' FOR UPDATE' : ''));
        $q->execute($lookupIds);
        $found = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $found[(string)$row['zayavka_id']] = $row;
        $missing = array_values(array_diff($lookupIds,array_keys($found)));
        if ($missing) {
            $missingRequests=array_values(array_intersect($ids,$missing));
            if ($missingRequests) throw new RuntimeException('Заявки не найдены в системе: '.implode(', ',$missingRequests).'.');
            throw new RuntimeException('Заявка не найдена в системе: '.$sourceId.'.');
        }
        $overrideQuery = $pdo->prepare("SELECT zayavka_id,batch_id FROM feo_location_overrides WHERE zayavka_id IN ($marks)" . ($lock ? ' FOR UPDATE' : ''));
        $overrideQuery->execute($lookupIds);
        $overrides = $overrideQuery->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($mode === 'request') {
            $source = $found[$sourceId];
            $destination = ['address'=>$source['mno_adres_pogruzki'],'longitude'=>$source['mno_sh'],'latitude'=>$source['mno_d'],
                'precision'=>'unknown','quality'=>'Из заявки №' . $sourceId . '; точность не подтверждена','provider'=>'request','source_request_id'=>$sourceId];
            if (isset($overrides[$sourceId])) {
                $p=$pdo->prepare('SELECT destination_json FROM feo_transfer_batches WHERE id=?'); $p->execute([$overrides[$sourceId]]);
                $prior=json_decode((string)$p->fetchColumn(),true);
                if (is_array($prior) && $this->locationFromDestination($prior) === $this->location($source)) {
                    $destination['precision']=$prior['precision']; $destination['quality']=$prior['quality'];
                }
            }
        } else {
            $destination = $geocoded ?? throw new RuntimeException('Сначала определите координаты адреса.');
            $destination['address'] = (string)($destination['query'] ?? '');
        }
        $this->validateDestination($destination);
        $rows=[];
        foreach ($ids as $id) $rows[]=$found[$id]+['net_kg'=>$found[$id]['mass_netto']===null?null:round((float)$found[$id]['mass_netto']*1000,1),'previous_batch_id'=>isset($overrides[$id])?(int)$overrides[$id]:null];
        // Current weight is display data. A weight edit after preview must not alter the existing
        // address/coordinate stale guard or block an otherwise valid transfer.
        $stateRows=array_map(static function(array $row): array {
            unset($row['mass_netto'],$row['net_kg']);
            return $row;
        },$rows);
        return ['rows'=>$rows,'count'=>count($rows),'destination'=>$destination,'mode'=>$mode,'source_request_id'=>$mode==='request'?$sourceId:null,
            'state_hash'=>hash('sha256',$this->json([$stateRows,$destination]))];
    }

    public function create(PDO $pdo, array $preview, array $actor, string $operationKey, string $name, string $comment, ?callable $event = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$operationKey)) throw new RuntimeException('Некорректный ключ операции.');
        $name=trim($name); $comment=trim($comment);
        if (mb_strlen($name)>180 || mb_strlen($comment)>2000) throw new RuntimeException('Сократите название или комментарий блока.');
        return $this->transaction($pdo, function (?FeoLegacyImportSync $mirror) use ($pdo,$preview,$actor,$operationKey,$name,$comment,$event): array {
            $q=$pdo->prepare('SELECT id FROM feo_transfer_batches WHERE operation_key=?'); $q->execute([$operationKey]);
            if ($id=$q->fetchColumn()) return ['batch_id'=>(int)$id,'already_applied'=>true,'legacy_synced'=>$this->wasMirrored($pdo,(int)$id,'transfer')];
            $fresh=$this->preview($pdo,array_column($preview['rows'],'zayavka_id'),$preview['mode'],(string)($preview['source_request_id']??''),$preview['destination'],true);
            if (!hash_equals($preview['state_hash'],$fresh['state_hash'])) throw new RuntimeException('Адреса или история заявок изменились после проверки. Проверьте блок заново.');
            if ((int)($actor['created_by_user_id']??0)<=0) throw new RuntimeException('Не удалось определить автора перемещения.');
            $q=$pdo->prepare('INSERT INTO feo_transfer_batches(name,mode,source_request_id,destination_json,comment,request_count,operation_key,created_by_user_id,created_by_role,created_by_name) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$name?:'Перемещение заявок',$fresh['mode'],$fresh['source_request_id'],$this->json($fresh['destination']),$comment,$fresh['count'],$operationKey,$actor['created_by_user_id'],$actor['created_by_role'],$actor['created_by_display_name']]);
            $batchId=(int)$pdo->lastInsertId();
            $insert=$pdo->prepare('INSERT INTO feo_transfer_items(batch_id,zayavka_id,request_row_id,before_json,after_json,previous_batch_id) VALUES(?,?,?,?,?,?)');
            $override=$pdo->prepare('INSERT INTO feo_location_overrides(zayavka_id,batch_id) VALUES(?,?) ON DUPLICATE KEY UPDATE batch_id=VALUES(batch_id)');
            $after=$this->locationFromDestination($fresh['destination']);
            $payload=[];
            foreach ($fresh['rows'] as $row) {
                $before=$this->location($row);
                $insert->execute([$batchId,$row['zayavka_id'],$row['id'],$this->json($before),$this->json($after),$row['previous_batch_id']]);
                $mirror?->mirrorLocation((string)$row['zayavka_id'],$before,$after);
                $this->writeLocation($pdo,(int)$row['id'],$after);
                $override->execute([$row['zayavka_id'],$batchId]);
                $payload[]=['request_number'=>(string)$row['zayavka_id'],'before'=>$before,'after'=>$after];
            }
            $this->enqueueLegacy($pdo,$batchId,'transfer',$payload,$mirror!==null);
            $result=['batch_id'=>$batchId,'count'=>$fresh['count'],'destination'=>$fresh['destination'],'legacy_synced'=>$mirror!==null];
            if ($event) $event($result+['items'=>$payload]);
            return $result;
        });
    }

    public function revert(PDO $pdo, int $batchId, array $actor, ?callable $event = null): array
    {
        return $this->transaction($pdo,function (?FeoLegacyImportSync $mirror) use ($pdo,$batchId,$actor,$event): array {
            $q=$pdo->prepare('SELECT * FROM feo_transfer_batches WHERE id=? FOR UPDATE'); $q->execute([$batchId]);
            $batch=$q->fetch(PDO::FETCH_ASSOC);
            if (!$batch) throw new RuntimeException('Блок не найден.');
            if ($batch['status']==='reverted') return ['batch_id'=>$batchId,'already_reverted'=>true,'legacy_synced'=>$this->wasMirrored($pdo,$batchId,'revert')];
            $items=$this->items($pdo,$batchId);
            $payload=[];
            foreach ($items as $item) {
                $q=$pdo->prepare('SELECT r.id,r.mno_adres_pogruzki,r.mno_sh,r.mno_d,o.batch_id FROM feo_requests r LEFT JOIN feo_location_overrides o ON o.zayavka_id=r.zayavka_id WHERE r.zayavka_id=? FOR UPDATE');
                $q->execute([$item['zayavka_id']]); $current=$q->fetch(PDO::FETCH_ASSOC);
                if (!$current || (int)$current['id']!==(int)$item['request_row_id'] || (int)$current['batch_id']!==$batchId || $this->location($current)!==$item['after']) throw new RuntimeException('Заявка №'.$item['zayavka_id'].' удалена или перемещена позже. Возврат всего блока остановлен. Сначала верните более поздний блок.');
                $mirror?->mirrorLocation((string)$item['zayavka_id'],$item['after'],$item['before']);
                $this->writeLocation($pdo,(int)$current['id'],$item['before']);
                if ($item['previous_batch_id']!==null) {
                    $q=$pdo->prepare('UPDATE feo_location_overrides SET batch_id=? WHERE zayavka_id=?'); $q->execute([$item['previous_batch_id'],$item['zayavka_id']]);
                } else {
                    $q=$pdo->prepare('DELETE FROM feo_location_overrides WHERE zayavka_id=?'); $q->execute([$item['zayavka_id']]);
                }
                $payload[]=['request_number'=>(string)$item['zayavka_id'],'before'=>$item['after'],'after'=>$item['before']];
            }
            $q=$pdo->prepare("UPDATE feo_transfer_batches SET status='reverted',reverted_by_user_id=?,reverted_by_name=?,reverted_at=NOW() WHERE id=?");
            $q->execute([$actor['created_by_user_id'],$actor['created_by_display_name'],$batchId]);
            $this->enqueueLegacy($pdo,$batchId,'revert',$payload,$mirror!==null);
            $result=['batch_id'=>$batchId,'count'=>count($items),'name'=>$batch['name'],'destination'=>json_decode($batch['destination_json'],true),'legacy_synced'=>$mirror!==null];
            if ($event) $event($result+['items'=>$payload]);
            return $result;
        });
    }

    public function batches(PDO $pdo, int $page = 1): array
    {
        $offset=(max(1,$page)-1)*50;
        return $pdo->query("SELECT * FROM feo_transfer_batches ORDER BY id DESC LIMIT 50 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Complete request IDs and current weights for the visible history page. */
    public function batchIndexItems(PDO $pdo, array $batches): array
    {
        if (!$batches) return [];
        $ids=array_map(static fn(array $batch): int => (int)$batch['id'], $batches);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$pdo->prepare("SELECT i.batch_id,i.zayavka_id,r.mass_netto,r.mass_brutto
            FROM feo_transfer_items i
            LEFT JOIN feo_requests r ON r.id=i.request_row_id AND r.zayavka_id=i.zayavka_id
            WHERE i.batch_id IN ($marks) ORDER BY i.batch_id DESC,i.zayavka_id");
        $q->execute($ids);
        $result=[];
        foreach ($ids as $id) $result[$id]=['ids'=>[],'net_kg'=>0.0,'gross_kg'=>0.0,'net_complete'=>true,'gross_complete'=>true];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $id=(int)$item['batch_id'];
            $result[$id]['ids'][]=(string)$item['zayavka_id'];
            foreach (['net'=>'mass_netto','gross'=>'mass_brutto'] as $kind=>$field) {
                if ($item[$field]===null) $result[$id][$kind.'_complete']=false;
                else $result[$id][$kind.'_kg']+=(float)$item[$field]*1000;
            }
        }
        foreach ($result as &$summary) {
            if (!$summary['ids']) $summary['net_complete']=$summary['gross_complete']=false;
            foreach (['net','gross'] as $kind) $summary[$kind.'_kg']=$summary[$kind.'_complete']?round($summary[$kind.'_kg'],1):null;
            unset($summary['net_complete'],$summary['gross_complete']);
        }
        unset($summary);
        return $result;
    }

    public function items(PDO $pdo, int $batchId): array
    {
        $q=$pdo->prepare('SELECT i.*,r.mass_netto FROM feo_transfer_items i LEFT JOIN feo_requests r ON r.id=i.request_row_id AND r.zayavka_id=i.zayavka_id WHERE i.batch_id=? ORDER BY i.zayavka_id'); $q->execute([$batchId]);
        return array_map(static function(array $row): array { $row['before']=json_decode($row['before_json'],true,16,JSON_THROW_ON_ERROR); $row['after']=json_decode($row['after_json'],true,16,JSON_THROW_ON_ERROR); $row['net_kg']=$row['mass_netto']===null?null:round((float)$row['mass_netto']*1000,1); unset($row['before_json'],$row['after_json'],$row['mass_netto']); return $row; },$q->fetchAll(PDO::FETCH_ASSOC));
    }

    private function transaction(PDO $pdo, callable $action): array
    {
        if ($pdo->inTransaction()) throw new RuntimeException('Перемещение должно выполняться отдельной операцией.');
        $lock='planex_feo_'.substr(hash('sha256',(string)$pdo->query('SELECT DATABASE()')->fetchColumn()),0,24);
        $q=$pdo->prepare('SELECT GET_LOCK(?,10)'); $q->execute([$lock]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Заявки сейчас изменяются. Повторите позже.');
        $transaction=null;
        try {
            FeoImportTransaction::recoverPending($pdo);
            $target=(string)env('FEO_LEGACY_SYNC_TENANT_DATABASE','');
            $enabled=(string)env('FEO_LEGACY_TRANSFER_SYNC_ENABLED','0')==='1';
            if ($enabled && $target==='') throw new RuntimeException('Зеркало перемещений ФЭО включено без привязки компании к старой базе.');
            $mirrorRequired=$enabled && (string)$pdo->query('SELECT DATABASE()')->fetchColumn()===$target;
            if ($mirrorRequired) {
                $transaction=FeoImportTransaction::begin($pdo);
                $mirror=$transaction->sync();
                if ($mirror===null) throw new RuntimeException('Подключение зеркала перемещений к старой базе не настроено.');
                $mirror->validate();
            } else {
                $pdo->beginTransaction();
                $mirror=null;
            }
            $result=$action($mirror);
            if ($transaction!==null) $transaction->commit(); else $pdo->commit();
            return $result;
        }
        catch (\Throwable $e) {
            if ($transaction!==null) $transaction->rollback();
            elseif ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        finally { $q=$pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]); }
    }

    private function validateDestination(array $destination): void
    {
        if (trim((string)($destination['address']??''))==='' || mb_strlen((string)$destination['address'])>4000) throw new RuntimeException('У места назначения отсутствует адрес или он слишком длинный.');
        foreach (['latitude'=>90,'longitude'=>180] as $key=>$limit) {
            $value=str_replace(',', '.', trim((string)($destination[$key]??'')));
            if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value)>$limit || strlen((string)$destination[$key])>64) throw new RuntimeException('У места назначения некорректные координаты.');
        }
        if ((float)str_replace(',','.',(string)$destination['latitude'])===0.0 && (float)str_replace(',','.',(string)$destination['longitude'])===0.0) throw new RuntimeException('У места назначения не заданы координаты (0, 0).');
    }

    private function location(array $row): array { return array_intersect_key($row,array_flip(FeoLocationOverrideService::FIELDS)); }
    private function locationFromDestination(array $d): array { return ['mno_adres_pogruzki'=>(string)$d['address'],'mno_sh'=>(string)$d['longitude'],'mno_d'=>(string)$d['latitude']]; }
    private function writeLocation(PDO $pdo, int $rowId, array $location): void
    {
        $q=$pdo->prepare('UPDATE feo_requests SET mno_adres_pogruzki=?,mno_sh=?,mno_d=? WHERE id=?');
        $q->execute([$location['mno_adres_pogruzki'],$location['mno_sh'],$location['mno_d'],$rowId]);
    }
    private function enqueueLegacy(PDO $pdo, int $batchId, string $operation, array $items, bool $mirrored): void
    {
        $q=$pdo->prepare('INSERT INTO feo_transfer_outbox(batch_id,operation,payload_json,status,sent_at) VALUES(?,?,?,?,?)');
        $q->execute([$batchId,$operation,$this->json(['version'=>1,'batch_id'=>$batchId,'operation'=>$operation,'coordinate_storage'=>['mno_sh'=>'longitude','mno_d'=>'latitude'],'items'=>$items]),$mirrored?'mirrored':'awaiting_connector',$mirrored?date('Y-m-d H:i:s'):null]);
    }
    private function wasMirrored(PDO $pdo, int $batchId, string $operation): bool
    {
        $q=$pdo->prepare('SELECT status FROM feo_transfer_outbox WHERE batch_id=? AND operation=?');
        $q->execute([$batchId,$operation]);
        return $q->fetchColumn()==='mirrored';
    }
    private function json(array $value): string { return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
