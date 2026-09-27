<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__.'/WasteTripLegacySourceService.php';
require_once __DIR__.'/FeoLegacyImportSync.php';

/** Corrects a legacy flight with no previous warehouse movements. */
final class WarehouseTripCorrectionService
{
    private const ROUTES = [
        'generator_to_warehouse' => ['receipt'],
        'warehouse_to_utilizer' => ['issue'],
        'warehouse_to_warehouse' => ['transfer_out', 'transfer_in'],
    ];

    public static function writeConnection(PDO $tenant): array
    {
        $sync = FeoLegacyImportSync::forTenant($tenant);
        if ($sync === null) throw new RuntimeException('Запись в старую базу для этой компании не настроена.');
        $database = $sync->database();
        if ($database !== trim((string)env('LEGACY_FLIGHTS_DB_DATABASE', ''))) {
            throw new RuntimeException('База записи не совпадает с базой складского журнала.');
        }
        return [$sync->connection(), $database];
    }

    public static function preview(PDO $pdo, string $database, int $flightId, string $route, int $source, int $destination, bool $lock = false): array
    {
        if ($flightId <= 0) throw new RuntimeException('Укажите ID рейса.');
        if (!isset(self::ROUTES[$route])) throw new RuntimeException('Выберите направление рейса.');
        $table = static fn(string $name): string => self::table($database, $name);
        $suffix = $lock ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT id,status,route_type,unload_type,source_warehouse_id,destination_warehouse_id,zayavki_ids,actual_end_date,comment FROM '.$table('flights').' WHERE id=? LIMIT 1'.$suffix);
        $stmt->execute([$flightId]);
        $flight = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$flight) throw new RuntimeException('Рейс не найден.');
        $ids = WasteTripLegacySourceService::parseRequestIdsStrict((string)$flight['zayavki_ids']);
        if (count($ids) > 1000) throw new RuntimeException('В рейсе слишком много заявок для одного исправления.');
        foreach ($ids as $id) if (filter_var($id, FILTER_VALIDATE_INT) === false || (int)$id <= 0) throw new RuntimeException('В рейсе есть некорректный ID заявки.');
        $errors = [];
        if ($ids === []) $errors[] = 'У рейса нет заявок.';
        if (in_array((string)$flight['status'], ['cancelled', 'canceled', 'deleted'], true)) $errors[] = 'Отменённый рейс исправлять нельзя.';
        if ($route === 'generator_to_warehouse') $source = 0;
        if ($route === 'warehouse_to_utilizer') $destination = 0;
        if ($route !== 'generator_to_warehouse' && $source <= 0) $errors[] = 'Выберите склад загрузки.';
        if ($route !== 'warehouse_to_utilizer' && $destination <= 0) $errors[] = 'Выберите склад выгрузки.';
        if ($source > 0 && $source === $destination) $errors[] = 'Склады загрузки и выгрузки должны различаться.';

