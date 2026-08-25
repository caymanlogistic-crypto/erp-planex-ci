<?php

namespace App\Service;

use ZipArchive;

final class FeoXlsxParser
{
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_ARCHIVE_ENTRIES = 2000;
    private const MAX_EXPANDED_BYTES = 100 * 1024 * 1024;
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const XLSX_WORKBOOK_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
    private const AUXILIARY_HEADERS = ['ПП'];

    public static function fieldMap(): array
    {
        return [
            'Заявка, ID' => 'zayavka_id',
            'Дата заявки' => 'data_zayavki',
            'Полное наименование заказчика (п.1а)' => 'polnoe_naim_zakazchika',
            'Юридический адрес заказчика (п.1а)' => 'ur_adres_zakazchika',
            'ИНН заказчика (п.1а)' => 'inn_zakazchika',
            '№ договора с ФГУП ФЭО (п. 1а)' => 'nomer_dogovora',
            'Дата договора с ФГУП ФЭО (п.1а)' => 'data_dogovora',
            'Заказ с ОП_Наименование организации (Грузополучатель) (п. 2)' => 'gruzopoluchatel',
            'ИНН ОП Грузополучателя (п. 2)' => 'inn_op_gruzopoluchatelya',
            'Адрес объекта обращения ОП Адрес места доставки отходов (п.2 транспртной накладной)' => 'adres_dostavki_otkhodov',
            'Юридический адрес ОП Грузополучателя (п. 2)' => 'ur_adres_op_gruzopoluchatelya',
            'ИНН ОО (Грузоотправитель) (п.1)' => 'inn_oo_gruzootpravitel',
            'ОО_Наименование организации (Грузоотправитель) (п.1)' => 'naim_oo_gruzootpravitel',
            'Юридический адрес ОО (Грузоотправитель) (п.1)' => 'ur_adres_oo_gruzootpravitelya',
            'МНО, Регион' => 'mno_region',
            'МНО, МО' => 'mno_mo',
            'МНО, Адрес (адрес места погрузки отходов)' => 'mno_adres_pogruzki',
            'Наименование отхода (код отхода по ФККО) (п. 3)' => 'naim_otkhoda_fkko',
            'Количество грузовых мест, шт (п. 3)' => 'kol_vo_mest',
            'Вид тары (п. 3)' => 'vid_tary',
            'Суммарная масса (нетто), т (п. 3)' => 'mass_netto',
            'Суммарная масса (брутто), т (п. 3)' => 'mass_brutto',
            'Длина места (только если грузовое место одно) (п. 3)' => 'dlina_mesta',
            'Ширина места (только если грузовое место одно) (п. 3)' => 'shirina_mesta',
            'Высота места (только если грузовое место одно) (п. 3)' => 'vysota_mesta',
            'Суммарный объем, куб.м (п. 3)' => 'summarnyy_obem',
            'Номер опасного груза по классификации ООН (п. 3)' => 'nomer_opasnogo_gr',
            'Адрес объекта обращения ОП Адрес места выгрузки отходов (п.10 транспортной накладной)' => 'adres_vygruzki_otkhodov',
            'Наименование ОТ (Грузоперевозчик) (п. 6)' => 'naim_ot_gruzoperevozchik',
            'ИНН ОТ (п. 6)' => 'inn_ot',
            'Юридический адрес ОТ организации Перевозчика (п. 6)' => 'ur_adres_ot',
            'Дата создания поручения' => 'data_sozdaniya_porucheniya',
            'Заказ с ОТ, Статус' => 'zakaz_s_ot_status',
            'Наличие транспортной накладной' => 'nalichie_transport_nakl',
            'Заказ с ОП, Статус' => 'zakaz_s_op_status',
            'ID заказа с ОП' => 'id_zakaza_s_op',
            'Заказ с ОТ, дата выгрузки' => 'zakaz_s_ot_data_vygruzki',
            'Заявка, ЖДВ (по)' => 'zayavka_zhdv_po',
            'Контактные данные в МНО (Тел.)' => 'kontakt_tel',
            'Телефон (дополнительный)' => 'tel_dopolnitelnyy',
            'Контактные данные в МНО (Эл.почта)' => 'kontakt_email',
            'МНО_Координаты (Ш)' => 'mno_sh',
            'МНО_Координаты (Д)' => 'mno_d',
            'Заказ с ОП, Стоимость услуг' => 'zakaz_s_op_stoimost',
        ];
    }

