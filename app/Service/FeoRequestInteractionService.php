<?php

namespace App\Service;

require_once __DIR__ . '/FeoLocationOverrideService.php';
require_once __DIR__ . '/FeoWeightChangeNotificationService.php';

use PDO;

final class FeoRequestInteractionService
{
    private const MAX_FILTER_IDS = 500;
    private const DATE_FIELDS = [
        'data_zayavki','data_dogovora','data_sozdaniya_porucheniya','zakaz_s_ot_data_vygruzki','zayavka_zhdv_po',
    ];
    private const DECIMAL_FIELDS = [
        'mass_netto','mass_brutto','dlina_mesta','shirina_mesta','vysota_mesta','summarnyy_obem','zakaz_s_op_stoimost',
    ];
    private const INTEGER_FIELDS = ['kol_vo_mest'];
    private const SEARCH_COLUMNS = [
        'zayavka_id',
        'polnoe_naim_zakazchika', 'ur_adres_zakazchika', 'inn_zakazchika', 'nomer_dogovora',
        'gruzopoluchatel', 'inn_op_gruzopoluchatelya', 'adres_dostavki_otkhodov', 'ur_adres_op_gruzopoluchatelya',
        'inn_oo_gruzootpravitel', 'naim_oo_gruzootpravitel', 'ur_adres_oo_gruzootpravitelya',
        'mno_region', 'mno_mo', 'mno_adres_pogruzki', 'naim_otkhoda_fkko', 'vid_tary', 'nomer_opasnogo_gr',
        'adres_vygruzki_otkhodov', 'naim_ot_gruzoperevozchik', 'inn_ot', 'ur_adres_ot',
        'zakaz_s_ot_status', 'nalichie_transport_nakl', 'zakaz_s_op_status', 'id_zakaza_s_op',
        'kontakt_tel', 'tel_dopolnitelnyy', 'kontakt_email', 'mno_sh', 'mno_d',
    ];

    public function extractRequestIds(string $raw): array
    {
        if (trim($raw) === '') return [];

        $structured = str_contains($raw, "\t") || str_contains($raw, '|');
        $matches = [];
        if ($structured) {
            foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
                $cell = $this->firstStructuredCell((string)$line);
                if ($cell === '') continue;
                preg_match_all('/(?<!\d)\d{6}(?!\d)/u', $cell, $cellMatches);
                foreach ($cellMatches[0] ?? [] as $id) $matches[] = (string)$id;
            }
        } else {
            preg_match_all('/(?<!\d)\d{6}(?!\d)/u', $raw, $rawMatches);
            $matches = array_map('strval', $rawMatches[0] ?? []);
        }

