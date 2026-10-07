<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__.'/WasteTripLegacySourceService.php';

/** Reconcile one legacy flight's warehouse ledger with its ERP request snapshot. */
final class WarehouseTripRequestReconciliationService
{
    public static function preview(PDO $legacy, PDO $tenant, string $database, int $flightId, int $warehouseId, string $direction, bool $lock = false): array
    {
        if ($flightId <= 225 || $warehouseId <= 0 || !in_array($direction, ['in', 'out'], true)) {
            throw new RuntimeException('Выберите складской рейс для сверки.');
        }
        $table = static fn(string $name): string => self::table($database, $name);
        $suffix = $lock ? ' FOR UPDATE' : '';
        $query = $tenant->prepare('SELECT id,business_id,status,source_system,legacy_flight_id,lock_version FROM waste_trips WHERE business_id=? AND deleted_at IS NULL LIMIT 2'.$suffix);
        $query->execute([$flightId]);
        $trips = $query->fetchAll(PDO::FETCH_ASSOC);
        if (count($trips) !== 1 || (int)$trips[0]['legacy_flight_id'] !== $flightId
            || !in_array((string)$trips[0]['source_system'], ['legacy_flights','legacy_sync','legacy_prefill'], true)) {
            throw new RuntimeException('Связанный рейс ERP не найден или неоднозначен.');
        }
        $trip = $trips[0];
        if ($trip['status'] === 'cancelled') throw new RuntimeException('Отменённый рейс сверять нельзя.');
        $query = $tenant->prepare('SELECT wtr.id,fr.zayavka_id,wtr.snapshot_mass_kg FROM waste_trip_requests wtr JOIN feo_requests fr ON fr.id=wtr.feo_request_id WHERE wtr.waste_trip_id=? AND wtr.removed_at IS NULL ORDER BY wtr.position_no,wtr.id'.$suffix);
        $query->execute([(int)$trip['id']]);
        $tripRequests = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($tripRequests === [] || count($tripRequests) > 1000) throw new RuntimeException('В рейсе ERP нет пригодного состава заявок.');
        $expected = [];
        foreach ($tripRequests as $row) {
            $id = (string)$row['zayavka_id'];
            $kg = (float)$row['snapshot_mass_kg'];
            if (!preg_match('/^[1-9][0-9]*$/D', $id) || isset($expected[$id]) || $kg <= 0 || abs($kg * 10 - round($kg * 10)) > 0.00001) {
                throw new RuntimeException('Состав или масса заявок рейса ERP не позволяют точную складскую сверку.');
            }
            $expected[$id] = ['id'=>$id, 'kg'=>$kg, 'net'=>number_format($kg / 1000, 4, '.', '')];
        }
        $query = $legacy->prepare('SELECT id,status,route_type,source_warehouse_id,destination_warehouse_id,zayavki_ids FROM '.$table('flights').' WHERE id=? LIMIT 1'.$suffix);
        $query->execute([$flightId]);
        $flight = $query->fetch(PDO::FETCH_ASSOC);
        if (!$flight) throw new RuntimeException('Складской рейс не найден.');
        if (in_array((string)$flight['status'], ['cancelled','canceled','deleted'], true)) {
            throw new RuntimeException('Отменённый складской рейс сверять нельзя.');
        }
        $query = $legacy->prepare('SELECT id,status,movement_type,warehouse_id,source_warehouse_id,destination_warehouse_id,zayavka_id,mass_netto,mass_brutto,volume,movement_date,comment FROM '.$table('warehouse_movements').' WHERE flight_id=? ORDER BY id'.$suffix);
        $query->execute([$flightId]);
        $all = $query->fetchAll(PDO::FETCH_ASSOC);
        $active = array_values(array_filter($all, static fn(array $row): bool => $row['status'] === 'active'));
        if ($active === []) throw new RuntimeException('У рейса нет движений в складском журнале.');
        $pairs = [];
        foreach ($active as $row) {
            $type = (string)$row['movement_type'];
            $warehouse = (int)$row['warehouse_id'];
            if (!in_array($type, ['receipt','issue','transfer_in','transfer_out'], true) || $warehouse <= 0) {
                throw new RuntimeException('У рейса есть движение неизвестного типа.');
            }
            $key = $type.':'.$warehouse;
            if (!isset($pairs[$key])) $pairs[$key] = ['type'=>$type,'warehouse_id'=>$warehouse,'date'=>(string)$row['movement_date']];
            else $pairs[$key]['date'] = min($pairs[$key]['date'], (string)$row['movement_date']);
        }
        $pairKeys = array_keys($pairs);
        sort($pairKeys);
        $source = (int)$flight['source_warehouse_id'];
        $destination = (int)$flight['destination_warehouse_id'];
        $allowed = match ((string)$flight['route_type']) {
            'generator_to_warehouse' => ['receipt:'.$destination],
            'warehouse_to_utilizer' => ['issue:'.$source],
            'warehouse_to_warehouse' => ['transfer_in:'.$destination, 'transfer_out:'.$source],
            default => [],
        };
        sort($allowed);
        if ($allowed === [] || $pairKeys !== $allowed || $source > 0 && $source === $destination) {
            throw new RuntimeException('Направление и движения рейса не совпадают. Сначала исправьте направление рейса.');
        }
        $selectedType = $direction === 'in' ? ['receipt','transfer_in'] : ['issue','transfer_out'];
        if (!array_filter($pairs, static fn(array $pair): bool => $pair['warehouse_id'] === $warehouseId && in_array($pair['type'], $selectedType, true))) {
            throw new RuntimeException('У выбранного склада нет движения этого рейса.');
        }
        $feo = [];
        foreach (array_chunk(array_keys($expected), 300) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $query = $legacy->prepare('SELECT zayavka_id,mass_netto,mass_brutto,summarnyy_obem,naim_otkhoda_fkko FROM '.$table('feo').' WHERE zayavka_id IN ('.$marks.')'.$suffix);
            $query->execute($chunk);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $feo[(string)$row['zayavka_id']] = $row;
        }
        foreach ($expected as $id => &$row) {
            if (!isset($feo[$id]) || (float)$feo[$id]['mass_netto'] <= 0) throw new RuntimeException('Заявка #'.$id.' отсутствует в ФЭО или не имеет массы.');
            $ratio = (float)$row['net'] / (float)$feo[$id]['mass_netto'];
            $row['gross'] = number_format((float)$feo[$id]['mass_brutto'] * $ratio, 4, '.', '');
            $row['volume'] = number_format((float)$feo[$id]['summarnyy_obem'] * $ratio, 4, '.', '');
            $row['fkko'] = (string)($feo[$id]['naim_otkhoda_fkko'] ?? '');
        }
        unset($row);
        $beforeIds = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['zayavka_id'], $active)));
        $afterIds = array_map('strval', array_keys($expected));
        $remove = array_values(array_diff($beforeIds, $afterIds));
        $add = array_values(array_diff($afterIds, $beforeIds));
        $change = [];
        foreach ($active as $row) {
            $id = (string)$row['zayavka_id'];
            if (isset($expected[$id]) && abs((float)$row['mass_netto'] - (float)$expected[$id]['net']) > 0.00005) $change[$id] = true;
        }
        $affected = array_values(array_unique(array_merge($beforeIds, $afterIds)));
        $ledger = [];
        foreach (array_chunk($affected, 300) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $query = $legacy->prepare('SELECT id,flight_id,warehouse_id,zayavka_id,movement_type,mass_netto,movement_date FROM '.$table('warehouse_movements')." WHERE status='active' AND zayavka_id IN (".$marks.') ORDER BY movement_date,id'.$suffix);
            $query->execute($chunk);
            array_push($ledger, ...$query->fetchAll(PDO::FETCH_ASSOC));
        }
        usort($ledger, static fn(array $a, array $b): int => strcmp((string)$a['movement_date'], (string)$b['movement_date']) ?: (int)$a['id'] <=> (int)$b['id']);
        $warnings = self::balanceWarnings($ledger, $pairs, $expected, $flightId);
        $legacyIds = WasteTripLegacySourceService::parseRequestIdsStrict((string)$flight['zayavki_ids']);
        $ready = $remove !== [] || $add !== [] || $change !== [] || $legacyIds !== $afterIds;
        $fingerprint = hash('sha256', json_encode([$trip,$tripRequests,$flight,$all,$feo,$ledger,$warehouseId,$direction], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return ['flight_id'=>$flightId,'warehouse_id'=>$warehouseId,'direction'=>$direction,'before_ids'=>$beforeIds,'after_ids'=>$afterIds,
            'remove_ids'=>$remove,'add_ids'=>$add,'change_mass_ids'=>array_map('strval', array_keys($change)),'movement_count'=>count($active),
            'before_kg'=>round(array_sum(array_map(static fn(array $row): float => (float)$row['mass_netto'] * 1000, $active)) / count($pairs)),
            'after_kg'=>array_sum(array_column($expected, 'kg')),'warnings'=>$warnings,'errors'=>[],'ready'=>$ready,'fingerprint'=>$fingerprint,
            'expected'=>$expected,'pairs'=>$pairs,'all_movements'=>$all,
            'source_warehouse_id'=>$source,'destination_warehouse_id'=>$destination];
    }

    public static function apply(PDO $legacy, PDO $tenant, string $database, int $flightId, int $warehouseId, string $direction, string $fingerprint, int $actorId): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) throw new RuntimeException('Повторите предварительную проверку.');
        self::assertTransactionalTables($legacy, $database);
        $lock = $legacy->prepare('SELECT GET_LOCK(?,10)');
        $lock->execute(['planex_warehouse_correction']);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Складской учёт занят. Повторите попытку.');
        try {
            if ($legacy->inTransaction() || $tenant->inTransaction()) throw new RuntimeException('Сверка уже выполняется в другой транзакции.');
            $sameConnection = $legacy === $tenant;
            if (!$sameConnection) $tenant->beginTransaction();
            $legacy->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            $legacy->beginTransaction();
            $preview = self::preview($legacy, $tenant, $database, $flightId, $warehouseId, $direction, true);
            if (!hash_equals($fingerprint, $preview['fingerprint'])) throw new RuntimeException('Состав рейса или склад изменился. Повторите предварительную проверку.');
            if (!$preview['ready']) throw new RuntimeException(implode(' ', $preview['errors']) ?: 'Расхождение уже устранено.');
            $table = self::table($database, 'warehouse_movements');
            $flightTable = self::table($database, 'flights');
            $updateFlight = $legacy->prepare('UPDATE '.$flightTable.' SET zayavki_ids=? WHERE id=?');
            $updateFlight->execute([implode(',', $preview['after_ids']), $flightId]);
            $active = [];
            $cancelled = [];
            foreach ($preview['all_movements'] as $row) {
                $key = $row['movement_type'].':'.$row['warehouse_id'].':'.$row['zayavka_id'];
                if ($row['status'] === 'active') $active[$key] = $row;
                elseif ($row['status'] === 'cancelled') $cancelled[$key] = $row;
            }
            $cancel = $legacy->prepare('UPDATE '.$table." SET status='cancelled',updated_at=NOW() WHERE id=? AND status='active'");
            $update = $legacy->prepare('UPDATE '.$table.' SET fkko_code=?,mass_netto=?,mass_brutto=?,volume=?,comment=?,updated_at=NOW() WHERE id=? AND status=\'active\'');
            $insert = $legacy->prepare('INSERT INTO '.$table.' (movement_type,warehouse_id,source_warehouse_id,destination_warehouse_id,flight_id,zayavka_id,fkko_code,mass_netto,mass_brutto,volume,movement_date,status,comment,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $changed = $created = $removed = 0;
            foreach ($active as $key => $row) {
                if (isset($preview['expected'][(string)$row['zayavka_id']])) continue;
                $cancel->execute([(int)$row['id']]);
                if ($cancel->rowCount() !== 1) throw new RuntimeException('Не удалось убрать лишнее складское движение.');
                $removed++;
            }
            foreach ($preview['pairs'] as $pair) {
                foreach ($preview['expected'] as $id => $item) {
                    $key = $pair['type'].':'.$pair['warehouse_id'].':'.$id;
                    if (isset($active[$key])) {
                        $old = $active[$key];
                        if (abs((float)$old['mass_netto'] - (float)$item['net']) <= 0.00005
                            && abs((float)$old['mass_brutto'] - (float)$item['gross']) <= 0.00005
                            && abs((float)$old['volume'] - (float)$item['volume']) <= 0.00005) continue;
                        $comment = self::comment((string)$old['comment'], 'сверка с рейсом ERP пользователем #'.$actorId.'; было '.$old['mass_netto'].' т');
                        $update->execute([$item['fkko'],$item['net'],$item['gross'],$item['volume'],$comment,(int)$old['id']]);
                        if ($update->rowCount() !== 1) throw new RuntimeException('Не удалось пересчитать складское движение.');
                        $changed++;
                    } else {
                        if (isset($cancelled[$key])) throw new RuntimeException('Заявка имеет ранее отменённое движение. Нужна отдельная проверка.');
                        $insert->execute([$pair['type'],$pair['warehouse_id'],$preview['source_warehouse_id'] ?: null,$preview['destination_warehouse_id'] ?: null,
                            $flightId,$id,$item['fkko'],$item['net'],$item['gross'],$item['volume'],$pair['date'],'active',
                            'Сверка с рейсом ERP #'.$flightId.' пользователем #'.$actorId]);
                        $created++;
                    }
                }
            }
            $check = $legacy->prepare('SELECT movement_type,warehouse_id,zayavka_id,mass_netto FROM '.$table." WHERE flight_id=? AND status='active' ORDER BY movement_type,warehouse_id,zayavka_id");
            $check->execute([$flightId]);
            $actual = $check->fetchAll(PDO::FETCH_ASSOC);
            if (count($actual) !== count($preview['pairs']) * count($preview['expected'])) throw new RuntimeException('Контроль состава складских движений не прошёл.');
            foreach ($actual as $row) {
                $key = $row['movement_type'].':'.$row['warehouse_id'];
                $id = (string)$row['zayavka_id'];
                if (!isset($preview['pairs'][$key], $preview['expected'][$id]) || abs((float)$row['mass_netto'] - (float)$preview['expected'][$id]['net']) > 0.00005) {
                    throw new RuntimeException('Контроль массы складских движений не прошёл.');
                }
            }
            $legacy->commit();
            if (!$sameConnection) $tenant->commit();
            return ['flight_id'=>$flightId,'removed'=>$removed,'created'=>$created,'updated'=>$changed,'request_count'=>count($preview['after_ids'])];
        } catch (Throwable $error) {
            if ($legacy->inTransaction()) $legacy->rollBack();
            if ($tenant->inTransaction()) $tenant->rollBack();
            throw $error;
        } finally {
            try { $release = $legacy->prepare('SELECT RELEASE_LOCK(?)'); $release->execute(['planex_warehouse_correction']); } catch (Throwable) {}
        }
    }

    private static function balanceWarnings(array $ledger, array $pairs, array $expected, int $flightId): array
    {
        $old = self::minimumBalances($ledger);
        $proposed = [];
        $seen = [];
        foreach ($ledger as $row) {
            if ((int)$row['flight_id'] === $flightId) {
                $id = (string)$row['zayavka_id'];
                if (!isset($expected[$id])) continue;
                $row['mass_netto'] = $expected[$id]['net'];
                $seen[$row['movement_type'].':'.$row['warehouse_id'].':'.$id] = true;
            }
            $proposed[] = $row;
        }
        foreach ($pairs as $pair) foreach ($expected as $id => $item) {
            if (isset($seen[$pair['type'].':'.$pair['warehouse_id'].':'.$id])) continue;
            $proposed[] = ['id'=>PHP_INT_MAX,'flight_id'=>$flightId,'warehouse_id'=>$pair['warehouse_id'],'zayavka_id'=>$id,
                'movement_type'=>$pair['type'],'mass_netto'=>$item['net'],'movement_date'=>$pair['date']];
        }
        usort($proposed, static fn(array $a, array $b): int => strcmp((string)$a['movement_date'], (string)$b['movement_date']) ?: (int)$a['id'] <=> (int)$b['id']);
        $after = self::minimumBalances($proposed);
        $warnings = [];
        foreach ($after as $key => $minimum) {
            if ($minimum < min(0, $old[$key] ?? 0) - 0.0001) {
                $warnings[] = 'После сверки остаток по заявке #'.explode(':', $key)[1]
                    .' станет отрицательным из-за движений других рейсов. Сверьте эти рейсы отдельно.';
            }
        }
        return $warnings;
    }

    private static function minimumBalances(array $rows): array
    {
        $balance = $minimum = [];
        foreach ($rows as $row) {
            $key = $row['warehouse_id'].':'.$row['zayavka_id'];
            $sign = in_array($row['movement_type'], ['receipt','transfer_in'], true) ? 1 : -1;
            $balance[$key] = ($balance[$key] ?? 0) + $sign * (float)$row['mass_netto'];
            $minimum[$key] = min($minimum[$key] ?? 0, $balance[$key]);
        }
        return $minimum;
    }

    private static function comment(string $before, string $note): string
    {
        return mb_substr($before === '' ? $note : $before.'; '.$note, 0, 255);
    }

    private static function assertTransactionalTables(PDO $pdo, string $database): void
    {
        self::table($database, 'flights');
        $query = $pdo->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('flights','warehouse_movements','feo')");
        $query->execute([$database]);
        $engines = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $engines[$row['TABLE_NAME']] = strtoupper((string)$row['ENGINE']);
        foreach (['flights','warehouse_movements','feo'] as $name) if (($engines[$name] ?? '') !== 'INNODB') throw new RuntimeException('Складская таблица '.$name.' не поддерживает атомарную сверку.');
    }

    private static function table(string $database, string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $database) || !in_array($name, ['flights','warehouse_movements','feo'], true)) throw new RuntimeException('Некорректная база складского журнала.');
        return '`'.$database.'`.`'.$name.'`';
    }
}
