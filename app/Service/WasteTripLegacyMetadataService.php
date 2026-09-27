<?php

namespace App\Service;

use PDO;
use RuntimeException;

final class WasteTripLegacyMetadataService
{
    public const COLUMNS = ['legal_entity_code', 'legacy_route_type', 'legacy_unload_type', 'legacy_source_warehouse_id', 'legacy_destination_warehouse_id', 'legacy_source_warehouse_name', 'legacy_destination_warehouse_name'];
    private static ?\WeakMap $sourceSqlCache = null;

    public static function sourceSql(PDO $legacy): string
    {
        self::$sourceSqlCache ??= new \WeakMap();
        if (isset(self::$sourceSqlCache[$legacy])) return self::$sourceSqlCache[$legacy];
        $columns = $legacy->query('SHOW COLUMNS FROM warehouses')->fetchAll(PDO::FETCH_COLUMN);
        $name = null;
        foreach (['name', 'title', 'warehouse_name', 'label'] as $candidate) {
            if (in_array($candidate, $columns, true)) { $name = $candidate; break; }
        }
        if ($name === null) throw new RuntimeException('В старой БД не найдено название склада.');
        return self::$sourceSqlCache[$legacy] = ',f.legal_entity_code,f.route_type AS legacy_route_type,f.unload_type AS legacy_unload_type,'
            .'f.source_warehouse_id AS legacy_source_warehouse_id,f.destination_warehouse_id AS legacy_destination_warehouse_id,'
            .'ws.`'.$name.'` AS legacy_source_warehouse_name,wd.`'.$name.'` AS legacy_destination_warehouse_name';
    }

    public static function joins(): string
    {
        return ' LEFT JOIN warehouses ws ON ws.id=f.source_warehouse_id LEFT JOIN warehouses wd ON wd.id=f.destination_warehouse_id';
    }

    public static function fromFlight(array $flight): array
    {
        $code = trim((string)($flight['legal_entity_code'] ?? ''));
        if ($code !== '' && !in_array($code, ['planex', 'mg_logistic'], true)) throw new RuntimeException('Неизвестное юридическое лицо рейса.');
        $route = trim((string)($flight['legacy_route_type'] ?? ''));
        $unload = trim((string)($flight['legacy_unload_type'] ?? $flight['unload_type'] ?? ''));
        $result = ['legal_entity_code'=>$code ?: null, 'legacy_route_type'=>$route ?: null, 'legacy_unload_type'=>$unload ?: null];
        foreach (['source', 'destination'] as $side) {
            $id = (int)($flight['legacy_'.$side.'_warehouse_id'] ?? 0);
            $result['legacy_'.$side.'_warehouse_id'] = $id > 0 ? $id : null;
            $name = trim((string)($flight['legacy_'.$side.'_warehouse_name'] ?? ''));
            $result['legacy_'.$side.'_warehouse_name'] = $name !== '' ? $name : null;
        }
        return $result;
    }

    public static function companyName(?string $code): ?string
    {
        return match ($code) { 'planex'=>'ООО «Планэкс»', 'mg_logistic'=>'ООО «МГ Логистик»', default=>null };
    }

    public static function warehouseLabel(array $trip): ?string
    {
        $source = trim((string)($trip['legacy_source_warehouse_name'] ?? '')) ?: 'название не указано';
        $destination = trim((string)($trip['legacy_destination_warehouse_name'] ?? '')) ?: 'название не указано';
        return match ((string)($trip['legacy_route_type'] ?? '')) {
            'generator_to_warehouse' => "Выгрузка на склад:\n".$destination,
            'warehouse_to_warehouse' => "Загрузка со склада:\n".$source."\nВыгрузка на склад:\n".$destination,
            'warehouse_to_utilizer' => "Загрузка со склада:\n".$source,
            default => ($trip['legacy_unload_type'] ?? null) === 'SKLAD' ? "Выгрузка на склад:\n".$destination : null,
        };
    }
}
