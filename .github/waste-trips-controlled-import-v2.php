<?php

declare(strict_types=1);

$source = __DIR__ . '/waste-trips-controlled-import.php';
if (!is_file($source)) {
    throw new RuntimeException('controlled import source unavailable');
}
$code = (string) file_get_contents($source);
$old = "if(array_keys(\$customerInns)!==['4714004270']) failImport('unexpected customer INN set '.json_encode(array_keys(\$customerInns),JSON_UNESCAPED_UNICODE));";
$new = "\$customerInnList=array_map('strval',array_keys(\$customerInns)); sort(\$customerInnList,SORT_STRING); if(\$customerInnList!==['4714004270']) failImport('unexpected customer INN set '.json_encode(\$customerInnList,JSON_UNESCAPED_UNICODE));";
if (substr_count($code, $old) !== 1) {
    throw new RuntimeException('numeric INN guard patch target mismatch');
}
$patched = str_replace($old, $new, $code);
$tmp = tempnam(sys_get_temp_dir(), 'waste_import_v2_');
if ($tmp === false || file_put_contents($tmp, $patched) === false) {
    throw new RuntimeException('cannot prepare corrected controlled import harness');
}
try {
    require $tmp;
} finally {
    @unlink($tmp);
}
