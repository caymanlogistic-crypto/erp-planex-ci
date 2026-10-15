<?php

namespace App\Service;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Read-only warehouse ledger sourced from the legacy flight movements.
 * All balances are integer kilograms to avoid cumulative float drift.
 */
final class WarehouseStockService
{
    private const LEGACY_CUTOVER_FLIGHT_ID = 225;

    public static function load(PDO $legacy, PDO $tenant): array
    {
        $warehouses = $legacy->query('SELECT id,name FROM warehouses ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC);
        $movements = $legacy->query(
            "SELECT id,movement_type,warehouse_id,flight_id,zayavka_id,mass_netto,movement_date,created_at,updated_at,status,comment
               FROM warehouse_movements
              WHERE status='active'
              ORDER BY created_at,id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $flights = $legacy->query(
            'SELECT f.id,f.comment,f.status,f.route_type,f.source_warehouse_id,f.destination_warehouse_id,
                    f.actual_start_date,f.actual_end_date,f.zayavki_ids,f.direct_zayavki_ids,d.full_name AS driver_name,d.vehicle_make_plate AS vehicle
               FROM flights f LEFT JOIN drivers d ON d.id=f.driver_id
              ORDER BY f.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_values(array_unique(array_map('intval', array_column($flights, 'id'))));
        $erp = [];
        if ($ids !== []) {
            foreach (array_chunk($ids, 300) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $query = $tenant->prepare(
                    "SELECT wt.business_id,wt.comment AS erp_name,wt.status,wt.actual_start_date,wt.legacy_source_warehouse_id,wt.legacy_destination_warehouse_id,
                            fr.zayavka_id,wtr.snapshot_mass_kg
                       FROM waste_trips wt
                       LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id AND wtr.removed_at IS NULL
                       LEFT JOIN feo_requests fr ON fr.id=wtr.feo_request_id
                      WHERE wt.deleted_at IS NULL AND wt.business_id IN ($marks)
                      ORDER BY wt.business_id,wtr.position_no,wtr.id"
                );
                $query->execute($chunk);
                array_push($erp, ...$query->fetchAll(PDO::FETCH_ASSOC));
            }
        }
        $erpOnly = $tenant->query(
            'SELECT wt.business_id,wt.comment AS erp_name,wt.status,wt.actual_start_date,wt.legacy_source_warehouse_id,wt.legacy_destination_warehouse_id,
                    fr.zayavka_id,wtr.snapshot_mass_kg
               FROM waste_trips wt
               LEFT JOIN waste_trip_requests wtr ON wtr.waste_trip_id=wt.id AND wtr.removed_at IS NULL
               LEFT JOIN feo_requests fr ON fr.id=wtr.feo_request_id
              WHERE wt.deleted_at IS NULL
                AND (wt.legacy_source_warehouse_id IS NOT NULL OR wt.legacy_destination_warehouse_id IS NOT NULL)
              ORDER BY wt.business_id,wtr.position_no,wtr.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $erp = array_merge($erp, $erpOnly);
        $exceptionsAvailable = true;
        try {
            $exceptions = $tenant->query('SELECT id,flight_id,message_hash,message_text,trip_name,warehouse_name,approved_by_user_id,approved_at,revoked_by_user_id,revoked_at FROM warehouse_stock_issue_exceptions ORDER BY approved_at DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1146) throw $error;
            $exceptions = [];
            $exceptionsAvailable = false;
        }
        $data = self::build($warehouses, $movements, $flights, $erp, $exceptions);
        $data['exceptions_available'] = $exceptionsAvailable;
        return $data;
    }

    public static function messageHash(string $message): string
    {
        return hash('sha256', $message);
    }

    public static function displayMessage(string $message): string
    {
        if (preg_match('/^(.* \/ (?:поступление|списание)): на складе есть заявки вне рейса ERP: (.*)\.$/u', $message, $matches)) {
            return $matches[1].': в складском журнале остались удалённые из рейса ERP заявки '
                .$matches[2].'. Их движения не участвуют в остатке.';
        }
        if (preg_match('/^(.* \/ поступление): на склад поступило \d+ из \d+ заявок рейса\. Не поступили: (.*)\. Проверьте складские записи\.$/u', $message, $matches)) {
            return $matches[1].': заявки '.$matches[2]
                .' добавлены в рейс ERP, но отсутствуют в складском журнале. Их масса учтена в остатке по снимку ERP.';
        }
        if (preg_match('/^(.* \/ списание): со склада списано \d+ из \d+ заявок рейса\. Не списаны: (.*)\. Проверьте, были ли эти заявки на складе и почему нет списания\.$/u', $message, $matches)) {
            return $matches[1].': заявки '.$matches[2]
                .' есть в рейсе ERP, но отсутствуют в журнале списания. Списание рассчитывается при наличии остатка.';
        }
        if (!preg_match('/^Состав заявок: только в старой базе (.*); только в ERP (.*)\.$/u', $message, $matches)) {
            return $message;
        }
        $onlyOld = $matches[1];
        $onlyErp = $matches[2];
        if ($onlyOld === '—') {
            return 'Рассинхронизация баз: в рейсе ERP есть заявки '.$onlyErp
                .', которых нет в списке рейса старой базы.';
        }
        if ($onlyErp === '—') {
            return 'Рассинхронизация баз: в списке рейса старой базы есть заявки '.$onlyOld
                .', которых нет в рейсе ERP.';
        }
        return 'Рассинхронизация баз: состав заявок рейса различается. Только в старой базе: '
            .$onlyOld.'. Только в ERP: '.$onlyErp.'.';
    }

    private static function requestCountLabel(int $count): string
    {
        $lastTwo = $count % 100;
        $word = $lastTwo >= 11 && $lastTwo <= 14 ? 'заявок' : match ($count % 10) {
            1 => 'заявка',
            2, 3, 4 => 'заявки',
            default => 'заявок',
        };
        return $count.' '.$word;
    }

    private static function requestIds(string $list): array
    {
        return array_values(array_unique(preg_split('/[\s,;]+/u', trim($list), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    private static function warehouseRequestIds(array $flight): array
    {
        $all = self::requestIds((string)($flight['zayavki_ids'] ?? ''));
        if (($flight['route_type'] ?? '') !== 'mixed_to_utilizer') return $all;
        return array_values(array_diff($all, self::requestIds((string)($flight['direct_zayavki_ids'] ?? ''))));
    }

    private static function movementOrderTime(array $row): string
    {
        // Reactivating a cancelled correction keeps its original created_at.
        $updated = (string)($row['updated_at'] ?? '');
        if (str_contains((string)($row['comment'] ?? ''), 'повторно проведено после отмены')
            && $updated !== '' && !str_starts_with($updated, '0000-00-00')) return $updated;
        $created = (string)($row['created_at'] ?? '');
        return $created !== '' && !str_starts_with($created, '0000-00-00')
            ? $created : (string)($row['movement_date'] ?? '');
    }

    public static function build(array $warehouses, array $movements, array $flights, array $erpRows, array $exceptionRows = []): array
    {
        $names = [];
        foreach ($warehouses as $row) $names[(int)$row['id']] = (string)$row['name'];
        $flightMap = [];
        foreach ($flights as $row) $flightMap[(int)$row['id']] = $row;
        $mixedWarehouseRequests = [];
        foreach ($flightMap as $flightId => $flight) {
            if (($flight['route_type'] ?? '') === 'mixed_to_utilizer') {
                $mixedWarehouseRequests[$flightId] = array_fill_keys(self::warehouseRequestIds($flight), true);
            }
        }
        $erpFlights = [];
        foreach ($erpRows as $row) {
            $id = (int)$row['business_id'];
            if ($id <= 0) continue;
            $erpFlights[$id]['status'] = (string)($row['status'] ?? '');
            $erpFlights[$id]['start_date'] = (string)($row['actual_start_date'] ?? '');
            $erpFlights[$id]['source'] = (int)($row['legacy_source_warehouse_id'] ?? 0);
            $erpFlights[$id]['destination'] = (int)($row['legacy_destination_warehouse_id'] ?? 0);
            if (($row['zayavka_id'] ?? null) !== null) {
                $requestId = (string)$row['zayavka_id'];
                $erpFlights[$id]['requests'][$requestId] = true;
                $erpFlights[$id]['masses'][$requestId] = (int)round((float)($row['snapshot_mass_kg'] ?? 0));
            }
        }
        $receiptDates = $receiptOrderTimes = $recordedReceipts = [];
        foreach ($movements as $row) {
            if (($row['status'] ?? 'active') !== 'active' || !in_array((string)$row['movement_type'], ['receipt', 'transfer_in'], true)) continue;
            $flightId = (int)$row['flight_id'];
            $warehouseId = (int)$row['warehouse_id'];
            $receiptDates[$flightId][$warehouseId] = max($receiptDates[$flightId][$warehouseId] ?? '', (string)$row['movement_date']);
            $receiptOrderTimes[$flightId][$warehouseId] = max($receiptOrderTimes[$flightId][$warehouseId] ?? '', self::movementOrderTime($row));
            $recordedReceipts[$flightId][$warehouseId][(string)$row['zayavka_id']] = true;
        }
        foreach ($erpFlights as $flightId => $erpFlight) {
            $erpWarehouseId = (int)($erpFlight['destination'] ?? 0);
            $legacyWarehouseId = (int)($flightMap[$flightId]['destination_warehouse_id'] ?? 0);
            $warehouseId = isset($receiptDates[$flightId][$erpWarehouseId]) ? $erpWarehouseId
                : (isset($receiptDates[$flightId][$legacyWarehouseId]) ? $legacyWarehouseId : 0);
            // A journal receipt proves that this trip delivered to this warehouse.
            // Newly added ERP requests join that receipt in the read-only ledger.
            if ($warehouseId <= 0 || !isset($flightMap[$flightId], $receiptDates[$flightId][$warehouseId])) continue;
            foreach (array_keys($erpFlight['requests'] ?? []) as $requestId) {
                if (isset($recordedReceipts[$flightId][$warehouseId][$requestId])) continue;
                $movements[] = [
                    'id'=>PHP_INT_MAX,'movement_type'=>'receipt','warehouse_id'=>$warehouseId,
                    'flight_id'=>$flightId,'zayavka_id'=>$requestId,
                    'mass_netto'=>($erpFlight['masses'][$requestId] ?? 0) / 1000,
                    'movement_date'=>$receiptDates[$flightId][$warehouseId],
                    'created_at'=>$receiptOrderTimes[$flightId][$warehouseId],
                    'status'=>'active','derived_receipt'=>true,
                ];
            }
        }
        $alreadyIssued = [];
        foreach ($movements as $row) {
            if (($row['status'] ?? 'active') === 'active' && in_array((string)$row['movement_type'], ['issue', 'transfer_out'], true)) {
                $alreadyIssued[(int)$row['flight_id']][(int)$row['warehouse_id']][(string)$row['zayavka_id']] = true;
            }
        }
        foreach ($flightMap as $flightId => $flight) {
            $erpFlight = $erpFlights[$flightId] ?? [];
            $status = (string)($erpFlight['status'] ?? '');
            if ($status === '') $status = (string)($flight['status'] ?? '');
            if (!in_array($status, ['started', 'in_progress', 'completed'], true)) continue;
            $warehouseId = (int)($flight['source_warehouse_id'] ?? 0) ?: (int)($erpFlight['source'] ?? 0);
            if ($warehouseId <= 0) continue;
            $requestIds = isset($mixedWarehouseRequests[$flightId])
                ? array_keys($mixedWarehouseRequests[$flightId]) : array_keys($erpFlight['requests'] ?? []);
            if ($requestIds === []) $requestIds = ($flight['route_type'] ?? '') === 'mixed_to_utilizer'
                ? [] : self::requestIds((string)($flight['zayavki_ids'] ?? ''));
            $date = (string)($erpFlight['start_date'] ?? '') ?: (string)($flight['actual_start_date'] ?? '') ?: (string)($flight['actual_end_date'] ?? '') ?: date('Y-m-d H:i:s');
            foreach (array_unique($requestIds) as $requestId) {
                if (isset($alreadyIssued[$flightId][$warehouseId][$requestId])) continue;
                $movements[] = [
                    'id'=>PHP_INT_MAX,'movement_type'=>'issue','warehouse_id'=>$warehouseId,
                    'flight_id'=>$flightId,'zayavka_id'=>$requestId,'mass_netto'=>null,
                    'movement_date'=>$date,'status'=>'active','derived'=>true,
                ];
            }
        }
        usort($movements, static fn(array $a, array $b): int =>
            strcmp(self::movementOrderTime($a), self::movementOrderTime($b)) ?: ((int)$a['id'] <=> (int)$b['id']));
        $balances = $groups = $movementFlightIds = $journalFlightIds = $journalRequestIds = [];
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
            $before = $balances[$warehouseId][$requestId] ?? 0;
            if (!empty($row['derived']) && $before <= 0) continue;
            $weight = !empty($row['derived']) ? $before : (int)round((float)$row['mass_netto'] * 1000);
            if ($weight < 0) throw new RuntimeException('Отрицательная масса складского движения.');
            $mixedWarehouseRequest = isset($mixedWarehouseRequests[$flightId][$requestId]);
            $excluded = isset($erpFlights[$flightId]) && !isset($erpFlights[$flightId]['requests'][$requestId]) && !$mixedWarehouseRequest;
            $after = $excluded ? $before : $before + $sign * $weight;
            if (!$excluded) $balances[$warehouseId][$requestId] = $after;
            $direction = $sign > 0 ? 'in' : 'out';
            $key = $warehouseId.':'.$flightId.':'.$direction;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'=>$key,'flight_id'=>$flightId,'warehouse_id'=>$warehouseId,
                    'direction'=>$direction,'date'=>(string)$row['movement_date'],
                    'requests'=>[],'mass_kg'=>0,'stock_count'=>0,'stock_kg'=>0,'is_correction'=>true,'is_derived'=>false,
                ];
            }
            $group =& $groups[$key];
            $group['date'] = max($group['date'], (string)$row['movement_date']);
            $group['requests'][] = ['id'=>$requestId,'mass_kg'=>$weight,'before_kg'=>$before,'after_kg'=>$after,
                'excluded'=>$excluded,'derived_receipt'=>!empty($row['derived_receipt']),'derived_issue'=>!empty($row['derived'])];
            if (!$excluded) $group['mass_kg'] += $weight;
            $group['is_derived'] = $group['is_derived'] || !empty($row['derived']) || !empty($row['derived_receipt']);
            $group['is_derived_receipt'] = ($group['is_derived_receipt'] ?? false) || !empty($row['derived_receipt']);
            $group['is_correction'] = $group['is_correction']
                && empty($row['derived'])
                && empty($row['derived_receipt'])
                && str_starts_with((string)($row['comment'] ?? ''), 'Исправление складского направления рейса #'.$flightId.' в ERP PLANEX пользователем #');
            $group['stock_count'] = count(array_filter($balances[$warehouseId], static fn(int $value): bool => $value > 0));
            $group['stock_kg'] = array_sum($balances[$warehouseId]);
            unset($group);
            $movementFlightIds[$flightId] = true;
            if (empty($row['derived'])) {
                $journalFlightIds[$flightId] = true;
                $journalRequestIds[$flightId][$requestId] = true;
            }
        }
        $current = [];
        foreach ($balances as $warehouseId => $requestBalances) {
            $present = array_filter($requestBalances, static fn(int $value): bool => $value > 0);
            $requestIds = array_keys($present);
            usort($requestIds, 'strnatcmp');
            $current[$warehouseId] = [
                'request_count'=>count($requestIds),
                'weight_kg'=>array_sum($requestBalances),
                'request_ids'=>$requestIds,
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
            $activeRequests = array_values(array_filter($group['requests'], static fn(array $request): bool => !$request['excluded']));
            $group['active_request_ids'] = array_values(array_unique(array_column($activeRequests, 'id')));
            $group['request_count'] = count($group['active_request_ids']);
            $journalRequests = array_values(array_filter($group['requests'], static fn(array $request): bool => !$request['derived_receipt'] && !$request['derived_issue']));
            $group['journal_request_ids'] = array_values(array_unique(array_column($journalRequests, 'id')));
            $group['has_journal'] = $group['journal_request_ids'] !== [];
            $tripRequestIds = isset($mixedWarehouseRequests[$group['flight_id']]) && $group['direction'] === 'out'
                ? array_map('strval', array_keys($mixedWarehouseRequests[$group['flight_id']]))
                : (isset($erpFlights[$group['flight_id']])
                    ? array_map('strval', array_keys($erpFlights[$group['flight_id']]['requests'] ?? []))
                    : null);
            $group['warehouse_only_request_ids'] = $tripRequestIds === null
                ? [] : array_values(array_diff($group['request_ids'], $tripRequestIds));
            $group['trip_only_request_ids'] = $tripRequestIds === null
                ? [] : array_values(array_diff($tripRequestIds, $group['journal_request_ids']));
            $derivedRequestIds = array_values(array_unique(array_column(array_filter(
                $group['requests'],
                static fn(array $request): bool => $request['derived_receipt'] || $request['derived_issue']
            ), 'id')));
            $group['added_request_ids'] = array_values(array_intersect($group['trip_only_request_ids'], $derivedRequestIds));
            $group['unmoved_request_ids'] = array_values(array_diff($group['trip_only_request_ids'], $group['added_request_ids']));
            $group['display_request_ids'] = array_values(array_unique(array_merge($group['request_ids'], $group['trip_only_request_ids'])));
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
                    'name'=>(string)($row['erp_name'] ?? ''),
                    'source'=>(int)($row['legacy_source_warehouse_id'] ?? 0),
                    'destination'=>(int)($row['legacy_destination_warehouse_id'] ?? 0),
                    'requests'=>[], 'mass_kg'=>0, 'request_masses_kg'=>[],
                ];
            }
            if (($row['zayavka_id'] ?? null) !== null && !in_array((string)$row['zayavka_id'], $erp[$id]['requests'], true)) {
                $erp[$id]['requests'][] = (string)$row['zayavka_id'];
                $erp[$id]['mass_kg'] += (int)round((float)($row['snapshot_mass_kg'] ?? 0));
                $erp[$id]['request_masses_kg'][(string)$row['zayavka_id']] = (int)round((float)($row['snapshot_mass_kg'] ?? 0));
            }
        }
        $receipts = [];
        foreach ($groups as $group) {
            if ($group['direction'] !== 'in') continue;
            foreach ($group['requests'] as $request) {
                if ($request['excluded']) continue;
                $receipts[$group['warehouse_id']][$request['id']][$group['flight_id']] =
                    ($receipts[$group['warehouse_id']][$request['id']][$group['flight_id']] ?? 0) + $request['mass_kg'];
            }
        }
        $formatKg = static fn(int $kg): string => number_format($kg, 0, ',', ' ');
        $issues = [];
        foreach ($flightMap as $flightId => $flight) {
            $oldSource = (int)($flight['source_warehouse_id'] ?? 0);
            $oldDestination = (int)($flight['destination_warehouse_id'] ?? 0);
            if ($oldSource <= 0 && $oldDestination <= 0 && !isset($movementFlightIds[$flightId])) continue;
            $messages = [];
            $reconcileTargets = [];
            if (!isset($erp[$flightId])) {
                // The ERP trip namespace starts after the legacy cutover boundary.
                if ($flightId <= self::LEGACY_CUTOVER_FLIGHT_ID) continue;
                $messages[] = 'Рейс есть в старой базе, но его нет в ERP. Проверьте перенос рейса #'.$flightId.'.';
            } else {
                if ($oldSource !== $erp[$flightId]['source'] || $oldDestination !== $erp[$flightId]['destination']) {
                    $messages[] = 'Склад рейса в ERP отличается от старой базы.';
                }
                $legacyRequests = self::requestIds((string)($flight['zayavki_ids'] ?? ''));
                if (isset($mixedWarehouseRequests[$flightId])) {
                    $directRequests = self::requestIds((string)($flight['direct_zayavki_ids'] ?? ''));
                    $erpRequestSet = array_fill_keys($erp[$flightId]['requests'], true);
                    // Old ERP trips may contain the complete mixed trip; newer ones contain only its direct leg.
                    if (count($erpRequestSet) === count($directRequests)
                        && count(array_intersect($directRequests, $erp[$flightId]['requests'])) === count($directRequests)) {
                        $legacyRequests = $directRequests;
                    }
                }
                $onlyOld = array_diff(array_unique($legacyRequests), array_unique($erp[$flightId]['requests']));
                $onlyErp = array_diff(array_unique($erp[$flightId]['requests']), array_unique($legacyRequests));
                if ($onlyOld !== [] || $onlyErp !== []) {
                    $messages[] = 'Состав заявок: только в старой базе '.(implode(', ', $onlyOld) ?: '—')
                        .'; только в ERP '.(implode(', ', $onlyErp) ?: '—').'.';
                }
                foreach ($groups as $group) {
                    if ($group['flight_id'] !== $flightId) continue;
                    $where = ($names[$group['warehouse_id']] ?? 'Склад #'.$group['warehouse_id'])
                        .' / '.($group['direction'] === 'in' ? 'поступление' : 'списание');
                    if ($group['warehouse_only_request_ids'] !== []) {
                        $message = $where.': на складе есть заявки вне рейса ERP: '
                            .implode(', ', $group['warehouse_only_request_ids']).'.';
                        $messages[] = $message;
                        if ($group['has_journal']) {
                            $reconcileTargets[$message] = ['warehouse_id'=>$group['warehouse_id'],'direction'=>$group['direction']];
                        }
                    }
                    if ($group['trip_only_request_ids'] !== []) {
                        $expectedRequests = isset($mixedWarehouseRequests[$flightId]) && $group['direction'] === 'out'
                            ? array_map('strval', array_keys($mixedWarehouseRequests[$flightId])) : $erp[$flightId]['requests'];
                        $recorded = count(array_intersect($group['journal_request_ids'], $expectedRequests));
                        $total = count($expectedRequests);
                        $missing = implode(', ', $group['trip_only_request_ids']);
                        $messages[] = $group['direction'] === 'out'
                            ? $where.': со склада списано '.$recorded.' из '.$total.' заявок рейса. Не списаны: '
                                .$missing.'. Проверьте, были ли эти заявки на складе и почему нет списания.'
                            : $where.': на склад поступило '.$recorded.' из '.$total.' заявок рейса. Не поступили: '
                                .$missing.'. Проверьте складские записи.';
                    }
                }
                $directionMass = ['in'=>0, 'out'=>0];
                $directionRequests = ['in'=>[], 'out'=>[]];
                $directionRequestMass = ['in'=>[], 'out'=>[]];
                $journalDirection = ['in'=>false, 'out'=>false];
                foreach ($groups as $group) {
                    if ($group['flight_id'] === $flightId) {
                        $directionMass[$group['direction']] += $group['mass_kg'];
                        $journalDirection[$group['direction']] = $journalDirection[$group['direction']] || $group['has_journal'];
                        $directionRequests[$group['direction']] = array_merge($directionRequests[$group['direction']], $group['active_request_ids']);
                        foreach ($group['requests'] as $request) {
                            if ($request['excluded']) continue;
                            $requestId = $request['id'];
                            $directionRequestMass[$group['direction']][$requestId] =
                                ($directionRequestMass[$group['direction']][$requestId] ?? 0) + $request['mass_kg'];
                        }
                    }
                }
                $flightMass = max($directionMass);
                $direction = $directionMass['out'] > $directionMass['in'] ? 'out' : 'in';
                $expectedMass = 0;
                foreach (array_unique($directionRequests[$direction]) as $requestId) {
                    $expectedMass += $erp[$flightId]['request_masses_kg'][$requestId] ?? 0;
                }
                // Compare mixed-trip mass only when ERP has snapshots for every warehouse request.
                $hasWarehouseMassSnapshot = !isset($mixedWarehouseRequests[$flightId])
                    || count(array_diff(array_keys($mixedWarehouseRequests[$flightId]), $erp[$flightId]['requests'])) === 0;
                if ($hasWarehouseMassSnapshot && $flightMass > 0 && abs($flightMass - $expectedMass) > 1) {
                    $difference = $flightMass - $expectedMass;
                    $massMessage = 'Масса по рейсу: складской учёт '.$formatKg($flightMass).' кг, снимок рейса ERP '
                        .$formatKg($expectedMass).' кг. Разница '.($difference > 0 ? '+' : '−').$formatKg(abs($difference)).' кг.';
                    $messages[] = $massMessage;
                    if ($reconcileTargets === [] && $journalDirection[$direction]) {
                        $warehouseId = $direction === 'in' ? $oldDestination : $oldSource;
                        $reconcileTargets[$massMessage] = ['warehouse_id'=>$warehouseId,'direction'=>$direction];
                    }
                    foreach ($directionRequestMass[$direction] as $requestId => $movementMass) {
                        $erpMass = $erp[$flightId]['request_masses_kg'][$requestId] ?? 0;
                        if (abs($movementMass - $erpMass) <= 1) continue;
                        $requestDifference = $movementMass - $erpMass;
                        $messages[] = 'Заявка #'.$requestId.': в складском учёте '.$formatKg($movementMass)
                            .' кг, в снимке рейса ERP '.$formatKg($erpMass).' кг; разница '
                            .($requestDifference > 0 ? '+' : '−').$formatKg(abs($requestDifference)).' кг.';
                        $warehouseId = $direction === 'in' ? $oldDestination : $oldSource;
                        $requestReceipts = $receipts[$warehouseId][$requestId] ?? [];
                        if (count($requestReceipts) > 1) {
                            ksort($requestReceipts, SORT_NUMERIC);
                            $parts = [];
                            foreach ($requestReceipts as $receiptFlightId => $mass) {
                                $parts[] = '#'.$receiptFlightId.' ('.$formatKg($mass).' кг)';
                            }
                            $messages[] = 'По этой заявке на складе учтены приходы в рейсах '
                                .implode(' и ', $parts).'. Проверьте, оба ли прихода относятся к одному грузу.';
                        }
                    }
                }
            }
            if ($messages !== []) {
                $issues[$flightId] = [
                    'flight_id'=>$flightId,
                    'name'=>trim((string)($flight['comment'] ?? '')) ?: trim((string)($erp[$flightId]['name'] ?? '')),
                    'warehouse_id'=>$oldDestination ?: $oldSource,
                    'messages'=>$messages,
                    'has_movements'=>isset($journalFlightIds[$flightId]),
                    'movement_label'=>isset($journalFlightIds[$flightId])
                        ? 'Складской журнал: '.self::requestCountLabel(count($journalRequestIds[$flightId]))
                            .(isset($erp[$flightId]) ? ' · ERP: '.self::requestCountLabel(count($erp[$flightId]['requests'])) : '')
                        : (isset($movementFlightIds[$flightId]) ? 'Расчётное движение' : 'Нет в журнале'),
                    'reconcile_targets'=>$reconcileTargets,
                ];
            }
        }
        foreach ($erp as $flightId => $row) {
            if (isset($flightMap[$flightId]) || ($row['source'] <= 0 && $row['destination'] <= 0)) continue;
            $issues[$flightId] = [
                'flight_id'=>$flightId,
                'name'=>trim((string)($row['name'] ?? '')),
                'warehouse_id'=>$row['destination'] ?: $row['source'],
                'messages'=>['Складской рейс есть в ERP, но отсутствует в старой базе.'],
                'has_movements'=>false, 'movement_label'=>'Нет в журнале',
            ];
        }
        krsort($issues, SORT_NUMERIC);
        $allIssues = $issues;
        foreach ($exceptionRows as &$exception) {
            $exception['matches_current'] = false;
            if ($exception['revoked_at'] !== null) continue;
            foreach ($allIssues[(int)$exception['flight_id']]['messages'] ?? [] as $currentMessage) {
                if (hash_equals((string)$exception['message_hash'], self::messageHash($currentMessage))) {
                    $exception['matches_current'] = true;
                    break;
                }
            }
        }
        unset($exception);
        $activeExceptions = [];
        foreach ($exceptionRows as $exception) {
            if ($exception['revoked_at'] !== null) continue;
            $activeExceptions[(int)$exception['flight_id']][(string)$exception['message_hash']] = true;
        }
        foreach ($issues as $flightId => &$issue) {
            $issue['messages'] = array_values(array_filter($issue['messages'], static function (string $message) use ($activeExceptions, $flightId): bool {
                return !isset($activeExceptions[$flightId][self::messageHash($message)]);
            }));
            if ($issue['messages'] === []) unset($issues[$flightId]);
        }
        unset($issue);
        $correctionFlights = [];
        foreach ($flightMap as $flight) $correctionFlights[] = [
            'id'=>(int)$flight['id'], 'name'=>(string)($flight['comment'] ?? ''),
        ];
        usort($correctionFlights, static fn(array $a, array $b): int => $b['id'] <=> $a['id']);
        return ['warehouses'=>$names,'groups'=>$byWarehouse,'current'=>$current,'issues'=>$issues,'all_issues'=>$allIssues,
            'exceptions'=>$exceptionRows,'issue_count'=>count($issues),'correction_flights'=>$correctionFlights];
    }
}
