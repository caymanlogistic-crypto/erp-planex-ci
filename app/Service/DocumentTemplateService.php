<?php

namespace App\Service;

use ZipArchive;

final class DocumentTemplateService
{
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
    public const MAX_ENTRY_COUNT = 2000;
    public const MAX_ENTRY_BYTES = 8 * 1024 * 1024;
    public const MAX_UNCOMPRESSED_BYTES = 32 * 1024 * 1024;

    private const DOCUMENT_TYPES = [
        'vehicle_lease_crew' => 'Договор-заявка аренды ТС с экипажем',
        'carrier_sublease_crew' => 'Договор-заявка субаренды ТС с экипажем',
        'waybill_driver_assignment' => 'Приложение к путевому листу / задание водителю',
        'client_sublease_crew' => 'Договор субаренды транспортного средства с экипажем',
        'client_invoice' => 'Счёт клиенту',
        'client_act' => 'Акт клиенту',
        'client_upd_contract' => 'Договор клиенту для комплекта с УПД',
        'client_upd_invoice' => 'Счёт клиенту для комплекта с УПД',
        'client_upd_status2' => 'УПД клиенту, статус 2',
    ];

    private const PLACEHOLDERS = [
        'contract.number', 'contract.date', 'contract.date_plain', 'contract.start_date', 'contract.start_time',
        'contract.finish_date', 'contract.price', 'contract.vat_mode', 'contract.payment_terms',
        'waybill.number', 'waybill.date',
        'invoice.number', 'invoice.date', 'act.number', 'act.date', 'upd.number', 'upd.date', 'upd.day', 'upd.month', 'upd.year_suffix',
        'company.name', 'company.inn', 'company.inn_kpp', 'company.kpp', 'company.kpp_line', 'company.ogrn_line',
        'company.legal_address', 'company.physical_address', 'company.bank_name',
        'company.bank_account', 'company.bank_corr_account', 'company.bank_bik',
        'company.contact_phone', 'company.contact_email', 'company.director_full_name',
        'company.director_position', 'company.signature_name', 'company.summary',
        'client.name', 'client.inn', 'client.inn_kpp', 'client.kpp', 'client.kpp_line', 'client.ogrn_line',
        'client.legal_address', 'client.physical_address', 'client.bank_name',
        'client.bank_account', 'client.bank_corr_account', 'client.bank_bik',
        'client.director_full_name', 'client.director_position', 'client.signature_name', 'client.summary',
        'executor.name', 'executor.inn', 'executor.kpp_line', 'executor.ogrn_line',
        'executor.legal_address', 'executor.physical_address', 'executor.bank_name',
        'executor.bank_account', 'executor.bank_corr_account', 'executor.bank_bik', 'executor.bank_inn',
        'executor.director_full_name', 'executor.director_position', 'executor.signature_name',
        'driver.full_name', 'driver.passport', 'driver.license', 'driver.signature_name',
        'vehicle.label', 'vehicle.model', 'vehicle.plate', 'vehicle.vin',
        'trailer.label', 'trailer.model', 'trailer.plate', 'trailer.vin', 'trailer.display',
        'service.description', 'service.route', 'service.amount', 'service.amount_ru', 'service.amount_ru_compact', 'service.amount_words', 'service.vat_mode',
        'trip.business_id',
        'requests.count', 'requests.total_mass_kg', 'requests.unload_address',
        'request.index', 'request.id', 'request.mass_kg', 'request.sender',
        'request.loading_address', 'request.contacts',
    ];

    public static function documentTypes(): array
    {
        return self::DOCUMENT_TYPES;
    }

    public static function placeholders(): array
    {
        return self::PLACEHOLDERS;
    }

    public static function validateDocumentType(string $type): bool
    {
        return isset(self::DOCUMENT_TYPES[$type]);
    }

