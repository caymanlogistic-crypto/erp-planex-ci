<?php

namespace App\Service;

use ZipArchive;

final class FeoXlsxParser
{
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

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
            throw new \RuntimeException('Не удалось открыть XLSX-файл.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheetPath = $this->firstSheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new \RuntimeException('Не найден первый лист XLSX.');
            }
            $rows = $this->readRows($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }

        if ($rows === []) {
            throw new \RuntimeException('XLSX-файл не содержит строк.');
        }

        $headers = array_shift($rows);
        $normalizedMap = [];
        foreach (self::fieldMap() as $header => $field) {
            $normalizedMap[$this->normalizeHeader($header)] = $field;
        }

        $columnMap = [];
        foreach ($headers as $index => $header) {
            $normalized = $this->normalizeHeader((string)$header);
            if (isset($normalizedMap[$normalized])) {
                $columnMap[$index] = $normalizedMap[$normalized];
            }
        }
        if (!in_array('zayavka_id', $columnMap, true)) {
            throw new \RuntimeException('В XLSX отсутствует обязательная колонка «Заявка, ID».');
        }

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
                throw new \RuntimeException("Строка {$sourceRow}: пустое значение «Заявка, ID». ");
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

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $root = $this->xml($xml, 'sharedStrings.xml');
        $root->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $out = [];
        foreach ($root->xpath('//m:si') ?: [] as $si) {
            $parts = $si->xpath('.//m:t') ?: [];
            $out[] = implode('', array_map(static fn($t): string => (string)$t, $parts));
        }
        return $out;
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbookRaw = $zip->getFromName('xl/workbook.xml');
        $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookRaw === false || $relsRaw === false) return 'xl/worksheets/sheet1.xml';

        $workbook = $this->xml($workbookRaw, 'workbook.xml');
        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $sheets = $workbook->xpath('//m:sheets/m:sheet') ?: [];
        if ($sheets === []) return 'xl/worksheets/sheet1.xml';
        $attrs = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string)($attrs['id'] ?? '');
        if ($rid === '') return 'xl/worksheets/sheet1.xml';

        $rels = $this->xml($relsRaw, 'workbook.xml.rels');
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] !== $rid) continue;
            $target = ltrim((string)$rel['Target'], '/');
            if (str_starts_with($target, 'xl/')) return $target;
            return 'xl/' . $target;
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private function readRows(string $sheetXml, array $sharedStrings): array
    {
        $root = $this->xml($sheetXml, 'worksheet');
        $root->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $out = [];
        foreach ($root->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $cells = [];
            foreach ($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
                $ref = (string)$cell['r'];
                $index = $this->columnIndex($ref);
                $type = (string)$cell['t'];
                $children = $cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                if ($type === 'inlineStr') {
                    $parts = $children->is->xpath('.//*[local-name()="t"]') ?: [];
                    $value = implode('', array_map(static fn($t): string => (string)$t, $parts));
                } else {
                    $raw = isset($children->v) ? (string)$children->v : '';
                    $value = $type === 's' ? ($sharedStrings[(int)$raw] ?? '') : $raw;
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
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
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
