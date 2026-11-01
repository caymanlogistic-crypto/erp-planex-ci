<?php

namespace App\Service;

use RuntimeException;

/** A protected, signed, company-specific backup is required before deletion. */
final class CompanyDeletionBackupVerifier
{
    public static function verify(array $company, string $root, string $key, ?\DateTimeImmutable $now = null): array
    {
        $companyId = (int)($company['id'] ?? 0);
        $dbIdentifier = (string)($company['db_identifier'] ?? '');
        if ($companyId <= 0 || $dbIdentifier === '' || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $dbIdentifier)) {
            throw new RuntimeException('COMPANY_DELETE_INVALID_IDENTITY');
        }
        $rootPath = realpath($root);
        if ($rootPath === false || !is_dir($rootPath) || strlen($key) < 32) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_CONFIG_MISSING');
        }
        $publicPath = realpath(base_path('public'));
        $projectPath = realpath(base_path(''));
        if (($publicPath !== false && self::inside($rootPath, $publicPath))
            || ($projectPath !== false && ($rootPath === $projectPath || self::inside($rootPath, $projectPath)))) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_ROOT_UNSAFE');
        }
        $manifestPath = $rootPath . DIRECTORY_SEPARATOR . 'manifests' . DIRECTORY_SEPARATOR . 'company-' . $companyId . '.json';
        if (is_link($manifestPath) || !is_file($manifestPath) || !self::inside((string)realpath($manifestPath), $rootPath)) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_MANIFEST_MISSING');
        }
        $manifestBytes = file_get_contents($manifestPath);
        $manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
        if (!is_array($manifest)) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_MANIFEST_INVALID');
        }
        $signature = (string)($manifest['signature'] ?? '');
        unset($manifest['signature']);
        if (!preg_match('/^[0-9a-f]{64}$/D', $signature)) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_SIGNATURE_INVALID');
        }
        $expected = hash_hmac('sha256', self::canonicalJson($manifest), $key);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_SIGNATURE_INVALID');
        }
        $id = (string)($manifest['id'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/D', $id)
            || (int)($manifest['company_id'] ?? 0) !== $companyId
            || (string)($manifest['db_identifier'] ?? '') !== $dbIdentifier) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_IDENTITY_MISMATCH');
        }
        $createdText = (string)($manifest['created_at_utc'] ?? '');
        $expiresText = (string)($manifest['expires_at_utc'] ?? '');
        $restoredText = (string)($manifest['restore_verified_at_utc'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $createdText)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $expiresText)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $restoredText)) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_TIME_INVALID');
        }
        try {
            $created = new \DateTimeImmutable($createdText);
            $expires = new \DateTimeImmutable($expiresText);
            $restored = new \DateTimeImmutable($restoredText);
        } catch (\Exception $e) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_TIME_INVALID', 0, $e);
        }
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($created > $now || $expires <= $now || $expires <= $created
            || $restored < $created || $restored > $now || $restored >= $expires) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_EXPIRED');
        }
        $databaseHash = self::verifyArchive($rootPath, $manifest['database_archive'] ?? null);
        $storageHash = self::verifyArchive($rootPath, $manifest['storage_archive'] ?? null);
        $receiptEntry = $manifest['restore_receipt'] ?? null;
        $receiptHash = self::verifyArchive($rootPath, $receiptEntry);
        $receiptPath = $rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$receiptEntry['path']);
        $receipt = json_decode((string)file_get_contents($receiptPath), true);
        if (!is_array($receipt) || ($receipt['status'] ?? null) !== 'PASS'
            || (int)($receipt['company_id'] ?? 0) !== $companyId
            || (string)($receipt['db_identifier'] ?? '') !== $dbIdentifier
            || (string)($receipt['database_sha256'] ?? '') !== $databaseHash
            || (string)($receipt['storage_sha256'] ?? '') !== $storageHash
            || (string)($receipt['verified_at_utc'] ?? '') !== $restoredText) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_RESTORE_RECEIPT_INVALID');
        }
        return [
            'id' => $id,
            'manifest_sha256' => hash('sha256', (string)$manifestBytes),
            'database_sha256' => $databaseHash,
            'storage_sha256' => $storageHash,
            'restore_receipt_sha256' => $receiptHash,
            'created_at' => $created->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'expires_at' => $expires->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];
    }

    /** Only the offline backup preparer may sign the protected manifest. */
    public static function sign(array $manifest, string $key): string
    {
        if (strlen($key) < 32) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_CONFIG_MISSING');
        }
        unset($manifest['signature']);
        return hash_hmac('sha256', self::canonicalJson($manifest), $key);
    }

    private static function verifyArchive(string $rootPath, mixed $entry): string
    {
        if (!is_array($entry)) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_ARCHIVE_INVALID');
        }
        $relative = (string)($entry['path'] ?? '');
        $expected = (string)($entry['sha256'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_.\/-]+$/D', $relative)
            || str_contains($relative, '..') || str_starts_with($relative, '/')
            || !preg_match('/^[0-9a-f]{64}$/D', $expected)) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_ARCHIVE_INVALID');
        }
        $path = $rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($path);
        if ($resolved === false || is_link($path) || !self::inside($resolved, $rootPath)
            || !is_file($resolved) || filesize($resolved) <= 0) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_ARCHIVE_MISSING');
        }
        $actual = hash_file('sha256', $resolved);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException('COMPANY_DELETE_BACKUP_HASH_MISMATCH');
        }
        return $actual;
    }

    private static function inside(string $path, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        return str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private static function canonicalJson(array $value): string
    {
        $sort = static function (array $items) use (&$sort): array {
            if (!array_is_list($items)) {
                ksort($items, SORT_STRING);
            }
            foreach ($items as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }
            return $items;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
