<?php

namespace App\Service;

use PDO;
use RuntimeException;

/** A legacy table on the SAME MySQL connection participates in the tenant transaction. */
final class FeoLegacyImportSync
{
    public const JOURNAL_KEY = '__legacy_import_sync';
    private array $columns = [];
    private string $table;

    private function __construct(private readonly PDO $pdo, private readonly string $database, private readonly PDO $tenantPdo)
    {
        $this->table = '`' . $database . '`.`feo`';
    }

    public static function forTenant(PDO $pdo): ?self
    {
        $tenant = trim((string)env('FEO_LEGACY_SYNC_TENANT_DATABASE', ''));
        $legacy = trim((string)env('FEO_LEGACY_SYNC_DATABASE', ''));
        if ($tenant === '' && $legacy === '') return null;
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $tenant) || !preg_match('/^[A-Za-z0-9_]{1,64}$/D', $legacy)) {
            throw new RuntimeException('Настройка синхронизации ФЭО неполна: нужны базы компании и старой системы.');
        }
        if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== $tenant) return null;
        $connection = trim((string)env('FEO_LEGACY_SYNC_CONNECTION_B64', ''));
        if ($connection === '') return new self($pdo, $legacy, $pdo);
        try {
            $c = json_decode((string)base64_decode($connection, true), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($c) || !is_string($c['host'] ?? null) || !preg_match('/^[a-zA-Z0-9_.-]+$/D', $c['host'])
                || !is_string($c['username'] ?? null) || $c['username'] === '' || !is_string($c['password'] ?? null)) throw new RuntimeException();
            $port = (int)($c['port'] ?? 3306);
            if ($port < 1 || $port > 65535) throw new RuntimeException();
            $legacyPdo = new PDO('mysql:host='.$c['host'].';port='.$port.';dbname='.$legacy.';charset=utf8mb4', $c['username'], $c['password'],
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>10]);
        } catch (\Throwable $e) {
            // Never expose connection JSON/password or PDO exception arguments to the UI/log.
            throw new RuntimeException('Не удалось открыть отдельное подключение к старой БД ФЭО. Проверьте защищённую настройку доступа.');
        }
        return new self($legacyPdo, $legacy, $pdo);
    }

    public function connection(): PDO { return $this->pdo; }

    public function database(): string { return $this->database; }

    /** Mirror only the three location fields; never overwrite unrelated legacy edits. */
    public function mirrorLocation(string $id, array $before, array $after): void
    {
        $this->assertReady();
        $fields = FeoLocationOverrideService::FIELDS;
        $current = $this->find($id);
        if ($current === null) throw new RuntimeException('Заявка №'.$id.' не найдена в старой базе. Перемещение отменено.');
        foreach ($fields as $field) {
            if (!array_key_exists($field, $before) || !array_key_exists($field, $after)
                || !$this->sameValue($current[$field] ?? null, $before[$field])) {
                throw new RuntimeException('Место заявки №'.$id.' в старой базе отличается от ERP. Перемещение отменено.');
            }
        }
        $values = array_intersect_key($after, array_flip($fields));
        $this->update($id, $values);
        $saved = $this->find($id);
        foreach ($fields as $field) {
            if (!$this->sameValue($saved[$field] ?? null, $after[$field])) {
                throw new RuntimeException('Не удалось точно сохранить место заявки №'.$id.' в старой базе. Перемещение отменено.');
            }
        }
    }

    /** Return the exact legacy values so a later operation can reverse only this weight change. */
    public function writeWeight(string $id, array $weights): array
    {
        $this->assertReady();
        $fields = ['mass_netto', 'mass_brutto'];
        if (!$this->weightFieldsOnly($weights)) throw new RuntimeException('Для изменения веса нужны только нетто и брутто.');
        $current = $this->find($id);
        if ($current === null) throw new RuntimeException('Заявка №'.$id.' не найдена в старой базе. Изменение веса отменено.');
        $before = ['mass_netto'=>$current['mass_netto'], 'mass_brutto'=>$current['mass_brutto']];
        $values = [];
        foreach ($fields as $field) $values[$field] = $this->compatibleValue($field, $weights[$field], $id);
        $this->update($id, $values);
        $saved = $this->find($id);
        $after = $saved === null ? [] : ['mass_netto'=>$saved['mass_netto'], 'mass_brutto'=>$saved['mass_brutto']];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $after) || !$this->sameValue($after[$field], $values[$field])) {
                throw new RuntimeException('Не удалось точно сохранить вес заявки №'.$id.' в старой базе.');
            }
        }
        return ['version'=>1, 'database'=>$this->database, 'zayavka_id'=>$id, 'before'=>$before, 'after'=>$after];
    }

    /** Refuse a late edit, then restore exactly the two weights captured before writeWeight. */
    public function revertWeight(string $id, array $snapshot): array
    {
        $this->assertReady();
        $fields = ['mass_netto', 'mass_brutto'];
        if (($snapshot['version'] ?? null) !== 1 || ($snapshot['database'] ?? null) !== $this->database
            || ($snapshot['zayavka_id'] ?? null) !== $id || !is_array($snapshot['before'] ?? null)
            || !is_array($snapshot['after'] ?? null) || !$this->weightFieldsOnly($snapshot['before'])
            || !$this->weightFieldsOnly($snapshot['after'])) {
            throw new RuntimeException('Снимок веса старой заявки не соответствует операции.');
        }
        $current = $this->find($id);
        if ($current === null) throw new RuntimeException('Заявка №'.$id.' не найдена в старой базе. Возврат веса отменён.');
        foreach ($fields as $field) {
            if (!$this->sameValue($current[$field] ?? null, $snapshot['after'][$field])) {
                throw new RuntimeException('Вес заявки №'.$id.' в старой базе изменён после записи. Возврат отменён.');
            }
        }
        $restore = ['mass_netto'=>$snapshot['before']['mass_netto'], 'mass_brutto'=>$snapshot['before']['mass_brutto']];
        $this->update($id, $restore);
        $saved = $this->find($id);
        foreach ($fields as $field) {
            if (!$this->sameValue($saved[$field] ?? null, $snapshot['before'][$field])) {
                throw new RuntimeException('Не удалось точно вернуть вес заявки №'.$id.' в старой базе.');
            }
        }
        return ['version'=>1, 'database'=>$this->database, 'zayavka_id'=>$id,
            'before'=>$snapshot['after'], 'after'=>$snapshot['before']];
    }

    private function weightFieldsOnly(array $weights): bool
    {
        if (count($weights) !== 2 || !array_key_exists('mass_netto',$weights) || !array_key_exists('mass_brutto',$weights)) return false;
        foreach ($weights as $value) if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value)) return false;
        return true;
    }

    public function validate(): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Синхронизация ФЭО требует общей транзакции.');
        // Take a metadata lock before inspecting engines/triggers and before any legacy write.
        $this->pdo->query('SELECT id FROM ' . $this->table . ' LIMIT 0 FOR UPDATE');
        $engines = $this->pdo->prepare("SELECT TABLE_SCHEMA,TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME='feo'");
        $engines->execute([$this->database]);
        $tables = $engines->fetchAll(PDO::FETCH_ASSOC);
        $tenantTables = $this->tenantPdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('feo_requests','feo_imports','feo_import_changes')")->fetchAll(PDO::FETCH_ASSOC);
        if (count($tables) !== 1 || count($tenantTables) !== 3) throw new RuntimeException('Не найдены все таблицы синхронизации ФЭО.');
        $tables = array_merge($tables, $tenantTables);
        foreach ($tables as $table) {
            if (strcasecmp((string)$table['ENGINE'], 'InnoDB') !== 0) throw new RuntimeException('Обе базы ФЭО должны использовать InnoDB.');
        }
        // SHOW TRIGGERS can hide objects without TRIGGER privilege: fail closed in that case.
        $triggerVisible = false;
        $writePrivileges = [];
        foreach ($this->pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (preg_match('/^GRANT (.+) ON (.+) TO /i', (string)$grant, $m)) {
                $scope = str_replace('`', '', $m[2]);
                if (in_array($scope, ['*.*', $this->database . '.*', $this->database . '.feo'], true)) {
                    $privileges = array_map('trim', explode(',', strtoupper($m[1])));
                    if (in_array('ALL PRIVILEGES', $privileges, true)) $privileges = ['SELECT','INSERT','UPDATE','DELETE','TRIGGER'];
                    $writePrivileges = array_merge($writePrivileges, $privileges);
                    if (in_array('TRIGGER', $privileges, true)) $triggerVisible = true;
                }
            }
        }
        if (!$triggerVisible) throw new RuntimeException('Для проверки безопасности таблицы feo требуется видимость её триггеров (право TRIGGER).');
        if (array_diff(['SELECT','INSERT','UPDATE','DELETE'], $writePrivileges) !== []) throw new RuntimeException('У подключения новой ERP недостаточно прав SELECT, INSERT, UPDATE, DELETE на старую таблицу feo.');
        $triggers = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE=\'feo\'');
        $triggers->execute([$this->database]);
        if ((int)$triggers->fetchColumn() !== 0) throw new RuntimeException('У таблицы feo есть триггеры: синхронизация остановлена.');
        $fk = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE UNIQUE_CONSTRAINT_SCHEMA=? AND REFERENCED_TABLE_NAME='feo' AND (DELETE_RULE IN ('CASCADE','SET NULL') OR UPDATE_RULE IN ('CASCADE','SET NULL'))");
        $fk->execute([$this->database]);
        if ((int)$fk->fetchColumn() !== 0) throw new RuntimeException('Каскадные связи старой таблицы feo не поддерживаются.');
        $cols = $this->pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH,CHARACTER_OCTET_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'feo\' ORDER BY ORDINAL_POSITION');
        $cols->execute([$this->database]);
        foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (preg_match('/(?:VIRTUAL|STORED) GENERATED/i', (string)$column['EXTRA'])) throw new RuntimeException('Вычисляемые поля старой feo не поддерживаются.');
            $this->columns[$column['COLUMN_NAME']] = $column;
        }
        foreach (array_merge(['id','created_at','updated_at'], FeoXlsxParser::fields()) as $field) {
            if (!isset($this->columns[$field])) throw new RuntimeException('В старой feo отсутствует поле ' . $field . '.');
        }
        $unique = $this->pdo->prepare("SELECT INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) columns_list,MAX(COALESCE(SUB_PART,0)) prefix_length FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='feo' AND NON_UNIQUE=0 GROUP BY INDEX_NAME");
        $unique->execute([$this->database]);
        $keys = [];
        foreach ($unique->fetchAll(PDO::FETCH_ASSOC) as $index) if ((int)$index['prefix_length'] === 0) $keys[] = $index['columns_list'];
        if (!in_array('id', $keys, true) || !in_array('zayavka_id', $keys, true)) throw new RuntimeException('Старая feo требует уникальных id и zayavka_id.');
    }

    /** Snapshot the legacy values independently of the tenant's before/after values. */
    public function apply(array $row, bool $overwriteExisting, array $excludedFields = []): array
    {
        $this->assertReady();
        $id = (string)$row['zayavka_id'];
        $before = $this->find($id);
        if ($before !== null && !$overwriteExisting) return $this->snapshot($before, $before);
        $values = [];
        foreach (FeoXlsxParser::fields() as $field) {
            if ($before !== null && in_array($field, $excludedFields, true)) continue;
            $values[$field] = $this->compatibleValue($field, $row[$field] ?? null, $id);
        }
        // The identity must still address the same request in both journals/databases.
        if ((string)$values['zayavka_id'] !== $id) throw new RuntimeException('Номер заявки ' . $id . ' не помещается в старой базе без изменения её идентификатора.');
        if ($before === null) {
            $this->insert($values);
        } else {
            // Only changed fields belong to this import; legacy-only columns remain intact.
            $changed = [];
            foreach ($values as $field => $value) {
                if (!$this->sameValue($before[$field], $value)) $changed[$field] = $value;
            }
            if ($changed !== []) $this->update($id, $changed);
        }
        $after = $this->find($id);
        if ($after === null) throw new RuntimeException('Не удалось проверить запись старой заявки ' . $id . '.');
        foreach ($values as $field => $value) {
            if (!$this->sameValue($after[$field], $value)) throw new RuntimeException("Заявка {$id}: старая база изменила значение {$field}; загрузка отменена.");
        }
        return $this->snapshot($before, $after);
    }

    public function replay(array $journal, string $id, bool $forward): void
    {
        $this->assertReady();
        if (($journal['version'] ?? null) !== 1 || ($journal['database'] ?? '') !== $this->database
            || !array_key_exists('before', $journal) || !is_array($journal['after'])) {
            throw new RuntimeException('Журнал синхронизации ФЭО не соответствует настроенной старой базе.');
        }
        $before = $journal['before'];
        $after = $journal['after'];
        foreach ([$before, $after] as $snapshot) {
            if ($snapshot === null) continue;
            if (!is_array($snapshot) || (string)($snapshot['zayavka_id'] ?? '') !== $id
                || array_keys($snapshot) !== array_keys($this->columns)) throw new RuntimeException('Изменилась структура старой feo или повреждён журнал.');
        }
        // A pre-existing row skipped by import never becomes owned by that import.
        if ($before === $after) return;
        $expected = $forward ? $before : $after;
        $target = $forward ? $after : $before;
        $current = $this->find($id);
        if ($current !== $expected) throw new RuntimeException("Заявка {$id} в старой базе изменилась после загрузки. Операция в обеих базах отменена.");
        if ($target === null) {
            $delete = $this->pdo->prepare('DELETE FROM ' . $this->table . ' WHERE id=? AND zayavka_id=?');
            $delete->execute([$current['id'], $id]);
            if ($delete->rowCount() !== 1) throw new RuntimeException('Не удалось отменить добавление старой заявки.');
        } elseif ($current === null) {
            $this->insert($target);
        } else {
            $values = $target;
            unset($values['id'], $values['zayavka_id']);
            $this->update($id, $values);
        }
        if ($this->find($id) !== $target) throw new RuntimeException('Проверка восстановления старой заявки не пройдена.');
    }

    private function snapshot(?array $before, array $after): array
    {
        return ['version'=>1,'database'=>$this->database,'before'=>$before,'after'=>$after];
    }

    private function assertReady(): void
    {
        if (!$this->pdo->inTransaction() || $this->columns === []) throw new RuntimeException('Синхронизация ФЭО не подготовлена.');
    }

    private function find(string $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM ' . $this->table . ' WHERE zayavka_id=? FOR UPDATE');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        // PDO integer typing must not change the durable snapshot after JSON decoding.
        return array_map(static fn($v) => $v === null ? null : (string)$v, $row);
    }

    private function insert(array $values): void
    {
        $columns = implode(',', array_map([$this, 'quoteColumn'], array_keys($values)));
        $marks = implode(',', array_fill(0, count($values), '?'));
        $this->pdo->prepare('INSERT INTO ' . $this->table . " ({$columns}) VALUES ({$marks})")->execute(array_values($values));
    }

    private function update(string $id, array $values): void
    {
        $sets = implode(',', array_map(fn($key) => $this->quoteColumn($key) . '=?', array_keys($values)));
        $this->pdo->prepare('UPDATE ' . $this->table . ' SET ' . $sets . ' WHERE zayavka_id=?')->execute([...array_values($values), $id]);
    }

    private function quoteColumn(string $column): string
    {
        if (!isset($this->columns[$column])) throw new RuntimeException('Неизвестное поле старой feo.');
        return '`' . str_replace('`', '``', $column) . '`';
    }

    private function sameValue(mixed $a, mixed $b): bool
    {
        return ($a === null ? null : (string)$a) === ($b === null ? null : (string)$b);
    }

    /** OWNER: fit legacy values to its schema; the tenant row keeps its original values. */
    private function compatibleValue(string $field, mixed $value, string $id): mixed
    {
        if ($value === null) return null;
        $meta = $this->columns[$field];
        $text = (string)$value;
        $type = $meta['DATA_TYPE'];
        $unsigned = stripos((string)$meta['COLUMN_TYPE'], 'unsigned') !== false;
        $integerLimits = [
            'tinyint'=>['128','127','255'], 'smallint'=>['32768','32767','65535'],
            'mediumint'=>['8388608','8388607','16777215'], 'int'=>['2147483648','2147483647','4294967295'],
            'bigint'=>['9223372036854775808','9223372036854775807','18446744073709551615'],
        ];
        if (isset($integerLimits[$type])) {
            if (!preg_match('/^-?\d+$/D', $text)) throw new RuntimeException("Некорректное целое {$field} заявки {$id}.");
            $negative = str_starts_with($text, '-');
            $digits = ltrim(ltrim($text, '-'), '0') ?: '0';
            if ($unsigned && $negative) return '0';
            $limit = $integerLimits[$type][$unsigned ? 2 : ($negative ? 0 : 1)];
            if ($this->exceedsUnsigned($digits, $limit)) $digits = $limit;
            return ($negative && $digits !== '0' ? '-' : '') . $digits;
        }
        if ($meta['CHARACTER_MAXIMUM_LENGTH'] !== null) {
            $text = mb_substr($text, 0, (int)$meta['CHARACTER_MAXIMUM_LENGTH'], 'UTF-8');
            // TEXT capacity is in bytes; never split a UTF-8 character at the boundary.
            if (in_array($type, ['tinytext','text','mediumtext','longtext'], true)) {
                $text = mb_strcut($text, 0, (int)$meta['CHARACTER_OCTET_LENGTH'], 'UTF-8');
            }
            if ($type === 'char') $text = rtrim($text, ' ');
        }
        if (in_array($type, ['decimal','numeric'], true)) {
            if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $m)) throw new RuntimeException("Некорректное число {$field} заявки {$id}.");
            $scale = (int)$meta['NUMERIC_SCALE'];
            $integer = ltrim($m[2], '0') ?: '0';
            $fraction = str_pad(substr($m[3] ?? '', 0, $scale), $scale, '0');
            $limit = str_repeat('9', (int)$meta['NUMERIC_PRECISION'] - $scale) ?: '0';
            if ($unsigned && $m[1] === '-') {
                $integer = '0'; $fraction = str_repeat('0', $scale);
            } elseif ($this->exceedsUnsigned($integer, $limit)) {
                $integer = $limit; $fraction = str_repeat('9', $scale);
            }
            $sign = $m[1] === '-' && !$unsigned && trim($integer . $fraction, '0') !== '' ? '-' : '';
            return $sign . $integer . ($scale ? '.' . $fraction : '');
        }
        return $text;
    }

    private function exceedsUnsigned(string $value, string $limit): bool
    {
        return strlen($value) > strlen($limit) || (strlen($value) === strlen($limit) && strcmp($value, $limit) > 0);
    }
}