    public static function fieldLabels(): array
    {
        return array_flip(self::fieldMap());
    }

    public static function fields(): array
    {
        return array_values(self::fieldMap());
    }

    public function parse(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('XLSX-файл недоступен для чтения.');
        }
        $size = filesize($filePath);
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Размер XLSX-файла некорректен или превышает 20 МБ.');
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Файл не является корректным XLSX-документом.');
        }

        try {
            $this->validatePackage($zip);
            $sharedStrings = $this->sharedStrings($zip);
            $sheetPath = $this->firstSheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new \RuntimeException('Файл не является корректным XLSX: не найден первый рабочий лист.');
            }
            $rows = $this->readRows($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }

        if ($rows === []) {
            throw new \RuntimeException('XLSX-файл не содержит строк.');
        }

        $headers = array_shift($rows);
        $columnMap = $this->validateHeaderSchema($headers);

        $dateFields = ['data_zayavki','data_dogovora','data_sozdaniya_porucheniya','zakaz_s_ot_data_vygruzki','zayavka_zhdv_po'];
        $decimalFields = ['mass_netto','mass_brutto','dlina_mesta','shirina_mesta','vysota_mesta','summarnyy_obem','zakaz_s_op_stoimost'];
        $intFields = ['kol_vo_mest'];
        $result = [];
        $seen = [];

        foreach ($rows as $rowOffset => $row) {
            $sourceRow = $rowOffset + 2;
            $record = array_fill_keys(self::fields(), null);
            foreach ($columnMap as $index => $field) {
                $value = $row[$index] ?? null;
                if (in_array($field, $dateFields, true)) {
                    $value = $this->normalizeDate($value);
                } elseif (in_array($field, $decimalFields, true)) {
                    $value = $this->normalizeDecimal($value);
                } elseif (in_array($field, $intFields, true)) {
                    $value = $this->normalizeInteger($value);
                } else {
                    $value = $this->normalizeText($value);
                }
                $record[$field] = $value;
            }

            $id = trim((string)($record['zayavka_id'] ?? ''));
            if ($id === '') {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }
                throw new \RuntimeException("Строка {$sourceRow}: пустое значение «Заявка, ID».");
            }
            if (isset($seen[$id])) {
                throw new \RuntimeException("Строка {$sourceRow}: заявка {$id} повторяется в файле.");
            }
            $seen[$id] = true;
            $record['zayavka_id'] = $id;
            $result[] = $record;
        }

        if ($result === []) {
            throw new \RuntimeException('В XLSX не найдено ни одной заявки ФЭО.');
        }

        return $result;
    }

    private function validatePackage(ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new \RuntimeException('Файл не соответствует допустимой структуре XLSX.');
        }
        $expanded = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) continue;
            $expanded += (int)($stat['size'] ?? 0);
            if ($expanded > self::MAX_EXPANDED_BYTES) {
                throw new \RuntimeException('XLSX-файл превышает допустимый размер после распаковки.');
            }
        }

        foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
            if ($zip->locateName($required, ZipArchive::FL_NOCASE) === false) {
                throw new \RuntimeException('Файл не является корректным XLSX-документом.');
            }
        }

        $contentTypes = $zip->getFromName('[Content_Types].xml');
        if ($contentTypes === false || !str_contains($contentTypes, self::XLSX_WORKBOOK_CONTENT_TYPE)) {
            throw new \RuntimeException('Допустим только обычный XLSX-файл без макросов и другого формата книги.');
        }
    }

    private function validateHeaderSchema(array $headers): array
    {
        $expected = [];
        foreach (self::fieldMap() as $header => $field) {
            $expected[$this->normalizeHeader($header)] = ['label' => $header, 'field' => $field];
        }
        $auxiliary = [];
        foreach (self::AUXILIARY_HEADERS as $header) {
            $auxiliary[$this->normalizeHeader($header)] = $header;
        }

        $seen = [];
        $columnMap = [];
        $unknown = [];
        $duplicates = [];
        $emptyColumns = [];

        foreach ($headers as $index => $header) {
            $raw = trim((string)($header ?? ''));
            $normalized = $this->normalizeHeader($raw);
            if ($normalized === '') {
                $emptyColumns[] = (string)($index + 1);
                continue;
            }
            if (isset($seen[$normalized])) {
                $duplicates[] = $raw;
                continue;
            }
            $seen[$normalized] = true;
            if (isset($expected[$normalized])) {
                $columnMap[$index] = $expected[$normalized]['field'];
                continue;
            }
            if (isset($auxiliary[$normalized])) {
                continue;
            }
            $unknown[] = $raw;
        }

        $missing = [];
        foreach ($expected as $normalized => $meta) {
            if (!isset($seen[$normalized])) $missing[] = $meta['label'];
        }
        foreach ($auxiliary as $normalized => $label) {
            if (!isset($seen[$normalized])) $missing[] = $label;
        }

        $expectedCount = count(self::fieldMap()) + count(self::AUXILIARY_HEADERS);
        if ($missing !== [] || $unknown !== [] || $duplicates !== [] || $emptyColumns !== [] || count($headers) !== $expectedCount) {
            $parts = ["Формат XLSX не соответствует шаблону заявок ФЭО. Ожидаются {$expectedCount} колонок: «ПП» и 44 поля ФЭО."];
            if ($missing !== []) $parts[] = 'Отсутствуют: ' . $this->formatHeaderList($missing) . '.';
            if ($unknown !== []) $parts[] = 'Неизвестные или переименованные: ' . $this->formatHeaderList($unknown) . '.';
            if ($duplicates !== []) $parts[] = 'Дублируются: ' . $this->formatHeaderList($duplicates) . '.';
            if ($emptyColumns !== []) $parts[] = 'Пустые заголовки в колонках: ' . implode(', ', $emptyColumns) . '.';
            if (count($headers) !== $expectedCount) $parts[] = 'Фактически колонок: ' . count($headers) . '.';
            throw new \RuntimeException(implode(' ', $parts));
        }

        return $columnMap;
    }

    private function formatHeaderList(array $headers): string
    {
        $headers = array_values(array_unique(array_map(static fn($v): string => trim((string)$v), $headers)));
        $limit = 12;
        $shown = array_slice($headers, 0, $limit);
        $text = implode(', ', array_map(static fn(string $h): string => '«' . $h . '»', $shown));
        if (count($headers) > $limit) $text .= ' и ещё ' . (count($headers) - $limit);
        return $text;
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $root = $this->xml($xml, 'sharedStrings.xml');
        $root->registerXPathNamespace('m', self::MAIN_NS);
        $out = [];
        foreach ($root->xpath('//m:si') ?: [] as $si) {
            $si->registerXPathNamespace('m', self::MAIN_NS);
            $parts = $si->xpath('.//m:t') ?: [];
            $out[] = implode('', array_map(static fn($t): string => (string)$t, $parts));
        }
        return $out;
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbookRaw = $zip->getFromName('xl/workbook.xml');
        $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookRaw === false || $relsRaw === false) {
            throw new \RuntimeException('Файл не является корректным XLSX: отсутствует описание книги.');
        }

        $workbook = $this->xml($workbookRaw, 'workbook.xml');
        $workbook->registerXPathNamespace('m', self::MAIN_NS);
        $workbook->registerXPathNamespace('r', self::REL_NS);
        $sheets = $workbook->xpath('//m:sheets/m:sheet') ?: [];
        if ($sheets === []) throw new \RuntimeException('XLSX-файл не содержит рабочих листов.');
        $attrs = $sheets[0]->attributes(self::REL_NS);
        $rid = (string)($attrs['id'] ?? '');
        if ($rid === '') throw new \RuntimeException('Не удалось определить первый лист XLSX.');

        $rels = $this->xml($relsRaw, 'workbook.xml.rels');
        $rels->registerXPathNamespace('p', self::PACKAGE_REL_NS);
        foreach ($rels->xpath('//p:Relationship') ?: [] as $rel) {
            if ((string)$rel['Id'] !== $rid) continue;
            $target = ltrim((string)$rel['Target'], '/');
            if ($target === '' || str_contains($target, '..')) {
                throw new \RuntimeException('Некорректная ссылка на рабочий лист XLSX.');
            }
            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }
        throw new \RuntimeException('Не удалось найти первый рабочий лист XLSX.');
    }

    private function readRows(string $sheetXml, array $sharedStrings): array
    {
        $root = $this->xml($sheetXml, 'worksheet');
        $root->registerXPathNamespace('m', self::MAIN_NS);
        $out = [];
        foreach ($root->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $cells = [];
            foreach ($row->children(self::MAIN_NS)->c as $cell) {
                $ref = (string)$cell['r'];
                $index = $this->columnIndex($ref);
                $type = (string)$cell['t'];
                $children = $cell->children(self::MAIN_NS);
                if ($type === 'inlineStr') {
                    $parts = $children->is->xpath('.//*[local-name()="t"]') ?: [];
                    $value = implode('', array_map(static fn($t): string => (string)$t, $parts));
                } else {
                    $raw = isset($children->v) ? (string)$children->v : '';
                    if ($type === 's') {
                        if ($raw === '' || !ctype_digit($raw) || !array_key_exists((int)$raw, $sharedStrings)) {
                            throw new \RuntimeException('Повреждена таблица строк XLSX.');
                        }
                        $value = $sharedStrings[(int)$raw];
                    } else {
                        $value = $raw;
                    }
                }
                $cells[$index] = $value;
            }
            if ($cells !== []) {
                $max = max(array_keys($cells));
                $dense = array_fill(0, $max + 1, null);
                foreach ($cells as $i => $value) $dense[$i] = $value;
                $out[] = $dense;
            }
        }
        return $out;
    }

    private function xml(string $raw, string $name): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$xml) throw new \RuntimeException("Повреждён XML внутри XLSX: {$name}.");
        return $xml;
    }

    private function columnIndex(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/i', $ref, $m)) return 0;
        $letters = strtoupper($m[1]);
        $index = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $index - 1);
    }

    private function normalizeHeader(string $value): string
    {
        $value = str_replace("\xc2\xa0", ' ', $value);
        return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value);
    }

    private function normalizeText(mixed $value): ?string
    {
        $value = trim((string)($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function normalizeInteger(mixed $value): ?int
    {
        $raw = str_replace([' ', ','], ['', '.'], trim((string)($value ?? '')));
        if ($raw === '' || !is_numeric($raw)) return null;
        return (int)round((float)$raw);
    }

    private function normalizeDecimal(mixed $value): ?string
    {
        $raw = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim((string)($value ?? '')));
        if ($raw === '' || !is_numeric($raw)) return null;
        return rtrim(rtrim(number_format((float)$raw, 4, '.', ''), '0'), '.');
    }

    private function normalizeDate(mixed $value): ?string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') return null;
        if (is_numeric($raw)) {
            $days = (float)$raw;
            if ($days > 1000 && $days < 100000) {
                $timestamp = (int)round(($days - 25569) * 86400);
                return gmdate('Y-m-d', $timestamp);
            }
        }
        foreach (['d.m.Y','d.m.y','Y-m-d','d/m/Y','d-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $raw);
            if ($date instanceof \DateTimeImmutable) return $date->format('Y-m-d');
        }
        $timestamp = strtotime($raw);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)($value ?? '')) !== '') return false;
        }
        return true;
    }
}
