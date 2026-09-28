<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class DocumentGeneratorWarehouseLoadingService
{
    /** @return array{address:string,request_ids:list<string>} */
    public static function resolve(PDO $legacy, array $trip, array $requestIds): array
    {
        $warehouseId = (int)($trip['legacy_source_warehouse_id'] ?? 0);
        if ($warehouseId <= 0 || $requestIds === []) return ['address'=>'', 'request_ids'=>[]];

        $flightId = (int)($trip['legacy_flight_id'] ?? $trip['business_id'] ?? 0);
        if ($flightId <= 0) throw new RuntimeException('Не найден номер рейса для проверки складской погрузки.');
        $found = [];
        foreach (array_chunk($requestIds, 300) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $query = $legacy->prepare(
                "SELECT DISTINCT zayavka_id FROM warehouse_movements
                  WHERE flight_id=? AND warehouse_id=? AND status='active'
                    AND movement_type IN ('issue','transfer_out') AND zayavka_id IN ($marks) ORDER BY zayavka_id"
            );
            $query->execute([$flightId, $warehouseId, ...$chunk]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) $found[(string)$id] = true;
        }
        if ($found === []) return ['address'=>'', 'request_ids'=>[]];

        $columns = $legacy->query('SHOW COLUMNS FROM warehouses')->fetchAll(PDO::FETCH_COLUMN);
        $addressColumn = null;
        foreach (['full_address', 'address', 'warehouse_address', 'adres', 'adres_sklada', 'location_address', 'physical_address', 'name'] as $candidate) {
            if (in_array($candidate, $columns, true)) { $addressColumn = $candidate; break; }
        }
        if ($addressColumn === null) throw new RuntimeException('В карточке склада не найден адрес для договора перевозчика.');
        $warehouse = $legacy->prepare('SELECT `'.$addressColumn.'` FROM warehouses WHERE id=? LIMIT 1');
        $warehouse->execute([$warehouseId]);
        $address = trim((string)$warehouse->fetchColumn());
        if ($address === '') throw new RuntimeException('Не заполнен адрес склада-источника выбранного рейса.');
        return ['address'=>$address, 'request_ids'=>array_map('strval', array_keys($found))];
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