        $warehouseIds = array_values(array_unique(array_filter([$source, $destination])));
        $warehouseNames = [];
        if ($warehouseIds !== []) {
            $marks = implode(',', array_fill(0, count($warehouseIds), '?'));
            $query = $pdo->prepare('SELECT id,name,is_active FROM '.$table('warehouses').' WHERE id IN ('.$marks.')'.$suffix);
            $query->execute($warehouseIds);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $warehouseNames[(int)$row['id']] = (string)$row['name'];
                if ((int)$row['is_active'] !== 1) $errors[] = 'Склад «'.$row['name'].'» неактивен.';
            }
            foreach ($warehouseIds as $id) if (!isset($warehouseNames[$id])) $errors[] = 'Склад #'.$id.' не найден.';
        }

        $query = $pdo->prepare('SELECT id FROM '.$table('warehouse_movements').' WHERE flight_id=?'.$suffix);
        $query->execute([$flightId]);
        $existing = $query->fetchAll(PDO::FETCH_COLUMN);
        if ($existing !== []) $errors[] = 'По рейсу уже есть складские движения. Автоматическая повторная разметка заблокирована.';

        $feo = [];
        if ($ids !== []) {
            foreach (array_chunk($ids, 300) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $query = $pdo->prepare('SELECT zayavka_id,naim_otkhoda_fkko,mass_netto,mass_brutto,summarnyy_obem FROM '.$table('feo').' WHERE zayavka_id IN ('.$marks.')'.$suffix);
                $query->execute($chunk);
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $id = (string)$row['zayavka_id'];
                    if (isset($feo[$id])) $errors[] = 'Дублируется заявка #'.$id.' в старой базе.';
                    $feo[$id] = $row;
                }
            }
        }
        $balances = [];
        if ($source > 0 && $ids !== []) {
            foreach (array_chunk($ids, 300) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                $query = $pdo->prepare('SELECT zayavka_id,movement_type,mass_netto,mass_brutto,volume FROM '.$table('warehouse_movements')." WHERE status='active' AND warehouse_id=? AND zayavka_id IN (".$marks.')'.$suffix);
                $query->execute(array_merge([$source], $chunk));
                foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $movement) {
                    $id = (string)$movement['zayavka_id'];
                    $sign = in_array($movement['movement_type'], ['receipt', 'transfer_in'], true) ? 1 : -1;
                    foreach (['mass_netto', 'mass_brutto', 'volume'] as $field) {
                        $balances[$id][$field] = ($balances[$id][$field] ?? 0) + $sign * (float)$movement[$field];
                    }
                }
            }
        }

        $rows = [];
        foreach ($ids as $id) {
            if (!isset($feo[$id])) { $errors[] = 'Заявка #'.$id.' отсутствует в старой базе.'; continue; }
            $item = $feo[$id];
            $net = (float)$item['mass_netto'];
            if ($net <= 0) $errors[] = 'У заявки #'.$id.' не указана положительная масса нетто.';
            if ($source > 0 && (($balances[$id]['mass_netto'] ?? 0) + 0.0001 < $net)) {
                $errors[] = 'На складе «'.($warehouseNames[$source] ?? '#'.$source).'» недостаточно остатка по заявке #'.$id.'.';
            }
            $rows[] = [
                'request_id'=>(int)$id,'fkko_code'=>(string)($item['naim_otkhoda_fkko'] ?? ''),
                'mass_netto'=>$item['mass_netto'],'mass_brutto'=>$item['mass_brutto'],
                'volume'=>$item['summarnyy_obem'],
                'available_netto'=>$balances[$id]['mass_netto'] ?? 0,
            ];
        }
        $fingerprint = hash('sha256', json_encode([$flight, $existing, $warehouseNames, $rows, $balances], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return [
            'flight_id'=>$flightId,'name'=>(string)$flight['comment'],'status'=>(string)$flight['status'],
            'current_route'=>(string)$flight['route_type'],'request_count'=>count($ids),
            'route_type'=>$route,'source_warehouse_id'=>$source,'destination_warehouse_id'=>$destination,
            'warehouses'=>$warehouseNames,'rows'=>$rows,'operation_count'=>count($rows)*count(self::ROUTES[$route]),
            'errors'=>array_values(array_unique($errors)),'ready'=>$errors === [],'fingerprint'=>$fingerprint,
            'movement_date'=>(string)($flight['actual_end_date'] ?: date('Y-m-d H:i:s')),
        ];
    }

    public static function apply(PDO $pdo, string $database, int $flightId, string $route, int $source, int $destination, string $fingerprint, int $actorId = 0): array
    {
        if ($fingerprint === '' || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) throw new RuntimeException('Повторите предварительную проверку рейса.');
        self::assertTransactionalTables($pdo, $database);
        $lockName = 'planex_warehouse_correction';
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Складской учёт занят. Повторите попытку.');
        try {
            if ($pdo->inTransaction()) throw new RuntimeException('Складское исправление нельзя начать внутри другой транзакции.');
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            $pdo->beginTransaction();
            $preview = self::preview($pdo, $database, $flightId, $route, $source, $destination, true);
            if (!hash_equals($fingerprint, $preview['fingerprint'])) throw new RuntimeException('Данные рейса или склада изменились. Повторите предварительную проверку.');
            if (!$preview['ready']) throw new RuntimeException(implode(' ', $preview['errors']));
            $flightTable = self::table($database, 'flights');
            $movementTable = self::table($database, 'warehouse_movements');
            $update = $pdo->prepare('UPDATE '.$flightTable.' SET route_type=?,unload_type=?,source_warehouse_id=?,destination_warehouse_id=? WHERE id=?');
            $update->execute([$route, $route === 'warehouse_to_utilizer' ? 'OO' : 'SKLAD', $preview['source_warehouse_id'] ?: null, $preview['destination_warehouse_id'] ?: null, $flightId]);
            $insert = $pdo->prepare('INSERT INTO '.$movementTable.' (movement_type,warehouse_id,source_warehouse_id,destination_warehouse_id,flight_id,zayavka_id,fkko_code,mass_netto,mass_brutto,volume,movement_date,status,comment,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $created = 0;
            foreach ($preview['rows'] as $row) {
                foreach (self::ROUTES[$route] as $type) {
                    $warehouseId = in_array($type, ['issue', 'transfer_out'], true) ? $preview['source_warehouse_id'] : $preview['destination_warehouse_id'];
                    $insert->execute([
                        $type,$warehouseId,$preview['source_warehouse_id'] ?: null,$preview['destination_warehouse_id'] ?: null,
                        $flightId,$row['request_id'],$row['fkko_code'],$row['mass_netto'],$row['mass_brutto'],$row['volume'],
                        $preview['movement_date'],'active','Исправление складского направления рейса #'.$flightId.' в ERP PLANEX пользователем #'.$actorId,
                    ]);
                    $created++;
                }
            }
            if ($created !== $preview['operation_count']) throw new RuntimeException('Не все складские движения созданы.');
            $check = $pdo->prepare('SELECT COUNT(*) FROM '.$movementTable.' WHERE flight_id=?');
            $check->execute([$flightId]);
            if ((int)$check->fetchColumn() !== $created) throw new RuntimeException('Контроль складских движений не прошёл.');
            $pdo->commit();
            return ['flight_id'=>$flightId,'created'=>$created,'request_count'=>$preview['request_count']];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        } finally {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable) {
                // The connection closing releases its advisory lock. Do not hide a committed result.
            }
        }
    }

    private static function table(string $database, string $table): string
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)) throw new RuntimeException('Некорректная настройка базы складского журнала.');
        return '`'.$database.'`.`'.$table.'`';
    }

    private static function assertTransactionalTables(PDO $pdo, string $database): void
    {
        self::table($database, 'flights');
        $query = $pdo->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('flights','warehouse_movements','feo','warehouses')");
        $query->execute([$database]);
        $engines = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $engines[(string)$row['TABLE_NAME']] = strtoupper((string)$row['ENGINE']);
        foreach (['flights','warehouse_movements','feo','warehouses'] as $table) {
            if (($engines[$table] ?? '') !== 'INNODB') {
                throw new RuntimeException('Складской журнал не поддерживает атомарное исправление: проверьте таблицу '.$table.'.');
            }
        }
    }
}
