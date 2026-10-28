<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class DocumentGeneratorWarehouseLoadingService
{
    /** @return array{address:string,request_ids:list<string>,trip_request_ids?:list<string>,ignored_request_ids?:list<string>} */
    public static function resolve(PDO $legacy, array $trip, array $requestIds): array
    {
        $warehouseId = (int)($trip['legacy_source_warehouse_id'] ?? 0);
        if ($warehouseId <= 0 || $requestIds === []) return ['address'=>'', 'request_ids'=>[]];

        $flightId = (int)($trip['legacy_flight_id'] ?? $trip['business_id'] ?? 0);
        if ($flightId <= 0) throw new RuntimeException('Не найден номер рейса для проверки складской погрузки.');
        $flightQuery = $legacy->prepare('SELECT route_type,source_warehouse_id,zayavki_ids,direct_zayavki_ids FROM flights WHERE id=? LIMIT 1');
        $flightQuery->execute([$flightId]);
        $flight = $flightQuery->fetch(PDO::FETCH_ASSOC);
        if (!$flight) throw new RuntimeException('Не найден рейс в старой базе для проверки складской погрузки.');
        if ((int)$flight['source_warehouse_id'] !== $warehouseId) {
            throw new RuntimeException('Склад-источник рейса отличается от склада в ERP.');
        }
        if ((string)($trip['legacy_route_type'] ?? '') === 'mixed_to_utilizer'
            && (string)$flight['route_type'] !== 'mixed_to_utilizer') {
            throw new RuntimeException('Тип смешанного рейса отличается от данных ERP.');
        }
        if ((string)$flight['route_type'] === 'mixed_to_utilizer') {
            $all = self::requestIds((string)$flight['zayavki_ids']);
            $direct = self::requestIds((string)$flight['direct_zayavki_ids']);
            if ($all === [] || array_diff($direct, $all) !== []) {
                throw new RuntimeException('Некорректный состав заявок смешанного рейса в старой базе.');
            }
            $ignored = array_values(array_diff($requestIds, $all));
            $found = array_values(array_diff($all, $direct));
            if ($found === []) return ['address'=>'', 'request_ids'=>[], 'trip_request_ids'=>$all, 'ignored_request_ids'=>$ignored];
            $address = self::warehouseAddress($legacy, $warehouseId);
            return ['address'=>$address, 'request_ids'=>$found, 'trip_request_ids'=>$all, 'ignored_request_ids'=>$ignored];
        }
        $found = [];
        foreach (array_chunk($requestIds, 300) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $query = $legacy->prepare(
                "SELECT zayavka_id FROM warehouse_movements
                  WHERE warehouse_id=? AND status='active' AND zayavka_id IN ($marks)
                  GROUP BY zayavka_id
                  HAVING SUM(CASE
                      WHEN movement_type IN ('receipt','transfer_in') THEN COALESCE(mass_netto,0)
                      WHEN movement_type IN ('issue','transfer_out') THEN -COALESCE(mass_netto,0)
                      ELSE 0 END) > 0
                    OR MAX(CASE WHEN flight_id=? AND movement_type IN ('issue','transfer_out') THEN 1 ELSE 0 END) > 0
                  ORDER BY zayavka_id"
            );
            $query->execute([$warehouseId, ...$chunk, $flightId]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) $found[(string)$id] = true;
        }
        if ($found === []) return ['address'=>'', 'request_ids'=>[]];

        $address = self::warehouseAddress($legacy, $warehouseId);
        return ['address'=>$address, 'request_ids'=>array_map('strval', array_keys($found))];
    }

    /** @return list<string> */
    private static function requestIds(string $list): array
    {
        return array_values(array_unique(preg_split('/[\s,;]+/u', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    public static function warehouseAddress(PDO $legacy, int $warehouseId): string
    {
        $columns = $legacy->query('SHOW COLUMNS FROM warehouses')->fetchAll(PDO::FETCH_COLUMN);
        $addressColumn = null;
        foreach (['full_address', 'address', 'warehouse_address', 'adres', 'adres_sklada', 'location_address', 'physical_address'] as $candidate) {
            if (in_array($candidate, $columns, true)) { $addressColumn = $candidate; break; }
        }
        if ($addressColumn === null) throw new RuntimeException('В карточке склада не найден адрес для документа.');
        $warehouse = $legacy->prepare('SELECT `'.$addressColumn.'` FROM warehouses WHERE id=? LIMIT 1');
        $warehouse->execute([$warehouseId]);
        $address = trim((string)$warehouse->fetchColumn());
        if ($address === '') throw new RuntimeException('Не заполнен адрес склада выбранного рейса.');
        return $address;
    }

    public static function apply(array $rows, array $loading): array
    {
        $ids = array_fill_keys($loading['request_ids'] ?? [], true);
        $address = (string)($loading['address'] ?? '');
        foreach ($rows as &$row) {
            if (!isset($ids[(string)($row['zayavka_id'] ?? '')])) continue;
            $row['document_loading_address'] = $address;
            $row['contacts'] = '—';
        }
        unset($row);
        return $rows;
    }
}
