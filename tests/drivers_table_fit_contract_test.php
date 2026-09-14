<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = @file_get_contents($root . '/app/View/pages/company_drivers.php');
if (!is_string($view)) {
    fwrite(STDERR, "FAIL: cannot read company_drivers.php\n");
    exit(1);
}

$must = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$must(str_contains($view, 'table-card table-card--standard drivers-table-card'), 'drivers table must use scoped fit-to-card class');
$must(str_contains($view, 'class="table drivers-table"'), 'drivers table must use scoped fixed-layout class');
$must(str_contains($view, '.drivers-table-card > .table-scroll') && str_contains($view, 'overflow-x: hidden;'), 'drivers table horizontal scrolling must stay disabled');
$must(str_contains($view, '.drivers-table-card .drivers-table') && str_contains($view, 'table-layout: fixed;'), 'drivers table must stay constrained to card width');
$must(substr_count($view, '.drivers-table-card .drivers-table th:nth-child(') === 8, 'drivers table must keep explicit widths for all eight columns');
$must(str_contains($view, 'driver_table_limit_text($fullPassport, 55)'), 'passport preview must stay compact');
$must(str_contains($view, 'title="\' . e($fullPassport) . \'"'), 'full passport text must remain available in the title tooltip');

echo "DRIVERS_TABLE_FIT_CONTRACT=PASS\n";