        $ids = [];
        $seen = [];
        foreach ($matches as $id) {
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $ids[] = $id;
            if (count($ids) > self::MAX_FILTER_IDS) {
                throw new \RuntimeException('За один поиск можно проверить не более 500 номеров заявок.');
            }
        }
        return $ids;
    }

    public function normalizedIdFilter(string $raw): string
    {
        return implode(' ', $this->extractRequestIds($raw));
    }

    public function filteredMassKg(PDO $pdo, array $requestIds, string $contentSearch): float
    {
        [$whereSql, $params] = $this->buildFilter($requestIds, trim($contentSearch));
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(mass_netto),0) FROM feo_requests' . $whereSql);
        $stmt->execute($params);
        return round(((float)$stmt->fetchColumn()) * 1000, 3);
    }

    public function prepareImportRows(PDO $pdo, array $rows, bool $overwriteExisting): array
    {
        if ($overwriteExisting || $rows === []) {
            return ['rows' => $rows, 'skipped_existing_count' => 0];
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['zayavka_id'] ?? ''));
            if ($id !== '') $ids[$id] = true;
        }
        if ($ids === []) return ['rows' => $rows, 'skipped_existing_count' => 0];

        $existing = [];
        foreach (array_chunk(array_keys($ids), self::MAX_FILTER_IDS) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare("SELECT * FROM feo_requests WHERE zayavka_id IN ({$placeholders})");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existing[(string)$row['zayavka_id']] = $this->businessRow($row);
            }
        }

        $prepared = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $id = (string)($row['zayavka_id'] ?? '');
            if (isset($existing[$id])) {
                if (!$this->sameBusinessRow($existing[$id], $row)) $skipped++;
                $prepared[] = $existing[$id];
            } else {
                $prepared[] = $row;
            }
        }
        return ['rows' => $prepared, 'skipped_existing_count' => $skipped];
    }

    public function updateRequest(PDO $pdo, int $id, array $input, ?callable $weightEvent = null): array
    {
        if ($id <= 0) throw new \RuntimeException('Некорректная заявка ФЭО.');
        $lockName = $this->acquireLock($pdo);
        try {
            $pdo->beginTransaction();
            $find = $pdo->prepare('SELECT * FROM feo_requests WHERE id=? LIMIT 1 FOR UPDATE');
            $find->execute([$id]);
            $current = $find->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$current) throw new \RuntimeException('Заявка ФЭО не найдена.');
            $locationOverrides = FeoLocationOverrideService::activeIds($pdo);
            if (isset($locationOverrides[(string)$current['zayavka_id']])) {
                foreach (FeoLocationOverrideService::FIELDS as $field) {
                    if (array_key_exists($field, $input) && (string)$this->normalizeEditableValue($field, $input[$field]) !== (string)$current[$field]) {
                        throw new \RuntimeException('Место погрузки изменено блоком перемещения. Используйте «Перемещение заявок» или верните блок.');
                    }
                }
            }

            $allowed = array_values(array_filter(FeoXlsxParser::fields(), static fn(string $field): bool => $field !== 'zayavka_id'));
            $assignments = [];
            $values = [];
            foreach ($allowed as $field) {
                if (!array_key_exists($field, $input)) continue;
                $assignments[] = '`' . $field . '`=?';
                $values[] = $this->normalizeEditableValue($field, $input[$field]);
            }
            if ($assignments === []) throw new \RuntimeException('Нет данных для сохранения.');

            $sql = 'UPDATE feo_requests SET ' . implode(',', $assignments) . ',current_import_id=NULL WHERE id=?';
            $update = $pdo->prepare($sql);
            $update->execute([...$values, $id]);

            $find->execute([$id]);
            $updated = $find->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$updated) throw new \RuntimeException('Не удалось прочитать сохранённую заявку ФЭО.');
            $weightChange = FeoWeightChangeNotificationService::change($current, $updated);
            if ($weightChange !== null && $weightEvent !== null) $weightEvent([$weightChange]);
            $pdo->commit();
            return $updated;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $this->releaseLock($pdo, $lockName);
        }
    }

    private function firstStructuredCell(string $line): string
    {
        if (str_contains($line, "\t")) {
            return trim((string)(explode("\t", $line, 2)[0] ?? ''));
        }
        if (str_contains($line, '|')) {
            $trimmed = trim($line);
            $trimmed = trim($trimmed, '|');
            return trim((string)(explode('|', $trimmed, 2)[0] ?? ''));
        }
        return '';
    }

    private function buildFilter(array $requestIds, string $contentSearch): array
    {
        $where = [];
        $params = [];
        if ($requestIds !== []) {
            $where[] = 'zayavka_id IN (' . implode(',', array_fill(0, count($requestIds), '?')) . ')';
            array_push($params, ...$requestIds);
        }
        if ($contentSearch !== '') {
            $needle = '%' . $contentSearch . '%';
            $parts = [];
            foreach (self::SEARCH_COLUMNS as $column) {
                $parts[] = '`' . $column . '` LIKE ?';
                $params[] = $needle;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params];
    }

    private function normalizeEditableValue(string $field, mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) throw new \RuntimeException('Некорректное значение поля ФЭО.');
        $text = trim((string)($value ?? ''));
        if ($text === '') return null;

        if (in_array($field, self::DATE_FIELDS, true)) {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                throw new \RuntimeException('Дата должна быть в формате ГГГГ-ММ-ДД.');
            }
            return $text;
        }
        if (in_array($field, self::DECIMAL_FIELDS, true)) {
            $number = str_replace(["\u{00A0}", ' '], '', str_replace(',', '.', $text));
            if (!is_numeric($number)) throw new \RuntimeException('Числовое поле содержит некорректное значение.');
            return $number;
        }
        if (in_array($field, self::INTEGER_FIELDS, true)) {
            if (!preg_match('/^-?\d+$/', $text)) throw new \RuntimeException('Количество должно быть целым числом.');
            return (int)$text;
        }
        return $text;
    }

    private function businessRow(array $row): array
    {
        $out = [];
        foreach (FeoXlsxParser::fields() as $field) {
            $value = $row[$field] ?? null;
            if ($value === '') $value = null;
            $out[$field] = $value === null ? null : (string)$value;
        }
        return $out;
    }

    private function sameBusinessRow(array $a, array $b): bool
    {
        $left = json_encode($this->businessRow($a), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $right = json_encode($this->businessRow($b), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($left) && $left === $right;
    }

    private function acquireLock(PDO $pdo): string
    {
        $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'planex_feo_' . substr(hash('sha256', $database), 0, 24);
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $stmt->execute([$lockName]);
        if ((int)$stmt->fetchColumn() !== 1) throw new \RuntimeException('Не удалось получить блокировку редактирования ФЭО.');
        return $lockName;
    }

    private function releaseLock(PDO $pdo, string $lockName): void
    {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    }
}
