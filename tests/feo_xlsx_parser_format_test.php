<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/FeoXlsxParser.php';

use App\Service\FeoXlsxParser;

if (!class_exists(ZipArchive::class) || !function_exists('simplexml_load_string')) {
    fwrite(STDERR, "FEO_XLSX_FORMAT_TEST: required PHP extensions zip/simplexml are missing\n");
    exit(1);
}

$pass = 0;
$fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) { $pass++; return; }
    $fail++;
    fwrite(STDERR, "FAIL: {$name}\n");
};

$xml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$column = static function (int $number): string {
    $out = '';
    while ($number > 0) {
        $number--;
        $out = chr(65 + ($number % 26)) . $out;
        $number = intdiv($number, 26);
    }
    return $out;
};

$build = static function (array $headers) use ($xml, $column): string {
    $tmp = tempnam(sys_get_temp_dir(), 'feo_xlsx_');
    if ($tmp === false) throw new RuntimeException('tempnam failed');
    @unlink($tmp);
    $path = $tmp . '.xlsx';
    $strings = array_values($headers);
    $strings[] = '1';
    $strings[] = '900001';

    $shared = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $value) $shared .= '<si><t>' . $xml((string)$value) . '</t></si>';
    $shared .= '</sst>';

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">';
    foreach ($headers as $i => $_) {
        $ref = $column($i + 1) . '1';
        $sheet .= '<c r="' . $ref . '" t="s"><v>' . $i . '</v></c>';
    }
    $sheet .= '</row><row r="2"><c r="A2" t="s"><v>' . count($headers) . '</v></c><c r="B2" t="s"><v>' . (count($headers) + 1) . '</v></c></row></sheetData></worksheet>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('zip create failed');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', $shared);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    return $path;
};

$headers = array_merge(['ПП'], array_keys(FeoXlsxParser::fieldMap()));
$headers[array_search('Юридический адрес заказчика (п.1а)', $headers, true)] = 'Юридический адрес заказчика  (п.1а)';
$headers[array_search('ИНН заказчика (п.1а)', $headers, true)] = "ИНН заказчика\u{00A0} (п.1а)";

$parser = new FeoXlsxParser();
$emptySharedXml = '<?xml version="1.0" encoding="utf-8"?><x:sst xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main" />';
$xmlMethod = new ReflectionMethod(FeoXlsxParser::class, 'xml');
$xmlMethod->setAccessible(true);
try {
    $emptyShared = $xmlMethod->invoke($parser, $emptySharedXml, 'sharedStrings.xml');
    $ok('empty sharedStrings XML accepted', $emptyShared instanceof SimpleXMLElement);
} catch (Throwable $e) {
    $ok('empty sharedStrings XML accepted', false);
}

$valid = $build($headers);
try {
    $rows = $parser->parse($valid);
    $ok('sharedStrings XLSX parses', count($rows) === 1);
    $ok('sharedStrings request id preserved', ($rows[0]['zayavka_id'] ?? null) === '900001');
} finally { @unlink($valid); }

$renamedHeaders = $headers;
$renamedHeaders[array_search('МНО, Регион', $renamedHeaders, true)] = 'Регион МНО';
$renamed = $build($renamedHeaders);
try {
    $parser->parse($renamed);
    $ok('renamed header rejected', false);
} catch (RuntimeException $e) {
    $ok('renamed header rejected', str_contains($e->getMessage(), 'Отсутствуют:') && str_contains($e->getMessage(), 'Неизвестные или переименованные:'));
} finally { @unlink($renamed); }

$missingHeaders = $headers;
array_pop($missingHeaders);
$missing = $build($missingHeaders);
try {
    $parser->parse($missing);
    $ok('missing header rejected', false);
} catch (RuntimeException $e) {
    $ok('missing header rejected', str_contains($e->getMessage(), 'Отсутствуют:') && str_contains($e->getMessage(), 'Фактически колонок: 44'));
} finally { @unlink($missing); }

$extraHeaders = $headers;
$extraHeaders[] = 'Чужая колонка';
$extra = $build($extraHeaders);
try {
    $parser->parse($extra);
    $ok('extra header rejected', false);
} catch (RuntimeException $e) {
    $ok('extra header rejected', str_contains($e->getMessage(), 'Неизвестные или переименованные:') && str_contains($e->getMessage(), 'Чужая колонка'));
} finally { @unlink($extra); }

$notXlsx = tempnam(sys_get_temp_dir(), 'feo_not_xlsx_');
file_put_contents($notXlsx, 'not an xlsx');
try {
    $parser->parse($notXlsx);
    $ok('foreign file rejected', false);
} catch (RuntimeException $e) {
    $ok('foreign file rejected', str_contains($e->getMessage(), 'корректным XLSX'));
} finally { @unlink($notXlsx); }

printf("FEO_XLSX_FORMAT_TEST: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
