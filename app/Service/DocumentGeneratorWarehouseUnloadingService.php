<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class DocumentGeneratorWarehouseUnloadingService
{
    public static function resolve(PDO $legacy, array $trip): ?string
    {
        $warehouseId = (int)($trip['legacy_destination_warehouse_id'] ?? 0);
        $isWarehouse = in_array((string)($trip['legacy_route_type'] ?? ''), ['generator_to_warehouse', 'warehouse_to_warehouse'], true)
            || (string)($trip['legacy_unload_type'] ?? '') === 'SKLAD';
        if (!$isWarehouse) return null;
        if ($warehouseId <= 0) throw new RuntimeException('Не указан склад выгрузки выбранного рейса.');

        $flightId = (int)($trip['legacy_flight_id'] ?? $trip['business_id'] ?? 0);
        if ($flightId <= 0) throw new RuntimeException('Не найден номер рейса для проверки складской выгрузки.');
        $query = $legacy->prepare('SELECT destination_warehouse_id FROM flights WHERE id=? LIMIT 1');
        $query->execute([$flightId]);
        $actualId = $query->fetchColumn();
        if ($actualId === false || (int)$actualId !== $warehouseId) {
            throw new RuntimeException('Склад выгрузки рейса отличается от данных ERP.');
        }
        return DocumentGeneratorWarehouseLoadingService::warehouseAddress($legacy, $warehouseId);
    }

    public static function apply(array $rows, string $address): array
    {
        foreach ($rows as &$row) $row['document_unloading_address'] = $address;
        unset($row);
        return $rows;
    }
}
