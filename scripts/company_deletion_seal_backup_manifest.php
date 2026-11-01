<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Service/CompanyDeletionBackupVerifier.php';

use App\Service\CompanyDeletionBackupVerifier as Verifier;

$input = (string)($argv[1] ?? '');
$root = (string)getenv('PLANEX_COMPANY_DELETION_BACKUP_ROOT');
$key = (string)getenv('PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY');
if ($input === '' || $root === '' || strlen($key) < 32) {
    fwrite(STDERR, "Usage: protected backup root/key environment + unsigned manifest JSON path\n");
    exit(2);
}
$rootPath = realpath($root);
$inputPath = realpath($input);
if ($rootPath === false || $inputPath === false || is_link($input)
    || !str_starts_with($inputPath, rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Unsigned manifest must be inside the protected backup root.');
}
$manifest = json_decode((string)file_get_contents($inputPath), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifest) || isset($manifest['signature'])) {
    throw new RuntimeException('Expected an unsigned manifest object.');
}
$companyId = (int)($manifest['company_id'] ?? 0);
$dbIdentifier = (string)($manifest['db_identifier'] ?? '');
if ($companyId <= 0 || $dbIdentifier === '') {
    throw new RuntimeException('Company identity is required.');
}
$manifest['signature'] = Verifier::sign($manifest, $key);
$finalPath = $rootPath . DIRECTORY_SEPARATOR . 'manifests' . DIRECTORY_SEPARATOR . 'company-' . $companyId . '.json';
$handle = fopen($finalPath, 'x');
if ($handle === false) {
    throw new RuntimeException('A company backup manifest already exists.');
}
try {
    $bytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (fwrite($handle, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Unable to write signed manifest.');
    }
    fflush($handle);
    fclose($handle);
    $handle = null;
    chmod($finalPath, 0600);
    $verified = Verifier::verify(['id' => $companyId, 'db_identifier' => $dbIdentifier], $rootPath, $key);
    if ($verified['id'] !== (string)($manifest['id'] ?? '')) {
        throw new RuntimeException('Sealed backup identity changed.');
    }
    echo "COMPANY_DELETE_BACKUP_MANIFEST_SEALED=PASS company_id=$companyId\n";
} catch (Throwable $e) {
    if (is_resource($handle)) fclose($handle);
    unlink($finalPath);
    throw $e;
}
