<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$viewPath = $root . '/app/View/pages/company_feo_map.php';
$jsPath = $root . '/public/assets/vendor/leaflet/1.9.4/leaflet.js';
$cssPath = $root . '/public/assets/vendor/leaflet/1.9.4/leaflet.css';
$sourcePath = $root . '/public/assets/vendor/leaflet/1.9.4/SOURCE.txt';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ([$viewPath, $jsPath, $cssPath, $sourcePath] as $path) {
    assertTrue(is_file($path), 'missing required file: ' . $path);
}

$view = file_get_contents($viewPath);
$source = file_get_contents($sourcePath);
$leafletJs = file_get_contents($jsPath);

assertTrue(is_string($view), 'cannot read map view');
assertTrue(is_string($source), 'cannot read Leaflet source manifest');
assertTrue(is_string($leafletJs), 'cannot read vendored Leaflet JS');

assertTrue(str_contains($view, '/assets/vendor/leaflet/1.9.4/leaflet.css'), 'map must load local Leaflet CSS');
assertTrue(str_contains($view, '/assets/vendor/leaflet/1.9.4/leaflet.js'), 'map must load local Leaflet JS');
assertTrue(!str_contains($view, 'cdn.jsdelivr.net'), 'map must not depend on jsDelivr');
assertTrue(!str_contains($view, 'unpkg.com'), 'map must not depend on unpkg');
assertTrue(!str_contains($view, 'data-fallback-href'), 'obsolete CDN fallback loader must be removed');

assertTrue(hash_file('sha256', $jsPath) === '85d455b4522415f6badc42a0e7d17c919d100347d6b8958bd0dc738fdecd6d50', 'vendored Leaflet JS hash drifted');
assertTrue(hash_file('sha256', $cssPath) === 'a7837102824184820dfa198d1ebcd109ff6d0ff9a2672a074b9a1b4d147d04c6', 'vendored Leaflet CSS hash drifted');
assertTrue(str_contains($source, 'Leaflet 1.9.4'), 'source manifest must pin Leaflet 1.9.4');
assertTrue(str_contains($source, 'https://github.com/Leaflet/Leaflet/releases/download/v1.9.4/leaflet.zip'), 'source manifest must identify official Leaflet release');
assertTrue(str_contains($leafletJs, 'Leaflet 1.9.4'), 'vendored JS must identify Leaflet 1.9.4');

echo "PASS: FEO map uses pinned same-origin Leaflet 1.9.4 assets.\n";
