<?php

namespace App\Service;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/** Reads the client invoice DOCX produced by the document generator. */
final class GeneratedClientInvoiceDocxParser
{
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;
    private const MAX_XML_BYTES = 2 * 1024 * 1024;
    private const MONTHS = [
        'января' => '01', 'февраля' => '02', 'марта' => '03', 'апреля' => '04',
        'мая' => '05', 'июня' => '06', 'июля' => '07', 'августа' => '08',
        'сентября' => '09', 'октября' => '10', 'ноября' => '11', 'декабря' => '12',
    ];

    public static function parse(string $path): array
    {
        if (!is_file($path) || filesize($path) === false || filesize($path) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Размер DOCX не должен превышать 5 МБ.');
        }
        if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
            throw new RuntimeException('На сервере недоступна обработка DOCX.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Не удалось открыть DOCX.');
        }
        try {
            if ($zip->numFiles > 200 || $zip->locateName('[Content_Types].xml') === false) {
                throw new RuntimeException('Некорректный файл DOCX.');
            }
            $index = $zip->locateName('word/document.xml');
            $stat = $index === false ? false : $zip->statIndex($index);
            if ($stat === false || (int)($stat['size'] ?? 0) > self::MAX_XML_BYTES) {
                throw new RuntimeException('В DOCX не найден текст счёта или документ слишком велик.');
            }
            $xml = $zip->getFromIndex($index);
            if (!is_string($xml) || strlen($xml) > self::MAX_XML_BYTES) {
                throw new RuntimeException('Не удалось прочитать текст счёта.');
            }
        } finally {
            $zip->close();
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Повреждён XML внутри DOCX.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = [];
        foreach ($xp->query('//w:body/w:p') as $paragraph) {
            $text = self::text($xp, $paragraph);
            if ($text !== '') $paragraphs[] = $text;
        }
        $heading = null;
        foreach ($paragraphs as $paragraph) {
            if (preg_match('/^Сч[её]т на оплату\s*№\s*(\S+)\s+от\s+(\d{1,2})\s+([а-яё]+)\s+(\d{4})$/ui', $paragraph, $m)) {
                $heading = $m;
                break;
            }
        }
        if ($heading === null) throw new RuntimeException('Это не счёт из генератора ERP: номер и дата не найдены.');
        $month = self::MONTHS[mb_strtolower($heading[3], 'UTF-8')] ?? null;
        $date = $month === null ? null : sprintf('%04d-%s-%02d', (int)$heading[4], $month, (int)$heading[2]);
        $parsedDate = $date === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            throw new RuntimeException('В счёте некорректная дата.');
        }

        $buyer = null;
        $supplier = null;
        $sellerInn = null;
        $total = null;
        $lineAmount = null;
        $vatText = null;
        $service = '';
        foreach ($xp->query('//w:body/w:tbl/w:tr') as $row) {
            $cells = [];
            foreach ($xp->query('./w:tc', $row) as $cell) $cells[] = self::text($xp, $cell);
            if (($cells[0] ?? '') === 'Поставщик:') $supplier = $cells[1] ?? null;
            if (($cells[0] ?? '') === 'Покупатель:') $buyer = $cells[1] ?? null;
            if (($cells[0] ?? '') === 'ИНН' && preg_match('/^\d{10,12}$/D', $cells[1] ?? '')) $sellerInn = $cells[1];
            if (($cells[0] ?? '') === '1' && count($cells) >= 6) {
                $service = $cells[1];
                $lineAmount = $cells[5];
            }
            if (($cells[0] ?? '') === 'Итого к оплате:') $total = $cells[count($cells) - 1] ?? null;
            if (($cells[0] ?? '') === 'В том числе НДС:') $vatText = $cells[count($cells) - 1] ?? null;
        }
        if ($supplier === null || $supplier === '' || $sellerInn === null || $buyer === null || $total === null || $vatText === null) {
            throw new RuntimeException('В DOCX не найдены обязательные поля системного счёта.');
        }
        if (!preg_match('/\bИНН\s*(\d{10,12})\b/u', $buyer, $buyerInn)) {
            throw new RuntimeException('В счёте не найден ИНН покупателя.');
        }
        $amount = FinanceInvoiceService::normalizeMoneyInput($total);
        if ($amount === null) throw new RuntimeException('В счёте некорректная итоговая сумма.');
        if ($lineAmount === null || FinanceInvoiceService::normalizeMoneyInput($lineAmount) !== $amount) {
            throw new RuntimeException('Итоговая сумма не совпадает со строкой услуги.');
        }
        $vatText = trim($vatText);
        if ($vatText === 'Без НДС') {
            $vatRate = null;
        } elseif (preg_match('/^(0|5|7|20|22)\s*%$/u', $vatText, $vatMatch)) {
            $vatRate = FinanceInvoiceService::normalizeVatRateInput($vatMatch[1]);
        } else {
            throw new RuntimeException('Не удалось определить ставку НДС счёта.');
        }
        return [
            'number' => $heading[1], 'invoice_date' => $date,
            'seller_inn' => $sellerInn, 'seller_name' => $supplier,
            'buyer_inn' => $buyerInn[1], 'buyer_name' => trim((string)strtok($buyer, ',')),
            'amount' => $amount, 'vat_rate' => $vatRate, 'basis' => $service,
        ];
    }

    private static function text(DOMXPath $xp, \DOMNode $node): string
    {
        $parts = [];
        foreach ($xp->query('.//w:t', $node) as $text) $parts[] = $text->textContent;
        return trim(preg_replace('/\s+/u', ' ', implode('', $parts)) ?? '');
    }
}
