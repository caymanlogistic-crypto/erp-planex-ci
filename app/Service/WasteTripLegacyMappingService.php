<?php

namespace App\Service;

use RuntimeException;

/** Mapping of the current legacy flights feed, shared by reconciliation and tests. */
final class WasteTripLegacyMappingService
{
    public const MIN_ID = 226;
    public const SOURCE_SYSTEM = 'legacy_sync';

    public static function status(string $legacy): string
    {
        return match ($legacy) {
            'planned_route', 'search', 'attention' => WasteTripService::STATUS_PLANNED,
            'found' => WasteTripService::STATUS_ASSIGNED,
            'started' => WasteTripService::STATUS_IN_PROGRESS,
            'completed' => WasteTripService::STATUS_COMPLETED,
            default => throw new RuntimeException('Неизвестный статус старого рейса: ' . $legacy),
        };
    }

    public static function surname(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $parts = preg_split('/\s+/u', str_replace('ё', 'е', $name), -1, PREG_SPLIT_NO_EMPTY);
        return $parts[0] ?? '';
    }

    /** Resolve an active central membership, never a coincidentally equal legacy user ID. */
    public static function manager(string $surname, string $firstName, array $memberships): ?int
    {
        $normalize = static fn(string $value): string => str_replace('ё', 'е', mb_strtolower(trim($value), 'UTF-8'));
        $surname = self::surname($surname); $firstName = $normalize($firstName);
        // Owner-confirmed spelling difference between the two systems.
        if ($surname === 'гладских' && $firstName === 'карина') $surname = 'гладких';
        if ($surname === '') return null;
        $matches = [];
        foreach ($memberships as $membership) {
            if (self::surname((string)($membership['full_name'] ?? '')) === $surname) {
                $id = (int)($membership['membership_id'] ?? 0);
                if ($id > 0) $matches[$id] = true;
            }
        }
        return count($matches) === 1 ? (int)array_key_first($matches) : null;
    }

    public static function plates(string $text): array
    {
        $text = strtr(mb_strtoupper($text, 'UTF-8'), [
            'А'=>'A','В'=>'B','Е'=>'E','К'=>'K','М'=>'M','Н'=>'H',
            'О'=>'O','Р'=>'P','С'=>'C','Т'=>'T','У'=>'Y','Х'=>'X',
        ]);
        // Match the plate itself, not vehicle-model digits; preserve the region.
        preg_match_all('/(?<![A-Z0-9])([ABEKMHOPCTYX]\s*\d{3}\s*[ABEKMHOPCTYX]{2}\s*\d{2,3})(?![A-Z0-9])/u', $text, $matches);
        return array_values(array_unique(array_map(static fn(string $plate): string => preg_replace('/\s+/u', '', $plate), $matches[1])));
    }

    public static function customer(array $clients): ?int
    {
        $matches = [];
        foreach ($clients as $client) {
            $name = FeoUtilizerService::normalizeText((string)($client['name'] ?? ''));
            if (in_array($name, ['мг логистик', 'ооо мг логистик'], true)) $matches[(int)$client['id']] = true;
        }
        return count($matches) === 1 ? (int)array_key_first($matches) : null;
    }

    /** candidates: crew_id, full_name (one row per driver), primary_plate. */
    public static function executor(array $flight, array $candidates): array
    {
        $surname = self::surname((string)($flight['driver_name'] ?? ''));
        $plates = self::plates((string)($flight['vehicle_make_plate'] ?? ''));
        if (empty($flight['driver_id'])) return ['id'=>null, 'warning'=>null];
        if ($surname === '' || count($plates) !== 1) {
            return ['id'=>null, 'warning'=>'Не удалось определить фамилию или единственный госномер водителя старого рейса.'];
        }
        $matches = [];
        foreach ($candidates as $candidate) {
            if (self::surname((string)$candidate['full_name']) === $surname
                && self::plates((string)$candidate['primary_plate']) === $plates) {
                $matches[(int)$candidate['crew_id']] = true;
            }
        }
        if (count($matches) === 1) return ['id'=>(int)array_key_first($matches), 'warning'=>null];
        return ['id'=>null, 'warning'=> $matches === []
            ? 'Исполнитель не найден по фамилии и госномеру.'
            : 'Найдено несколько исполнителей с этой фамилией и госномером.'];
    }

    public static function state(array $flight): array
    {
        $id = filter_var($flight['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < self::MIN_ID) throw new RuntimeException('ID старого рейса должен быть больше 225.');
        $requests = WasteTripLegacySourceService::parseRequestIdsStrict((string)($flight['zayavki_ids'] ?? ''));
        if ($requests === []) throw new RuntimeException('В старом рейсе не указаны заявки ФЭО.');
        $state = ['business_id'=>$id, 'request_ids'=>$requests, 'status'=>self::status((string)($flight['status'] ?? ''))];
        foreach (['planned_start_date','planned_start_date_from','planned_start_date_to','actual_start_date','actual_end_date'] as $field) {
            $raw = trim((string)($flight[$field] ?? ''));
            if ($raw === '' || $raw === '0000-00-00') { $state[$field] = null; continue; }
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $raw, $parts)
                || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
                throw new RuntimeException('Некорректная дата в старом рейсе: ' . $field);
            }
            $state[$field] = $raw;
        }
        $exact = $state['planned_start_date']; $from = $state['planned_start_date_from']; $to = $state['planned_start_date_to'];
        if ($exact !== null && ($from !== null || $to !== null)) throw new RuntimeException('В старом рейсе одновременно указаны точная дата и диапазон.');
        // The actual feed uses open ranges ("from" only); preserve that meaning.
        if ($from !== null && $to !== null && $from > $to) throw new RuntimeException('Начало диапазона позже окончания.');
        $state['planned_start_mode'] = $exact !== null ? 'exact' : ($from !== null || $to !== null ? 'range' : 'unspecified');
        if ($state['actual_start_date'] !== null && $state['actual_end_date'] !== null && $state['actual_start_date'] > $state['actual_end_date']) {
            throw new RuntimeException('Фактическое начало рейса позже окончания.');
        }
        $state['comment'] = mb_substr(trim((string)($flight['comment'] ?? '')), 0, 500, 'UTF-8') ?: null;
        $rawCost = trim((string)($flight['cost'] ?? ''));
        if ($rawCost !== '' && (!preg_match('/^\d{1,10}$/D', $rawCost))) throw new RuntimeException('Некорректная стоимость перевозчика в старом рейсе.');
        $state['carrier_cost'] = $rawCost === '' ? null : (string)(int)$rawCost . '.00';
        $state['payment_terms'] = trim((string)($flight['payment_terms'] ?? '')) ?: null;
        return $state;
    }
}
