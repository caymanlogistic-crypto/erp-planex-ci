<?php
declare(strict_types=1);

if (getenv('APP_ENV') !== 'test') throw new RuntimeException('Test only.');
if (PHP_OS_FAMILY === 'Windows') throw new RuntimeException('XA durability test requires local WSL/POSIX.');

$journal = sys_get_temp_dir() . '/planex-feo-placement-xa-' . bin2hex(random_bytes(8));
$connection = [
    'host'=>(string)getenv('PLANEX_SCHEMA_DB_HOST'),
    'port'=>(int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306),
    'username'=>(string)getenv('PLANEX_SCHEMA_DB_USER'),
    'password'=>(string)getenv('PLANEX_SCHEMA_DB_PASSWORD'),
];
putenv('PLANEX_TEST_PLACEMENT_LEGACY_XA=1');
putenv('FEO_LEGACY_SYNC_CONNECTION_B64=' . base64_encode(json_encode($connection, JSON_THROW_ON_ERROR)));
putenv('FEO_LEGACY_SYNC_JOURNAL_DIR=' . $journal);
try {
    require __DIR__ . '/feo_placement_legacy_sync_mysql_test.php';
    echo "FEO_PLACEMENT_LEGACY_SYNC_XA_MYSQL=PASS\n";
} finally {
    foreach (glob($journal . '/*') ?: [] as $file) @unlink($file);
    if (is_dir($journal)) @rmdir($journal);
    putenv('PLANEX_TEST_PLACEMENT_LEGACY_XA');
    putenv('FEO_LEGACY_SYNC_CONNECTION_B64');
    putenv('FEO_LEGACY_SYNC_JOURNAL_DIR');
}
