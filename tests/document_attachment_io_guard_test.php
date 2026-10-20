<?php
declare(strict_types=1);

define('PLANEX_ATTACHMENT_RECOVERY_LIBRARY',true);
define('PLANEX_DOCUMENT_INVENTORY_LIBRARY_ONLY',true);
require_once dirname(__DIR__).'/scripts/registry_document_attachment_recovery.php';
require_once dirname(__DIR__).'/scripts/registry_document_inventory_readonly_probe.php';

$assert=static function(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); };
$storage=sys_get_temp_dir().'/planex_document_io_guard_'.bin2hex(random_bytes(8));
$prefix='companies/25/documents/driver/1/';
if (!mkdir($storage.'/'.$prefix,0700,true)) throw new RuntimeException('Fixture directory unavailable');
$fixtures=[
    'proof.pdf'=>['mime'=>'application/pdf','bytes'=>"%PDF-1.4\nsynthetic I/O guard regression\n%%EOF"],
    'proof.png'=>['mime'=>'image/png','bytes'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO2beioAAAAASUVORK5CYII=',true)],
];
foreach ($fixtures as $name=>$fixture) file_put_contents($storage.'/'.$prefix.$name,$fixture['bytes']);
$reporting=error_reporting(E_ALL);
$symlinkResult='unavailable_on_windows';
RegistryDocumentAttachmentRecovery::installIoGuard();
try {
    // Linux /proc/self/fd enumeration includes an already closed directory FD.
    // Its suppressed stat failure must not turn a valid file into status=symlink.
    foreach ($fixtures as $name=>$fixture) {
        for ($attempt=0;$attempt<3;$attempt++) {
            $inspection=RegistryDocumentInventoryReadOnly::inspect($storage,25,$prefix.$name,$attempt%2===0?'documents':'documents/driver/1',microtime(true)+10);
            $assert($inspection['status']==='present_stable','Actual CLI I/O guard must accept a stable regular file, including Linux FD probes');
            $assert($inspection['sha256']===hash('sha256',$fixture['bytes']) && $inspection['size']===strlen($fixture['bytes']),'Stable inspection must retain exact hash and size');
            $assert((new finfo(FILEINFO_MIME_TYPE))->file($storage.'/'.$prefix.$name)===$fixture['mime'],'I/O guard must allow MIME inspection after hashing');
        }
    }
    $missing=RegistryDocumentInventoryReadOnly::inspect($storage,25,$prefix.'absent.pdf','documents',microtime(true)+10);
    $assert($missing['status']==='missing','Missing files must remain an explicit negative result');
    $escaped=RegistryDocumentInventoryReadOnly::inspect($storage,25,$prefix.'../proof.pdf','documents',microtime(true)+10);
    $assert($escaped['status']==='rejected_path','Traversal must remain rejected');
    if (@symlink($storage.'/'.$prefix.'proof.pdf',$storage.'/'.$prefix.'link.pdf')) {
        $symlink=RegistryDocumentInventoryReadOnly::inspect($storage,25,$prefix.'link.pdf','documents',microtime(true)+10);
        $assert($symlink['status']==='symlink','Symlink files must remain rejected under the actual CLI I/O guard');
        $symlinkResult='rejected';
    } else $assert(PHP_OS_FAMILY==='Windows','POSIX regression requires a real symlink negative control');
    ob_start();
    try {
        @trigger_error('PRIVATE_SUPPRESSED_SENTINEL',E_USER_WARNING);
        error_reporting(E_ALL & ~E_USER_WARNING);
        trigger_error('PRIVATE_MASKED_SENTINEL',E_USER_WARNING);
        error_reporting(E_ALL);
        $caught=false;
        try { trigger_error('PRIVATE_NONSUPPRESSED_SENTINEL',E_USER_WARNING); }
        catch (RuntimeException $error) { $caught=true;$assert($error->getMessage()==='RECOVERY_IO_ERROR','Visible I/O warnings must expose only the fixed generic error code'); }
        $assert($caught,'A non-suppressed warning must still stop recovery');
    } finally { $output=ob_get_clean(); }
    $assert($output==='','I/O guard must not print private warning content');
} finally {
    restore_error_handler();error_reporting($reporting);
    $resolved=realpath($storage);$temporary=realpath(sys_get_temp_dir());
    $assert($resolved!==false && $temporary!==false && str_starts_with(str_replace('\\','/',$resolved),rtrim(str_replace('\\','/',$temporary),'/').'/planex_document_io_guard_'),'Fixture cleanup boundary');
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());else unlink($file->getPathname()); }rmdir($storage);
}
echo 'DOCUMENT_ATTACHMENT_IO_GUARD=PASS os='.PHP_OS_FAMILY.' regular=PASS missing=REJECTED symlink='.$symlinkResult." warning=GENERIC suppression=PASS\n";
