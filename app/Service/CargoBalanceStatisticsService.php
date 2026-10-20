<?php
declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use PDO;

final class CargoBalanceStatisticsService
{
    public static function schemaReady(PDO $pdo): bool
    {
        $tables = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('feo_placement_blocks','feo_placement_block_requests','feo_placement_current','waste_trips','waste_trip_requests','feo_requests')");
        return (int)$tables->fetchColumn() === 6;
    }

    /** @return array{daily:list<array<string,int|string>>,today:string,history_note:string,forecast:array{formed_trip_count:int,projected_new:int}} */
    public static function dashboard(PDO $pdo): array
    {
        $blocks = $pdo->query("SELECT b.id,b.block_type,DATE(b.created_at) day,br.zayavka_id
            FROM feo_placement_blocks b
            JOIN feo_placement_block_requests br ON br.block_id=b.id
            WHERE b.status='applied' ORDER BY b.id,br.position_no,br.id")->fetchAll(PDO::FETCH_ASSOC);
        $starts = $pdo->query("SELECT fr.zayavka_id,MIN(wt.actual_start_date) day
            FROM waste_trips wt
            JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id AND wtr.removed_at IS NULL
            JOIN feo_requests fr ON fr.id=wtr.feo_request_id
            WHERE wt.deleted_at IS NULL AND wt.status IN ('in_progress','completed')
              AND wt.actual_start_date IS NOT NULL
            GROUP BY fr.zayavka_id")->fetchAll(PDO::FETCH_ASSOC);
        $formedTripCount = (int)$pdo->query("SELECT COUNT(*) FROM waste_trips WHERE status='assigned' AND deleted_at IS NULL")->fetchColumn();
        $formedRequests = $pdo->query("SELECT DISTINCT fr.zayavka_id
            FROM waste_trips wt
            JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id AND wtr.removed_at IS NULL
            JOIN feo_requests fr ON fr.id=wtr.feo_request_id
            WHERE wt.status='assigned' AND wt.deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $events = [];
        foreach ($blocks as $block) {
            $day = (string)$block['day'];
            if ($day > $today) continue;
            $events[$day][] = $block;
        }
        $startsByDay = [];
        foreach ($starts as $start) {
            $day = (string)$start['day'];
            if ($day <= $today) $startsByDay[$day][] = (string)$start['zayavka_id'];
        }
        $first = min(array_merge([$today], array_keys($events), array_keys($startsByDay)));
        $assigned = [];
        $started = [];
        $daily = [];
        $day = new DateTimeImmutable($first);
        $last = new DateTimeImmutable($today);
        for (; $day <= $last; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $before = $assigned;
            foreach ($events[$key] ?? [] as $event) {
                $id = (string)$event['zayavka_id'];
                if ($event['block_type'] === 'addition') $assigned[$id] = true;
                else unset($assigned[$id]);
            }
            $newStarts = 0;
            foreach ($startsByDay[$key] ?? [] as $id) {
                if (!isset($started[$id])) { $started[$id] = true; $newStarts++; }
            }
            $daily[] = [
                'date'=>$key,
                'assigned'=>count($assigned),
                'started'=>count($started),
                'received'=>count(array_diff_key($assigned, $before)),
                'withdrawn'=>count(array_diff_key($before, $assigned)),
                'started_new'=>$newStarts,
            ];
        }
        // The current placement table is authoritative when a past block was disabled.
        $current = (int)$pdo->query('SELECT COUNT(*) FROM feo_placement_current')->fetchColumn();
        if ($daily !== []) $daily[count($daily)-1]['assigned'] = $current;
        $projectedNew = 0;
        foreach ($formedRequests as $id) {
            if (!isset($started[(string)$id])) $projectedNew++;
        }
        return [
            'daily'=>$daily,
            'today'=>$today,
            'forecast'=>['formed_trip_count'=>$formedTripCount,'projected_new'=>$projectedNew],
            'history_note'=>'Прошлые остатки восстановлены по блокам, применяемым сейчас; изменения отключённых блоков могут отсутствовать.',
        ];
    }
}
