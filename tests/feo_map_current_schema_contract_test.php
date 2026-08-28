<?php

declare(strict_types=1);

$pass = 0;
$fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) { $pass++; return; }
    $fail++;
    fwrite(STDERR, "FAIL: {$name}\n");
};

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/migrations-local/123_create_feo_map_placement.sql') ?: '';
$service = file_get_contents($root . '/app/Service/FeoMapService.php') ?: '';

$ok('placement current schema defines created_at', str_contains($migration, '`feo_placement_current`') && str_contains($migration, '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'));
$ok('map aliases canonical created_at as applied_at payload field', str_contains($service, 'c.created_at AS applied_at'));
$ok('map ordering uses canonical created_at', str_contains($service, 'ORDER BY c.created_at DESC,c.zayavka_id ASC'));
$ok('map never queries nonexistent placement-current applied_at column', !str_contains($service, 'c.applied_at'));

printf("PASS=%d FAIL=%d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
