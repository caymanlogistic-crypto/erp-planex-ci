<?php

namespace App\Service;

use RuntimeException;

require_once __DIR__ . '/DocumentGeneratorTemplateTrait.php';
require_once __DIR__ . '/DocumentGeneratorWorkflowTrait.php';
require_once __DIR__ . '/DocumentGeneratorAccessTrait.php';
require_once __DIR__ . '/DocumentGeneratorDataTrait.php';

final class DocumentGeneratorService
{
    use DocumentGeneratorTemplateTrait;
    use DocumentGeneratorWorkflowTrait;
    use DocumentGeneratorAccessTrait;
    use DocumentGeneratorDataTrait;

    /**
     * Materialize the audited built-in DOCX resources.
     *
     * Class precedence intentionally overrides the historical private method in
     * DocumentGeneratorTemplateTrait. Repository parts are independently
     * base64-encoded binary chunks and must be decoded one-by-one.
     */
    private static function materializeBuiltInTemplate(string $filename): string
    {
        $expected = [
            'contract_vehicle_lease_crew_v1.docx' => '0e3e7a3ca4d81face23fa070bcabffacc86636d08affdda91faa3c725d4e8adc',
            'waybill_driver_assignment_v1.docx' => '5a45e855c6fb47dbc96e6d8b9185d7032b5ca86a9baefbb78fdc6419749f73ab',
        ];
        if (!isset($expected[$filename])) {
            throw new RuntimeException('Неизвестный встроенный DOCX-шаблон: ' . $filename);
        }

        $parts = glob(base_path('resources/document-templates/' . $filename . '.b64.part*')) ?: [];
        sort($parts, SORT_STRING);
        if ($parts === []) {
            throw new RuntimeException('Не найдены части встроенного DOCX-шаблона: ' . $filename);
        }

        $bytes = '';
        foreach ($parts as $part) {
            $chunk = file_get_contents($part);
            if ($chunk === false) {
                throw new RuntimeException('Не удалось прочитать часть встроенного DOCX-шаблона.');
            }
            $encoded = preg_replace('/\s+/', '', $chunk) ?? '';
            $decoded = base64_decode($encoded, true);
            if (!is_string($decoded) || $decoded === '') {
                throw new RuntimeException('Встроенный DOCX-шаблон повреждён: base64 part decode failed.');
            }
            $bytes .= $decoded;
        }
        if ($bytes === '') {
            throw new RuntimeException('Встроенный DOCX-шаблон повреждён: empty materialized content.');
        }

        $actual = hash('sha256', $bytes);
        if (!hash_equals($expected[$filename], $actual)) {
            throw new RuntimeException('Встроенный DOCX-шаблон повреждён: SHA-256 mismatch.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'planex_docgen_builtin_');
        if ($tmp === false) {
            throw new RuntimeException('Не удалось создать временный файл встроенного шаблона.');
        }
        $docx = $tmp . '.docx';
        @unlink($tmp);
        if (file_put_contents($docx, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($docx);
            throw new RuntimeException('Не удалось материализовать встроенный DOCX-шаблон.');
        }
        return $docx;
    }
}
