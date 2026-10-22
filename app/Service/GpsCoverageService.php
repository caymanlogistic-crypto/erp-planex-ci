<?php

namespace App\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Read-only, request-time GPS coverage over the FREGAT database. */
final class GpsCoverageService
{
    private const MOSCOW = 'Europe/Moscow';
    private const UTC = 'UTC';

    /**
     * Last valid GPS point for already authorized, visible ERP trips.
     * @param list<array<string,mixed>> $trips
     * @return array<int,string> UTC timestamp keyed by ERP trip ID
     */
    public static function lastPointsForTrips(PDO $pdo, array $trips, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone(self::UTC));
        $now = $now->setTimezone(new DateTimeZone(self::UTC));
        $oldest = $now->sub(new DateInterval('P90D'));
        $latest = $now->add(new DateInterval('PT5M'))->format('Y-m-d H:i:s');
        $query = $pdo->prepare('SELECT uniqueid,name,MAX(lastupdate) AS last_point FROM tracker_log FORCE INDEX (idx_fetched_at)
            WHERE fetched_at BETWEEN :from AND :to AND name LIKE :surname
              AND lat IS NOT NULL AND lon IS NOT NULL AND lastupdate IS NOT NULL
              AND lastupdate BETWEEN :from_point AND :latest
              AND lastupdate <= DATE_ADD(fetched_at, INTERVAL 5 MINUTE)
            GROUP BY uniqueid,name');
        $points = [];
        $cache = [];
        foreach ($trips as $trip) {
            if (($trip['status'] ?? '') !== 'in_progress') continue;
            $id = (int)($trip['id'] ?? 0);
            $driver = trim((string)($trip['executor_driver_names'] ?? ''));
            $vehicle = trim((string)($trip['executor_vehicle_label'] ?? ''));
            if ($id <= 0 || $driver === '' || str_contains($driver, '/') || str_contains($vehicle, ' + ')) continue;
            $surname = self::surname($driver);
            $plate = self::plate($vehicle);
            if ($surname === '' || $plate === '') continue;
            $start = (string)($trip['actual_start_date'] ?? '');
            $from = self::dateValid($start)
                ? (new DateTimeImmutable($start.' 00:00:00', new DateTimeZone(self::MOSCOW)))->setTimezone(new DateTimeZone(self::UTC))
                : $oldest;
            if ($from < $oldest) $from = $oldest;
            if ($from > $now) continue;
            $key = $surname.'|'.$plate.'|'.$from->format('Y-m-d H:i:s');
            if (!array_key_exists($key, $cache)) {
                $query->execute(['from'=>$from->format('Y-m-d H:i:s'), 'to'=>$latest,
                    'surname'=>'%'.$surname.'%', 'from_point'=>$from->format('Y-m-d H:i:s'), 'latest'=>$latest]);
                $devices = [];
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (self::trackerKey((string)$row['name']) !== $surname.'|'.$plate) continue;
                    $device = trim((string)$row['uniqueid']);
                    if ($device === '') continue;
                    $point = (string)$row['last_point'];
                    if (!isset($devices[$device]) || $point > $devices[$device]) $devices[$device] = $point;
                }
                $cache[$key] = count($devices) === 1 ? reset($devices) : null;
            }
            if ($cache[$key] !== null) $points[$id] = $cache[$key];
        }
        return $points;
    }

    /** @param list<int>|null $managerIds Null is reserved for the company owner. */
    public static function page(PDO $pdo, int $page = 1, string $status = 'all', ?array $managerIds = null): array
    {
        if (!in_array($status, ['all', 'started', 'completed'], true)) throw new \InvalidArgumentException('Недопустимый статус рейса.');
        $page = max(1, $page);
        $managerIds = $managerIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $managerIds), static fn(int $id): bool => $id > 0)));
        $where = "f.id >= 244 AND f.status IN ('started','completed')";
        $params = [];
        if ($managerIds !== null) {
            $where .= $managerIds === [] ? ' AND 1=0' : ' AND f.assigned_manager_id IN ('.implode(',', $managerIds).')';
        }
        $counts = $pdo->query("SELECT f.status,COUNT(*) AS amount FROM flights f WHERE $where GROUP BY f.status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $totals = ['all'=>0,'started'=>(int)($counts['started']??0),'completed'=>(int)($counts['completed']??0)];
        $totals['all'] = $totals['started'] + $totals['completed'];
        if ($status !== 'all') { $where .= ' AND f.status=:status'; $params['status'] = $status; }
        $total = $totals[$status];
        $page = min($page, max(1, (int)ceil($total/25)));
        $statement = $pdo->prepare("SELECT f.id,f.status,f.comment,f.actual_start_date,f.actual_end_date,d.full_name AS driver,d.vehicle_make_plate AS vehicle
            FROM flights f LEFT JOIN drivers d ON d.id=f.driver_id
            WHERE $where ORDER BY f.id DESC LIMIT 25 OFFSET :offset");
        foreach ($params as $key=>$value) $statement->bindValue(':'.$key, $value);
        $statement->bindValue(':offset', ($page - 1) * 25, PDO::PARAM_INT);
        $statement->execute();
        return ['rows'=>$statement->fetchAll(PDO::FETCH_ASSOC), 'total'=>$total, 'totals'=>$totals, 'status'=>$status, 'page'=>$page,
            'source_at'=>$pdo->query('SELECT MAX(fetched_at) FROM tracker_log')->fetchColumn() ?: null];
    }

    /** Resolve legacy manager IDs only for a unique active company membership with matching surname and first name. */
    public static function managerIds(PDO $source, PDO $central, int $companyId, int $membershipId): array
    {
        if ($companyId <= 0 || $membershipId <= 0) return [];
        $members = $central->prepare("SELECT m.id AS membership_id,a.full_name FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.company_id=? AND m.status='active' AND a.status='active'");
        $members->execute([$companyId]);
        $memberships = $members->fetchAll(PDO::FETCH_ASSOC);
        $member = null;
        foreach ($memberships as $candidate) {
            if ((int)$candidate['membership_id'] === $membershipId) { $member = $candidate; break; }
        }
        if ($member === null) return [];
        $parts = preg_split('/\s+/u', trim((string)$member['full_name']), -1, PREG_SPLIT_NO_EMPTY);
        $memberFirst = mb_strtolower(str_replace('ё','е', (string)($parts[1]??'')), 'UTF-8');
        if ($memberFirst === '') return [];
        $managers = $source->query("SELECT DISTINCT u.id,u.`Фамилия` AS surname,u.`Имя` AS first_name FROM flights f JOIN users u ON u.id=f.assigned_manager_id WHERE f.id>=244 AND f.status IN ('started','completed')")->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($managers as $manager) {
            if (WasteTripLegacyMappingService::manager((string)$manager['surname'], (string)$manager['first_name'], $memberships) !== $membershipId) continue;
            $first = mb_strtolower(str_replace('ё','е', trim((string)$manager['first_name'])), 'UTF-8');
            if ($first === '' || $first !== $memberFirst) continue;
            $ids[] = (int)$manager['id'];
        }
        return $ids;
    }

    /** @param list<int>|null $managerIds Null is reserved for the company owner. */
    public static function flight(PDO $pdo, int $id, int $minutes = 15, ?array $managerIds = null): array
    {
        if ($id < 244 || !in_array($minutes, [5,15,30,60], true)) throw new \InvalidArgumentException('Недопустимые параметры рейса.');
        $managerIds = $managerIds === null ? null : array_values(array_unique(array_filter(array_map('intval',$managerIds),static fn(int $id):bool=>$id>0)));
        if ($managerIds !== null && $managerIds === []) throw new \InvalidArgumentException('Рейс не найден.');
        $managerWhere = $managerIds === null ? '' : ' AND f.assigned_manager_id IN ('.implode(',', $managerIds).')';
        $stmt=$pdo->prepare("SELECT f.id,f.status,f.actual_start_date,f.actual_end_date,d.full_name AS driver,d.vehicle_make_plate AS vehicle
            FROM flights f LEFT JOIN drivers d ON d.id=f.driver_id WHERE f.id=:id AND f.status IN ('started','completed')$managerWhere LIMIT 1");
        $stmt->execute(['id'=>$id]);
        $flight=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$flight) throw new \InvalidArgumentException('Рейс не найден.');
        $base=['id'=>$id,'threshold_minutes'=>$minutes,'assessment'=>'unknown','pct'=>null,'gaps'=>[],'segments'=>[],'point_count'=>0];
        $startDay=(string)($flight['actual_start_date']??'');
        $endDay=(string)($flight['actual_end_date']??'');
        if (!self::dateValid($startDay) || ($flight['status']==='completed' && !self::dateValid($endDay))) return array_replace($base,['assessment'=>'missing_dates']);
        $moscow=new DateTimeZone(self::MOSCOW); $utc=new DateTimeZone(self::UTC);
        $start=(new DateTimeImmutable($startDay.' 00:00:00',$moscow))->setTimezone($utc);
        $end=$flight['status']==='completed'
            ? (new DateTimeImmutable($endDay.' 00:00:00',$moscow))->add(new DateInterval('P1D'))->setTimezone($utc)
            : new DateTimeImmutable('now',$utc);
        if ($end <= $start) return array_replace($base,['assessment'=>'missing_dates']);
        $seconds=$end->getTimestamp()-$start->getTimestamp();
        if ($seconds > 90*86400) return array_replace($base,['assessment'=>'long_interval','start_utc'=>$start->format('Y-m-d H:i:s'),'end_utc'=>$end->format('Y-m-d H:i:s')]);
        $bounds=$pdo->query('SELECT MIN(fetched_at) AS first_fetch, MAX(fetched_at) AS last_fetch FROM tracker_log')->fetch(PDO::FETCH_ASSOC);
        if (empty($bounds['first_fetch']) || $start->format('Y-m-d H:i:s') < $bounds['first_fetch'] ||
            ($flight['status']==='completed' && $end->format('Y-m-d H:i:s') > $bounds['last_fetch']))
            return array_replace($base,['assessment'=>'outside_log_window','start_utc'=>$start->format('Y-m-d H:i:s'),'end_utc'=>$end->format('Y-m-d H:i:s')]);
        $surname=self::surname((string)($flight['driver']??''));
        $plate=self::plate((string)($flight['vehicle']??''));
        if ($surname==='' || $plate==='') return array_replace($base,['assessment'=>'unmatched']);

        // fetched_at is indexed. The lower margin retains points immediately before trip start.
        $from=$start->sub(new DateInterval('PT60M'))->format('Y-m-d H:i:s');
        $to=$end->add(new DateInterval('PT5M'))->format('Y-m-d H:i:s');
        $query=$pdo->prepare('SELECT uniqueid,name,lastupdate,lat,lon,fetched_at FROM tracker_log FORCE INDEX (idx_fetched_at)
            WHERE fetched_at BETWEEN :from AND :to AND name LIKE :surname ORDER BY fetched_at LIMIT 50001');
        $query->execute(['from'=>$from,'to'=>$to,'surname'=>'%'.$surname.'%']);
        $devices=[]; $scanned=0;
        while ($row=$query->fetch(PDO::FETCH_ASSOC)) {
            if (++$scanned>50000) return array_replace($base,['assessment'=>'too_many_rows']);
            if (self::trackerKey((string)$row['name'])!==$surname.'|'.$plate) continue;
            if ($row['lat']===null || $row['lon']===null || $row['lat']==='' || $row['lon']==='' || empty($row['lastupdate'])) continue;
            $point=strtotime((string)$row['lastupdate'].' UTC');
            $fetched=strtotime((string)$row['fetched_at'].' UTC');
            if ($point===false || $fetched===false || $point>$fetched+300 || $point<$start->getTimestamp()-$minutes*60 || $point>=$end->getTimestamp()) continue;
            $devices[(string)$row['uniqueid']][$point]=true;
        }
        $active=array_filter($devices,static function(array $points)use($start,$end):bool {
            foreach($points as $time=>$_) if($time >= $start->getTimestamp() && $time < $end->getTimestamp()) return true;
            return false;
        });
        $selected=$active ?: $devices;
        if(count($selected)!==1) return array_replace($base,['assessment'=>$selected?'ambiguous':'unmatched']);
        $points=array_keys(reset($selected)); sort($points,SORT_NUMERIC);
        $spans=[]; $inside=0; $lastPoint=null;
        foreach($points as $point){
            $point=(int)$point;
            if($point >= $start->getTimestamp()){$inside++;$lastPoint=$point;}
            $a=max($start->getTimestamp(),$point);$b=min($end->getTimestamp(),$point+$minutes*60);
            if($a >= $b)continue;
            $n=count($spans)-1;
            if($n>=0 && $a<=$spans[$n][1])$spans[$n][1]=max($spans[$n][1],$b);
            else $spans[]=[$a,$b];
        }
        $gaps=[];$cursor=$start->getTimestamp();$covered=0;
        foreach($spans as [$a,$b]){
            if($a>$cursor)$gaps[]=self::gap($cursor,$a,$cursor===$start->getTimestamp()?'leading':'internal');
            $covered+=$b-$a;$cursor=$b;
        }
        if($cursor<$end->getTimestamp())$gaps[]=self::gap($cursor,$end->getTimestamp(),'trailing');
        return array_replace($base,['assessment'=>'calculated','pct'=>round(100*$covered/$seconds,1),'covered_seconds'=>$covered,
            'total_seconds'=>$seconds,'point_count'=>$inside,'last_point_utc'=>$lastPoint?gmdate('Y-m-d H:i:s',$lastPoint):null,
            'start_utc'=>$start->format('Y-m-d H:i:s'),'end_utc'=>$end->format('Y-m-d H:i:s'),
            'gaps'=>$gaps,'segments'=>array_map(static fn(array $s):array=>[gmdate('Y-m-d H:i:s',$s[0]),gmdate('Y-m-d H:i:s',$s[1])],$spans)]);
    }

    private static function gap(int $a,int $b,string $kind):array
    { return ['start_utc'=>gmdate('Y-m-d H:i:s',$a),'end_utc'=>gmdate('Y-m-d H:i:s',$b),'seconds'=>$b-$a,'kind'=>$kind]; }
    private static function dateValid(string $date):bool
    { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);return $d!==false && $d->format('Y-m-d')===$date; }
    private static function normalize(string $s):string
    { $s=mb_strtoupper(str_replace('Ё','Е',$s),'UTF-8');$s=strtr($s,['A'=>'А','B'=>'В','E'=>'Е','K'=>'К','M'=>'М','H'=>'Н','O'=>'О','P'=>'Р','C'=>'С','T'=>'Т','Y'=>'У','X'=>'Х']);return preg_replace('/[^А-Я0-9]/u','',$s)??''; }
    private static function surname(string $s):string
    { return self::normalize(preg_split('/\s+/u',trim($s))[0]??''); }
    private static function plate(string $s):string
    { return preg_match('/([А-ЯA-Z]\s*\d{3}\s*[А-ЯA-Z]{2}\s*\d{2,3})/iu',$s,$m)?self::normalize($m[1]):''; }
    private static function trackerKey(string $s):string
    { return preg_match('/^\s*([^(]+?)\s*\(([^)]+)\)/u',$s,$m)?self::surname($m[2]).'|'.self::normalize($m[1]):''; }
}
