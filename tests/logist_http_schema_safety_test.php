<?php

declare(strict_types=1);

$actions = glob(dirname(__DIR__) . '/app/Http/Controllers/Company/LogistActions/*.php') ?: [];
if (count($actions) < 11) {
    throw new RuntimeException('Logist HTTP action inventory is incomplete.');
}

foreach ($actions as $path) {
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    if (preg_match('/\b(?:CREATE|ALTER|DROP)\s+(?:DATABASE|TABLE|INDEX)\b|database\/migrations-local\/|->exec\s*\(/i', $source)) {
        throw new RuntimeException('Request-time schema mutation in ' . basename($path));
    }
}

echo "LOGIST_HTTP_SCHEMA_SAFETY=PASS\n";
