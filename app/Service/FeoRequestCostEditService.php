<?php

namespace App\Service;

use PDO;
use PDOException;

final class FeoRequestCostEditService
{
    private const CHUNK = 800;
    private const RATE_34_5 = 34.50;
    private const RATE_38 = 38.00;

    public function validateSelection(PDO $pdo, int $blockId, array $ids): array
    {
        $state = $this->state($pdo, $blockId, false);
        if (!$state) throw new \RuntimeException('Блок стоимости не найден.');
        $ids = $this->normalizeIds($ids);
        $oldIds = array_column($state['members'], 'zayavka_id');
        [$added, $removed] = $this->diff($oldIds, $ids);
        $rows = $this->snapshotRows($state['members']);
        $addedRows = $this->currentRows($pdo, $added, false);
        $missing = array_values(array_diff($added, array_keys($addedRows)));
        foreach ($addedRows as $id=>$row) $rows[$id]=$row;
        foreach ($removed as $id) unset($rows[$id]);
        $priced = $this->pricedByOtherBlocks($pdo, $added, $blockId, false);
        return [
            'valid'=>$missing === [] && $priced === [],
            'ids'=>$ids,
            'composition_changed'=>$added !== [] || $removed !== [],
            'added_ids'=>$added,
            'removed_ids'=>$removed,
            'missing_placement_ids'=>$missing,
            'already_priced_ids'=>array_values(array_map('strval', array_keys($priced))),
            'already_priced_blocks'=>$priced,
            'selected_count'=>count($ids),
            'selected_mass_kg'=>$this->massForIds($rows, $ids),
            'added_snapshot_fingerprint'=>$this->addedSnapshotFingerprint($addedRows, $added),
        ];
    }

    public function previewEdit(PDO $pdo, int $blockId, array $ids, string $pricingType, mixed $manualTotal, string $comment): array
    {
        $state = $this->state($pdo, $blockId, false);
        if (!$state) throw new \RuntimeException('Блок стоимости не найден.');
        $comment = trim($comment);
        if (mb_strlen($comment) > 4000) throw new \RuntimeException('Комментарий слишком длинный.');
        $validation = $this->validateSelection($pdo, $blockId, $ids);
        if (!$validation['valid']) {
            if ($validation['missing_placement_ids'] !== []) throw new \RuntimeException('Добавляемые заявки отсутствуют в актуальном «Размещении на карте»: ' . $this->idList($validation['missing_placement_ids']) . '.');
            if ($validation['already_priced_ids'] !== []) throw new \RuntimeException('Добавляемые заявки уже имеют стоимость в другом блоке: ' . $this->pricedList($validation['already_priced_blocks']) . '.');
            throw new \RuntimeException('Новый состав блока не прошёл проверку.');
        }
        $price = $this->price($pricingType, (float)$validation['selected_mass_kg'], $manualTotal);
        return [
            'block_id'=>$blockId,
            'pricing_type'=>$price['pricing_type'],
            'rate_per_kg'=>$price['rate_per_kg'],
            'total_cost'=>$price['total_cost'],
            'comment'=>$comment,
            'selected_count'=>$validation['selected_count'],
            'selected_mass_kg'=>$validation['selected_mass_kg'],
            'composition_changed'=>$validation['composition_changed'],
            'added_ids'=>$validation['added_ids'],
            'removed_ids'=>$validation['removed_ids'],
            'old_request_count'=>(int)$state['block']['snapshot_request_count'],
            'old_total_mass_kg'=>round((float)$state['block']['snapshot_total_mass_kg'],3),
            'old_total_cost'=>round((float)$state['block']['total_cost'],2),
            'edit_fingerprint'=>$this->fingerprint($state),
            'added_snapshot_fingerprint'=>$validation['added_snapshot_fingerprint'],
        ];
    }

