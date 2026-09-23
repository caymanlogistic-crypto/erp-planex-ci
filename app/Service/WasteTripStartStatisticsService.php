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
    public const MODES = ['week', 'month', 'year', 'custom'];
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

    /**
     * The exact central role "Логист" is the authority. Historical companies
     * can have that role without a system_key, while newer companies use the
     * stable "logist" system key. Both shapes are accepted; similarly named
     * roles such as "Логист+" and "Логист (lite)" remain excluded.
     *
     * @return list<array{id:int,membership_id:int,full_name:string,login:string}>
     */
    public static function eligibleLogists(PDO $central, int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }
        $stmt = $central->prepare(
            "SELECT m.id AS membership_id,a.full_name,a.login
               FROM company_memberships m
               JOIN user_accounts a ON a.id=m.account_id
               JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id
              WHERE m.company_id=?
                AND m.status='active'
                AND a.status='active'
                AND (
                    r.system_key='logist'
                    OR (r.system_key IS NULL AND r.normalized_name='логист')
                )
              ORDER BY a.full_name,m.id"
        );
        $stmt->execute([$companyId]);
        return array_values(array_map(static fn(array $row): array => [
            'id'=>(int)$row['membership_id'],
            'membership_id'=>(int)$row['membership_id'],
            'full_name'=>(string)$row['full_name'],
            'login'=>(string)$row['login'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** Capture the first responsible logist and keep corrected composition/date current. */
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
        $mode = in_array((string)($query['mode'] ?? ''), self::MODES, true) ? (string)$query['mode'] : 'year';
        $metric = in_array((string)($query['metric'] ?? ''), self::METRICS, true) ? (string)$query['metric'] : 'trips';
        $selectedWeek = self::parseWeek((string)($query['week'] ?? ''), $tz) ?? $today->modify('monday this week');
        $selectedMonth = self::parseMonth((string)($query['month'] ?? ''), $tz) ?? $today->modify('first day of this month');
        $selectedYear = filter_var($query['year'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>2000,'max_range'=>2100]]) ?: (int)$today->format('Y');

        if ($mode === 'week') {
            $from = $selectedWeek;
            $to = $from->add(new DateInterval('P6D'));
            $group = 'day';
        } elseif ($mode === 'month') {
            $from = $selectedMonth;
            $to = $from->modify('last day of this month');
            $group = 'week';
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
            $from = $today->setDate((int)$selectedYear, 1, 1);
            $to = (int)$selectedYear === (int)$today->format('Y')
                ? $today
                : $today->setDate((int)$selectedYear, 12, 31);
            $group = in_array((string)($query['group'] ?? ''), ['week','month'], true)
                ? (string)$query['group']
                : 'week';
        }

        $durationDays = max(1, (int)$from->diff($to)->days + 1);
        if ($durationDays > 1096) {
            $from = $to->sub(new DateInterval('P1095D'));
            $durationDays = 1096;
        }

        if ($mode === 'year') {
            $previousFrom = $from->modify('-1 year');
            $previousTo = $to->modify('-1 year');
        } elseif ($mode === 'month') {
            $previousFrom = $from->modify('-1 month');
            $previousTo = $previousFrom->modify('last day of this month');
        } else {
            $previousTo = $from->sub(new DateInterval('P1D'));
            $previousFrom = $previousTo->sub(new DateInterval('P'.($durationDays - 1).'D'));
        }

        $rawManagers = $query['manager_ids'] ?? [];
        if (!is_array($rawManagers)) {
            $rawManagers = explode(',', (string)$rawManagers);
        }
        $managerIds = array_values(array_unique(array_filter(
            array_map('intval', $rawManagers),
            static fn(int $id): bool => $id > 0
        )));

        return [
            'mode'=>$mode,
            'metric'=>$metric,
            'group'=>$group,
            'week'=>$selectedWeek->format('o-\WW'),
            'month'=>$selectedMonth->format('Y-m'),
            'year'=>(int)$selectedYear,
            'from'=>$from->format('Y-m-d'),
            'to'=>$to->format('Y-m-d'),
            'previous_from'=>$previousFrom->format('Y-m-d'),
            'previous_to'=>$previousTo->format('Y-m-d'),
            'manager_ids'=>$managerIds,
            'compare'=>!isset($query['compare']) || (string)$query['compare'] !== '0',
            'duration_days'=>$durationDays,
        ];
    }

    /** @param list<array{membership_id:int,full_name:string,login?:string}> $eligibleManagers */
    public static function dashboard(PDO $pdo, array $filters, array $eligibleManagers): array
    {
        if (!self::schemaReady($pdo)) {
            throw new RuntimeException('Схема статистики не готова: требуется обновление 141.');
        }
        $allowedIds = array_values(array_filter(array_map(
            static fn(array $manager): int => (int)($manager['membership_id'] ?? 0),
            $eligibleManagers
        )));
        $requestedIds = array_values(array_map('intval', $filters['manager_ids'] ?? []));
        $current = self::aggregate($pdo, (string)$filters['from'], (string)$filters['to'], $requestedIds, $allowedIds);
        $previous = self::aggregate($pdo, (string)$filters['previous_from'], (string)$filters['previous_to'], $requestedIds, $allowedIds);
        $selectedManagers = array_fill_keys($requestedIds, true);
        $visibleManagers = $selectedManagers === [] ? $eligibleManagers : array_values(array_filter(
            $eligibleManagers,
            static fn(array $manager): bool => isset($selectedManagers[(int)($manager['membership_id'] ?? 0)])
        ));
        $managers = self::managerRows($current['managers'], $previous['managers'], $visibleManagers, (string)$filters['metric']);
        $trend = self::trend($current['daily'], (string)$filters['from'], (string)$filters['to'], (string)$filters['group']);
        $managerTrends = self::managerTrends(
            $current['daily_managers'],
            $visibleManagers,
            (string)$filters['from'],
            (string)$filters['to'],
            (string)$filters['group']
        );

        return [
            'totals'=>$current['totals'],
            'previous_totals'=>$previous['totals'],
            'managers'=>$managers,
            'trend'=>$trend,
            'previous_trend'=>self::trend($previous['daily'], (string)$filters['previous_from'], (string)$filters['previous_to'], (string)$filters['group']),
            'manager_trends'=>$managerTrends,
            'period_rows'=>self::periodRows($trend, $managerTrends),
            'details'=>self::details($pdo, $filters, $allowedIds),
            'available_years'=>self::availableYears($pdo, $allowedIds),
            'scope'=>AccessControlService::effectiveDataScope('waste_trip'),
        ];
    }

    /** @return array{totals:array<string,int|float>,managers:list<array<string,mixed>>,daily:list<array<string,mixed>>,daily_managers:list<array<string,mixed>>} */
    private static function aggregate(PDO $pdo, string $from, string $to, array $managerIds, array $allowedIds): array
    {
        [$where, $params] = self::where($from, $to, $managerIds, $allowedIds);
        $base = " FROM waste_trip_start_statistics s JOIN waste_trips wt ON wt.id=s.waste_trip_id WHERE ".implode(' AND ', $where);

        $totalStmt = $pdo->prepare(
            "SELECT COUNT(*) AS trips,COALESCE(SUM(s.request_count),0) AS requests,
                    COALESCE(SUM(s.total_mass_kg),0) AS weight,
                    COUNT(DISTINCT s.responsible_membership_id) AS active_managers,
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
        $daily = array_map([self::class, 'castDaily'], $dailyStmt->fetchAll(PDO::FETCH_ASSOC));

        $dailyManagersStmt = $pdo->prepare(
            "SELECT s.actual_start_date,s.responsible_membership_id,COUNT(*) AS trips,
                    COALESCE(SUM(s.request_count),0) AS requests,COALESCE(SUM(s.total_mass_kg),0) AS weight".$base.
            ' GROUP BY s.actual_start_date,s.responsible_membership_id ORDER BY s.actual_start_date,s.responsible_membership_id'
        );
        $dailyManagersStmt->execute($params);
        $dailyManagers = array_map(static function(array $row): array {
            return ['membership_id'=>(int)$row['responsible_membership_id']] + self::castDaily($row);
        }, $dailyManagersStmt->fetchAll(PDO::FETCH_ASSOC));

        return ['totals'=>$totals,'managers'=>$managers,'daily'=>$daily,'daily_managers'=>$dailyManagers];
    }

    /** @return array{0:list<string>,1:list<int|string>} */
    private static function where(string $from, string $to, array $managerIds, array $allowedIds): array
    {
        $access = RegulatedDataScopeService::condition('waste_trip', 'wt.id', 'wt.created_by_membership_id');
        $where = [$access['sql'], 'wt.deleted_at IS NULL', 's.actual_start_date BETWEEN ? AND ?'];
        $params = array_merge($access['params'], [$from, $to]);
        if ($allowedIds === []) {
            $where[] = '1=0';
            return [$where, $params];
        }
        $where[] = 's.responsible_membership_id IN ('.implode(',', array_fill(0, count($allowedIds), '?')).')';
        $params = array_merge($params, $allowedIds);
        if ($managerIds !== []) {
            $selected = array_values(array_intersect($managerIds, $allowedIds));
            if ($selected === []) {
                $where[] = '1=0';
            } else {
                $where[] = 's.responsible_membership_id IN ('.implode(',', array_fill(0, count($selected), '?')).')';
                $params = array_merge($params, $selected);
            }
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

    private static function castDaily(array $row): array
    {
        return [
            'date'=>(string)$row['actual_start_date'],
            'trips'=>(int)$row['trips'],
            'requests'=>(int)$row['requests'],
            'weight'=>(float)$row['weight'],
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
            if (!isset($rows[$key])) {
                continue;
            }
            $rows[$key] = [
                'membership_id'=>(int)$row['responsible_membership_id'],
                'responsible_user_id'=>(int)$row['responsible_user_id'],
                'name'=>(string)$rows[$key]['name'],
                'is_active'=>true,
            ] + self::castMetrics($row);
        }
        $previousMap = [];
        foreach ($previous as $row) {
            $previousMap[self::managerKey($row)] = self::castMetrics($row);
        }
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
        return 'm'.(int)($row['responsible_membership_id'] ?? $row['membership_id'] ?? 0);
    }

    private static function trend(array $daily, string $from, string $to, string $group): array
    {
        $byDate = [];
        foreach ($daily as $row) {
            $byDate[$row['date']] = $row;
        }
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
                    'week' => 'Нед. '.$date->format('W').' · '.$date->modify('monday this week')->format('d.m'),
                    default => $date->format('d.m'),
                };
                $buckets[$key] = ['key'=>$key,'label'=>$label,'from'=>$date->format('Y-m-d'),'to'=>$date->format('Y-m-d'),'trips'=>0,'requests'=>0,'weight'=>0.0];
            }
            $buckets[$key]['to'] = $date->format('Y-m-d');
            $row = $byDate[$date->format('Y-m-d')] ?? null;
            if ($row !== null) {
                $buckets[$key]['trips'] += (int)$row['trips'];
                $buckets[$key]['requests'] += (int)$row['requests'];
                $buckets[$key]['weight'] += (float)$row['weight'];
            }
        }
        return array_values($buckets);
    }

    private static function managerTrends(array $dailyManagers, array $visibleManagers, string $from, string $to, string $group): array
    {
        $byManager = [];
        foreach ($dailyManagers as $row) {
            $byManager[(int)$row['membership_id']][] = $row;
        }
        $result = [];
        foreach ($visibleManagers as $manager) {
            $id = (int)$manager['membership_id'];
            $result[] = [
                'membership_id'=>$id,
                'name'=>(string)$manager['full_name'],
                'values'=>self::trend($byManager[$id] ?? [], $from, $to, $group),
            ];
        }
        return $result;
    }

    private static function periodRows(array $trend, array $managerTrends): array
    {
        $rows = [];
        foreach ($trend as $index => $company) {
            $managerValues = [];
            foreach ($managerTrends as $manager) {
                $managerValues[(int)$manager['membership_id']] = $manager['values'][$index] ?? self::castMetrics([]);
            }
            $rows[] = $company + ['manager_values'=>$managerValues];
        }
        return $rows;
    }

    private static function details(PDO $pdo, array $filters, array $allowedIds): array
    {
        [$where,$params] = self::where((string)$filters['from'], (string)$filters['to'], $filters['manager_ids'], $allowedIds);
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

    private static function availableYears(PDO $pdo, array $allowedIds): array
    {
        $years = [(int)(new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y')];
        if ($allowedIds !== []) {
            [$where,$params] = self::where('2000-01-01', '2100-12-31', [], $allowedIds);
            $stmt = $pdo->prepare(
                'SELECT DISTINCT YEAR(s.actual_start_date) AS year
                   FROM waste_trip_start_statistics s
                   JOIN waste_trips wt ON wt.id=s.waste_trip_id
                  WHERE '.implode(' AND ', $where).' ORDER BY year DESC'
            );
            $stmt->execute($params);
            $years = array_merge($years, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        $years = array_values(array_unique(array_filter($years)));
        rsort($years, SORT_NUMERIC);
        return $years;
    }

    private static function parseDate(string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function parseMonth(string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/D', $value) !== 1) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m', $value, $tz);
        return $date && $date->format('Y-m') === $value ? $date : null;
    }

    private static function parseWeek(string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/D', $value, $matches) !== 1) return null;
        $year = (int)$matches[1];
        $week = (int)$matches[2];
        if ($week < 1 || $week > 53) return null;
        $date = (new DateTimeImmutable('now', $tz))->setISODate($year, $week, 1)->setTime(0, 0);
        return $date->format('o-\WW') === $value ? $date : null;
    }

    private static function monthLabel(DateTimeImmutable $date): string
    {
        $months = [1=>'Янв',2=>'Фев',3=>'Мар',4=>'Апр',5=>'Май',6=>'Июн',7=>'Июл',8=>'Авг',9=>'Сен',10=>'Окт',11=>'Ноя',12=>'Дек'];
        return $months[(int)$date->format('n')].' '.$date->format('Y');
    }
}
