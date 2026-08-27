<?php

namespace App\Service;

use PDO;
use PDOException;

final class FeoRequestCostService
{
    private const CHUNK = 800;
    private const RATE_34_5 = 34.50;
    private const RATE_38 = 38.00;

    public function moduleReady(PDO $pdo): bool
    {
        $tables = ['feo_requests','feo_placement_current','feo_request_cost_blocks','feo_request_cost_block_requests'];
        $q = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
        $q->execute($tables);
        return (int)$q->fetchColumn() === count($tables);
    }

    public function pageState(PDO $pdo): array
    {
        $blocks = $this->listBlocks($pdo);
        $issueBlocks = 0;
        $issueRequests = 0;
        foreach ($blocks as $block) {
            if (!empty($block['has_integrity_issue'])) $issueBlocks++;
            $issueRequests += (int)$block['mismatch_request_count'];
        }
        return [
            'blocks' => $blocks,
            'integrity' => ['block_count'=>$issueBlocks,'request_count'=>$issueRequests,'has_issues'=>$issueBlocks > 0],
        ];
    }

    public function listBlocks(PDO $pdo): array
    {
        $sql = "SELECT b.*,
                       COUNT(br.id) actual_request_count,
                       COALESCE(SUM(CASE WHEN br.id IS NOT NULL AND c.zayavka_id IS NULL THEN 1 ELSE 0 END),0) removed_from_placement_count,
                       COALESCE(SUM(CASE WHEN br.id IS NOT NULL AND r.zayavka_id IS NULL THEN 1 ELSE 0 END),0) missing_request_count,
                       COALESCE(SUM(CASE WHEN br.id IS NOT NULL AND r.zayavka_id IS NOT NULL AND ABS((COALESCE(r.mass_netto,0)*1000)-br.snapshot_mass_kg) > 0.0005 THEN 1 ELSE 0 END),0) mass_changed_count,
                       COALESCE(SUM(CASE WHEN br.id IS NOT NULL AND (r.zayavka_id IS NULL OR c.zayavka_id IS NULL OR ABS((COALESCE(r.mass_netto,0)*1000)-br.snapshot_mass_kg) > 0.0005) THEN 1 ELSE 0 END),0) mismatch_request_count
                  FROM feo_request_cost_blocks b
             LEFT JOIN feo_request_cost_block_requests br ON br.block_id=b.id
             LEFT JOIN feo_requests r ON r.zayavka_id=br.zayavka_id
             LEFT JOIN feo_placement_current c ON c.zayavka_id=br.zayavka_id
              GROUP BY b.id
              ORDER BY b.id DESC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['snapshot_request_count','actual_request_count','removed_from_placement_count','missing_request_count','mass_changed_count','mismatch_request_count'] as $key) {
                $row[$key] = (int)($row[$key] ?? 0);
            }
            $row['snapshot_total_mass_kg'] = round((float)$row['snapshot_total_mass_kg'], 3);
            $row['total_cost'] = round((float)$row['total_cost'], 2);
            $row['rate_per_kg'] = $row['rate_per_kg'] === null ? null : round((float)$row['rate_per_kg'], 2);
            $row['has_integrity_issue'] = $row['mismatch_request_count'] > 0 || $row['actual_request_count'] !== $row['snapshot_request_count'];
        }
        unset($row);
        return $rows;
    }

    public function validateSelection(PDO $pdo, array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        $rows = $this->selectionRows($pdo, $ids, false);
        $missing = array_values(array_diff($ids, array_keys($rows)));
        $priced = $this->pricedMemberships($pdo, $ids, false);
        $already = array_values(array_keys($priced));
        return [
            'valid'=>$missing === [] && $already === [],
            'ids'=>$ids,
            'missing_placement_ids'=>$missing,
            'already_priced_ids'=>$already,
            'already_priced_blocks'=>$priced,
            'selected_count'=>count($ids),
            'selected_mass_kg'=>$this->massForIds($rows, $ids),
        ];
    }

    public function previewCreate(PDO $pdo, array $ids, string $pricingType, mixed $manualTotal): array
    {
        $ids = $this->normalizeIds($ids);
        $rows = $this->selectionRows($pdo, $ids, false);
        $missing = array_values(array_diff($ids, array_keys($rows)));
        if ($missing !== []) throw new \RuntimeException('Заявки отсутствуют в актуальном «Размещении на карте»: ' . $this->idList($missing) . '.');
        $priced = $this->pricedMemberships($pdo, $ids, false);
        if ($priced !== []) throw new \RuntimeException('Повторная оценка запрещена. Уже имеют стоимость: ' . $this->pricedList($priced) . '.');
        $mass = $this->massForIds($rows, $ids);
        $price = $this->price($pricingType, $mass, $manualTotal);
        return [
            'ids'=>$ids,
            'selected_count'=>count($ids),
            'selected_mass_kg'=>$mass,
            'pricing_type'=>$price['pricing_type'],
            'rate_per_kg'=>$price['rate_per_kg'],
            'total_cost'=>$price['total_cost'],
            'selection_fingerprint'=>$this->selectionFingerprint($rows, $ids),
        ];
    }

    public function createBlock(PDO $pdo, array $payload, string $expectedFingerprint): array
    {
        $ids = $this->normalizeIds((array)($payload['ids'] ?? []));
        $comment = trim((string)($payload['comment'] ?? ''));
        if (mb_strlen($comment) > 4000) throw new \RuntimeException('Комментарий слишком длинный.');
        $pdo->beginTransaction();
        try {
            $rows = $this->selectionRows($pdo, $ids, true);
            $missing = array_values(array_diff($ids, array_keys($rows)));
            if ($missing !== []) throw new \RuntimeException('Состав размещения изменился. Отсутствуют заявки: ' . $this->idList($missing) . '. Выполните проверку ещё раз.');
            if (!hash_equals($expectedFingerprint, $this->selectionFingerprint($rows, $ids))) throw new \RuntimeException('Вес или состав заявок изменился после предварительной проверки. Выполните проверку ещё раз.');
            $priced = $this->pricedMemberships($pdo, $ids, true);
            if ($priced !== []) throw new \RuntimeException('Повторная оценка запрещена. Уже имеют стоимость: ' . $this->pricedList($priced) . '.');
            $mass = $this->massForIds($rows, $ids);
            $price = $this->price((string)($payload['pricing_type'] ?? ''), $mass, $payload['manual_total'] ?? null);
            $stmt = $pdo->prepare('INSERT INTO feo_request_cost_blocks(pricing_type,rate_per_kg,snapshot_request_count,snapshot_total_mass_kg,total_cost,comment,created_by_user_id,created_by_role) VALUES(?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $price['pricing_type'],$price['rate_per_kg'],count($ids),$mass,$price['total_cost'],$comment !== '' ? $comment : null,
                ((int)($payload['created_by_user_id'] ?? 0)) ?: null,trim((string)($payload['created_by_role'] ?? '')) ?: null,
            ]);
            $blockId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO feo_request_cost_block_requests(block_id,zayavka_id,snapshot_mass_kg,position_no) VALUES(?,?,?,?)');
            foreach ($ids as $i => $id) $ins->execute([$blockId,$id,$rows[$id]['mass_kg'],$i+1]);
            $pdo->commit();
            return ['block_id'=>$blockId,'request_count'=>count($ids),'snapshot_total_mass_kg'=>$mass,'total_cost'=>$price['total_cost'],'pricing_type'=>$price['pricing_type']];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (($e->errorInfo[1] ?? null) === 1062) throw new \RuntimeException('Одна из заявок уже получила стоимость в другом блоке. Обновите страницу и повторите проверку.');
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function block(PDO $pdo, int $blockId): ?array
    {
        if ($blockId <= 0) return null;
        $q = $pdo->prepare('SELECT * FROM feo_request_cost_blocks WHERE id=? LIMIT 1');
        $q->execute([$blockId]);
        $block = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$block) return null;
        $q = $pdo->prepare("SELECT br.zayavka_id,br.snapshot_mass_kg,
                                   CASE WHEN r.zayavka_id IS NULL THEN NULL ELSE COALESCE(r.mass_netto,0)*1000 END current_mass_kg,
                                   r.zayavka_id IS NULL missing_request,
                                   c.zayavka_id IS NOT NULL in_current_placement
                              FROM feo_request_cost_block_requests br
                         LEFT JOIN feo_requests r ON r.zayavka_id=br.zayavka_id
                         LEFT JOIN feo_placement_current c ON c.zayavka_id=br.zayavka_id
                             WHERE br.block_id=? ORDER BY br.position_no,br.id");
        $q->execute([$blockId]);
        $requests = [];
        $mismatchCount = 0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $snapshot = round((float)$row['snapshot_mass_kg'],3);
            $current = $row['current_mass_kg'] === null ? null : round((float)$row['current_mass_kg'],3);
            $massChanged = $current !== null && abs($current-$snapshot) > 0.0005;
            $inPlacement = (bool)$row['in_current_placement'];
            $missingRequest = (bool)$row['missing_request'];
            $mismatch = $massChanged || !$inPlacement || $missingRequest;
            if ($mismatch) $mismatchCount++;
            $requests[] = [
                'zayavka_id'=>(string)$row['zayavka_id'],'snapshot_mass_kg'=>$snapshot,'current_mass_kg'=>$current,
                'mass_changed'=>$massChanged,'in_current_placement'=>$inPlacement,'missing_request'=>$missingRequest,'mismatch'=>$mismatch,
            ];
        }
        $block['snapshot_request_count']=(int)$block['snapshot_request_count'];
        $block['snapshot_total_mass_kg']=round((float)$block['snapshot_total_mass_kg'],3);
        $block['total_cost']=round((float)$block['total_cost'],2);
        $block['rate_per_kg']=$block['rate_per_kg'] === null ? null : round((float)$block['rate_per_kg'],2);
        $block['requests']=$requests;
        $block['mismatch_request_count']=$mismatchCount;
        $block['has_integrity_issue']=$mismatchCount > 0 || count($requests)!==$block['snapshot_request_count'];
        return $block;
    }

    public function previewEdit(PDO $pdo, int $blockId, string $pricingType, mixed $manualTotal, string $comment): array
    {
        $block = $this->block($pdo,$blockId);
        if (!$block) throw new \RuntimeException('Блок стоимости не найден.');
        $comment=trim($comment);
        if (mb_strlen($comment)>4000) throw new \RuntimeException('Комментарий слишком длинный.');
        $price=$this->price($pricingType,(float)$block['snapshot_total_mass_kg'],$manualTotal);
        return [
            'block_id'=>$blockId,'pricing_type'=>$price['pricing_type'],'rate_per_kg'=>$price['rate_per_kg'],'total_cost'=>$price['total_cost'],
            'snapshot_total_mass_kg'=>$block['snapshot_total_mass_kg'],'snapshot_request_count'=>$block['snapshot_request_count'],'comment'=>$comment,
            'edit_fingerprint'=>$this->editFingerprint($block),
        ];
    }

    public function editBlock(PDO $pdo, int $blockId, array $payload, string $expectedFingerprint): array
    {
        $comment=trim((string)($payload['comment'] ?? ''));
        if (mb_strlen($comment)>4000) throw new \RuntimeException('Комментарий слишком длинный.');
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT * FROM feo_request_cost_blocks WHERE id=? FOR UPDATE');
            $q->execute([$blockId]);
            $block=$q->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$block) throw new \RuntimeException('Блок стоимости не найден.');
            $block['snapshot_request_count']=(int)$block['snapshot_request_count'];
            $block['snapshot_total_mass_kg']=round((float)$block['snapshot_total_mass_kg'],3);
            $block['total_cost']=round((float)$block['total_cost'],2);
            $block['rate_per_kg']=$block['rate_per_kg']===null?null:round((float)$block['rate_per_kg'],2);
            if (!hash_equals($expectedFingerprint,$this->editFingerprint($block))) throw new \RuntimeException('Блок был изменён после открытия. Откройте его заново.');
            $price=$this->price((string)($payload['pricing_type'] ?? ''),(float)$block['snapshot_total_mass_kg'],$payload['manual_total'] ?? null);
            $q=$pdo->prepare('UPDATE feo_request_cost_blocks SET pricing_type=?,rate_per_kg=?,total_cost=?,comment=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $q->execute([$price['pricing_type'],$price['rate_per_kg'],$price['total_cost'],$comment!==''?$comment:null,$blockId]);
            $pdo->commit();
            return ['block_id'=>$blockId,'pricing_type'=>$price['pricing_type'],'total_cost'=>$price['total_cost']];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function searchBlocks(PDO $pdo, array $ids): array
    {
        $ids=$this->normalizeIds($ids);
        $found=[];
        foreach (array_chunk($ids,self::CHUNK) as $chunk) {
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $q=$pdo->prepare("SELECT br.zayavka_id,b.id block_id,b.pricing_type,b.snapshot_total_mass_kg,b.total_cost,b.created_at
                                FROM feo_request_cost_block_requests br JOIN feo_request_cost_blocks b ON b.id=br.block_id
                               WHERE br.zayavka_id IN ({$marks}) ORDER BY b.id DESC");
            $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $found[(string)$row['zayavka_id']]=$row;
        }
        $out=[];
        foreach ($ids as $id) $out[]=['zayavka_id'=>$id,'block'=>$found[$id]??null];
        return $out;
    }

    private function selectionRows(PDO $pdo, array $ids, bool $forUpdate): array
    {
        $rows=[];
        foreach (array_chunk($ids,self::CHUNK) as $chunk) {
            if ($chunk===[]) continue;
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $sql="SELECT c.zayavka_id,ROUND(COALESCE(r.mass_netto,0)*1000,3) mass_kg
                    FROM feo_placement_current c JOIN feo_requests r ON r.zayavka_id=c.zayavka_id
                   WHERE c.zayavka_id IN ({$marks})" . ($forUpdate ? ' FOR UPDATE' : '');
            $q=$pdo->prepare($sql); $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(string)$row['zayavka_id']]=['mass_kg'=>round((float)$row['mass_kg'],3)];
        }
        return $rows;
    }

    private function pricedMemberships(PDO $pdo, array $ids, bool $forUpdate): array
    {
        $out=[];
        foreach (array_chunk($ids,self::CHUNK) as $chunk) {
            if ($chunk===[]) continue;
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            $sql="SELECT zayavka_id,block_id FROM feo_request_cost_block_requests WHERE zayavka_id IN ({$marks})" . ($forUpdate ? ' FOR UPDATE' : '');
            $q=$pdo->prepare($sql); $q->execute($chunk);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(string)$row['zayavka_id']]=(int)$row['block_id'];
        }
        return $out;
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

    private function price(string $type, float $massKg, mixed $manualTotal): array
    {
        if ($type==='rate_34_5') return ['pricing_type'=>$type,'rate_per_kg'=>self::RATE_34_5,'total_cost'=>round($massKg*self::RATE_34_5,2)];
        if ($type==='rate_38') return ['pricing_type'=>$type,'rate_per_kg'=>self::RATE_38,'total_cost'=>round($massKg*self::RATE_38,2)];
        if ($type!=='agreement') throw new \RuntimeException('Выберите способ расчёта стоимости.');
        $total=$this->money($manualTotal);
        if ($total<=0) throw new \RuntimeException('Введите согласованную стоимость больше нуля.');
        return ['pricing_type'=>'agreement','rate_per_kg'=>null,'total_cost'=>$total];
    }

    private function money(mixed $value): float
    {
        $s=str_replace(["\xC2\xA0",' '],'',trim((string)$value));
        $s=str_replace(',','.',$s);
        if ($s==='' || !preg_match('/^\d+(?:\.\d{1,2})?$/D',$s)) throw new \RuntimeException('Стоимость должна быть числом с точностью до копеек.');
        $v=round((float)$s,2);
        if (!is_finite($v) || $v>9999999999999999.99) throw new \RuntimeException('Стоимость выходит за допустимый диапазон.');
        return $v;
    }

    private function massForIds(array $rows, array $ids): float
    {
        $sum=0.0; foreach ($ids as $id) $sum+=(float)($rows[$id]['mass_kg']??0); return round($sum,3);
    }

    private function selectionFingerprint(array $rows, array $ids): string
    {
        $parts=[]; foreach ($ids as $id) $parts[]=$id.':'.number_format((float)($rows[$id]['mass_kg']??0),3,'.',''); return hash('sha256',implode('|',$parts));
    }

    private function editFingerprint(array $block): string
    {
        return hash('sha256',implode('|',[(int)$block['id'],(string)$block['pricing_type'],(string)($block['rate_per_kg']??''),number_format((float)$block['snapshot_total_mass_kg'],3,'.',''),number_format((float)$block['total_cost'],2,'.',''),(string)($block['comment']??''),(string)($block['updated_at']??'')]));
    }

    private function idList(array $ids): string
    {
        $head=array_slice($ids,0,50); return implode(', ',$head).(count($ids)>50?' и ещё '.(count($ids)-50):'');
    }

    private function pricedList(array $priced): string
    {
        $items=[]; foreach (array_slice($priced,0,50,true) as $id=>$block) $items[]=$id.' (блок #'.$block.')'; return implode(', ',$items).(count($priced)>50?' и ещё '.(count($priced)-50):'');
    }
}
