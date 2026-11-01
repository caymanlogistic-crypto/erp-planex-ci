<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Service/CompanyDeletionBackupVerifier.php';

use App\Service\CompanyDeletionBackupVerifier as Verifier;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'planex_company_delete_backup_' . bin2hex(random_bytes(8));
mkdir($root . '/manifests', 0700, true);
mkdir($root . '/archives', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
            $remove($path . DIRECTORY_SEPARATOR . $item);
        }
        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
};
$expectFailure = static function (callable $action, string $code): void {
    try {
        $action();
    } catch (RuntimeException $e) {
        if ($e->getMessage() === $code) {
            return;
        }
        throw $e;
    }
    throw new RuntimeException('Expected ' . $code);
};
$company = ['id' => 991827, 'db_identifier' => 'planex_t_fixture'];
$key = str_repeat('k', 48);
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$createdAt = $now->modify('-30 minutes')->format('Y-m-d\TH:i:s\Z');
$restoredAt = $now->modify('-15 minutes')->format('Y-m-d\TH:i:s\Z');
$expiresAt = $now->modify('+1 hour')->format('Y-m-d\TH:i:s\Z');
$dump = $root . '/archives/tenant.sql.gz';
$storage = $root . '/archives/storage.tar.gz';
file_put_contents($dump, 'synthetic tenant archive');
file_put_contents($storage, 'synthetic storage archive');
$receiptPath = $root . '/archives/restore-receipt.json';
file_put_contents($receiptPath, json_encode([
    'status' => 'PASS', 'company_id' => $company['id'], 'db_identifier' => $company['db_identifier'],
    'database_sha256' => hash_file('sha256', $dump), 'storage_sha256' => hash_file('sha256', $storage),
    'verified_at_utc' => $restoredAt,
], JSON_THROW_ON_ERROR));
$manifest = [
    'id' => bin2hex(random_bytes(16)),
    'company_id' => $company['id'],
    'db_identifier' => $company['db_identifier'],
    'created_at_utc' => $createdAt,
    'restore_verified_at_utc' => $restoredAt,
    'expires_at_utc' => $expiresAt,
    'database_archive' => ['path' => 'archives/tenant.sql.gz', 'sha256' => hash_file('sha256', $dump)],
    'storage_archive' => ['path' => 'archives/storage.tar.gz', 'sha256' => hash_file('sha256', $storage)],
    'restore_receipt' => ['path' => 'archives/restore-receipt.json', 'sha256' => hash_file('sha256', $receiptPath)],
];
$write = static function (array $value) use ($root, $company, $key): void {
    $value['signature'] = Verifier::sign($value, $key);
    file_put_contents($root . '/manifests/company-' . $company['id'] . '.json', json_encode($value, JSON_THROW_ON_ERROR));
};
try {
    $write($manifest);
    $verified = Verifier::verify($company, $root, $key, $now);
    if ($verified['id'] !== $manifest['id'] || $verified['database_sha256'] !== hash_file('sha256', $dump)) {
        throw new RuntimeException('Exact backup identity/hash was lost');
    }
    $expectFailure(static fn() => Verifier::verify($company, $root, '', $now), 'COMPANY_DELETE_BACKUP_CONFIG_MISSING');
    $expectFailure(static fn() => Verifier::verify(['id' => 991828, 'db_identifier' => 'planex_t_fixture'], $root, $key, $now), 'COMPANY_DELETE_BACKUP_MANIFEST_MISSING');
    $tampered = $manifest;
    $tampered['db_identifier'] = 'other_schema';
    $write($tampered);
    $expectFailure(static fn() => Verifier::verify($company, $root, $key, $now), 'COMPANY_DELETE_BACKUP_IDENTITY_MISMATCH');
    $write($manifest);
    file_put_contents($dump, 'changed archive bytes');
    $expectFailure(static fn() => Verifier::verify($company, $root, $key, $now), 'COMPANY_DELETE_BACKUP_HASH_MISMATCH');
    file_put_contents($dump, 'synthetic tenant archive');
    $expectFailure(static fn() => Verifier::verify($company, $root, $key, $now->modify('+1 hour')), 'COMPANY_DELETE_BACKUP_EXPIRED');
    $traversal = $manifest;
    $traversal['database_archive']['path'] = '../outside.sql.gz';
    $write($traversal);
    $expectFailure(static fn() => Verifier::verify($company, $root, $key, $now), 'COMPANY_DELETE_BACKUP_ARCHIVE_INVALID');
    unlink($root . '/manifests/company-' . $company['id'] . '.json');
    $unsignedPath = $root . '/unsigned.json';
    file_put_contents($unsignedPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    putenv('PLANEX_COMPANY_DELETION_BACKUP_ROOT=' . $root);
    putenv('PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY=' . $key);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/company_deletion_seal_backup_manifest.php')
        . ' ' . escapeshellarg($unsignedPath);
    exec($command, $output, $exitCode);
    if ($exitCode !== 0 || !in_array('COMPANY_DELETE_BACKUP_MANIFEST_SEALED=PASS company_id=' . $company['id'], $output, true)) {
        throw new RuntimeException('CLI manifest sealing failed');
    }
    Verifier::verify($company, $root, $key);
    echo "COMPANY_DELETION_BACKUP_VERIFIER=PASS\n";
} finally {
    putenv('PLANEX_COMPANY_DELETION_BACKUP_ROOT');
    putenv('PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY');
    $remove($root);
}
