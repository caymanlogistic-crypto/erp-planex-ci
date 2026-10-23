<?php

namespace App\Service;

use PDO;

require_once __DIR__.'/WarehouseStockService.php';

/** Read-only trip diagnostics built on the existing warehouse stock projection. */
final class WasteTripActionConflictService
{
    public static function load(PDO $legacy, PDO $tenant, ?array $stock = null): array
    {
        $flights = $legacy->query(
            "SELECT id,status,route_type,source_warehouse_id,destination_warehouse_id,
                    zayavki_ids,direct_zayavki_ids
               FROM flights WHERE id > 225 ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
        return self::analyze($flights, $stock ?? WarehouseStockService::load($legacy, $tenant));
    }

    /** @return array<int, list<string>> Messages keyed by business flight ID. */
    public static function analyze(array $flights, array $stock): array
    {
        $messages = [];
        $directPickups = $utilizerDeliveries = $warehouseDeliveries = [];
        $outgoing = $receiptWarehouses = [];
        foreach ($stock['groups'] ?? [] as $groups) {
            foreach ($groups as $group) {
                foreach ($group['requests'] ?? [] as $request) {
                    if (!empty($request['excluded'])) continue;
                    $id = (int)$group['flight_id'];
                    $warehouseId = (int)$group['warehouse_id'];
                    $requestId = (string)$request['id'];
                    if (($group['direction'] ?? '') === 'out') $outgoing[$id][$warehouseId][$requestId] = $request;
                    elseif (($group['direction'] ?? '') === 'in') $receiptWarehouses[$id][$requestId][$warehouseId] = true;
                }
            }
        }

        foreach ($flights as $flight) {
            $id = (int)($flight['id'] ?? 0);
            if ($id <= 225 || !in_array((string)($flight['status'] ?? ''), ['started', 'completed'], true)) continue;
            $route = (string)($flight['route_type'] ?? '');
            $all = self::requestIds((string)($flight['zayavki_ids'] ?? ''));
            $direct = match ($route) {
                'generator_to_warehouse', 'generator_to_utilizer' => $all,
                'mixed_to_utilizer' => self::requestIds((string)($flight['direct_zayavki_ids'] ?? '')),
                default => [],
            };
            foreach ($direct as $requestId) $directPickups[$requestId][$id] = true;
            if (in_array($route, ['generator_to_utilizer', 'warehouse_to_utilizer', 'mixed_to_utilizer'], true)) {
                foreach ($all as $requestId) $utilizerDeliveries[$requestId][$id] = true;
            }
            if (in_array($route, ['generator_to_warehouse', 'warehouse_to_warehouse'], true)) {
                $destination = (int)($flight['destination_warehouse_id'] ?? 0);
                if ($destination > 0) foreach ($all as $requestId) $warehouseDeliveries[$destination][$requestId][$id] = true;
            }
            if (!in_array($route, ['warehouse_to_warehouse', 'warehouse_to_utilizer', 'mixed_to_utilizer'], true)) continue;
            $warehouseId = (int)($flight['source_warehouse_id'] ?? 0);
            if ($warehouseId <= 0) continue;
            $fromWarehouse = $route === 'mixed_to_utilizer' ? array_values(array_diff($all, $direct)) : $all;
            foreach ($fromWarehouse as $requestId) {
                $movement = $outgoing[$id][$warehouseId][$requestId] ?? null;
                if ($movement === null) {
                    self::add($messages, $id, 'Заявка '.$requestId.': не подтверждено списание со склада #'.$warehouseId.'. Проверьте остаток и складской рейс.');
                } elseif ((int)$movement['after_kg'] < 0) {
                    self::add($messages, $id, 'Заявка '.$requestId.': списание со склада #'.$warehouseId.' превышает остаток на '.abs((int)$movement['after_kg']).' кг.');
                }
            }
        }

        self::addPairWarnings($messages, $directPickups, 'Возможный повторный забор', $receiptWarehouses);
        self::addPairWarnings($messages, $utilizerDeliveries, 'Возможная повторная сдача утилизатору');
        foreach ($warehouseDeliveries as $warehouseId => $requests) {
            self::addPairWarnings($messages, $requests, 'Возможная повторная сдача на склад #'.$warehouseId, $receiptWarehouses);
        }

        // WarehouseStockService already decides when another receipt is a duplicate,
        // including its order, mass semantics and approved issue exceptions.
        foreach ($stock['duplicate_receipts'] ?? [] as $warehouseId => $requests) {
            foreach ($requests as $requestId => $duplicate) {
                foreach (array_keys($duplicate['repeats'] ?? []) as $id) {
                    $text = 'Повторное поступление заявки '.$requestId.'.';
                    $visible = false;
                    foreach ($stock['issues'][$id]['messages'] ?? [] as $issue) {
                        if (str_starts_with((string)$issue, $text)) { $visible = true; break; }
                    }
                    if (!$visible) continue;
                    self::add($messages, (int)$id, 'Заявка '.$requestId.': повторное поступление на склад #'.$warehouseId.' в рейсе #'.$id.' после рейса #'.(int)$duplicate['first'].'. Подробности — в складских остатках.');
                }
            }
        }
        ksort($messages, SORT_NUMERIC);
        return $messages;
    }

    private static function requestIds(string $list): array
    {
        return array_values(array_unique(preg_split('/[\s,;]+/u', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    private static function addPairWarnings(array &$messages, array $groups, string $label, array $receiptWarehouses = []): void
    {
        foreach ($groups as $requestId => $flights) {
            $ids = array_map('intval', array_keys($flights));
            if (count($ids) < 2) continue;
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                $others = array_values(array_filter($ids, static function (int $other) use ($id, $requestId, $receiptWarehouses): bool {
                    if ($other === $id) return false;
                    $first = $receiptWarehouses[$id][$requestId] ?? [];
                    $second = $receiptWarehouses[$other][$requestId] ?? [];
                    return $first === [] || $second === [] || array_intersect_key($first, $second) === [];
                }));
                if ($others === []) continue;
                self::add($messages, $id, $label.' заявки '.$requestId.' в рейсах #'.implode(', #', $others).'.');
            }
        }
    }

    private static function add(array &$messages, int $id, string $message): void
    {
        if ($id > 0 && !in_array($message, $messages[$id] ?? [], true)) $messages[$id][] = $message;
    }
}
