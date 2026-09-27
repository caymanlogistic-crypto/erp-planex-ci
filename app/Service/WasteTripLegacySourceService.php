<?php

namespace App\Service;

use DateTimeImmutable;
use PDO;
use RuntimeException;

require_once __DIR__.'/WasteTripLegacyMetadataService.php';

final class WasteTripLegacySourceService
{
    public const SOURCE_SYSTEM = 'legacy_flights';
    public const PREFILL_SOURCE_SYSTEM = 'legacy_prefill';
    public const MIN_LEGACY_ID = 244;
    public const MIN_SYNCHRONIZABLE_ID = 226;

    public static function connectionFromEnvironment(): ?PDO
    {
        $host = trim((string) env('LEGACY_FLIGHTS_DB_HOST', ''));
        $database = trim((string) env('LEGACY_FLIGHTS_DB_DATABASE', ''));
        $username = trim((string) env('LEGACY_FLIGHTS_DB_USERNAME', ''));
        if ($host === '' || $database === '' || $username === '') return null;
        $port = trim((string) env('LEGACY_FLIGHTS_DB_PORT', '3306')) ?: '3306';
        $charset = trim((string) env('LEGACY_FLIGHTS_DB_CHARSET', 'utf8mb4')) ?: 'utf8mb4';
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset={$charset}", $username, (string) env('LEGACY_FLIGHTS_DB_PASSWORD', ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        return $pdo;
    }

    public static function fetchFlight(PDO $legacyPdo, int $legacyId): ?array
    {
        if($legacyId<self::MIN_SYNCHRONIZABLE_ID)throw new RuntimeException('Legacy ID должен быть больше 225.');
        $stmt=$legacyPdo->prepare(self::baseSql($legacyPdo).' WHERE f.id=? LIMIT 1');$stmt->execute([$legacyId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);return$row===false?null:$row;
    }

    public static function maxLegacyId(PDO $legacyPdo): int
    { return(int)$legacyPdo->query('SELECT COALESCE(MAX(id),0) FROM flights')->fetchColumn(); }

    public static function hasFlightId(PDO $legacyPdo,int $legacyId): bool
    { $stmt=$legacyPdo->prepare('SELECT 1 FROM flights WHERE id=? LIMIT 1');$stmt->execute([$legacyId]);return$stmt->fetchColumn()!==false; }

    public static function authoritativeState(array $flight): array
    {
        $businessId=(int)($flight['id']??0); if($businessId<self::MIN_SYNCHRONIZABLE_ID)throw new RuntimeException('Legacy ID должен быть больше 225.');
        $requestIds=self::parseRequestIdsStrict((string)($flight['zayavki_ids']??'')); if($requestIds===[])throw new RuntimeException('В legacy-рейсе отсутствует полный состав заявок ФЭО.');
        $exact=self::dateStrict($flight['planned_start_date']??null,'planned_start_date');$from=self::dateStrict($flight['planned_start_date_from']??null,'planned_start_date_from');$to=self::dateStrict($flight['planned_start_date_to']??null,'planned_start_date_to');
        if($exact!==null&&($from!==null||$to!==null))throw new RuntimeException('Legacy план содержит одновременно точную дату и диапазон.');
        if($from!==null&&$to!==null&&$from>$to)throw new RuntimeException('Legacy план содержит диапазон с началом позже окончания.');
        $mode=$exact!==null?'exact':(($from!==null||$to!==null)?'range':'unspecified');
        $actualStart=self::dateStrict($flight['actual_start_date']??null,'actual_start_date');$actualEnd=self::dateStrict($flight['actual_end_date']??null,'actual_end_date');
        if($actualStart!==null&&$actualEnd!==null&&$actualStart>$actualEnd)throw new RuntimeException('Legacy факт содержит начало позже окончания.');
        return ['business_id'=>$businessId,'comment'=>self::text($flight['comment']??null,500),'planned_start_mode'=>$mode,'planned_start_date'=>$exact,'planned_start_date_from'=>$from,'planned_start_date_to'=>$to,'planned_finish_date'=>null,'actual_start_date'=>$actualStart,'actual_end_date'=>$actualEnd,'request_ids'=>$requestIds] + WasteTripLegacyMetadataService::fromFlight($flight);
    }

    public static function parseRequestIdsStrict(string $raw): array
    {
        $parts=preg_split('/[\s,;]+/u',trim($raw),-1,PREG_SPLIT_NO_EMPTY)?:[];$seen=[];$ids=[];
        foreach($parts as$part){$id=trim((string)$part);if($id===''||preg_match('/^[0-9]+$/D',$id)!==1)throw new RuntimeException('Legacy zayavki_ids содержит некорректный ID: '.($id!==''?$id:'[empty]').'.');if(isset($seen[$id]))throw new RuntimeException('Legacy zayavki_ids содержит дубликат заявки '.$id.'.');$seen[$id]=true;$ids[]=$id;}
        return$ids;
    }

    private static function baseSql(PDO $legacyPdo): string
    { return 'SELECT f.id,f.zayavki_ids,f.comment,f.planned_start_date,f.planned_start_date_from,f.planned_start_date_to,f.actual_start_date,f.actual_end_date'.WasteTripLegacyMetadataService::sourceSql($legacyPdo).' FROM flights f'.WasteTripLegacyMetadataService::joins(); }

    private static function dateStrict(mixed $value,string $field): ?string
    {
        $value=trim((string)$value);if($value===''||$value==='0000-00-00')return null;if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)!==1)throw new RuntimeException('Legacy '.$field.' имеет некорректный формат даты.');
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$value);$errors=DateTimeImmutable::getLastErrors();if($dt===false||($errors!==false&&(($errors['warning_count']??0)>0||($errors['error_count']??0)>0))||$dt->format('Y-m-d')!==$value)throw new RuntimeException('Legacy '.$field.' содержит невозможную календарную дату.');return$value;
    }

    private static function text(mixed $value,int $max): ?string
    { $value=trim((string)$value);if($value==='')return null;return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max); }
}
