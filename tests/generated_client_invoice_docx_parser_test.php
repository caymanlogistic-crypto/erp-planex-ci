<?php

require_once __DIR__ . '/../app/Service/FinanceInvoiceService.php';
require_once __DIR__ . '/../app/Service/GeneratedClientInvoiceDocxParser.php';

use App\Service\GeneratedClientInvoiceDocxParser;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$xmlEscape = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$cell = static function (string $value) use ($xmlEscape): string {
    return '<w:tc><w:p><w:r><w:t>' . $xmlEscape($value) . '</w:t></w:r></w:p></w:tc>';
};
$row = static function (array $values) use ($cell): string {
    return '<w:tr>' . implode('', array_map($cell, $values)) . '</w:tr>';
};
$makeDocx = static function (string $sellerInn, string $total) use ($row, $xmlEscape): string {
    $path = tempnam(sys_get_temp_dir(), 'planex_invoice_');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('zip');
    $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . '<w:p><w:r><w:t>' . $xmlEscape('Счёт на оплату № 26-256 от 27 сентября 2026') . '</w:t></w:r></w:p>'
        . '<w:tbl>' . $row(['ИНН', $sellerInn]) . '</w:tbl>'
        . '<w:tbl>' . $row(['Поставщик:', 'ООО «ПЛАНЭКС»'])
        . $row(['Покупатель:', 'ООО «МГ ЛОГИСТИК», ИНН 6685231046, КПП 668501001']) . '</w:tbl>'
        . '<w:tbl>' . $row(['1', 'Услуга по договору', '1', 'усл.', $total, $total])
        . $row(['Итого к оплате:', $total]) . $row(['В том числе НДС:', 'Без НДС']) . '</w:tbl>'
        . '</w:body></w:document>';
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();
    return $path;
};

$valid = $makeDocx('5406854242', '596 446.00');
$invalid = $makeDocx('5406854242', '0.00');
try {
    $invoice = GeneratedClientInvoiceDocxParser::parse($valid);
    $assert($invoice['number'] === '26-256', 'number');
    $assert($invoice['invoice_date'] === '2026-09-27', 'date');
    $assert($invoice['seller_inn'] === '5406854242' && $invoice['buyer_inn'] === '6685231046', 'party INNs');
    $assert($invoice['amount'] === '596446.00' && $invoice['vat_rate'] === null, 'amount and VAT');
    try {
        GeneratedClientInvoiceDocxParser::parse($invalid);
        throw new RuntimeException('zero total accepted');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'итоговая сумма'), 'zero total rejection');
    }
} finally {
    @unlink($valid);
    @unlink($invalid);
}
echo "generated_client_invoice_docx_parser_test PASS\n";
