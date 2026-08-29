<?php

namespace App\Service;

final class FeoMapTileService
{
    private const MAX_ZOOM = 19;
    private const CACHE_TTL_SECONDS = 604800;
    private const UPSTREAM_BASE = 'https://tile.openstreetmap.org';
    private const USER_AGENT = 'PLANEX-ERP-FEO-Map/1.0 (+https://plan-ex.ru/)';
    private const REFERER = 'https://plan-ex.ru/erpv2/company/feo/requests/map';

    public static function normalizeCoordinates(string $zRaw, string $xRaw, string $yRaw): ?array
    {
        if (!preg_match('/^\d{1,2}$/', $zRaw)
            || !preg_match('/^\d{1,7}$/', $xRaw)
            || !preg_match('/^\d{1,7}$/', $yRaw)) {
            return null;
        }

        $z = (int) $zRaw;
        $x = (int) $xRaw;
        $y = (int) $yRaw;
        if ($z < 0 || $z > self::MAX_ZOOM) {
            return null;
        }

        $limit = 1 << $z;
        if ($x < 0 || $y < 0 || $x >= $limit || $y >= $limit) {
            return null;
        }

        return [$z, $x, $y];
    }

    public function serve(string $zRaw, string $xRaw, string $yRaw): void
    {
        $coordinates = self::normalizeCoordinates($zRaw, $xRaw, $yRaw);
        if ($coordinates === null) {
            $this->errorResponse(404, 'Tile not found.');
            return;
        }

        [$z, $x, $y] = $coordinates;
        $directory = storage_path("cache/feo-map-tiles/{$z}/{$x}");
        $cachePath = $directory . DIRECTORY_SEPARATOR . $y . '.png';
        $metaPath = $directory . DIRECTORY_SEPARATOR . $y . '.json';

        if ($this->isFresh($cachePath)) {
            $this->serveCached($cachePath, false);
            return;
        }

        $staleExists = is_file($cachePath) && (int) @filesize($cachePath) > 0;
        $meta = $this->readMetadata($metaPath);
        $response = $this->fetchUpstream($z, $x, $y, $meta);

        if (($response['status'] ?? 0) === 304 && $staleExists) {
            @touch($cachePath);
            $this->serveCached($cachePath, false);
            return;
        }

        $body = $response['body'] ?? null;
        if (($response['status'] ?? 0) === 200 && is_string($body) && $this->isPng($body)) {
            if (!$this->persistTile($directory, $cachePath, $metaPath, $body, $response['headers'] ?? [])) {
                if ($staleExists) {
                    $this->serveCached($cachePath, true);
                    return;
                }
                $this->errorResponse(503, 'Tile cache unavailable.');
                return;
            }
            $this->serveCached($cachePath, false);
            return;
        }

        if ($staleExists) {
            $this->serveCached($cachePath, true);
            return;
        }

        $this->errorResponse(502, 'Map tile unavailable.');
    }

    private function isFresh(string $cachePath): bool
    {
        if (!is_file($cachePath) || (int) @filesize($cachePath) <= 0) {
            return false;
        }
        $mtime = @filemtime($cachePath);
        return is_int($mtime) && $mtime >= time() - self::CACHE_TTL_SECONDS;
    }

    private function serveCached(string $cachePath, bool $stale): void
    {
        $mtime = @filemtime($cachePath) ?: time();
        $ifModifiedSince = trim((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if (!$stale && $ifModifiedSince !== '') {
            $since = strtotime($ifModifiedSince);
            if ($since !== false && $since >= $mtime) {
                http_response_code(304);
                header('Cache-Control: public, max-age=' . self::CACHE_TTL_SECONDS);
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
                return;
            }
        }

        http_response_code(200);
        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=' . ($stale ? '3600' : (string) self::CACHE_TTL_SECONDS));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('Content-Length: ' . (string) filesize($cachePath));
        readfile($cachePath);
    }

    private function persistTile(string $directory, string $cachePath, string $metaPath, string $body, array $headers): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (\Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        $temp = $cachePath . '.tmp.' . $suffix;
        if (@file_put_contents($temp, $body, LOCK_EX) !== strlen($body)) {
            @unlink($temp);
            return false;
        }
        @chmod($temp, 0664);
        if (!@rename($temp, $cachePath)) {
            @unlink($temp);
            return false;
        }

        $metadata = [
            'etag' => (string) ($headers['etag'] ?? ''),
            'last_modified' => (string) ($headers['last-modified'] ?? ''),
            'cached_at' => gmdate('c'),
        ];
        @file_put_contents($metaPath, json_encode($metadata, JSON_UNESCAPED_SLASHES) ?: '{}', LOCK_EX);
        return true;
    }

    private function readMetadata(string $metaPath): array
    {
        if (!is_file($metaPath)) {
            return [];
        }
        $raw = @file_get_contents($metaPath);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function fetchUpstream(int $z, int $x, int $y, array $meta): array
    {
        $url = self::UPSTREAM_BASE . "/{$z}/{$x}/{$y}.png";
        $requestHeaders = ['Accept: image/png,image/*;q=0.8,*/*;q=0.5'];
        if (($meta['etag'] ?? '') !== '') {
            $requestHeaders[] = 'If-None-Match: ' . $meta['etag'];
        }
        if (($meta['last_modified'] ?? '') !== '') {
            $requestHeaders[] = 'If-Modified-Since: ' . $meta['last_modified'];
        }

        if (function_exists('curl_init')) {
            $responseHeaders = [];
            $ch = curl_init($url);
            if ($ch !== false) {
                $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_USERAGENT => self::USER_AGENT,
                    CURLOPT_REFERER => self::REFERER,
                    CURLOPT_HTTPHEADER => $requestHeaders,
                    CURLOPT_ENCODING => '',
                    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                        $length = strlen($line);
                        $position = strpos($line, ':');
                        if ($position !== false) {
                            $name = strtolower(trim(substr($line, 0, $position)));
                            $value = trim(substr($line, $position + 1));
                            if ($name !== '') {
                                $responseHeaders[$name] = $value;
                            }
                        }
                        return $length;
                    },
                ];
                if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                    $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
                }
                curl_setopt_array($ch, $options);
                $body = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                return ['status'=>$status,'body'=>is_string($body) ? $body : null,'headers'=>$responseHeaders];
            }
        }

        if ((bool) ini_get('allow_url_fopen')) {
            $headerText = implode("\r\n", array_merge([
                'User-Agent: ' . self::USER_AGENT,
                'Referer: ' . self::REFERER,
            ], $requestHeaders));
            $context = stream_context_create(['http'=>[
                'method'=>'GET',
                'timeout'=>10,
                'ignore_errors'=>true,
                'follow_location'=>0,
                'max_redirects'=>0,
                'header'=>$headerText,
            ]]);
            $body = @file_get_contents($url, false, $context);
            $rawHeaders = $http_response_header ?? [];
            $status = 0;
            $responseHeaders = [];
            foreach ($rawHeaders as $line) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match)) {
                    $status = (int) $match[1];
                    continue;
                }
                $position = strpos($line, ':');
                if ($position !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
                }
            }
            return ['status'=>$status,'body'=>is_string($body) ? $body : null,'headers'=>$responseHeaders];
        }

        return ['status'=>0,'body'=>null,'headers'=>[]];
    }

    private function isPng(string $body): bool
    {
        return strlen($body) > 8 && substr($body, 0, 8) === "\x89PNG\r\n\x1a\n";
    }

    private function errorResponse(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $message;
    }
}
