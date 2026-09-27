<?php
declare(strict_types=1);

namespace App\Service;

use DomainException;
use RuntimeException;

/** Private, streamed storage for the ZIP sent with a trip review link. */
final class TripReviewPackageStore
{
    private const CHUNK_BYTES = 5 * 1024 * 1024;
    private const STAGE_SECONDS = 86400;
    private const LINK_SECONDS = 30 * 86400;

    public function __construct(private readonly int $companyId) {}

    public function begin(int $tripId, int $actorId, int $version, string $snapshot): string
    {
        $this->prepare();
        $this->cleanExpired();
        $token = bin2hex(random_bytes(32));
        $meta = ['company_id'=>$this->companyId, 'trip_id'=>$tripId, 'actor_id'=>$actorId,
            'version'=>$version, 'snapshot'=>$snapshot, 'size'=>0,
            'expires_at'=>time()+self::STAGE_SECONDS];
        if (file_put_contents($this->path('pending', $token, 'json'), json_encode($meta, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('Не удалось начать загрузку комплекта.');
        }
        return $token;
    }

    /** @param resource|null $input Testable stream; HTTP requests use php://input. */
    public function append(string $token, int $tripId, int $actorId, int $offset, $input = null): int
    {
        $metaFile = $this->lockedStage($token);
        try {
            $meta = $this->readMeta($metaFile);
            $this->assertStage($meta, $tripId, $actorId);
            if ($offset !== (int)$meta['size']) throw new DomainException('Загрузка прервана. Повторите передачу комплекта.');
            $path = $this->path('pending', $token, 'part');
            $destination = fopen($path, 'c+b');
            $source = $input ?? fopen('php://input', 'rb');
            if ($destination === false || $source === false) {
                if (is_resource($destination)) fclose($destination);
                if ($input === null && is_resource($source)) fclose($source);
                throw new RuntimeException('Не удалось принять часть комплекта.');
            }
            try {
                if (fseek($destination, 0, SEEK_END) !== 0 || ftell($destination) !== $offset) {
                    throw new DomainException('Загрузка прервана. Повторите передачу комплекта.');
                }
                $written = 0;
                while (!feof($source)) {
                    $bytes = fread($source, min(1024 * 1024, self::CHUNK_BYTES + 1 - $written));
                    if ($bytes === false) throw new RuntimeException('Не удалось прочитать часть комплекта.');
                    if ($bytes === '') break;
                    $written += strlen($bytes);
                    if ($written > self::CHUNK_BYTES || fwrite($destination, $bytes) !== strlen($bytes)) {
                        throw new DomainException('Часть комплекта слишком велика или на сервере нет места.');
                    }
                }
                if ($written === 0 || !fflush($destination)) throw new RuntimeException('Не удалось сохранить часть комплекта.');
            } catch (\Throwable $exception) {
                ftruncate($destination, $offset);
                throw $exception;
            } finally {
                if ($input === null && is_resource($source)) fclose($source);
                fclose($destination);
            }
            $meta['size'] += $written;
            $this->writeMeta($metaFile, $meta);
            return (int)$meta['size'];
        } finally { flock($metaFile, LOCK_UN); fclose($metaFile); }
    }

    /** @param callable(string):void $validate */
    public function publish(string $token, int $tripId, int $actorId, int $version, string $snapshot,
        string $filename, callable $validate): string
    {
        $metaFile = $this->lockedStage($token);
        $published = false;
        try {
            $meta = $this->readMeta($metaFile);
            $this->assertStage($meta, $tripId, $actorId);
            if ($version !== (int)$meta['version'] || !hash_equals((string)$meta['snapshot'], $snapshot)) {
                throw new DomainException('Данные рейса изменились. Подготовьте комплект повторно.');
            }
            $source = $this->path('pending', $token, 'part');
            if ((int)$meta['size'] < 100 || !is_file($source) || filesize($source) !== (int)$meta['size']) {
                throw new DomainException('Комплект не загружен полностью.');
            }
            $validate($source);
            $destination = $this->path('ready', $token, 'zip');
            if (!rename($source, $destination)) throw new RuntimeException('Не удалось сохранить комплект для скачивания.');
            try {
                $ready = ['company_id'=>$this->companyId, 'trip_id'=>$tripId,
                    'name'=>$filename, 'size'=>$meta['size'], 'expires_at'=>time()+self::LINK_SECONDS];
                if (file_put_contents($this->path('ready', $token, 'json'), json_encode($ready, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                    throw new RuntimeException('Не удалось сохранить ссылку на комплект.');
                }
            } catch (\Throwable $exception) {
                @unlink($this->path('ready', $token, 'json'));
                if (!rename($destination, $source)) @unlink($destination);
                throw $exception;
            }
            $published = true;
            return $token;
        } finally {
            flock($metaFile, LOCK_UN); fclose($metaFile);
            if ($published) @unlink($this->path('pending', $token, 'json'));
        }
    }

    public function discard(string $token): void
    {
        if (!$this->validToken($token)) return;
        @unlink($this->path('ready', $token, 'json'));
        @unlink($this->path('ready', $token, 'zip'));
    }

    /** @return array{path:string,name:string,size:int} */
    public function download(string $token): array
    {
        if (!$this->validToken($token)) throw new DomainException('Ссылка недействительна.');
        $path = $this->path('ready', $token, 'zip');
        $metaPath = $this->path('ready', $token, 'json');
        $meta = is_file($metaPath) ? json_decode((string)file_get_contents($metaPath), true) : null;
        if (!is_array($meta) || (int)($meta['company_id'] ?? 0) !== $this->companyId
            || (int)($meta['expires_at'] ?? 0) < time() || !is_file($path)
            || filesize($path) !== (int)($meta['size'] ?? -1)) {
            throw new DomainException('Ссылка недействительна или срок её действия истёк.');
        }
        return ['path'=>$path, 'name'=>(string)$meta['name'], 'size'=>(int)$meta['size']];
    }

    private function lockedStage(string $token)
    {
        if (!$this->validToken($token)) throw new DomainException('Загрузка комплекта не найдена.');
        $file = @fopen($this->path('pending', $token, 'json'), 'r+b');
        if ($file === false || !flock($file, LOCK_EX)) throw new DomainException('Загрузка комплекта не найдена.');
        return $file;
    }

    private function assertStage(array $meta, int $tripId, int $actorId): void
    {
        if ((int)($meta['company_id'] ?? 0) !== $this->companyId
            || (int)($meta['trip_id'] ?? 0) !== $tripId
            || (int)($meta['actor_id'] ?? 0) !== $actorId
            || (int)($meta['expires_at'] ?? 0) < time()) {
            throw new DomainException('Загрузка комплекта недействительна.');
        }
    }

    private function readMeta($file): array
    {
        rewind($file);
        $value = json_decode((string)stream_get_contents($file), true);
        if (!is_array($value)) throw new RuntimeException('Состояние загрузки повреждено.');
        return $value;
    }

    private function writeMeta($file, array $meta): void
    {
        rewind($file);
        if (!ftruncate($file, 0) || fwrite($file, json_encode($meta, JSON_THROW_ON_ERROR)) === false || !fflush($file)) {
            throw new RuntimeException('Не удалось сохранить состояние загрузки.');
        }
    }

    private function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $token) === 1;
    }

    private function path(string $bucket, string $token, string $extension): string
    {
        return storage_path('companies/'.$this->companyId.'/trip-review-packages/'.$bucket.'/'.$token.'.'.$extension);
    }

    private function prepare(): void
    {
        foreach (['pending', 'ready'] as $bucket) {
            $directory = storage_path('companies/'.$this->companyId.'/trip-review-packages/'.$bucket);
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Хранилище комплектов недоступно.');
            }
        }
    }

    private function cleanExpired(): void
    {
        foreach (['pending', 'ready'] as $bucket) {
            $directory = storage_path('companies/'.$this->companyId.'/trip-review-packages/'.$bucket);
            $cleaned = 0;
            foreach (glob($directory.'/*.json') ?: [] as $metaPath) {
                if ($cleaned >= 20) break;
                $meta = json_decode((string)@file_get_contents($metaPath), true);
                if (is_array($meta) && (int)($meta['expires_at'] ?? 0) >= time()) continue;
                $token = basename($metaPath, '.json');
                if (!$this->validToken($token)) continue;
                @unlink($this->path($bucket, $token, $bucket === 'pending' ? 'part' : 'zip'));
                @unlink($metaPath);
                $cleaned++;
            }
        }
    }
}