    public function editBlock(PDO $pdo, int $blockId, array $payload, string $expectedFingerprint, array $expectedPreview): array
    {
        $ids = $this->normalizeIds((array)($payload['ids'] ?? []));
        $comment = trim((string)($payload['comment'] ?? ''));
        if (mb_strlen($comment) > 4000) throw new \RuntimeException('Комментарий слишком длинный.');
        $this->assertExpectedPreviewShape($expectedPreview);
        $pdo->beginTransaction();
        try {
            $state = $this->state($pdo, $blockId, true);
            if (!$state) throw new \RuntimeException('Блок стоимости не найден.');
            if (!hash_equals($expectedFingerprint, $this->fingerprint($state))) throw new \RuntimeException('Блок был изменён после открытия. Откройте его заново.');
            $oldIds = array_column($state['members'], 'zayavka_id');
            [$added, $removed] = $this->diff($oldIds, $ids);
            $changed = $added !== [] || $removed !== [];
            $rows = $this->snapshotRows($state['members']);
            $addedRows = $this->currentRows($pdo, $added, true);
            $missing = array_values(array_diff($added, array_keys($addedRows)));
            if ($missing !== []) throw new \RuntimeException('Состав размещения изменился. Добавляемые заявки отсутствуют: ' . $this->idList($missing) . '. Выполните проверку ещё раз.');
            $actualAddedFingerprint = $this->addedSnapshotFingerprint($addedRows, $added);
            if (!hash_equals((string)$expectedPreview['added_snapshot_fingerprint'], $actualAddedFingerprint)) {
                throw new \RuntimeException('Данные добавляемых заявок изменились после предпросмотра. Выполните проверку ещё раз.');
            }
            $priced = $this->pricedByOtherBlocks($pdo, $added, $blockId, true);
            if ($priced !== []) throw new \RuntimeException('Одна из добавленных заявок уже получила стоимость в другом блоке: ' . $this->pricedList($priced) . '.');
            foreach ($addedRows as $id=>$row) $rows[$id]=$row;
            foreach ($removed as $id) unset($rows[$id]);
            $mass = $this->massForIds($rows, $ids);
            $price = $this->price((string)($payload['pricing_type'] ?? ''), $mass, $payload['manual_total'] ?? null);
            $this->assertPreviewMatchesCommit($expectedPreview, count($ids), $mass, (float)$price['total_cost']);
            $q = $pdo->prepare('UPDATE feo_request_cost_blocks SET pricing_type=?,rate_per_kg=?,snapshot_request_count=?,snapshot_total_mass_kg=?,total_cost=?,comment=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $q->execute([$price['pricing_type'],$price['rate_per_kg'],count($ids),$mass,$price['total_cost'],$comment !== '' ? $comment : null,$blockId]);
            if ($changed) {
                $q = $pdo->prepare('DELETE FROM feo_request_cost_block_requests WHERE block_id=?');
                $q->execute([$blockId]);
                $ins = $pdo->prepare('INSERT INTO feo_request_cost_block_requests(block_id,zayavka_id,snapshot_mass_kg,position_no) VALUES(?,?,?,?)');
                foreach ($ids as $i=>$id) $ins->execute([$blockId,$id,$rows[$id]['mass_kg'],$i+1]);
            }
            $pdo->commit();
            return [
                'block_id'=>$blockId,
                'request_count'=>count($ids),
                'snapshot_total_mass_kg'=>$mass,
                'pricing_type'=>$price['pricing_type'],
                'rate_per_kg'=>$price['rate_per_kg'],
                'total_cost'=>$price['total_cost'],
                'composition_changed'=>$changed,
                'added_ids'=>$added,
                'removed_ids'=>$removed,
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (($e->errorInfo[1] ?? null) === 1062) throw new \RuntimeException('Одна из добавленных заявок уже получила стоимость в другом блоке. Обновите блок и повторите проверку.');
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function state(PDO $pdo, int $blockId, bool $forUpdate): ?array
    {
        if ($blockId <= 0) return null;
        $sql = 'SELECT * FROM feo_request_cost_blocks WHERE id=? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $q = $pdo->prepare($sql);
        $q->execute([$blockId]);
        $block = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$block) return null;
        $q = $pdo->prepare('SELECT id,zayavka_id,snapshot_mass_kg,position_no FROM feo_request_cost_block_requests WHERE block_id=? ORDER BY position_no,id' . ($forUpdate ? ' FOR UPDATE' : ''));
        $q->execute([$blockId]);
        $members = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($members as &$row) {
            $row['zayavka_id']=(string)$row['zayavka_id'];
            $row['snapshot_mass_kg']=round((float)$row['snapshot_mass_kg'],3);
            $row['position_no']=(int)$row['position_no'];
        }
        unset($row);
        return ['block'=>$block,'members'=>$members];
    }

    private function snapshotRows(array $members): array
    {
        $rows=[];
        foreach ($members as $row) $rows[(string)$row['zayavka_id']]=['mass_kg'=>round((float)$row['snapshot_mass_kg'],3)];
        return $rows;
    }

    private function currentRows(PDO $pdo, array $ids, bool $forUpdate): array
    {
        $rows=[];
        foreach (array_chunk($ids,self::CHUNK) as $chunk) {
            if ($chunk===[]) continue;
            if ($forUpdate) {
                $lockIds=$chunk;
                sort($lockIds,SORT_STRING);
                $marks=implode(',',array_fill(0,count($lockIds),'?'));
                $q=$pdo->prepare("SELECT zayavka_id FROM feo_requests WHERE zayavka_id IN ({$marks}) ORDER BY zayavka_id FOR UPDATE");
                $q->execute($lockIds);
                $q->fetchAll(PDO::FETCH_ASSOC);
                $q=$pdo->prepare("SELECT zayavka_id FROM feo_placement_current WHERE zayavka_id IN ({$marks}) ORDER BY zayavka_id FOR UPDATE");
                $q->execute($lockIds);
                $q->fetchAll(PDO::FETCH_ASSOC);
            }
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $sql="SELECT c.zayavka_id,ROUND(COALESCE(r.mass_netto,0)*1000,3) mass_kg FROM feo_placement_current c JOIN feo_requests r ON r.zayavka_id=c.zayavka_id WHERE c.zayavka_id IN ({$marks})" . ($forUpdate ? ' FOR UPDATE' : '');
            $q=$pdo->prepare($sql);$q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(string)$row['zayavka_id']]=['mass_kg'=>round((float)$row['mass_kg'],3)];
        }
        return $rows;
    }

    private function pricedByOtherBlocks(PDO $pdo, array $ids, int $blockId, bool $forUpdate): array
    {
        $found=[];
        foreach (array_chunk($ids,self::CHUNK) as $chunk) {
            if ($chunk===[]) continue;
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $sql="SELECT zayavka_id,block_id FROM feo_request_cost_block_requests WHERE zayavka_id IN ({$marks}) AND block_id<>?" . ($forUpdate ? ' FOR UPDATE' : '');
            $q=$pdo->prepare($sql);$q->execute([...$chunk,$blockId]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $found[(string)$row['zayavka_id']]=(int)$row['block_id'];
        }
        return $found;
    }

    private function normalizeIds(array $ids): array
    {
        $out=[];$seen=[];
        foreach ($ids as $id) {
            $id=trim((string)$id);
            if (!preg_match('/^\d{6}$/D',$id)) throw new \RuntimeException('Некорректный номер заявки: '.$id.'.');
            if (isset($seen[$id])) continue;
            $seen[$id]=true;$out[]=$id;
        }
        if ($out===[]) throw new \RuntimeException('Список заявок пуст.');
        if (count($out)>20000) throw new \RuntimeException('За одну операцию можно обработать не более 20 000 заявок.');
        return $out;
    }

    private function diff(array $oldIds, array $newIds): array
    {
        return [array_values(array_diff($newIds,$oldIds)),array_values(array_diff($oldIds,$newIds))];
    }

    private function massForIds(array $rows, array $ids): float
    {
        $sum=0.0;foreach ($ids as $id)$sum+=(float)($rows[$id]['mass_kg']??0);return round($sum,3);
    }

    private function price(string $type, float $mass, mixed $manualTotal): array
    {
        if ($type==='rate_34_5') return ['pricing_type'=>$type,'rate_per_kg'=>self::RATE_34_5,'total_cost'=>round($mass*self::RATE_34_5,2)];
        if ($type==='rate_38') return ['pricing_type'=>$type,'rate_per_kg'=>self::RATE_38,'total_cost'=>round($mass*self::RATE_38,2)];
        if ($type!=='agreement') throw new \RuntimeException('Выберите способ расчёта стоимости.');
        $raw=str_replace(["\xC2\xA0",' '],'',trim((string)$manualTotal));$raw=str_replace(',','.',$raw);
        if ($raw===''||!preg_match('/^\d+(?:\.\d{1,2})?$/D',$raw)) throw new \RuntimeException('Стоимость должна быть числом с точностью до копеек.');
        $value=round((float)$raw,2);if ($value<=0) throw new \RuntimeException('Введите согласованную стоимость больше нуля.');
        return ['pricing_type'=>'agreement','rate_per_kg'=>null,'total_cost'=>$value];
    }

    private function fingerprint(array $state): string
    {
        $block=$state['block'];
        $data=[
            'id'=>(int)$block['id'],'pricing_type'=>(string)$block['pricing_type'],'rate_per_kg'=>$block['rate_per_kg']===null?null:(string)$block['rate_per_kg'],
            'snapshot_request_count'=>(int)$block['snapshot_request_count'],'snapshot_total_mass_kg'=>(string)$block['snapshot_total_mass_kg'],'total_cost'=>(string)$block['total_cost'],
            'comment'=>(string)($block['comment']??''),'updated_at'=>(string)($block['updated_at']??''),
            'members'=>array_map(static fn(array $r):array=>[(string)$r['zayavka_id'],(string)$r['snapshot_mass_kg'],(int)$r['position_no']],$state['members']),
        ];
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function addedSnapshotFingerprint(array $rows, array $ids): string
    {
        $data=[];
        foreach ($ids as $id) {
            $row=$rows[$id]??null;
            $data[]=[(string)$id,$row===null?null:number_format((float)$row['mass_kg'],3,'.','')];
        }
        return hash('sha256',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private function assertExpectedPreviewShape(array $expectedPreview): void
    {
        foreach (['added_snapshot_fingerprint','selected_count','selected_mass_kg','total_cost'] as $key) {
            if (!array_key_exists($key,$expectedPreview)) throw new \RuntimeException('Подтверждение устарело. Выполните операцию ещё раз.');
        }
        if (!is_string($expectedPreview['added_snapshot_fingerprint']) || !preg_match('/^[0-9a-f]{64}$/D',$expectedPreview['added_snapshot_fingerprint'])) {
            throw new \RuntimeException('Подтверждение устарело. Выполните операцию ещё раз.');
        }
    }

    private function assertPreviewMatchesCommit(array $expectedPreview, int $count, float $mass, float $totalCost): void
    {
        $expectedCount=(int)$expectedPreview['selected_count'];
        $expectedMass=round((float)$expectedPreview['selected_mass_kg'],3);
        $expectedCost=round((float)$expectedPreview['total_cost'],2);
        if ($count!==$expectedCount || number_format($mass,3,'.','')!==number_format($expectedMass,3,'.','') || number_format($totalCost,2,'.','')!==number_format($expectedCost,2,'.','')) {
            throw new \RuntimeException('Данные предпросмотра изменились до подтверждения. Выполните проверку ещё раз.');
        }
    }

    private function idList(array $ids): string
    {
        $head=array_slice($ids,0,50);return implode(', ',$head).(count($ids)>50?' и ещё '.(count($ids)-50):'');
    }

    private function pricedList(array $priced): string
    {
        $items=[];foreach (array_slice($priced,0,50,true) as $id=>$block)$items[]=$id.' (блок #'.$block.')';return implode(', ',$items).(count($priced)>50?' и ещё '.(count($priced)-50):'');
    }
}
