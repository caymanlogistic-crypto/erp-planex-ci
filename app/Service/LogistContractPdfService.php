<?php

namespace App\Service;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/** Builds a PDF from the currently active DOCX and the two images supplied by OWNER. */
final class LogistContractPdfService
{
    private const SEAL = 'resources/document-signatures/planex-seal.png';
    private const SIGNATURE = 'resources/document-signatures/planex-signature.png';

    public static function render(string $template, array $values, array $requests, string $output): void
    {
        $dir = dirname($output);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Не удалось создать каталог PDF.');
        $working = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'planex_contract_' . bin2hex(random_bytes(10));
        if (!mkdir($working, 0700)) throw new RuntimeException('Не удалось подготовить временный каталог PDF.');
        $decorated = $working . DIRECTORY_SEPARATOR . 'template.docx';
        $docx = $working . DIRECTORY_SEPARATOR . 'contract.docx';
        try {
            self::decorateTemplate($template, $decorated);
            DocumentTemplateService::render($decorated, $docx, $values, $requests);
            self::convert($docx, $working);
            $pdf = $working . DIRECTORY_SEPARATOR . 'contract.pdf';
            if (!is_file($pdf) || filesize($pdf) < 100 || file_get_contents($pdf, false, null, 0, 5) !== '%PDF-') {
                throw new RuntimeException('Конвертация договора в PDF не завершилась.');
            }
            if (!rename($pdf, $output)) throw new RuntimeException('Не удалось сохранить PDF договора.');
        } finally {
            foreach ([$decorated, $docx, $working . DIRECTORY_SEPARATOR . 'contract.pdf'] as $file) if (is_file($file)) @unlink($file);
            @rmdir($working);
        }
    }

    private static function decorateTemplate(string $source, string $target): void
    {
        if (!copy($source, $target)) throw new RuntimeException('Не удалось подготовить шаблон договора.');
        $zip = new ZipArchive();
        if ($zip->open($target) !== true) throw new RuntimeException('Не удалось открыть шаблон договора.');
        try {
            $xml = $zip->getFromName('word/document.xml');
            $rels = $zip->getFromName('word/_rels/document.xml.rels');
            $types = $zip->getFromName('[Content_Types].xml');
            if (!is_string($xml) || !is_string($rels) || !is_string($types)) throw new RuntimeException('Шаблон договора повреждён.');
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->loadXML($xml, LIBXML_NONET);
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $count = 0;
            foreach ($xp->query('//w:p') as $paragraph) {
                $text = '';
                foreach ($xp->query('.//w:t', $paragraph) as $node) $text .= $node->textContent;
                if (!str_contains($text, '{{company.signature_name}}')) continue;
                $count++;
                // Anchor both images to the signature paragraph without changing
                // its line height. The source sample places the signature above
                // the line and the round seal beneath its left half.
                $firstRun = $xp->query('./w:r', $paragraph)->item(0);
                if (!$firstRun) throw new RuntimeException('В шаблоне повреждена строка подписи ПЛАНЭКС.');
                $paragraph->insertBefore(self::imageRun($dom, 'rIdPlanexSignature', 1750000, 900000, 1450000, -750000, 'Подпись ПЛАНЭКС'), $firstRun);
                $paragraph->insertBefore(self::imageRun($dom, 'rIdPlanexSeal', 1200000, 1200000, 35000, 140000, 'Печать ПЛАНЭКС'), $firstRun);
            }
            if ($count < 1) throw new RuntimeException('В актуальном шаблоне не найдено место подписи ПЛАНЭКС.');
            $seal = base_path(self::SEAL);
            $signature = base_path(self::SIGNATURE);
            if (!is_file($seal) || !is_file($signature)) throw new RuntimeException('Утверждённые изображения подписи и печати не найдены.');
            // The existing placeholder renderer expects explicit w:t end tags.
            // DOMDocument may shorten empty runs to <w:t/>, which would make its
            // paragraph matcher consume following XML across runs.
            $documentXml = preg_replace('~<w:t(\s[^>]*)?/>~', '<w:t$1></w:t>', $dom->saveXML()) ?? '';
            $zip->addFromString('word/document.xml', $documentXml);
            $zip->addFromString('word/media/planex-seal.png', file_get_contents($seal));
            $zip->addFromString('word/media/planex-signature.png', file_get_contents($signature));
            $relation = '<Relationship Id="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/%s"/>';
            $rels = str_replace('</Relationships>', sprintf($relation, 'rIdPlanexSignature', 'planex-signature.png') . sprintf($relation, 'rIdPlanexSeal', 'planex-seal.png') . '</Relationships>', $rels);
            $zip->addFromString('word/_rels/document.xml.rels', $rels);
            if (!str_contains($types, 'Extension="png"')) {
                $types = str_replace('</Types>', '<Default Extension="png" ContentType="image/png"/></Types>', $types);
                $zip->addFromString('[Content_Types].xml', $types);
            }
        } finally {
            $zip->close();
        }
    }

