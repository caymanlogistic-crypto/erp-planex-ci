<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    fwrite(STDERR, "CLI/CGI only.\n");
    exit(1);
}

$helper = (string)(getenv('PLANEX_GOOGLE_HELPER') ?: '');
if ($helper === '' || !is_file($helper) || !is_readable($helper)) {
    throw new RuntimeException('Google Sheets helper is unavailable for read-only discovery');
}
$source = file_get_contents($helper);
if (!is_string($source) || $source === '') {
    throw new RuntimeException('Google Sheets helper is empty');
}

$result = [
    'helper_sha256' => hash('sha256', $source),
    'has_get_google_sheets_data' => preg_match('/function\s+getGoogleSheetsData\s*\(/', $source) === 1,
    'uses_curl' => stripos($source, 'curl_') !== false,
    'uses_file_get_contents' => stripos($source, 'file_get_contents') !== false,
    'uses_google_api_client' => stripos($source, 'Google\\Client') !== false || stripos($source, 'Google_Client') !== false,
    'uses_service_account' => stripos($source, 'service_account') !== false || stripos($source, 'GOOGLE_APPLICATION_CREDENTIALS') !== false,
    'uses_csv' => stripos($source, 'csv') !== false,
];

final class DocgenCaptureHttpsStream
{
    public static string $url = '';
    private int $offset = 0;
    private string $payload = "id,c1,c2,c3,c4,c5,c6,c7,c8,contacts\n";
    public mixed $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$url = $path;
        $this->offset = 0;
        return true;
    }
    public function stream_read(int $count): string
    {
        $part = substr($this->payload, $this->offset, $count);
        $this->offset += strlen($part);
        return $part;
    }
    public function stream_eof(): bool { return $this->offset >= strlen($this->payload); }
    public function stream_stat(): array { return []; }
    public function url_stat(string $path, int $flags): array|false { return []; }
}

$hadHttps = in_array('https', stream_get_wrappers(), true);
if ($hadHttps && !stream_wrapper_unregister('https')) {
    throw new RuntimeException('Cannot isolate HTTPS wrapper');
}
try {
    if (!stream_wrapper_register('https', DocgenCaptureHttpsStream::class)) {
        throw new RuntimeException('Cannot register capture HTTPS wrapper');
    }
    require_once $helper;
    if (!function_exists('getGoogleSheetsData')) throw new RuntimeException('getGoogleSheetsData missing');
    getGoogleSheetsData();
} finally {
    if (in_array('https', stream_get_wrappers(), true)) stream_wrapper_unregister('https');
    if ($hadHttps) stream_wrapper_restore('https');
}

$url = DocgenCaptureHttpsStream::$url;
if ($url === '') throw new RuntimeException('Google helper did not request HTTPS URL');
$parts = parse_url($url);
if (!is_array($parts) || strtolower((string)($parts['host'] ?? '')) !== 'docs.google.com') {
    throw new RuntimeException('Captured source is not docs.google.com');
}
$query = [];
parse_str((string)($parts['query'] ?? ''), $query);
$safeQuery = [];
foreach ($query as $key => $value) {
    if (in_array((string)$key, ['output','format','gid','sheet'], true)) $safeQuery[(string)$key] = (string)$value;
    else $safeQuery[(string)$key] = '[redacted]';
}
$result['captured'] = [
    'host' => strtolower((string)$parts['host']),
    'path' => (string)($parts['path'] ?? ''),
    'query' => $safeQuery,
];

if (preg_match('~/spreadsheets/d/(?:e/)?([A-Za-z0-9_-]+)~', (string)($parts['path'] ?? ''), $m)) {
    $result['captured']['spreadsheet_id'] = (string)$m[1];
}

// Never print API keys, credentials, cookies, tokens, or unapproved query values.
echo 'DOCGEN_GOOGLE_SOURCE_DISCOVERY=' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
