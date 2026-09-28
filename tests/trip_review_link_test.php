<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir().'/planex_review_link_'.bin2hex(random_bytes(8));
function storage_path(string $relative): string { global $testRoot; return $testRoot.'/'.$relative; }
require_once dirname(__DIR__).'/app/Service/TripReviewPackageStore.php';
require_once dirname(__DIR__).'/app/Service/TripReviewEmail.php';
require_once dirname(__DIR__).'/app/Service/DocumentMailMime.php';

use App\Service\DocumentMailMime;
use App\Service\TripReviewEmail;
use App\Service\TripReviewPackageStore;

$ok = static function(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); };
$remove = static function(string $path) use (&$remove): void {
    if (!is_dir($path)) { if (is_file($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
mkdir($testRoot, 0700, true);
try {
    $pdf = $testRoot.'/scan.pdf';
    $stream = fopen($pdf, 'wb');
    fwrite($stream, '%PDF-1.4'.str_repeat('P', 1024*1024-8));
    for ($i=0; $i<17; $i++) fwrite($stream, str_repeat('P', 1024*1024));
    fclose($stream);
    $zipPath = $testRoot.'/source.zip';
    $zip = new ZipArchive();
    $ok($zip->open($zipPath, ZipArchive::CREATE) === true, 'ZIP creation failed');
    $zip->addFromString('Рейс_301_сводный_отчёт.xlsx', "PK\x03\x04report");
    $zip->addFile($pdf, 'Заявка_123_ТН.pdf');
    $zip->setCompressionName('Заявка_123_ТН.pdf', ZipArchive::CM_STORE);
    $zip->close();
    $ok(filesize($zipPath) > 16*1024*1024, 'Test ZIP must exceed the previous 16 MiB limit');

    $store = new TripReviewPackageStore(987654);
    $token = $store->begin(301, 42, 0, 'snapshot');
    $source = fopen($zipPath, 'rb');
    $offset = 0;
    while (!feof($source)) {
        $chunk = fread($source, 4*1024*1024);
        if ($chunk === '') break;
        $input = fopen('php://temp', 'r+b');
        fwrite($input, $chunk); rewind($input);
        $offset = $store->append($token, 301, 42, $offset, $input);
        fclose($input);
    }
    fclose($source);
    $ok($offset === filesize($zipPath), 'Chunked upload changed the ZIP size');
    $rejected = false;
    try { $store->publish($token, 301, 43, 0, 'snapshot', 'Рейс_301.zip', static function():void {}); }
    catch (DomainException) { $rejected = true; }
    $ok($rejected, 'Another actor could publish the package');
    $store->publish($token, 301, 42, 0, 'snapshot', 'Рейс_301.zip', static function(string $path):void {
        $check = new ZipArchive();
        if ($check->open($path) !== true || $check->numFiles !== 2) throw new RuntimeException('ZIP invalid');
        $check->close();
    });
    $download = $store->download($token);
    $ok($download['size'] === filesize($zipPath) && hash_file('sha256', $download['path']) === hash_file('sha256', $zipPath),
        'Shared link changed the ZIP contents');
    $rejected = false;
    try { $store->download(str_repeat('0', 64)); } catch (DomainException) { $rejected = true; }
    $ok($rejected, 'Unknown link was accepted');
    $metaPath = $testRoot.'/companies/987654/trip-review-packages/ready/'.$token.'.json';
    $meta = json_decode((string)file_get_contents($metaPath), true);
    $meta['expires_at'] = time()-1;
    file_put_contents($metaPath, json_encode($meta, JSON_THROW_ON_ERROR));
    $rejected = false;
    try { $store->download($token); } catch (DomainException) { $rejected = true; }
    $ok($rejected, 'Expired link was accepted');

    $trip = ['business_id'=>301, 'requests'=>[['zayavka_id'=>'123', 'shipper_name'=>'ООО <Тест>',
        'arrived_at'=>'2026-09-28 10:30:00', 'departed_at'=>'2026-09-28 11:00:00',
        'tn_absent'=>0, 'documents'=>[['id'=>1]], 'sender_signature_status'=>'yes',
        'driver_signature_status'=>'no', 'tn_datetime_status'=>'partial', 'recovery_source'=>'driver']]];
    $mail = TripReviewEmail::build($trip, 'https://plan-ex.ru/erpv2/trip-review-package/987654/'.$token);
    $ok(str_contains($mail['html'], 'ООО &lt;Тест&gt;') && !str_contains($mail['html'], 'ООО <Тест>'),
        'HTML table did not escape business data');
    $raw = DocumentMailMime::build(['from'=>'sender@example.test','to'=>['recipient@example.test'],
        'message_id'=>'<review.301@example.test>','subject'=>'Рейс 301', 'text'=>$mail['text'], 'html'=>$mail['html']]);
    $parsed = DocumentMailMime::parse($raw);
    $ok($parsed['attachments'] === [] && str_contains($parsed['text'], 'Скачать полный комплект (хранится 30 дней):')
        && str_contains($mail['html'], 'Хранится 30 дней'),
        'Link mail was not text/html alternative without attachments');
    $ok(str_contains($raw, 'multipart/alternative') && str_contains($raw, 'text/html'),
        'HTML table was not included in the email');
    echo "TRIP_REVIEW_LINK=PASS\n";
} finally { $remove($testRoot); }
