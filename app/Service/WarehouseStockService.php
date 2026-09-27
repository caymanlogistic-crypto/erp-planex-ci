<?php

namespace App\Service;

use PDO;
use RuntimeException;

/**
 * Read-only warehouse ledger sourced from the legacy flight movements.
 * All balances are integer kilograms to avoid cumulative float drift.
 */
final class WarehouseStockService
{
    public static function load(PDO $legacy, PDO $tenant): array
    {
        $warehouses = $legacy->query('SELECT id,name FROM warehouses ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC);
        $movements = $legacy->query(
            "SELECT id,movement_type,warehouse_id,flight_id,zayavka_id,mass_netto,movement_date,status
               FROM warehouse_movements
              WHERE status='active'
              ORDER BY movement_date,id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $flights = $legacy->query(
            'SELECT f.id,f.comment,f.status,f.route_type,f.source_warehouse_id,f.destination_warehouse_id,
                    f.actual_end_date,f.zayavki_ids,d.full_name AS driver_name,d.vehicle_make_plate AS vehicle
               FROM flights f LEFT JOIN drivers d ON d.id=f.driver_id
              ORDER BY f.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_values(array_unique(array_map('intval', array_column($flights, 'id'))));
        $erp = [];
        if ($ids !== []) {
            foreach (array_chunk($ids, 300) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $query = $tenant->prepare(
                    "SELECT wt.business_id,wt.legacy_source_warehouse_id,wt.legacy_destination_warehouse_id,
                            fr.zayavka_id,wtr.snapshot_mass_kg
                       FROM waste_trips wt
                       LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id
                       LEFT JOIN feo_requests fr ON fr.id=wtr.feo_request_id
                      WHERE wt.deleted_at IS NULL AND wt.business_id IN ($marks)
                      ORDER BY wt.business_id,wtr.position_no,wtr.id"
                );
                $query->execute($chunk);
                array_push($erp, ...$query->fetchAll(PDO::FETCH_ASSOC));
            }
        }
        $erpOnly = $tenant->query(
            'SELECT wt.business_id,wt.legacy_source_warehouse_id,wt.legacy_destination_warehouse_id,
                    fr.zayavka_id,wtr.snapshot_mass_kg
               FROM waste_trips wt
               LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id
               LEFT JOIN feo_requests fr ON fr.id=wtr.feo_request_id
              WHERE wt.deleted_at IS NULL
                AND (wt.legacy_source_warehouse_id IS NOT NULL OR wt.legacy_destination_warehouse_id IS NOT NULL)
              ORDER BY wt.business_id,wtr.position_no,wtr.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $erp = array_merge($erp, $erpOnly);
        return self::build($warehouses, $movements, $flights, $erp);
    }

    public static function build(array $warehouses, array $movements, array $flights, array $erpRows): array
    {
        $names = [];
        foreach ($warehouses as $row) $names[(int)$row['id']] = (string)$row['name'];
        $flightMap = [];
        foreach ($flights as $row) $flightMap[(int)$row['id']] = $row;
        usort($movements, static fn(array $a, array $b): int =>
            strcmp((string)$a['movement_date'], (string)$b['movement_date']) ?: ((int)$a['id'] <=> (int)$b['id']));
        $balances = $groups = $movementFlightIds = [];
        foreach ($movements as $row) {
            if (($row['status'] ?? 'active') !== 'active') continue;
            $type = (string)$row['movement_type'];
            $sign = match ($type) { 'receipt','transfer_in' => 1, 'issue','transfer_out' => -1, default => 0 };
            if ($sign === 0) throw new RuntimeException('Неизвестный тип складского движения.');
            $warehouseId = (int)$row['warehouse_id'];
            $flightId = (int)$row['flight_id'];
            $requestId = (string)$row['zayavka_id'];
            if ($warehouseId <= 0 || $flightId <= 0 || $requestId === '' || !isset($flightMap[$flightId])) {
                throw new RuntimeException('Складское движение не связано с корректным рейсом.');
            }
            $weight = (int)round((float)$row['mass_netto'] * 1000);
            if ($weight < 0) throw new RuntimeException('Отрицательная масса складского движения.');
            $before = $balances[$warehouseId][$requestId] ?? 0;
            $after = $before + $sign * $weight;
            $balances[$warehouseId][$requestId] = $after;
            $direction = $sign > 0 ? 'in' : 'out';
            $key = $warehouseId.':'.$flightId.':'.$direction;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'=>$key,'flight_id'=>$flightId,'warehouse_id'=>$warehouseId,
                    'direction'=>$direction,'date'=>(string)$row['movement_date'],
                    'requests'=>[],'mass_kg'=>0,'stock_count'=>0,'stock_kg'=>0,
                ];
            }
            $group =& $groups[$key];
            $group['date'] = max($group['date'], (string)$row['movement_date']);
            $group['requests'][] = ['id'=>$requestId,'mass_kg'=>$weight,'before_kg'=>$before,'after_kg'=>$after];
            $group['mass_kg'] += $weight;
            $group['stock_count'] = count(array_filter($balances[$warehouseId], static fn(int $value): bool => $value > 0));
            $group['stock_kg'] = array_sum($balances[$warehouseId]);
            unset($group);
            $movementFlightIds[$flightId] = true;
        }
        $current = [];
        foreach ($balances as $warehouseId => $requestBalances) {
            $current[$warehouseId] = [
                'request_count'=>count(array_filter($requestBalances, static fn(int $value): bool => $value > 0)),
                'weight_kg'=>array_sum($requestBalances),
            ];
        }
        foreach ($groups as &$group) {
            $flight = $flightMap[$group['flight_id']];
            $group['name'] = trim((string)($flight['comment'] ?? '')) ?: '—';
            $group['driver'] = trim((string)($flight['driver_name'] ?? '')) ?: '—';
            $group['vehicle'] = trim((string)($flight['vehicle'] ?? '')) ?: '—';
            $group['route_type'] = (string)($flight['route_type'] ?? '');
            $group['flight_status'] = (string)($flight['status'] ?? '');
            $group['actual_end_date'] = $flight['actual_end_date'] ?? null;
            $group['request_ids'] = array_values(array_unique(array_column($group['requests'], 'id')));
            $group['request_count'] = count($group['request_ids']);
        }
        unset($group);
        $byWarehouse = [];
        foreach ($groups as $group) $byWarehouse[$group['warehouse_id']][] = $group;
        foreach ($byWarehouse as &$items) {
            usort($items, static fn(array $a, array $b): int =>
                strcmp($b['date'], $a['date']) ?: $b['flight_id'] <=> $a['flight_id']);
        }
        unset($items);
        uksort($byWarehouse, static fn(int $a, int $b): int =>
            strnatcasecmp($names[$a] ?? '', $names[$b] ?? ''));

        $erp = [];
        foreach ($erpRows as $row) {
            $id = (int)$row['business_id'];
            if ($id <= 0) continue;
            if (!isset($erp[$id])) {
                $erp[$id] = [
                    'source'=>(int)($row['legacy_source_warehouse_id'] ?? 0),
                    'destination'=>(int)($row['legacy_destination_warehouse_id'] ?? 0),
                    'requests'=>[], 'mass_kg'=>0,
                ];
            }
            if (($row['zayavka_id'] ?? null) !== null && !in_array((string)$row['zayavka_id'], $erp[$id]['requests'], true)) {
                $erp[$id]['requests'][] = (string)$row['zayavka_id'];
                $erp[$id]['mass_kg'] += (int)round((float)($row['snapshot_mass_kg'] ?? 0));
            }
        }
        $issues = [];
        foreach ($flightMap as $flightId => $flight) {
            $oldSource = (int)($flight['source_warehouse_id'] ?? 0);
            $oldDestination = (int)($flight['destination_warehouse_id'] ?? 0);
            if ($oldSource <= 0 && $oldDestination <= 0 && !isset($movementFlightIds[$flightId])) continue;
            $messages = [];
            if (!isset($erp[$flightId])) {
                $messages[] = 'Рейс отсутствует в ERP.';
            } else {
                if ($oldSource !== $erp[$flightId]['source'] || $oldDestination !== $erp[$flightId]['destination']) {
                    $messages[] = 'Склад рейса в ERP отличается от старой базы.';
                }
                $legacyRequests = preg_split('/[\s,;]+/u', trim((string)($flight['zayavki_ids'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $onlyOld = array_diff(array_unique($legacyRequests), array_unique($erp[$flightId]['requests']));
                $onlyErp = array_diff(array_unique($erp[$flightId]['requests']), array_unique($legacyRequests));
                if ($onlyOld !== [] || $onlyErp !== []) {
                    $messages[] = 'Состав заявок: только в старой базе '.(implode(', ', $onlyOld) ?: '—')
                        .'; только в ERP '.(implode(', ', $onlyErp) ?: '—').'.';
                }
                $directionMass = ['in'=>0, 'out'=>0];
                foreach ($groups as $group) {
                    if ($group['flight_id'] === $flightId) $directionMass[$group['direction']] += $group['mass_kg'];
                }
                $flightMass = max($directionMass);
                if ($flightMass > 0 && abs($flightMass - $erp[$flightId]['mass_kg']) > 1) {
                    $messages[] = 'Масса заявок: старая база '.$flightMass.' кг, ERP '.$erp[$flightId]['mass_kg'].' кг.';
                }
            }
            if ($messages !== []) {
                $issues[$flightId] = [
                    'flight_id'=>$flightId,'warehouse_id'=>$oldDestination ?: $oldSource,
                    'messages'=>$messages,
                    'has_movements'=>isset($movementFlightIds[$flightId]),
                ];
            }
        }
        foreach ($erp as $flightId => $row) {
            if (isset($flightMap[$flightId]) || ($row['source'] <= 0 && $row['destination'] <= 0)) continue;
            $issues[$flightId] = [
                'flight_id'=>$flightId,
                'warehouse_id'=>$row['destination'] ?: $row['source'],
                'messages'=>['Складской рейс есть в ERP, но отсутствует в старой базе.'],
                'has_movements'=>false,
            ];
        }
        krsort($issues, SORT_NUMERIC);
        $correctionFlights = [];
        foreach ($flightMap as $flight) $correctionFlights[] = [
            'id'=>(int)$flight['id'], 'name'=>(string)($flight['comment'] ?? ''),
        ];
        usort($correctionFlights, static fn(array $a, array $b): int => $b['id'] <=> $a['id']);
        return ['warehouses'=>$names,'groups'=>$byWarehouse,'current'=>$current,'issues'=>$issues,'issue_count'=>count($issues),'correction_flights'=>$correctionFlights];
    }
}
