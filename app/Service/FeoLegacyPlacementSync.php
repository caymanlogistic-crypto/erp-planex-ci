<?php
declare(strict_types=1);

namespace App\Service;

use PDO;
use PDOException;
use RuntimeException;

/** Mirrors the authoritative new-ERP placement set into legacy "Доступно" blocks. */
final class FeoLegacyPlacementSync
{
    public const LEGACY_AVAILABLE_BLOCK_MIN_ID = 109;
    private const CHUNK = 800;
    private string $blocksTable;
    private string $feoTable;

    public function __construct(
        private readonly PDO $legacy,
        private readonly string $database,
        private readonly PDO $tenant
    ) {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)) {
            throw new RuntimeException('Некорректная настройка старой базы ФЭО.');
        }
        $quoted = '`' . $database . '`';
        $this->blocksTable = $quoted . '.`status_blocks`';
        $this->feoTable = $quoted . '.`feo`';
    }

    public static function fromTransaction(FeoImportTransaction $transaction, PDO $tenant): ?self
    {
        $sync = $transaction->sync();
        return $sync === null ? null : new self($sync->connection(), $sync->database(), $tenant);
    }

    /** Read-only structural readiness used by the guarded configuration check. */
    public function validate(): void
    {
        $this->assertPrivileges();
        $tables = $this->legacy->prepare(
            "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('feo','status_blocks')"
        );
        $tables->execute([$this->database]);
        $byName = [];
        foreach ($tables->fetchAll(PDO::FETCH_ASSOC) as $table) $byName[(string)$table['TABLE_NAME']] = (string)$table['ENGINE'];
        if (count($byName) !== 2) throw new RuntimeException('В старой базе не найдены таблицы feo и status_blocks.');
        foreach ($byName as $engine) {
            if (strcasecmp($engine, 'InnoDB') !== 0) throw new RuntimeException('Таблицы feo и status_blocks старой базы должны использовать InnoDB.');
        }

        $columns = $this->legacy->prepare(
            "SELECT COLUMN_NAME,DATA_TYPE,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='status_blocks'"
        );
        $columns->execute([$this->database]);
        $byColumn = [];
        foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) $byColumn[(string)$column['COLUMN_NAME']] = $column;
        foreach (['id','block_date','comment','status_type','status_value','zayavki_count','total_mass_tonn','zayavki_ids'] as $required) {
            if (!isset($byColumn[$required])) throw new RuntimeException('В старой status_blocks отсутствует поле ' . $required . '.');
        }
        if (!str_contains(strtolower((string)$byColumn['id']['EXTRA']), 'auto_increment')) {
            throw new RuntimeException('Старая status_blocks требует автоинкрементный id.');
        }
        if (!in_array(strtolower((string)$byColumn['total_mass_tonn']['DATA_TYPE']), ['decimal','double','float'], true)) {
            throw new RuntimeException('Поле total_mass_tonn старой status_blocks должно быть числовым.');
        }

        $tenantTables = $this->tenant->query(
            "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('feo_placement_blocks','feo_placement_block_requests','feo_placement_current')"
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($tenantTables) !== 3) throw new RuntimeException('Не найдены таблицы размещения ФЭО новой ERP.');
        foreach ($tenantTables as $table) {
            if (strcasecmp((string)$table['ENGINE'], 'InnoDB') !== 0) throw new RuntimeException('Таблицы размещения ФЭО должны использовать InnoDB.');
        }

        $triggers = $this->legacy->prepare(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA=? AND EVENT_OBJECT_TABLE='status_blocks'"
        );
        $triggers->execute([$this->database]);
        if ((int)$triggers->fetchColumn() !== 0) throw new RuntimeException('У старой status_blocks есть триггеры: зеркалирование остановлено.');
        $foreignKeys = $this->legacy->prepare(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE UNIQUE_CONSTRAINT_SCHEMA=? AND REFERENCED_TABLE_NAME='status_blocks' AND (DELETE_RULE IN ('CASCADE','SET NULL') OR UPDATE_RULE IN ('CASCADE','SET NULL'))"
        );
        $foreignKeys->execute([$this->database]);
        if ((int)$foreignKeys->fetchColumn() !== 0) {
            throw new RuntimeException('Каскадные связи старой status_blocks не поддерживаются.');
        }
    }

    private function assertPrivileges(): void
    {
        $privileges = [];
        foreach ($this->legacy->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (preg_match('/^GRANT (.+) ON (.+) TO /i', (string)$grant, $match) !== 1) continue;
            $scope = str_replace('`', '', $match[2]);
            if (!in_array($scope, ['*.*', $this->database . '.*', $this->database . '.status_blocks'], true)) continue;
            $granted = array_map('trim', explode(',', strtoupper($match[1])));
            if (in_array('ALL PRIVILEGES', $granted, true)) {
                $granted = ['SELECT','INSERT','UPDATE','DELETE','TRIGGER'];
            }
            $privileges = array_merge($privileges, $granted);
        }
        if (array_diff(['SELECT','INSERT','UPDATE','DELETE','TRIGGER'], $privileges) !== []) {
            throw new RuntimeException('У подключения новой ERP недостаточно прав SELECT, INSERT, UPDATE, DELETE, TRIGGER на старую status_blocks.');
        }
    }

    /**
     * @param array<string,int> $before authoritative new-ERP set before mutation
     * @param array<string,int> $after authoritative new-ERP set after mutation
     * @return array{enabled:bool,added:int,removed:int,updated_blocks:int,deleted_blocks:int,created_block_id:?int}
     */
    public function apply(array $before, array $after, string $context): array
    {
        $added = array_values(array_diff(array_keys($after), array_keys($before)));
        $removed = array_values(array_diff(array_keys($before), array_keys($after)));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        $result = ['enabled'=>true,'added'=>count($added),'removed'=>count($removed),'updated_blocks'=>0,'deleted_blocks'=>0,'created_block_id'=>null];
        if ($added === [] && $removed === []) return $result;

        try {
            $this->validate();
            $query = $this->legacy->query(
                'SELECT id,zayavki_ids FROM ' . $this->blocksTable
                . " WHERE id > " . self::LEGACY_AVAILABLE_BLOCK_MIN_ID
                . " AND status_type='dostupno' ORDER BY id FOR UPDATE"
            );
            $blocks = [];
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $blocks[(int)$row['id']] = $this->parseIds((string)($row['zayavki_ids'] ?? ''), false);
            }

            $removeMap = array_fill_keys($removed, true);
            $addedMap = array_fill_keys($added, true);
            $seenAdded = [];
            $changed = [];
            foreach ($blocks as $blockId => $ids) {
                $next = [];
                foreach ($ids as $id) {
                    if (isset($removeMap[$id])) continue;
                    if (isset($addedMap[$id])) {
                        if (isset($seenAdded[$id])) continue;
                        $seenAdded[$id] = true;
                    }
                    $next[] = $id;
                }
                if ($next !== $ids) {
                    $blocks[$blockId] = $next;
                    $changed[$blockId] = true;
                }
            }

            $allRemainingIds = [];
            foreach ($changed as $blockId => $_) foreach ($blocks[$blockId] as $id) $allRemainingIds[$id] = true;
            $masses = $this->masses(array_keys($allRemainingIds));
            $update = $this->legacy->prepare(
                'UPDATE ' . $this->blocksTable . ' SET zayavki_ids=?,zayavki_count=?,total_mass_tonn=? WHERE id=?'
            );
            $delete = $this->legacy->prepare('DELETE FROM ' . $this->blocksTable . ' WHERE id=?');
            foreach (array_keys($changed) as $blockId) {
                $ids = $blocks[$blockId];
                if ($ids === []) {
                    $delete->execute([$blockId]);
                    if ($delete->rowCount() !== 1) throw new RuntimeException('Не удалось удалить пустой блок старой ERP.');
                    unset($blocks[$blockId]);
                    $result['deleted_blocks']++;
                    continue;
                }
                $mass = 0.0;
                foreach ($ids as $id) $mass += (float)$masses[$id];
                $update->execute([implode(',', $ids), count($ids), round($mass, 3), $blockId]);
                if ($update->rowCount() > 1) throw new RuntimeException('Некорректное обновление блока старой ERP.');
                $result['updated_blocks']++;
            }

            $missingAdded = array_values(array_filter($added, static fn(string $id): bool => !isset($seenAdded[$id])));
            if ($missingAdded !== []) {
                $newMasses = $this->masses($missingAdded);
                $mass = 0.0;
                foreach ($missingAdded as $id) $mass += (float)$newMasses[$id];
                $insert = $this->legacy->prepare(
                    'INSERT INTO ' . $this->blocksTable
                    . " (block_date,comment,status_type,status_value,zayavki_count,total_mass_tonn,zayavki_ids)"
                    . " VALUES (CURRENT_TIMESTAMP,?,'dostupno','Доступно к вывозу',?,?,?)"
                );
                $insert->execute([$this->comment($context), count($missingAdded), round($mass, 3), implode(',', $missingAdded)]);
                $created = (int)$this->legacy->lastInsertId();
                if ($created <= self::LEGACY_AVAILABLE_BLOCK_MIN_ID) {
                    throw new RuntimeException('Новый блок старой ERP получил исторический ID и не входит в актуальный пул.');
                }
                $result['created_block_id'] = $created;
            }

            $this->assertMembership($added, 1);
            $this->assertMembership($removed, 0);
            return $result;
        } catch (PDOException $e) {
            error_log('FEO placement legacy mirror DB error: ' . $e->getMessage());
            throw new RuntimeException('Не удалось зеркально обновить состав заявок в старой ERP. Операция новой ERP отменена.');
        }
    }

    /** @return list<string> */
    private function parseIds(string $raw, bool $unique = true): array
    {
        $out = [];
        $seen = [];
        foreach (preg_split('/[,;\s]+/u', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $id = trim((string)$part);
            if (preg_match('/^[0-9]+$/D', $id) !== 1) throw new RuntimeException('Старая status_blocks содержит некорректный номер заявки.');
            if ($unique && isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = $id;
        }
        return $out;
    }

    /** @param list<string> $ids @return array<string,string> */
    private function masses(array $ids): array
    {
        if ($ids === []) return [];
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $query = $this->legacy->prepare(
                'SELECT zayavka_id,mass_netto FROM ' . $this->feoTable
                . ' WHERE zayavka_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') FOR UPDATE'
            );
            $query->execute($chunk);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $out[(string)$row['zayavka_id']] = (string)($row['mass_netto'] ?? '0');
        }
        $missing = array_values(array_diff($ids, array_keys($out)));
        if ($missing !== []) throw new RuntimeException('В старой базе не найдены заявки: ' . implode(', ', array_slice($missing, 0, 20)) . '.');
        return $out;
    }

    /** @param list<string> $ids */
    private function assertMembership(array $ids, int $expected): void
    {
        if ($ids === []) return;
        $counts = array_fill_keys($ids, 0);
        $query = $this->legacy->query(
            'SELECT zayavki_ids FROM ' . $this->blocksTable
            . ' WHERE id > ' . self::LEGACY_AVAILABLE_BLOCK_MIN_ID . " AND status_type='dostupno' ORDER BY id"
        );
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $raw) {
            foreach ($this->parseIds((string)$raw, false) as $id) if (array_key_exists($id, $counts)) $counts[$id]++;
        }
        foreach ($counts as $id => $actual) {
            if ($actual !== $expected) throw new RuntimeException('Старая ERP не подтвердила зеркальный состав для заявки ' . $id . '.');
        }
    }

    private function comment(string $context): string
    {
        $value = 'Новая ERP: ' . trim($context);
        return mb_substr($value, 0, 255, 'UTF-8');
    }
}
