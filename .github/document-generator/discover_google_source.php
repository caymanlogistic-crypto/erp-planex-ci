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
    'uses_gviz' => stripos($source, 'gviz') !== false,
    'uses_pubhtml' => stripos($source, 'pubhtml') !== false,
];

$ids = [];
if (preg_match_all('~docs\.google\.com/spreadsheets/d/(?:e/)?([A-Za-z0-9_-]{16,})~', $source, $m)) {
    foreach ($m[1] as $id) $ids[(string)$id] = true;
}
$result['spreadsheet_ids'] = array_keys($ids);

$hosts = [];
if (preg_match_all('~https?://([^/\s\"\']+)~i', $source, $m)) {
    foreach ($m[1] as $host) {
        $host = strtolower((string)$host);
        if (str_contains($host, 'google')) $hosts[$host] = true;
    }
}
$result['google_hosts'] = array_keys($hosts);

$config = [];
if (preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*(?:sheet|spreadsheet|gid|url|csv)[A-Za-z0-9_]*)\s*=\s*([\'\"])(.*?)\2\s*;/is', $source, $m, PREG_SET_ORDER)) {
    foreach ($m as $match) {
        $name = (string)$match[1];
        $value = trim((string)$match[3]);
        if ($value === '') continue;
        if (preg_match('~^https?://~i', $value)) {
            $parts = parse_url($value);
            if (!is_array($parts)) continue;
            $entry = [
                'host' => strtolower((string)($parts['host'] ?? '')),
                'path' => (string)($parts['path'] ?? ''),
            ];
            if (isset($parts['query'])) {
                parse_str((string)$parts['query'], $q);
                $entry['query_keys'] = array_values(array_map('strval', array_keys($q)));
            }
            $config[$name] = $entry;
        } elseif (preg_match('/^[0-9]+$/', $value) || preg_match('/^[A-Za-z0-9_-]{12,}$/', $value)) {
            $config[$name] = $value;
        }
    }
}
$result['safe_config_literals'] = $config;

// Never print API keys, credentials, cookies, tokens, or query values.
echo 'DOCGEN_GOOGLE_SOURCE_DISCOVERY=' . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
