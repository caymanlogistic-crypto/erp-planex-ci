<?php

$root = dirname(__DIR__);
$failures = [];
$read = static function (string $path) use ($root, &$failures): string {
    $full = $root . '/' . $path;
    $content = is_file($full) ? file_get_contents($full) : false;
    if (!is_string($content)) {
        $failures[] = 'missing/unreadable: ' . $path;
        return '';
    }
    return $content;
};
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$route = $read('app/Http/Routes/superadmin.php');
$controller = $read('app/Http/Controllers/Superadmin/UiPatternLabController.php');
$view = $read('app/View/pages/ui_pattern_lab.php');
$css = $read('public/assets/css/ui-pattern-lab.css');
$visual = $read('.github/ui-pattern-lab/ui_pattern_lab_visual.js');

$expect(str_contains($route, "'/ui-library'"), 'GET /ui-library route not registered');
$expect(str_contains($controller, "requireRole('superadmin')"), 'superadmin role gate missing');
$expect(!str_contains($controller, 'connection(') && !str_contains($view, 'connection('), 'UI lab must not open a DB connection');
$expect(preg_match('/\bINSERT\s+INTO\b|\bUPDATE\s+[A-Za-z0-9_]+\s+SET\b|\bDELETE\s+FROM\b|\bFROM\s+finance_/i', $controller . "\n" . $view) !== 1, 'UI lab must not contain mutating/business SQL');

$patternIds = [
    'UI-PAGE-HEADER-01',
    'UI-TABLE-01',
    'UI-SEARCH-01',
    'UI-FILTER-BAR-01',
    'UI-FILTER-PANEL-01',
    'UI-BUTTON-01',
    'UI-FIELD-01',
];
foreach ($patternIds as $patternId) {
    $expect(str_contains($view, $patternId), 'missing pattern id: ' . $patternId);
}

foreach (['foundation', 'page-header', 'table', 'search', 'filters', 'buttons', 'fields'] as $section) {
    $expect(str_contains($view, 'id="' . $section . '"'), 'missing section: ' . $section);
}

$expect(str_contains($view, 'ООО «Пример»'), 'synthetic comparison dataset missing');
$expect(str_contains($view, 'OBSERVED LEGACY'), 'legacy comparison status missing');
$expect(str_contains($view, 'OBSERVED DRIFT'), 'drift comparison status missing');
$expect(!str_contains($view, 'APPROVED') && !str_contains($view, 'CANONICAL') && !str_contains($view, 'LOCKED'), 'owner-only approval words must not be assigned in V0');
$expect(str_contains($view, 'ui-pattern-lab.css'), 'gallery stylesheet not loaded');

$expect(str_contains($css, '.ui-lab'), 'gallery CSS prefix missing');
$expect(preg_match('/(^|\})\s*\.(btn|table|tbl|field|field-input|field-select|page-head|modal|filters-bar|ux-table)\b/m', $css) !== 1, 'gallery CSS must not redefine production component selectors');
$expect(str_contains($visual, 'ui-pattern-lab-v0-1920.png'), '1920 screenshot contract missing');
$expect(str_contains($visual, 'ui-pattern-lab-v0-1440.png'), '1440 screenshot contract missing');

if ($failures !== []) {
    fwrite(STDERR, "UI_PATTERN_LAB_CONTRACT=FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "UI_PATTERN_LAB_CONTRACT=PASS\n";