    private static function imageRun(DOMDocument $dom, string $relationship, int $width, int $height, int $x, int $y, string $label): \DOMNode
    {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $run = $dom->createElementNS($w, 'w:r');
        $drawing = $dom->createElementNS($w, 'w:drawing');
        $fragment = $dom->createDocumentFragment();
        $id = $relationship === 'rIdPlanexSeal' ? 801 : 802;
        $fragment->appendXML('<wp:anchor xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251658240" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/><wp:positionH relativeFrom="character"><wp:posOffset>'.$x.'</wp:posOffset></wp:positionH><wp:positionV relativeFrom="paragraph"><wp:posOffset>'.$y.'</wp:posOffset></wp:positionV><wp:extent cx="'.$width.'" cy="'.$height.'"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/><wp:docPr id="'.$id.'" name="'.htmlspecialchars($label, ENT_XML1).'"/><wp:cNvGraphicFramePr/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="'.$id.'" name="'.htmlspecialchars($label, ENT_XML1).'"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$relationship.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:anchor>');
        $drawing->appendChild($fragment);
        $run->appendChild($drawing);
        return $run;
    }

    private static function convert(string $docx, string $outputDir): void
    {
        $binary = trim((string)(getenv('PLANEX_LIBREOFFICE_BIN') ?: ''));
        $privateRuntime = PHP_OS_FAMILY === 'Windows' ? null : dirname(base_path(), 2) . '/deploy/logist_contracts_pdf_runtime_7_1_8_1';
        if ($binary === '') $binary = PHP_OS_FAMILY === 'Windows'
            ? 'E:\\Applications\\LibreOffice\\program\\soffice.com'
            : $privateRuntime . '/opt/libreoffice7.1/program/soffice';
        if (!is_file($binary) || !is_executable($binary)) throw new RuntimeException('На сервере недоступна конвертация DOCX в PDF.');
        $profile = $outputDir . DIRECTORY_SEPARATOR . 'lo-profile';
        $profileUri = 'file:///' . str_replace('\\', '/', ltrim($profile, '/'));
        $command = [$binary, '-env:UserInstallation=' . $profileUri, '--headless', '--convert-to', 'pdf:writer_pdf_Export', '--outdir', $outputDir, $docx];
        $environment = null;
        if ($privateRuntime !== null && str_starts_with($binary, $privateRuntime . '/')) {
            $environment = getenv();
            $environment['LD_LIBRARY_PATH'] = $privateRuntime . '/lib/x86_64-linux-gnu:' . $privateRuntime . '/usr/lib/x86_64-linux-gnu'
                . (isset($environment['LD_LIBRARY_PATH']) && $environment['LD_LIBRARY_PATH'] !== '' ? ':' . $environment['LD_LIBRARY_PATH'] : '');
        }
        $process = @proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, null, $environment);
        if (!is_resource($process)) throw new RuntimeException('На сервере недоступна конвертация DOCX в PDF.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) throw new RuntimeException('Конвертация DOCX в PDF завершилась с ошибкой: ' . mb_substr(trim($stderr ?: $stdout), 0, 300));
    }
}
