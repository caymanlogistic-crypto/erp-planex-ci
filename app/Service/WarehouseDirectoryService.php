<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

/** The existing warehouse address and its ERP contact record share one legacy DB transaction. */
final class WarehouseDirectoryService
{
    private const ADDRESS_COLUMNS = ['full_address', 'address', 'warehouse_address', 'adres', 'adres_sklada', 'location_address', 'physical_address'];

    public static function addressColumn(PDO $legacy): string
    {
        $columns = $legacy->query('SHOW COLUMNS FROM warehouses')->fetchAll(PDO::FETCH_COLUMN);
        foreach (self::ADDRESS_COLUMNS as $candidate) {
            if (in_array($candidate, $columns, true)) return $candidate;
        }
        throw new RuntimeException('В складском справочнике нет поля адреса.');
    }

    public static function list(PDO $legacy): array
    {
        $column = self::addressColumn($legacy);
        $coordinatesAvailable = self::coordinatesAvailable($legacy);
        $warehouses = $legacy->query('SELECT id,name,`'.$column.'` AS address FROM warehouses ORDER BY name,id')->fetchAll(PDO::FETCH_ASSOC);
        $contacts = $legacy->query('SELECT warehouse_id,contacts_json'.($coordinatesAvailable ? ',latitude,longitude' : '').' FROM warehouse_contacts')->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($contacts as $row) {
            $decoded = json_decode((string)$row['contacts_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !array_is_list($decoded)) throw new RuntimeException('Некорректная запись контактов склада.');
            $byId[(int)$row['warehouse_id']] = [
                'contacts'=>$decoded,
                'latitude'=>self::coordinateDisplay($row['latitude'] ?? null),
                'longitude'=>self::coordinateDisplay($row['longitude'] ?? null),
            ];
        }
        foreach ($warehouses as &$row) {
            $row['id'] = (int)$row['id'];
            $row['address'] = trim((string)($row['address'] ?? ''));
            $details = $byId[$row['id']] ?? ['contacts'=>[], 'latitude'=>null, 'longitude'=>null];
            $row += $details;
        }
        unset($row);
        return $warehouses;
    }

    public static function save(PDO $legacyReader, PDO $legacyWriter, string $legacyDatabase,
        int $warehouseId, string $expectedAddress, string $expectedContactsHash, string $expectedCoordinatesHash,
        string $address, ?string $latitude, ?string $longitude, array $contacts, int $userId): void
    {
        if ($warehouseId <= 0 || $userId <= 0) throw new RuntimeException('Некорректный склад или пользователь.');
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $legacyDatabase)) throw new RuntimeException('Некорректная база складов.');
        $address = trim($address);
        if ($address === '' || mb_strlen($address) > 500) throw new RuntimeException('Укажите полный адрес склада до 500 символов.');
        $latitude = self::coordinateInput($latitude, 90, 'широту');
        $longitude = self::coordinateInput($longitude, 180, 'долготу');
        if (($latitude === null) !== ($longitude === null)) throw new RuntimeException('Укажите широту и долготу вместе.');
        if (count($contacts) > 20) throw new RuntimeException('Можно указать не более 20 контактов склада.');
        $valid = [];
        foreach ($contacts as $contact) {
            if (!is_array($contact)) throw new RuntimeException('Некорректные данные контакта.');
            $name = trim((string)($contact['name'] ?? ''));
            $position = trim((string)($contact['position'] ?? ''));
            $phone = trim((string)($contact['phone'] ?? ''));
            $email = trim((string)($contact['email'] ?? ''));
            if ($name === '' && $position === '' && $phone === '' && $email === '') continue;
            if ($name === '' || ($phone === '' && $email === '')) throw new RuntimeException('У контакта нужны ФИО и телефон или email.');
            if (mb_strlen($name) > 255 || mb_strlen($position) > 255 || mb_strlen($phone) > 80 || mb_strlen($email) > 255) throw new RuntimeException('Поле контакта слишком длинное.');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('Проверьте email контакта.');
            $valid[] = compact('name', 'position', 'phone', 'email');
        }
        $column = self::addressColumn($legacyReader);
        $coordinatesAvailable = self::coordinatesAvailable($legacyReader);
        if (!$coordinatesAvailable && $latitude !== null) throw new RuntimeException('Сохранение координат пока недоступно.');
        $table = '`'.$legacyDatabase.'`.`warehouses`';
        $contactTable = '`'.$legacyDatabase.'`.`warehouse_contacts`';
        $legacyWriter->beginTransaction();
        try {
            $select = $legacyWriter->prepare('SELECT `'.$column.'` AS address FROM '.$table.' WHERE id=? FOR UPDATE');
            $select->execute([$warehouseId]);
            $current = $select->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new RuntimeException('Склад не найден.');
            if (trim((string)($current['address'] ?? '')) !== trim($expectedAddress)) throw new RuntimeException('Адрес склада изменился. Обновите страницу и повторите сохранение.');
            $contactSelect = $legacyWriter->prepare('SELECT contacts_json'.($coordinatesAvailable ? ',latitude,longitude' : '').' FROM '.$contactTable.' WHERE warehouse_id=? FOR UPDATE');
            $contactSelect->execute([$warehouseId]);
            $currentDetails = $contactSelect->fetch(PDO::FETCH_ASSOC) ?: null;
            $currentContacts = $currentDetails === null ? [] : json_decode((string)$currentDetails['contacts_json'], true, 512, JSON_THROW_ON_ERROR);
            $currentHash = hash('sha256', json_encode($currentContacts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (!hash_equals($currentHash, $expectedContactsHash)) throw new RuntimeException('Контакты склада изменились. Обновите страницу и повторите сохранение.');
            $currentCoordinatesHash = self::coordinatesHash(
                self::coordinateDisplay($currentDetails['latitude'] ?? null),
                self::coordinateDisplay($currentDetails['longitude'] ?? null)
            );
            if (!hash_equals($currentCoordinatesHash, $expectedCoordinatesHash)) throw new RuntimeException('Координаты склада изменились. Обновите страницу и повторите сохранение.');

            $coordinateColumns = $coordinatesAvailable ? ',latitude,longitude' : '';
            $coordinateValues = $coordinatesAvailable ? ',?,?' : '';
            $coordinateUpdate = $coordinatesAvailable ? ',latitude=VALUES(latitude),longitude=VALUES(longitude)' : '';
            $save = $legacyWriter->prepare('INSERT INTO '.$contactTable.' (warehouse_id,contacts_json,updated_by_user_id,updated_at'.$coordinateColumns.') VALUES (?,?,?,CURRENT_TIMESTAMP(6)'.$coordinateValues.') ON DUPLICATE KEY UPDATE contacts_json=VALUES(contacts_json),updated_by_user_id=VALUES(updated_by_user_id),updated_at=VALUES(updated_at)'.$coordinateUpdate);
            $params = [$warehouseId, json_encode($valid, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $userId];
            if ($coordinatesAvailable) array_push($params, $latitude, $longitude);
            $save->execute($params);
            if ($address !== trim((string)($current['address'] ?? ''))) {
                $update = $legacyWriter->prepare('UPDATE '.$table.' SET `'.$column.'`=? WHERE id=?');
                $update->execute([$address, $warehouseId]);
            }
            $legacyWriter->commit();
        } catch (Throwable $error) {
            if ($legacyWriter->inTransaction()) $legacyWriter->rollBack();
            throw $error;
        }
    }

    public static function coordinatesHash(?string $latitude, ?string $longitude): string
    {
        return hash('sha256', json_encode([$latitude, $longitude], JSON_THROW_ON_ERROR));
    }

    private static function coordinatesAvailable(PDO $legacy): bool
    {
        $columns = $legacy->query('SHOW COLUMNS FROM warehouse_contacts')->fetchAll(PDO::FETCH_COLUMN);
        $latitude = in_array('latitude', $columns, true);
        $longitude = in_array('longitude', $columns, true);
        if ($latitude !== $longitude) throw new RuntimeException('Поля координат склада установлены не полностью.');
        return $latitude;
    }

    private static function coordinateDisplay(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        $text = (string)$value;
        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    private static function coordinateInput(?string $value, int $maximum, string $label): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        $value = str_replace(',', '.', $value);
        if (!preg_match('/^[+-]?\\d{1,3}(?:\\.\\d{1,7})?$/D', $value) || abs((float)$value) > $maximum) {
            throw new RuntimeException('Проверьте '.$label.' склада: допустимо до 7 знаков после запятой.');
        }
        return ltrim($value, '+');
    }
}