    public static function validateUploadedDocx(array $file): array
    {
        $name = (string)($file['name'] ?? '');
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'errors' => ['Файл DOCX не загружен или загрузка завершилась ошибкой.']];
        }
        if ($name === '' || basename($name) !== $name || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'docx') {
            return ['ok' => false, 'errors' => ['Разрешены только безопасно именованные файлы с расширением .docx.']];
        }
        if ($tmp === '' || !is_file($tmp) || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'errors' => ['Файл не прошёл проверку HTTP-upload.']];
        }
        if (class_exists('finfo')) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (!is_string($mime) || !in_array(strtolower($mime), ['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
                return ['ok' => false, 'errors' => ['Загруженный файл не распознан как DOCX/ZIP.']];
            }
        }
        if ($size <= 0) $size = (int)filesize($tmp);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'errors' => ['Размер DOCX должен быть от 1 байта до 10 МБ.']];
        }
        return self::validateDocxFile($tmp);
    }

    public static function validateDocxFile(string $path): array
    {
        $errors = [];
        if (!is_file($path)) return ['ok' => false, 'errors' => ['DOCX не найден.']];
        if (!class_exists(ZipArchive::class)) return ['ok' => false, 'errors' => ['На сервере недоступна поддержка ZIP, необходимая для DOCX.']];

        $zip = new ZipArchive();
        $open = $zip->open($path, ZipArchive::RDONLY);
        if ($open !== true) return ['ok' => false, 'errors' => ['Файл не является корректным ZIP/DOCX.']];

        try {
            if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ENTRY_COUNT) {
                $errors[] = 'DOCX содержит недопустимое количество файлов внутри архива.';
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) { $errors[] = 'Не удалось прочитать структуру DOCX.'; break; }
                $entry = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                $entrySize = (int)($stat['size'] ?? 0);
                if ($entry === '' || str_starts_with($entry, '/') || preg_match('~(^|/)\.\.(/|$)~', $entry)) {
                    $errors[] = 'DOCX содержит небезопасный путь внутри архива.';
                    break;
                }
                if ($entrySize > self::MAX_ENTRY_BYTES) {
                    $errors[] = 'Один из файлов внутри DOCX превышает допустимый размер.';
                    break;
                }
                $total += $entrySize;
                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    $errors[] = 'Распакованный размер DOCX превышает безопасный лимит.';
                    break;
                }
            }

            $contentTypes = $zip->getFromName('[Content_Types].xml');
            $documentXml = $zip->getFromName('word/document.xml');
            if (!is_string($contentTypes) || !is_string($documentXml)) {
                $errors[] = 'Файл не содержит обязательную структуру Word DOCX.';
            } elseif (!str_contains($contentTypes, 'wordprocessingml.document.main+xml')) {
                $errors[] = 'Файл не распознан как документ Microsoft Word DOCX.';
            }

            $xmlParts = [];
            if (is_string($contentTypes) && !self::isWellFormedXml($contentTypes)) {
                $errors[] = 'Файл [Content_Types].xml повреждён или содержит небезопасный XML.';
            }
            if (is_string($documentXml)) $xmlParts['word/document.xml'] = $documentXml;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = is_array($stat) ? (string)($stat['name'] ?? '') : '';
                if ($entry !== '' && preg_match('~\.xml$~i', $entry)) {
                    $raw = $zip->getFromIndex($i);
                    if (is_string($raw) && !self::isWellFormedXml($raw)) {
                        $errors[] = 'DOCX содержит повреждённый или небезопасный XML: ' . $entry . '.';
                    }
                }
                if (preg_match('~^word/(?:header|footer)\d+\.xml$~', $entry)) {
                    $raw = $zip->getFromIndex($i);
                    if (is_string($raw)) $xmlParts[$entry] = $raw;
                }
            }

            $found = [];
            $repeatRows = 0;
            foreach ($xmlParts as $xml) {
                foreach (self::extractPlaceholders($xml) as $placeholder) $found[$placeholder] = true;
                if (preg_match_all('~<w:tr\b[^>]*>.*?</w:tr>~s', $xml, $rows)) {
                    foreach ($rows[0] as $row) {
                        if (str_contains(self::visibleText($row), '{{request.')) $repeatRows++;
                    }
                }
            }
            $unknown = array_values(array_diff(array_keys($found), self::PLACEHOLDERS));
            sort($unknown);
            foreach ($unknown as $placeholder) $errors[] = 'Неизвестная переменная: {{' . $placeholder . '}}';
            if ($repeatRows > 1) $errors[] = 'В шаблоне допускается только одна повторяемая строка {{request.*}}.';

            return [
                'ok' => $errors === [],
                'errors' => array_values(array_unique($errors)),
                'placeholders' => array_values(array_keys($found)),
                'repeat_rows' => $repeatRows,
                'sha256' => hash_file('sha256', $path) ?: '',
                'size' => (int)filesize($path),
            ];
        } finally {
            $zip->close();
        }
    }

    public static function render(string $templatePath, string $outputPath, array $values, array $requestRows): void
    {
        $validation = self::validateDocxFile($templatePath);
        if (!($validation['ok'] ?? false)) {
            throw new \RuntimeException('Шаблон DOCX не прошёл проверку: ' . implode('; ', $validation['errors'] ?? []));
        }
        if (!class_exists(ZipArchive::class)) throw new \RuntimeException('На сервере недоступна поддержка ZIP/DOCX.');

        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог для сформированного документа.');
        }
        if (!copy($templatePath, $outputPath)) throw new \RuntimeException('Не удалось подготовить копию DOCX-шаблона.');

        $zip = new ZipArchive();
        $open = $zip->open($outputPath);
        if ($open !== true) throw new \RuntimeException('Не удалось открыть копию DOCX для формирования.');
        try {
            $targets = ['word/document.xml'];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = is_array($stat) ? (string)($stat['name'] ?? '') : '';
                if (preg_match('~^word/(?:header|footer)\d+\.xml$~', $entry)) $targets[] = $entry;
            }
            foreach (array_values(array_unique($targets)) as $entry) {
                $xml = $zip->getFromName($entry);
                if (!is_string($xml)) continue;
                if ($entry === 'word/document.xml') $xml = self::expandRequestRows($xml, $requestRows);
                $xml = self::replaceMappedPlaceholders($xml, $values);
                if (!$zip->addFromString($entry, $xml)) throw new \RuntimeException('Не удалось записать XML сформированного DOCX.');
            }
        } finally {
            $zip->close();
        }

        $after = self::validateDocxFile($outputPath);
        if (!($after['ok'] ?? false)) {
            @unlink($outputPath);
            throw new \RuntimeException('Сформированный DOCX не прошёл структурную проверку.');
        }
        $leftovers = array_values(array_filter($after['placeholders'] ?? [], 'is_string'));
        if ($leftovers !== []) {
            @unlink($outputPath);
            throw new \RuntimeException('В сформированном DOCX остались незаполненные переменные: ' . implode(', ', $leftovers));
        }
    }

    private static function isWellFormedXml(string $xml): bool
    {
        if ($xml === '' || preg_match('//u', $xml) !== 1) return false;
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) return false;

        $clean = preg_replace('/<\?xml\b.*?\?>/is', '', $xml, 1) ?? $xml;
        $clean = preg_replace('/<!--.*?-->/s', '', $clean) ?? $clean;
        $clean = preg_replace('/<!\[CDATA\[.*?\]\]>/s', '', $clean) ?? $clean;
        if (preg_match('/<(?!(?:\/?[A-Za-z_][A-Za-z0-9_.:-]*(?:\s|\/?>)))/s', $clean)) return false;
        if (!preg_match_all('/<\/?[A-Za-z_][A-Za-z0-9_.:-]*(?:\s[^<>]*?)?\/?>/s', $clean, $tags, PREG_OFFSET_CAPTURE)) {
            return !str_contains($clean, '<') && !str_contains($clean, '>');
        }

        $masked = $clean;
        foreach (array_reverse($tags[0]) as $capture) {
            $masked = substr_replace($masked, '', (int)$capture[1], strlen((string)$capture[0]));
        }
        if (str_contains($masked, '<') || str_contains($masked, '>')) return false;

        $stack = [];
        foreach ($tags[0] as $capture) {
            $tag = (string)$capture[0];
            if (str_ends_with(rtrim($tag), '/>')) continue;
            if (preg_match('/^<\/([A-Za-z_][A-Za-z0-9_.:-]*)\s*>$/s', $tag, $m)) {
                if ($stack === [] || array_pop($stack) !== $m[1]) return false;
                continue;
            }
            if (!preg_match('/^<([A-Za-z_][A-Za-z0-9_.:-]*)(?:\s[^<>]*?)?>$/s', $tag, $m)) return false;
            $stack[] = $m[1];
        }
        return $stack === [];
    }

    public static function visibleText(string $xml): string
    {
        $text = preg_replace('~<w:tab\b[^>]*/>~', "\t", $xml) ?? $xml;
        $text = preg_replace('~<w:br\b[^>]*/>~', "\n", $text) ?? $text;
        $text = preg_replace('~<[^>]+>~', '', $text) ?? $text;
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function extractPlaceholders(string $xml): array
    {
        $text = self::visibleText($xml);
        preg_match_all('/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/i', $text, $m);
        $out = [];
        foreach ($m[1] ?? [] as $name) $out[(string)$name] = true;
        return array_keys($out);
    }

    private static function expandRequestRows(string $xml, array $requestRows): string
    {
        return preg_replace_callback('~<w:tr\b[^>]*>.*?</w:tr>~s', static function (array $m) use ($requestRows): string {
            $row = $m[0];
            if (!str_contains(self::visibleText($row), '{{request.')) return $row;
            if ($requestRows === []) return '';
            $rendered = '';
            foreach ($requestRows as $values) $rendered .= self::replaceMappedPlaceholders($row, $values);
            return $rendered;
        }, $xml) ?? $xml;
    }

    private static function replaceMappedPlaceholders(string $xml, array $values): string
    {
        return preg_replace_callback('~<w:p\b[^>]*>.*?</w:p>~s', static function (array $m) use ($values): string {
            return self::replaceInParagraph($m[0], $values);
        }, $xml) ?? $xml;
    }

    private static function replaceInParagraph(string $paragraphXml, array $values): string
    {
        if (!str_contains(self::visibleText($paragraphXml), '{{')) return $paragraphXml;
        if (!preg_match_all('~<w:t\b[^>]*>(.*?)</w:t>~s', $paragraphXml, $matches, PREG_OFFSET_CAPTURE)) return $paragraphXml;

        $nodes = [];
        $plain = '';
        foreach ($matches[1] as $idx => $capture) {
            $encoded = (string)$capture[0];
            $decoded = html_entity_decode($encoded, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $nodes[] = [
                'full' => $matches[0][$idx][0],
                'full_offset' => $matches[0][$idx][1],
                'inner' => $encoded,
                'text' => $decoded,
                'start' => strlen($plain),
                'end' => strlen($plain) + strlen($decoded),
            ];
            $plain .= $decoded;
        }

        if (!preg_match_all('/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/i', $plain, $tokens, PREG_OFFSET_CAPTURE)) return $paragraphXml;
        $replacements = [];
        foreach ($tokens[0] as $i => $token) {
            $name = (string)($tokens[1][$i][0] ?? '');
            if (!array_key_exists($name, $values)) continue;
            $replacements[] = [
                'start' => (int)$token[1],
                'end' => (int)$token[1] + strlen((string)$token[0]),
                'value' => (string)($values[$name] ?? ''),
            ];
        }
        if ($replacements === []) return $paragraphXml;

        foreach (array_reverse($replacements) as $replacement) {
            $first = null;
            foreach ($nodes as $idx => $node) {
                if ($node['end'] <= $replacement['start'] || $node['start'] >= $replacement['end']) continue;
                if ($first === null) $first = $idx;
                $localStart = max(0, $replacement['start'] - $node['start']);
                $localEnd = min(strlen($node['text']), $replacement['end'] - $node['start']);
                $prefix = substr($nodes[$idx]['text'], 0, $localStart);
                $suffix = substr($nodes[$idx]['text'], $localEnd);
                $nodes[$idx]['text'] = $prefix . ($idx === $first ? $replacement['value'] : '') . $suffix;
            }
        }

        $result = $paragraphXml;
        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            $node = $nodes[$i];
            $escaped = htmlspecialchars($node['text'], ENT_QUOTES | ENT_XML1, 'UTF-8');
            // Replacement values may contain semantic line breaks (for example trailer.display).
            // WordprocessingML does not reliably render literal LF inside w:t, so emit w:br elements.
            $escaped = str_replace(["\r\n", "\r", "\n"], '</w:t><w:br/><w:t>', $escaped);
            $newFull = preg_replace_callback(
                '~(<w:t\b[^>]*>).*?(</w:t>)~s',
                static fn(array $m): string => $m[1] . $escaped . $m[2],
                $node['full'],
                1
            ) ?? $node['full'];
            $result = substr_replace($result, $newFull, $node['full_offset'], strlen($node['full']));
        }
        return $result;
    }
}
