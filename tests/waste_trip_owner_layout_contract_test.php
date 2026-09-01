<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root . '/app/Http/Controllers/Company/WasteTripController.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: cannot read WasteTripController.php\n");
    exit(1);
}

function methodBody(string $source, string $method): string
{
    $pattern = '/    public function ' . preg_quote($method, '/') . '\([^)]*\): void\s*\{(.*?)(?=\n    public function |\n    private function )/s';
    if (!preg_match($pattern, $source, $match)) {
        fwrite(STDERR, "FAIL: cannot locate {$method}() body\n");
        exit(1);
    }
    return (string) $match[1];
}

foreach (['index', 'importIndex'] as $method) {
    $body = methodBody($source, $method);
    $layoutNeedle = "require base_path('app/View/layouts/main.php');";
    $layoutPos = strpos($body, $layoutNeedle);
    if ($layoutPos === false) {
        fwrite(STDERR, "FAIL: {$method}() does not render main layout\n");
        exit(1);
    }
    $beforeLayout = substr($body, 0, $layoutPos);
    if (!str_contains($beforeLayout, '$config = $this->config;')) {
        fwrite(STDERR, "FAIL: {$method}() does not expose config to main layout\n");
        exit(1);
    }
    if (!str_contains($beforeLayout, '$db = $this->db;')) {
        fwrite(STDERR, "FAIL: {$method}() does not expose db to main layout\n");
        exit(1);
    }
}

if (substr_count($source, '$config = $this->config;') !== 2) {
    fwrite(STDERR, "FAIL: expected exactly two Waste Trips config layout bindings\n");
    exit(1);
}
if (substr_count($source, '$db = $this->db;') !== 2) {
    fwrite(STDERR, "FAIL: expected exactly two Waste Trips db layout bindings\n");
    exit(1);
}

echo "WASTE_TRIP_OWNER_LAYOUT=PASS\n";
