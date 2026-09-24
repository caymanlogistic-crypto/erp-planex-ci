<?php
declare(strict_types=1);

namespace App\Service;

use PDO;

final class FeoShareStatisticsService
{
    public static function schemaReady(PDO $pdo): bool
    {
        $query = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('feo_share_import_totals','feo_share_import_requests','feo_placement_current')");
        return (int)$query->fetchColumn() === 3;
    }

    public static function dashboard(PDO $pdo): array
    {
        $data = json_decode((string)file_get_contents(base_path('resources/data/feo_share_baseline.json')), true, 512, JSON_THROW_ON_ERROR);
        $knownImports = $pdo->query("SELECT filename,unique_requests FROM feo_imports WHERE status='applied'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($data['snapshots'] as $historical) {
            $found = false;
            foreach ($knownImports as $import) {
                $sameFile = (string)$import['filename'] === (string)$historical['file']
                    || str_contains((string)$import['filename'], (string)$historical['date']);
                if ($sameFile && (int)$import['unique_requests'] === (int)$historical['feo_count']) { $found = true; break; }
            }
            if (!$found) { $data['snapshots'] = []; break; }
        }
        $rows = $pdo->query("SELECT i.id,i.filename,i.created_at,t.feo_count,t.feo_kg,t.company_count,t.company_kg FROM feo_imports i JOIN feo_share_import_totals t ON t.import_id=i.id WHERE i.status='applied' ORDER BY i.id")->fetchAll(PDO::FETCH_ASSOC);
        $previousId = null;
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $filename = (string)$row['filename'];
            $date = preg_match('/20\d{2}-\d{2}-\d{2}/', $filename, $match) ? $match[0] : substr((string)$row['created_at'], 0, 10);
            $snapshot = [
                'date' => $date,
                'file' => $filename,
                'feo_count' => (int)$row['feo_count'],
                'feo_kg' => (float)$row['feo_kg'],
                'added_count' => null, 'added_kg' => null,
                'gone_count' => null, 'gone_kg' => null,
                'own_count' => (int)$row['company_count'],
                'own_kg' => (float)$row['company_kg'],
                'imported_at' => date('d.m H:i', strtotime((string)$row['created_at'])),
            ];
            if ($previousId !== null) {
                $change = $pdo->prepare("SELECT
                    SUM(CASE WHEN p.zayavka_id IS NULL THEN 1 ELSE 0 END) added_count,
                    SUM(CASE WHEN p.zayavka_id IS NULL THEN n.mass_kg ELSE 0 END) added_kg
                    FROM feo_share_import_requests n LEFT JOIN feo_share_import_requests p ON p.import_id=? AND p.zayavka_id=n.zayavka_id
                    WHERE n.import_id=?");
                $change->execute([$previousId, $id]);
                $added = $change->fetch(PDO::FETCH_ASSOC);
                $change = $pdo->prepare("SELECT
                    SUM(CASE WHEN n.zayavka_id IS NULL THEN 1 ELSE 0 END) gone_count,
                    SUM(CASE WHEN n.zayavka_id IS NULL THEN p.mass_kg ELSE 0 END) gone_kg
                    FROM feo_share_import_requests p LEFT JOIN feo_share_import_requests n ON n.import_id=? AND n.zayavka_id=p.zayavka_id
                    WHERE p.import_id=?");
                $change->execute([$id, $previousId]);
                $gone = $change->fetch(PDO::FETCH_ASSOC);
                $snapshot['added_count'] = (int)$added['added_count'];
                $snapshot['added_kg'] = (float)$added['added_kg'];
                $snapshot['gone_count'] = (int)$gone['gone_count'];
                $snapshot['gone_kg'] = (float)$gone['gone_kg'];
            }
            $data['snapshots'][] = $snapshot;
            $previousId = $id;
        }

        $geo = [];
        foreach ($data['regions'] as $region) {
            $geo[(string)$region['region']] = [
                'district' => (string)$region['district'],
                'region' => (string)$region['region'],
                'feo_count' => $data['snapshots'] === [] ? 0 : (int)$region['feo_count'],
                'feo_kg' => $data['snapshots'] === [] ? 0.0 : (float)$region['feo_kg'],
                'own_count' => 0, 'own_kg' => 0.0,
            ];
        }
        if ($previousId !== null) {
            foreach ($geo as &$region) { $region['feo_count'] = 0; $region['feo_kg'] = 0.0; }
            unset($region);
            $stmt = $pdo->prepare('SELECT COALESCE(NULLIF(TRIM(mno_region),\'\'),\'Не определён\') region,COUNT(*) count,SUM(mass_kg) kg FROM feo_share_import_requests WHERE import_id=? GROUP BY region');
            $stmt->execute([$previousId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $name = (string)$row['region'];
                $geo[$name] ??= ['district'=>'Не определён','region'=>$name,'feo_count'=>0,'feo_kg'=>0.0,'own_count'=>0,'own_kg'=>0.0];
                $geo[$name]['feo_count'] = (int)$row['count'];
                $geo[$name]['feo_kg'] = (float)$row['kg'];
            }
        }
        $current = $pdo->query('SELECT COUNT(*) count,COALESCE(SUM(COALESCE(r.mass_netto,0))*1000,0) kg FROM feo_placement_current c LEFT JOIN feo_requests r ON r.zayavka_id=c.zayavka_id')->fetch(PDO::FETCH_ASSOC);
        $data['current'] = ['own_count'=>(int)$current['count'],'own_kg'=>(float)$current['kg'],'as_of'=>date('d.m.Y')];
        $ownRegions = $pdo->query("SELECT COALESCE(NULLIF(TRIM(r.mno_region),''),'Не определён') region,COUNT(*) count,COALESCE(SUM(COALESCE(r.mass_netto,0))*1000,0) kg FROM feo_placement_current c LEFT JOIN feo_requests r ON r.zayavka_id=c.zayavka_id GROUP BY region");
        foreach ($ownRegions->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string)$row['region'];
            $geo[$name] ??= ['district'=>'Не определён','region'=>$name,'feo_count'=>0,'feo_kg'=>0.0,'own_count'=>0,'own_kg'=>0.0];
            $geo[$name]['own_count'] = (int)$row['count'];
            $geo[$name]['own_kg'] = (float)$row['kg'];
        }
        $data['regions'] = array_values(array_filter($geo, static fn(array $r): bool => $r['feo_count'] > 0 || $r['own_count'] > 0));
        $districts = [];
        foreach (['ЦФО','СЗФО','ЮФО','СКФО','ПФО','УФО','СФО','ДФО','Не определён'] as $name) {
            $districts[$name] = ['district'=>$name,'feo_count'=>0,'feo_kg'=>0.0,'own_count'=>0,'own_kg'=>0.0];
        }
        foreach ($data['regions'] as $region) {
            $district = $region['district'];
            foreach (['feo_count','feo_kg','own_count','own_kg'] as $field) $districts[$district][$field] += $region[$field];
        }
        $data['districts'] = array_values($districts);

        $blocks = $pdo->query("SELECT DATE(b.created_at) day,b.block_type,COUNT(*) count,COALESCE(SUM(COALESCE(r.mass_netto,0))*1000,0) kg FROM feo_placement_blocks b JOIN feo_placement_block_requests br ON br.block_id=b.id LEFT JOIN feo_requests r ON r.zayavka_id=br.zayavka_id WHERE b.status='applied' GROUP BY DATE(b.created_at),b.block_type ORDER BY day");
        $daily = [];
        foreach ($blocks->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $day = (string)$row['day'];
            $daily[$day] ??= [$day,0,0.0,0,0.0];
            $offset = $row['block_type'] === 'addition' ? 1 : 3;
            $daily[$day][$offset] += (int)$row['count'];
            $daily[$day][$offset+1] += (float)$row['kg'];
        }
        $data['block_daily'] = array_values($daily);
        $data['historical_note'] = 'Срезы до внедрения отчёта восстановлены по полным файлам ФЭО; последующие срезы сохраняются при импорте. Для перехода от последнего исторического файла к первой новой загрузке сравнение ID недоступно.';
        return $data;
    }
}
