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

    private function __construct(private readonly PDO $pdo, private readonly string $database)
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
        return new self($pdo, $legacy);
    }

    public function validate(): void
    {
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Синхронизация ФЭО требует общей транзакции.');
        // Take a metadata lock before inspecting engines/triggers and before any legacy write.
        $this->pdo->query('SELECT id FROM ' . $this->table . ' LIMIT 0 FOR UPDATE');
        $engines = $this->pdo->prepare("SELECT TABLE_SCHEMA,TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE (TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('feo_requests','feo_imports','feo_import_changes')) OR (TABLE_SCHEMA=? AND TABLE_NAME='feo')");
        $engines->execute([$this->database]);
        $tables = $engines->fetchAll(PDO::FETCH_ASSOC);
        if (count($tables) !== 4) throw new RuntimeException('Не найдены все таблицы синхронизации ФЭО.');
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
        $cols = $this->pdo->prepare('SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,NUMERIC_PRECISION,NUMERIC_SCALE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'feo\' ORDER BY ORDINAL_POSITION');
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

    /** Reject silent truncation/rounding: the legacy schema has smaller numeric/text columns. */
    private function compatibleValue(string $field, mixed $value, string $id): mixed
    {
        if ($value === null) return null;
        $meta = $this->columns[$field];
        $text = (string)$value;
        if (in_array($meta['DATA_TYPE'], ['int','bigint','smallint','tinyint','mediumint'], true)) {
            if (!preg_match('/^-?\d+$/D', $text)) throw new RuntimeException("Некорректное целое {$field} заявки {$id}.");
            return (string)(int)$text;
        }
        if ($meta['CHARACTER_MAXIMUM_LENGTH'] !== null && mb_strlen($text, 'UTF-8') > (int)$meta['CHARACTER_MAXIMUM_LENGTH']) {
            throw new RuntimeException("Заявка {$id}: поле {$field} длиннее допустимого в старой базе.");
        }
        if (in_array($meta['DATA_TYPE'], ['decimal','numeric'], true)) {
            if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/D', $text, $m)) throw new RuntimeException("Некорректное число {$field} заявки {$id}.");
            $scale = (int)$meta['NUMERIC_SCALE'];
            $integer = ltrim($m[2], '0');
            $fraction = rtrim($m[3] ?? '', '0');
            if (strlen($integer) > (int)$meta['NUMERIC_PRECISION'] - $scale || strlen($fraction) > $scale) {
                throw new RuntimeException("Заявка {$id}: число {$field} нельзя сохранить в старой базе без потери точности.");
            }
            return $m[1] . ($integer === '' ? '0' : $integer) . ($scale ? '.' . str_pad($fraction, $scale, '0') : '');
        }
        return $text;
    }
}
