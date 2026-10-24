<?php

namespace App\Service;

use ZipArchive;

final class FeoPlacementInputParser
{
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_IDS = 20000;
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public function parseManual(string $raw): array
    {
        return $this->extractFromText($raw, true);
    }

    public function parseFile(string $path, string $originalName): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Файл недоступен для чтения.');
        }
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Размер файла некорректен или превышает 20 МБ.');
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $cells = match ($ext) {
            'xlsx' => $this->readXlsxFirstColumn($path),
            'xls' => $this->readXlsFirstColumn($path),
            default => throw new \RuntimeException('Допустимы только файлы XLS и XLSX.'),
        };
        try {
            return $this->extractFromCells($cells);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Не найдено самостоятельных шестизначных номеров заявок.') {
                throw new \RuntimeException('В первом столбце не найден ни один номер заявки.');
            }
            throw $e;
        }
    }

    public function extractFromCells(array $cells): array
    {
        $matches = [];
        foreach ($cells as $cell) {
            preg_match_all('/(?<!\d)\d{6}(?!\d)/u', trim((string)$cell), $m);
            foreach ($m[0] ?? [] as $id) $matches[] = (string)$id;
        }
        return $this->normalizeMatches($matches);
    }

    private function extractFromText(string $raw, bool $structuredFirstColumn): array
    {
        if (trim($raw) === '') throw new \RuntimeException('Введите номера заявок.');
        $matches = [];
        if ($structuredFirstColumn) {
            foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
                $line = (string)$line;
                $cell = trim($line);
                if (str_contains($line, "\t")) {
                    $cell = trim((string)(explode("\t", $line, 2)[0] ?? ''));
                } elseif (str_contains($line, '|')) {
                    $cell = trim((string)(explode('|', trim($line, " \t\n\r\0\x0B|"), 2)[0] ?? ''));
                }
                if ($cell === '') continue;
                preg_match_all('/(?<!\d)\d{6}(?!\d)/u', $cell, $m);
                foreach ($m[0] ?? [] as $id) $matches[] = (string)$id;
            }
        } else {
            preg_match_all('/(?<!\d)\d{6}(?!\d)/u', $raw, $m);
            $matches = array_map('strval', $m[0] ?? []);
        }
        return $this->normalizeMatches($matches);
    }

    private function normalizeMatches(array $matches): array
    {
        if ($matches === []) throw new \RuntimeException('Не найдено самостоятельных шестизначных номеров заявок.');
        $ids = [];
        $seen = [];
        $duplicates = [];
        foreach ($matches as $id) {
            $id = (string)$id;
            if (isset($seen[$id])) {
                $duplicates[$id] = true;
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
            if (count($ids) > self::MAX_IDS) throw new \RuntimeException('За одну операцию можно обработать не более 20 000 заявок.');
        }
        return ['ids' => $ids, 'duplicates' => array_map('strval', array_keys($duplicates)), 'duplicate_count' => count($matches) - count($ids)];
    }

    private function readXlsxFirstColumn(string $path): array
    {
        if (!class_exists(ZipArchive::class) || !function_exists('simplexml_load_string')) {
            throw new \RuntimeException('Сервер не поддерживает чтение XLSX.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('Файл не является корректным XLSX-документом.');
        try {
            foreach (['[Content_Types].xml','xl/workbook.xml','xl/_rels/workbook.xml.rels'] as $required) {
                if ($zip->locateName($required, ZipArchive::FL_NOCASE) === false) throw new \RuntimeException('Файл не является корректным XLSX-документом.');
            }
            $shared = $this->xlsxSharedStrings($zip);
            $sheetPath = $this->xlsxFirstSheetPath($zip);
            $xml = $zip->getFromName($sheetPath);
            if (!is_string($xml) || $xml === '') throw new \RuntimeException('В XLSX не найден первый рабочий лист.');
            $root = $this->xml($xml, 'worksheet');
            $root->registerXPathNamespace('m', self::MAIN_NS);
            $cells = [];
            foreach ($root->xpath('//m:sheetData/m:row') ?: [] as $row) {
                $row->registerXPathNamespace('m', self::MAIN_NS);
                $first = null;
                foreach ($row->xpath('./m:c') ?: [] as $cell) {
                    $ref = (string)($cell['r'] ?? '');
                    if ($ref !== '' && !preg_match('/^A\d+$/i', $ref)) continue;
                    $first = $cell;
                    break;
                }
                if ($first === null) continue;
                $cells[] = $this->xlsxCellValue($first, $shared);
            }
            return $cells;
        } finally {
            $zip->close();
        }
    }

    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml) || $xml === '') return [];
        $root = $this->xml($xml, 'sharedStrings');
        $root->registerXPathNamespace('m', self::MAIN_NS);
        $out = [];
        foreach ($root->xpath('//m:si') ?: [] as $si) {
            $si->registerXPathNamespace('m', self::MAIN_NS);
            $parts = [];
            foreach ($si->xpath('.//m:t') ?: [] as $t) $parts[] = (string)$t;
            $out[] = implode('', $parts);
        }
        return $out;
    }

    private function xlsxFirstSheetPath(ZipArchive $zip): string
    {
        $workbook = $this->xml((string)$zip->getFromName('xl/workbook.xml'), 'workbook');
        $workbook->registerXPathNamespace('m', self::MAIN_NS);
        $workbook->registerXPathNamespace('r', self::REL_NS);
        $sheets = $workbook->xpath('//m:sheets/m:sheet') ?: [];
        if ($sheets === []) throw new \RuntimeException('XLSX не содержит листов.');
        if (count($sheets) !== 1) throw new \RuntimeException('В файле должен быть ровно один лист.');
        $sheet = $sheets[0];
        $attrs = $sheet->attributes(self::REL_NS);
        $rid = (string)($attrs['id'] ?? '');
        if ($rid === '') throw new \RuntimeException('Не удалось определить первый лист XLSX.');

        $rels = $this->xml((string)$zip->getFromName('xl/_rels/workbook.xml.rels'), 'workbook relationships');
        $rels->registerXPathNamespace('p', self::PACKAGE_REL_NS);
        foreach ($rels->xpath('//p:Relationship') ?: [] as $rel) {
            if ((string)($rel['Id'] ?? '') !== $rid) continue;
            $target = str_replace('\\', '/', (string)($rel['Target'] ?? ''));
            $target = ltrim($target, '/');
            if (str_starts_with($target, 'xl/')) return $target;
            return 'xl/' . preg_replace('~^(?:\.\./)+~', '', $target);
        }
        throw new \RuntimeException('Не удалось открыть первый лист XLSX.');
    }

    private function xlsxCellValue(\SimpleXMLElement $cell, array $shared): string
    {
        $cell->registerXPathNamespace('m', self::MAIN_NS);
        $type = (string)($cell['t'] ?? '');
        if ($type === 'inlineStr') {
            $parts = [];
            foreach ($cell->xpath('.//m:is//m:t') ?: [] as $t) $parts[] = (string)$t;
            return implode('', $parts);
        }
        $valueNode = ($cell->xpath('./m:v') ?: [])[0] ?? null;
        $value = $valueNode === null ? '' : (string)$valueNode;
        if ($type === 's' && preg_match('/^\d+$/', $value)) return (string)($shared[(int)$value] ?? '');
        return $value;
    }

    private function xml(string $xml, string $label): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$root) throw new \RuntimeException('Повреждён XML внутри XLSX: ' . $label . '.');
        return $root;
    }

    private function readXlsFirstColumn(string $path): array
    {
        $data = file_get_contents($path);
        if (!is_string($data) || strlen($data) < 512) throw new \RuntimeException('Файл не является корректным XLS-документом.');
        $workbook = $this->oleWorkbookStream($data);
        return $this->biffFirstColumn($workbook);
    }

    private function oleWorkbookStream(string $data): string
    {
        if (substr($data, 0, 8) !== hex2bin('D0CF11E0A1B11AE1')) throw new \RuntimeException('Файл не является бинарным XLS-документом.');
        $sectorSize = 1 << $this->u16($data, 0x1e);
        $miniSectorSize = 1 << $this->u16($data, 0x20);
        if (!in_array($sectorSize, [512, 4096], true) || $miniSectorSize !== 64) throw new \RuntimeException('Неподдерживаемая структура XLS.');
        $firstDir = $this->u32($data, 0x30);
        $miniCutoff = $this->u32($data, 0x38);
        $firstMiniFat = $this->u32($data, 0x3c);
        $numMiniFat = $this->u32($data, 0x40);
        $firstDifat = $this->u32($data, 0x44);
        $numDifat = $this->u32($data, 0x48);

        $difat = [];
        for ($i = 0; $i < 109; $i++) {
            $sid = $this->u32($data, 0x4c + ($i * 4));
            if ($sid < 0xFFFFFFFA) $difat[] = $sid;
        }
        $next = $firstDifat;
        for ($d = 0; $d < $numDifat && $next < 0xFFFFFFFA; $d++) {
            $sector = $this->oleSector($data, $next, $sectorSize);
            $entries = intdiv($sectorSize, 4) - 1;
            for ($i = 0; $i < $entries; $i++) {
                $sid = $this->u32($sector, $i * 4);
                if ($sid < 0xFFFFFFFA) $difat[] = $sid;
            }
            $next = $this->u32($sector, $entries * 4);
        }
        if ($difat === []) throw new \RuntimeException('XLS не содержит таблицу FAT.');

        $fat = [];
        foreach ($difat as $fatSid) {
            $sector = $this->oleSector($data, $fatSid, $sectorSize);
            for ($i = 0; $i < $sectorSize; $i += 4) $fat[] = $this->u32($sector, $i);
        }
        $dirData = $this->oleReadChain($data, $fat, $firstDir, $sectorSize, null);
        $root = null;
        $book = null;
        for ($off = 0; $off + 128 <= strlen($dirData); $off += 128) {
            $entry = substr($dirData, $off, 128);
            $nameLen = $this->u16($entry, 64);
            if ($nameLen < 2) continue;
            $nameRaw = substr($entry, 0, min(64, $nameLen - 2));
            $name = '';
            for ($i = 0; $i + 1 < strlen($nameRaw); $i += 2) {
                $c = ord($nameRaw[$i]);
                if ($c === 0) break;
                $name .= chr($c);
            }
            $meta = ['type' => ord($entry[66]), 'start' => $this->u32($entry, 116), 'size' => $this->u32($entry, 120)];
            if ($meta['type'] === 5) $root = $meta;
            if ($meta['type'] === 2 && in_array(strtolower($name), ['workbook','book'], true)) $book = $meta;
        }
        if ($book === null || $root === null) throw new \RuntimeException('В XLS не найден поток Workbook.');
        if ($book['size'] >= $miniCutoff) return substr($this->oleReadChain($data, $fat, $book['start'], $sectorSize, $book['size']), 0, $book['size']);

        $miniFatBytes = $numMiniFat > 0 && $firstMiniFat < 0xFFFFFFFA
            ? $this->oleReadChain($data, $fat, $firstMiniFat, $sectorSize, $numMiniFat * $sectorSize)
            : '';
        $miniFat = [];
        for ($i = 0; $i + 4 <= strlen($miniFatBytes); $i += 4) $miniFat[] = $this->u32($miniFatBytes, $i);
        $miniStream = $this->oleReadChain($data, $fat, $root['start'], $sectorSize, $root['size']);
        $out = '';
        $sid = $book['start'];
        $guard = 0;
        while ($sid < 0xFFFFFFFA && strlen($out) < $book['size']) {
            if (++$guard > 100000 || !isset($miniFat[$sid])) throw new \RuntimeException('Повреждена miniFAT XLS.');
            $start = $sid * $miniSectorSize;
            $out .= substr($miniStream, $start, $miniSectorSize);
            $sid = $miniFat[$sid];
        }
        return substr($out, 0, $book['size']);
    }

    private function oleReadChain(string $data, array $fat, int $startSid, int $sectorSize, ?int $limit): string
    {
        $out = '';
        $sid = $startSid;
        $guard = 0;
        while ($sid < 0xFFFFFFFA) {
            if (++$guard > 100000 || !isset($fat[$sid])) throw new \RuntimeException('Повреждена цепочка FAT XLS.');
            $out .= $this->oleSector($data, $sid, $sectorSize);
            if ($limit !== null && strlen($out) >= $limit) break;
            $sid = $fat[$sid];
        }
        return $limit === null ? $out : substr($out, 0, $limit);
    }

    private function oleSector(string $data, int $sid, int $sectorSize): string
    {
        $offset = $sectorSize + ($sid * $sectorSize);
        if ($offset < 0 || $offset + $sectorSize > strlen($data)) throw new \RuntimeException('Сектор XLS выходит за границы файла.');
        return substr($data, $offset, $sectorSize);
    }

    private function biffFirstColumn(string $stream): array
    {
        $records = [];
        $off = 0;
        $len = strlen($stream);
        while ($off + 4 <= $len) {
            $id = $this->u16($stream, $off);
            $size = $this->u16($stream, $off + 2);
            $off += 4;
            if ($off + $size > $len) break;
            $records[] = [$id, substr($stream, $off, $size)];
            $off += $size;
        }
        $sheetCount = 0;
        foreach ($records as [$recordId]) if ($recordId === 0x0085) $sheetCount++;
        if ($sheetCount === 0) throw new \RuntimeException('XLS не содержит листов.');
        if ($sheetCount !== 1) throw new \RuntimeException('В файле должен быть ровно один лист.');

        $sst = $this->biffSharedStrings($records);
        $cells = [];
        $inSheet = false;
        foreach ($records as [$id, $payload]) {
            if ($id === 0x0809 && strlen($payload) >= 4) {
                $type = $this->u16($payload, 2);
                if ($type === 0x0010 && !$inSheet) { $inSheet = true; continue; }
            }
            if (!$inSheet) continue;
            if ($id === 0x000A) break;
            if ($id === 0x0203 && strlen($payload) >= 14 && $this->u16($payload, 2) === 0) {
                $cells[] = $this->formatBiffNumber($this->leDouble(substr($payload, 6, 8)));
            } elseif ($id === 0x027E && strlen($payload) >= 10 && $this->u16($payload, 2) === 0) {
                $cells[] = $this->formatBiffNumber($this->decodeRk($this->u32($payload, 6)));
            } elseif ($id === 0x00BD && strlen($payload) >= 12) {
                $firstCol = $this->u16($payload, 2);
                $lastCol = $this->u16($payload, strlen($payload) - 2);
                if ($firstCol === 0 && $lastCol >= 0 && strlen($payload) >= 10) $cells[] = $this->formatBiffNumber($this->decodeRk($this->u32($payload, 6)));
            } elseif ($id === 0x00FD && strlen($payload) >= 10 && $this->u16($payload, 2) === 0) {
                $idx = $this->u32($payload, 6);
                $cells[] = (string)($sst[$idx] ?? '');
            } elseif ($id === 0x0204 && strlen($payload) >= 8 && $this->u16($payload, 2) === 0) {
                $chars = $this->u16($payload, 6);
                $cells[] = substr($payload, 8, $chars);
            } elseif ($id === 0x0006 && strlen($payload) >= 14 && $this->u16($payload, 2) === 0) {
                $raw = substr($payload, 6, 8);
                if (substr($raw, 6, 2) !== "\xFF\xFF") $cells[] = $this->formatBiffNumber($this->leDouble($raw));
            }
        }
        return $cells;
    }

    private function biffSharedStrings(array $records): array
    {
        $payload = '';
        $collect = false;
        foreach ($records as [$id, $data]) {
            if ($id === 0x00FC) { $payload = $data; $collect = true; continue; }
            if ($collect && $id === 0x003C) { $payload .= $data; continue; }
            if ($collect) break;
        }
        if (strlen($payload) < 8) return [];
        $unique = $this->u32($payload, 4);
        $off = 8;
        $out = [];
        for ($i = 0; $i < $unique && $off + 3 <= strlen($payload); $i++) {
            $cch = $this->u16($payload, $off); $off += 2;
            $flags = ord($payload[$off++]);
            $high = ($flags & 0x01) !== 0;
            $rich = ($flags & 0x08) !== 0;
            $ext = ($flags & 0x04) !== 0;
            $runs = 0; $extLen = 0;
            if ($rich) { if ($off + 2 > strlen($payload)) break; $runs = $this->u16($payload, $off); $off += 2; }
            if ($ext) { if ($off + 4 > strlen($payload)) break; $extLen = $this->u32($payload, $off); $off += 4; }
            $byteLen = $cch * ($high ? 2 : 1);
            if ($off + $byteLen > strlen($payload)) break;
            $raw = substr($payload, $off, $byteLen); $off += $byteLen;
            if ($high) {
                $text = '';
                for ($j = 0; $j + 1 < strlen($raw); $j += 2) $text .= $raw[$j];
            } else {
                $text = $raw;
            }
            $out[] = $text;
            $off += ($runs * 4) + $extLen;
        }
        return $out;
    }

    private function decodeRk(int $rk): float
    {
        $divide = ($rk & 0x01) !== 0;
        if (($rk & 0x02) !== 0) {
            $v = $rk >> 2;
            if (($v & 0x20000000) !== 0) $v -= 0x40000000;
            $num = (float)$v;
        } else {
            $num = $this->leDouble(pack('V2', 0, $rk & 0xFFFFFFFC));
        }
        return $divide ? $num / 100 : $num;
    }

    private function formatBiffNumber(float $number): string
    {
        if (abs($number - round($number)) < 0.0000001) return sprintf('%.0f', $number);
        return rtrim(rtrim(sprintf('%.10F', $number), '0'), '.');
    }

    private function leDouble(string $bytes): float
    {
        $v = unpack('evalue', $bytes);
        return (float)($v['value'] ?? 0.0);
    }

    private function u16(string $data, int $offset): int
    {
        $v = unpack('vvalue', substr($data, $offset, 2));
        return (int)($v['value'] ?? 0);
    }

    private function u32(string $data, int $offset): int
    {
        $v = unpack('Vvalue', substr($data, $offset, 4));
        return (int)($v['value'] ?? 0);
    }
}
