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
$read = static fn(string $path): string => file_get_contents($path) ?: '';

$map = FeoXlsxParser::fieldMap();
$ok('44 canonical FEO fields remain protected', count($map) === 44);

$service = $read($root . '/app/Service/FeoRequestService.php');
foreach ([
    'listRequestsBatch' => 'server batch API',
    'parseRequestIds' => 'multi-id parser',
    "preg_split('/[,;\\s]+/u'" => 'comma semicolon whitespace ID syntax',
    'MAX_FILTER_IDS = 500' => 'ID filter safety cap',
    'missing_ids' => 'missing ID diagnostics',
    'searchColumns' => 'server content search',
    'INTERVAL 7 DAY' => 'seven day freshness SLA',
    'freshnessState' => 'freshness state',
    'restoreRolledBackImport' => 'restore operation',
    "ORDER BY id ASC" => 'restore journal forward order',
    'after_json' => 'restore after snapshot',
    "status='applied',rolled_back_by_user_id=NULL" => 'restore reactivates import',
    'restoreCandidateId' => 'strict restore candidate',
    'can_restore' => 'history restore capability',
] as $needle => $label) {
    $ok($label, str_contains($service, $needle));
}
$ok('rollback remains latest active LIFO', str_contains($service, "WHERE status='applied' ORDER BY id DESC LIMIT 1 FOR UPDATE"));
$ok('delete remains latest history LIFO', str_contains($service, 'SELECT * FROM feo_imports ORDER BY id DESC LIMIT 1 FOR UPDATE'));
$ok('restore validates pre-rollback state', str_contains($service, 'currentPrevious !== $expectedPrevious') && str_contains($service, 'sameBusinessRow($current, $before)'));

$controller = $read($root . '/app/Http/Controllers/Company/FeoRequestsController.php');
$ok('JSON data endpoint action exists', str_contains($controller, 'public function data(): void'));
$ok('restore controller is privileged', str_contains($controller, 'public function restoreImport(): void') && substr_count($controller, "requireRole(['company_owner','senior_logist']);") >= 3);
$ok('restore controller calls service', str_contains($controller, 'restoreRolledBackImport($pdo, $importId, $_SESSION)'));
$ok('index exposes freshness', str_contains($controller, 'freshnessState($pdo)'));

$route = $read($root . '/app/Http/Routes/company_feo_requests.php');
foreach (['/company/feo/requests/data','import/rollback','import/restore','import/delete','registerFeoFreshnessNavigation'] as $needle) {
    $ok('route contains ' . $needle, str_contains($route, $needle));
}

$nav = $read($root . '/app/Support/feo_freshness_navigation.php');
$ok('menu freshness badge is global HTML-safe', str_contains($nav, 'data-feo-freshness-badge') && str_contains($nav, 'application/json'));
$ok('menu freshness badge uses ERP alert counter style', str_contains($nav, "nav-count is-alert"));
$ok('menu freshness covers all FEO roles', str_contains($nav, "['company_owner','senior_logist','logist']"));

$view = $read($root . '/app/View/pages/company_feo_requests.php');
foreach (['ID заявок: через пробел, запятую или ;','Поиск по таблице','Не найдены заявки:','IntersectionObserver','data-feo-sentinel','Вернуть','История загрузок ФЭО','Требуется актуальная загрузка ФЭО'] as $needle) {
    $ok('view contains ' . $needle, str_contains($view, $needle));
}
$ok('browser confirm is forbidden', !str_contains($view, 'confirm('));
$ok('browser alert is forbidden', !str_contains($view, 'alert('));
$ok('pagination UI removed', !str_contains($view, 'Страница ') && !str_contains($view, '>Вперёд<') && !str_contains($view, 'feo-pagination'));
$ok('system ERP confirm modal used', str_contains($view, 'feo-history-confirm-modal') && str_contains($view, 'modal-overlay'));
$ok('history modal body has zero padding', str_contains($view, '.feo-history-modal .modal-body{padding:0!important'));
$ok('FEO workspace uses standard bounded wide-workspace card', !str_contains($view, '.content.content--wide-workspace{padding-left:0!important') && !str_contains($view, 'width:100%!important;max-width:none!important') && str_contains($view, '.feo-table-card{min-width:0}'));
$ok('table horizontal overflow is disabled', str_contains($view, 'overflow-x:hidden!important'));
$ok('dense table uses six intrinsic FEO columns', str_contains($view, 'ID заявки') && str_contains($view, 'Масса, кг') && str_contains($view, 'МНО, Регион') && str_contains($view, 'МНО, МО') && str_contains($view, 'Грузоотправитель') && str_contains($view, 'Адрес погрузки'));
$ok('search calls server endpoint', str_contains($view, "app_url('/company/feo/requests/data')"));
$ok('FEO search uses invoices toolbar pattern', str_contains($view, 'table-toolbar feo-result-toolbar') && str_contains($view, 'toolbar-right feo-search-toolbar') && substr_count($view, 'toolbar-search feo-search-input') >= 2);
$ok('search action buttons removed', !str_contains($view, 'data-feo-id-search') && !str_contains($view, 'data-feo-content-search') && !str_contains($view, 'data-feo-id-clear') && !str_contains($view, 'data-feo-content-clear'));
$ok('filters auto-apply on input with debounce', str_contains($view, "input.addEventListener('input',scheduleFilter)") && str_contains($view, 'setTimeout(function(){resetLoad();},280)'));
$ok('table scroll gets stable viewport height', str_contains($view, 'min-height:360px') && str_contains($view, "scroll.style.height=height+'px'"));
$ok('partial ID results are replaced only after successful response', !str_contains($view, "state.hasMore=true;body.innerHTML=''") && str_contains($view, "if(reset)body.innerHTML='';rows.forEach(addRow);setDiagnostics(data,reset)"));
$ok('multiple missing IDs stay visible as one diagnostic list', str_contains($view, "missing.join(', ')"));
$ok('history modal is thirty percent wider than modal-lg', str_contains($view, '.feo-history-modal{width:1066px;max-width:calc(100vw - 32px)}'));
$ok('history rows and actions do not wrap', str_contains($view, 'white-space:nowrap;overflow-wrap:normal') && str_contains($view, '.feo-history-actions{display:flex;gap:4px;flex-wrap:nowrap'));

printf("FEO_REQUESTS_CONTRACT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
