<?php

namespace App\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class WasteTripStartStatisticsService
{
    public const METRICS = ['trips', 'requests', 'weight'];
    public const MODES = ['weeks', 'months', 'custom'];
    public const GROUPS = ['day', 'week', 'month'];

    public static function schemaReady(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='waste_trip_start_statistics'");
            return (int)$stmt->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Capture the first responsible manager and keep corrected composition/date current. */
    public static function synchronizeTrip(PDO $pdo, int $tripId, bool $backfilled = false): void
    {
        if ($tripId <= 0 || !self::schemaReady($pdo)) {
            return;
        }
        $stmt = $pdo->prepare(
            "SELECT wt.id,wt.actual_start_date,wt.status,wt.responsible_user_id,
                    u.membership_id,u.full_name,
                    COUNT(wtr.id) AS request_count,
                    COALESCE(SUM(wtr.snapshot_mass_kg),0) AS total_mass_kg
               FROM waste_trips wt
               JOIN users u ON u.id=wt.responsible_user_id
          LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id
              WHERE wt.id=?
              GROUP BY wt.id,wt.actual_start_date,wt.status,wt.responsible_user_id,u.membership_id,u.full_name"
        );
        $stmt->execute([$tripId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['actual_start_date'])
            || !in_array((string)$row['status'], ['in_progress','completed','cancelled'], true)) {
            return;
        }

        $upsert = $pdo->prepare(
            'INSERT INTO waste_trip_start_statistics
                (waste_trip_id,actual_start_date,responsible_user_id,responsible_membership_id,
                 responsible_name_snapshot,request_count,total_mass_kg,is_backfilled)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                 actual_start_date=VALUES(actual_start_date),
                 request_count=VALUES(request_count),
                 total_mass_kg=VALUES(total_mass_kg),
                 updated_at=CURRENT_TIMESTAMP'
        );
        $upsert->execute([
            (int)$row['id'],
            (string)$row['actual_start_date'],
            (int)$row['responsible_user_id'],
            (int)($row['membership_id'] ?? 0) ?: null,
            (string)$row['full_name'],
            (int)$row['request_count'],
            number_format((float)$row['total_mass_kg'], 3, '.', ''),
            $backfilled ? 1 : 0,
        ]);
    }

    /** @return array<string,mixed> */
    public static function normalizeFilters(array $query, ?DateTimeImmutable $today = null): array
    {
        $tz = new DateTimeZone('Europe/Moscow');
        $today = ($today ?? new DateTimeImmutable('today', $tz))->setTimezone($tz)->setTime(0, 0);
        $mode = in_array((string)($query['mode'] ?? ''), self::MODES, true) ? (string)$query['mode'] : 'weeks';
        $metric = in_array((string)($query['metric'] ?? ''), self::METRICS, true) ? (string)$query['metric'] : 'trips';

        if ($mode === 'months') {
            $from = $today->setDate((int)$today->format('Y'), 1, 1);
            $to = $today;
            $group = 'month';
        } elseif ($mode === 'custom') {
            $from = self::parseDate((string)($query['from'] ?? ''), $tz) ?? $today->sub(new DateInterval('P29D'));
            $to = self::parseDate((string)($query['to'] ?? ''), $tz) ?? $today;
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            $group = in_array((string)($query['group'] ?? ''), self::GROUPS, true)
                ? (string)$query['group']
                : (($to->diff($from)->days ?? 0) <= 31 ? 'day' : 'week');
        } else {
            $weekStart = $today->modify('monday this week');
            $from = $weekStart->sub(new DateInterval('P11W'));
            $to = $today;
            $group = 'week';
        }

        $durationDays = max(1, (int)$from->diff($to)->days + 1);
        if ($durationDays > 1096) {
            $from = $to->sub(new DateInterval('P1095D'));
            $durationDays = 1096;
        }
        $previousTo = $from->sub(new DateInterval('P1D'));
        $previousFrom = $previousTo->sub(new DateInterval('P'.($durationDays - 1).'D'));

        $rawManagers = $query['manager_ids'] ?? [];
        if (!is_array($rawManagers)) {
            $rawManagers = explode(',', (string)$rawManagers);
        }
        $managerIds = array_values(array_unique(array_filter(
            array_map('intval', $rawManagers),
            static fn(int $id): bool => $id > 0
        )));

        return [
            'mode' => $mode,
            'metric' => $metric,
            'group' => $group,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'previous_from' => $previousFrom->format('Y-m-d'),
            'previous_to' => $previousTo->format('Y-m-d'),
            'manager_ids' => $managerIds,
            'compare' => !isset($query['compare']) || (string)$query['compare'] !== '0',
            'duration_days' => $durationDays,
        ];
    }

    /** @param list<array{membership_id:int,full_name:string,login?:string}> $eligibleManagers */
    public static function dashboard(PDO $pdo, array $filters, array $eligibleManagers): array
    {
        if (!self::schemaReady($pdo)) {
            throw new RuntimeException('Схема статистики не готова: требуется обновление 141.');
        }
        $current = self::aggregate($pdo, (string)$filters['from'], (string)$filters['to'], $filters['manager_ids']);
        $previous = self::aggregate($pdo, (string)$filters['previous_from'], (string)$filters['previous_to'], $filters['manager_ids']);
        $selectedManagers = array_fill_keys(array_map('intval', $filters['manager_ids']), true);
        $visibleManagers = $selectedManagers === [] ? $eligibleManagers : array_values(array_filter(
            $eligibleManagers,
            static fn(array $manager): bool => isset($selectedManagers[(int)($manager['membership_id'] ?? 0)])
        ));
        $managers = self::managerRows($current['managers'], $previous['managers'], $visibleManagers, (string)$filters['metric']);

        return [
            'totals' => $current['totals'],
            'previous_totals' => $previous['totals'],
            'managers' => $managers,
            'trend' => self::trend($current['daily'], (string)$filters['from'], (string)$filters['to'], (string)$filters['group']),
            'previous_trend' => self::trend($previous['daily'], (string)$filters['previous_from'], (string)$filters['previous_to'], (string)$filters['group']),
            'details' => self::details($pdo, $filters),
            'scope' => AccessControlService::effectiveDataScope('waste_trip'),
        ];
    }

    /** @return array{totals:array<string,int|float>,managers:list<array<string,mixed>>,daily:list<array<string,mixed>>} */
    private static function aggregate(PDO $pdo, string $from, string $to, array $managerIds): array
    {
        [$where, $params] = self::where($from, $to, $managerIds);
        $base = " FROM waste_trip_start_statistics s JOIN waste_trips wt ON wt.id=s.waste_trip_id WHERE ".implode(' AND ', $where);

        $totalStmt = $pdo->prepare(
            "SELECT COUNT(*) AS trips,COALESCE(SUM(s.request_count),0) AS requests,
                    COALESCE(SUM(s.total_mass_kg),0) AS weight,
                    COUNT(DISTINCT COALESCE(s.responsible_membership_id,-s.responsible_user_id)) AS active_managers,
                    COALESCE(SUM(wt.status='completed'),0) AS completed,
                    COALESCE(SUM(wt.status='cancelled'),0) AS cancelled".$base
        );
        $totalStmt->execute($params);
        $totals = self::castMetrics($totalStmt->fetch(PDO::FETCH_ASSOC) ?: []);

        $managerStmt = $pdo->prepare(
            "SELECT s.responsible_membership_id,s.responsible_user_id,MIN(s.responsible_name_snapshot) AS responsible_name_snapshot,
                    COUNT(*) AS trips,COALESCE(SUM(s.request_count),0) AS requests,
                    COALESCE(SUM(s.total_mass_kg),0) AS weight,
                    COALESCE(SUM(wt.status='completed'),0) AS completed,
                    COALESCE(SUM(wt.status='cancelled'),0) AS cancelled".$base.
            ' GROUP BY s.responsible_membership_id,s.responsible_user_id'
        );
        $managerStmt->execute($params);
        $managers = array_map(static function(array $row): array {
            return array_merge($row, self::castMetrics($row));
        }, $managerStmt->fetchAll(PDO::FETCH_ASSOC));

        $dailyStmt = $pdo->prepare(
            "SELECT s.actual_start_date,COUNT(*) AS trips,COALESCE(SUM(s.request_count),0) AS requests,
                    COALESCE(SUM(s.total_mass_kg),0) AS weight".$base.' GROUP BY s.actual_start_date ORDER BY s.actual_start_date'
        );
        $dailyStmt->execute($params);
        $daily = array_map(static fn(array $row): array => [
            'date'=>(string)$row['actual_start_date'],
            'trips'=>(int)$row['trips'],
            'requests'=>(int)$row['requests'],
            'weight'=>(float)$row['weight'],
        ], $dailyStmt->fetchAll(PDO::FETCH_ASSOC));

        return compact('totals','managers','daily');
    }

    /** @return array{0:list<string>,1:list<int|string>} */
    private static function where(string $from, string $to, array $managerIds): array
    {
        $access = RegulatedDataScopeService::condition('waste_trip', 'wt.id', 'wt.created_by_membership_id');
        $where = [$access['sql'], 'wt.deleted_at IS NULL', 's.actual_start_date BETWEEN ? AND ?'];
        $params = array_merge($access['params'], [$from, $to]);
        if ($managerIds !== []) {
            $where[] = 's.responsible_membership_id IN ('.implode(',', array_fill(0, count($managerIds), '?')).')';
            $params = array_merge($params, $managerIds);
        }
        return [$where, $params];
    }

    private static function castMetrics(array $row): array
    {
        return [
            'trips'=>(int)($row['trips'] ?? 0),
            'requests'=>(int)($row['requests'] ?? 0),
            'weight'=>(float)($row['weight'] ?? 0),
            'active_managers'=>(int)($row['active_managers'] ?? 0),
            'completed'=>(int)($row['completed'] ?? 0),
            'cancelled'=>(int)($row['cancelled'] ?? 0),
        ];
    }

    private static function managerRows(array $current, array $previous, array $eligible, string $metric): array
    {
        $rows = [];
        foreach ($eligible as $manager) {
            $id = (int)($manager['membership_id'] ?? 0);
            if ($id > 0) {
                $rows['m'.$id] = ['membership_id'=>$id,'responsible_user_id'=>0,'name'=>(string)$manager['full_name'],'is_active'=>true] + self::castMetrics([]);
            }
        }
        foreach ($current as $row) {
            $key = self::managerKey($row);
            $existingName = isset($rows[$key]) ? (string)$rows[$key]['name'] : '';
            $rows[$key] = [
                'membership_id'=>(int)($row['responsible_membership_id'] ?? 0),
                'responsible_user_id'=>(int)($row['responsible_user_id'] ?? 0),
                'name'=>$existingName !== '' ? $existingName : (string)$row['responsible_name_snapshot'],
                'is_active'=>isset($rows[$key]),
            ] + self::castMetrics($row);
        }
        $previousMap = [];
        foreach ($previous as $row) $previousMap[self::managerKey($row)] = self::castMetrics($row);
        foreach ($rows as $key => &$row) {
            $row['previous'] = $previousMap[$key] ?? self::castMetrics([]);
            $row['requests_per_trip'] = $row['trips'] > 0 ? $row['requests'] / $row['trips'] : 0.0;
            $row['weight_per_trip'] = $row['trips'] > 0 ? $row['weight'] / $row['trips'] : 0.0;
            $row['completion_rate'] = $row['trips'] > 0 ? $row['completed'] / $row['trips'] * 100 : 0.0;
        }
        unset($row);
        usort($rows, static fn(array $a,array $b): int => ($b[$metric] <=> $a[$metric]) ?: strcmp($a['name'],$b['name']));
        return array_values($rows);
    }

    private static function managerKey(array $row): string
    {
        $membership = (int)($row['responsible_membership_id'] ?? $row['membership_id'] ?? 0);
        return $membership > 0 ? 'm'.$membership : 'u'.(int)($row['responsible_user_id'] ?? 0);
    }

    private static function trend(array $daily, string $from, string $to, string $group): array
    {
        $byDate = [];
        foreach ($daily as $row) $byDate[$row['date']] = $row;
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        $buckets = [];
        for ($date = $start; $date <= $end; $date = $date->add(new DateInterval('P1D'))) {
            $key = match ($group) {
                'month' => $date->format('Y-m-01'),
                'week' => $date->modify('monday this week')->format('Y-m-d'),
                default => $date->format('Y-m-d'),
            };
            if (!isset($buckets[$key])) {
                $label = match ($group) {
                    'month' => self::monthLabel($date),
                    'week' => 'Нед. '.$date->format('W'),
                    default => $date->format('d.m'),
                };
                $buckets[$key] = ['key'=>$key,'label'=>$label,'trips'=>0,'requests'=>0,'weight'=>0.0];
            }
            $row = $byDate[$date->format('Y-m-d')] ?? null;
            if ($row !== null) {
                $buckets[$key]['trips'] += (int)$row['trips'];
                $buckets[$key]['requests'] += (int)$row['requests'];
                $buckets[$key]['weight'] += (float)$row['weight'];
            }
        }
        return array_values($buckets);
    }

    private static function details(PDO $pdo, array $filters): array
    {
        [$where,$params] = self::where((string)$filters['from'], (string)$filters['to'], $filters['manager_ids']);
        $stmt = $pdo->prepare(
            "SELECT wt.id,wt.business_id,wt.status,s.actual_start_date,s.responsible_name_snapshot,
                    s.responsible_membership_id,s.request_count,s.total_mass_kg
               FROM waste_trip_start_statistics s
               JOIN waste_trips wt ON wt.id=s.waste_trip_id
              WHERE ".implode(' AND ', $where).
            ' ORDER BY s.actual_start_date DESC,wt.business_id DESC LIMIT 500'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function parseDate(string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function monthLabel(DateTimeImmutable $date): string
    {
        $months = [1=>'Янв',2=>'Фев',3=>'Мар',4=>'Апр',5=>'Май',6=>'Июн',7=>'Июл',8=>'Авг',9=>'Сен',10=>'Окт',11=>'Ноя',12=>'Дек'];
        return $months[(int)$date->format('n')].' '.$date->format('Y');
    }
}
