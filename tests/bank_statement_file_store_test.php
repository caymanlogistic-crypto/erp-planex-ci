<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Support/entrypoint_dependencies.php';
if (!class_exists(\App\Service\BankStatementFileStore::class, false)) {
    throw new RuntimeException('Bank statement file store is not registered by the application entrypoint');
}

$root = sys_get_temp_dir() . '/planex-bank-store-' . bin2hex(random_bytes(8));
$source = tempnam(sys_get_temp_dir(), 'bank-xlsx-');
try {
    file_put_contents($source, 'test statement bytes');
    $hash = hash_file('sha256', $source);
    $store = new \App\Service\BankStatementFileStore($root);
    if ($store->has($hash)) throw new RuntimeException('Unexpected stored file');
    $store->save($source, $hash);
    $path = $store->verifiedPath($hash);
    if ($path === null || file_get_contents($path) !== 'test statement bytes') throw new RuntimeException('Saved bytes differ');
    $store->save($source, $hash);
    file_put_contents($path, 'corrupt');
    if ($store->verifiedPath($hash) !== null) throw new RuntimeException('Corrupt file downloadable');
    $store->remove($hash);
    if ($store->has($hash)) throw new RuntimeException('File still stored');
    if ($store->has('') || $store->has('../escape') || $store->verifiedPath('') !== null) {
        throw new RuntimeException('Legacy or invalid hash appeared downloadable');
    }
    $store->remove('');
    echo "BANK_STATEMENT_FILE_STORE=PASS\n";
} finally {
    if (is_file($source)) unlink($source);
    $dir = $root . '/bank-statements';
    if (is_dir($dir)) rmdir($dir);
    if (is_dir($root)) rmdir($root);
}
