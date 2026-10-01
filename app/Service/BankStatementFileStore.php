<?php

namespace App\Service;

final class BankStatementFileStore
{
    private const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly string $companyStorageRoot)
    {
    }

    public function has(string $hash): bool
    {
        return is_file($this->path($hash));
    }

    public function save(string $sourcePath, string $hash): void
    {
        $destination = $this->path($hash);
        $size = is_file($sourcePath) ? filesize($sourcePath) : false;
        if ($size === false || $size <= 0 || $size > self::MAX_BYTES || !hash_equals($hash, (string)hash_file('sha256', $sourcePath))) {
            throw new \RuntimeException('Файл выписки не прошёл проверку перед сохранением.');
        }
        if (is_file($destination)) {
            if (hash_equals($hash, (string)hash_file('sha256', $destination))) {
                return;
            }
            throw new \RuntimeException('Сохранённая выписка повреждена.');
        }
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать хранилище выписок.');
        }
        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(16)) . '.tmp';
        try {
            if (!copy($sourcePath, $temporary)) {
                throw new \RuntimeException('Не удалось сохранить файл выписки.');
            }
            chmod($temporary, 0600);
            if (!hash_equals($hash, (string)hash_file('sha256', $temporary)) || !rename($temporary, $destination)) {
                throw new \RuntimeException('Не удалось проверить сохранённый файл выписки.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function verifiedPath(string $hash): ?string
    {
        $path = $this->path($hash);
        return is_file($path) && hash_equals($hash, (string)hash_file('sha256', $path)) ? $path : null;
    }

    public function remove(string $hash): void
    {
        $path = $this->path($hash);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Не удалось удалить файл выписки.');
        }
    }

    private function path(string $hash): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new \InvalidArgumentException('Неверная контрольная сумма выписки.');
        }
        return rtrim($this->companyStorageRoot, '/\\') . DIRECTORY_SEPARATOR . 'bank-statements' . DIRECTORY_SEPARATOR . $hash . '.xlsx';
    }
}
