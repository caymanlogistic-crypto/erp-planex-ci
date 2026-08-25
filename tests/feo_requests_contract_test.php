<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/FeoXlsxParser.php';

use App\Service\FeoXlsxParser;

$pass = 0; $fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) { $pass++; return; }
    $fail++; fwrite(STDERR, "FAIL: {$name}\n");
};

$root = dirname(__DIR__);
$map = FeoXlsxParser::fieldMap();
$ok('44 canonical FEO fields', count($map) === 44);
$ok('request id mapping', ($map['Заявка, ID'] ?? null) === 'zayavka_id');
$ok('gross mass mapping', ($map['Суммарная масса (брутто), т (п. 3)'] ?? null) === 'mass_brutto');
$ok('coordinates preserved', isset($map['МНО_Координаты (Ш)'], $map['МНО_Координаты (Д)']));

$migration = file_get_contents($root . '/database/migrations-local/122_create_feo_requests.sql') ?: '';
foreach (['feo_requests','feo_imports','feo_import_changes','current_import_id','before_json','previous_import_id'] as $needle) {
    $ok('migration contains ' . $needle, str_contains($migration, $needle));
}

$route = file_get_contents($root . '/app/Http/Routes/company_feo_requests.php') ?: '';
foreach (['/company/feo/requests','import/analyze','import/commit','import/rollback','ЗАЯВКИ ФЭО','Все заявки'] as $needle) {
    $ok('route/menu contains ' . $needle, str_contains($route, $needle));
}

$service = file_get_contents($root . '/app/Service/FeoRequestService.php') ?: '';
$ok('rollback is LIFO', str_contains($service, "WHERE status='applied' ORDER BY id DESC LIMIT 1 FOR UPDATE"));
$ok('rollback restores snapshots', str_contains($service, 'before_json'));
$ok('rollback deletes inserted requests only by import ownership', str_contains($service, 'DELETE FROM feo_requests WHERE zayavka_id=? AND current_import_id=?'));
$ok('active duplicate hash is blocked', str_contains($service, "file_hash = ? AND status = 'applied'"));
$ok('missing rows are counted, not deleted', str_contains($service, 'missing_count'));

$view = file_get_contents($root . '/app/View/pages/company_feo_requests.php') ?: '';
foreach (['История загрузок','Загрузить XLSX','Анализировать','Применить загрузку','пропало из файла','Двойной клик'] as $needle) {
    $ok('view contains ' . $needle, str_contains($view, $needle));
}

$index = file_get_contents($root . '/public/index.php') ?: '';
$ok('entrypoint loads FEO routes', str_contains($index, "company_feo_requests.php"));

printf("FEO_REQUESTS_CONTRACT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
