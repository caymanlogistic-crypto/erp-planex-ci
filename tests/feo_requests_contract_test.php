<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/FeoXlsxParser.php';
require_once __DIR__ . '/../app/Service/FeoRequestInteractionService.php';

use App\Service\FeoRequestInteractionService;
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
    'parseRequestIds' => 'legacy normalized multi-id parser remains available',
    "preg_split('/[,;\\s]+/u'" => 'legacy normalized ID syntax remains stable',
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

$interaction = $read($root . '/app/Service/FeoRequestInteractionService.php');
foreach ([
    'final class FeoRequestInteractionService' => 'interaction service exists',
    "preg_match_all('/(?<!\\d)\\d{6}(?!\\d)/u'" => 'strict six consecutive digit extraction',
    'firstStructuredCell' => 'structured paste uses first-column parsing',
    'MAX_FILTER_IDS = 500' => 'interaction ID safety cap',
    'filteredMassKg' => 'filtered mass summary',
    'SUM(mass_netto)' => 'mass summary uses full filtered dataset',
    'prepareImportRows' => 'safe import preparation',
    'overwriteExisting' => 'explicit overwrite switch',
    'skipped_existing_count' => 'skipped existing diagnostics',
    'updateRequest' => 'manual request editing',
    'current_import_id=NULL' => 'manual edit detaches request from rollback ownership',
] as $needle => $label) {
    $ok($label, str_contains($interaction, $needle));
}
$interactionService = new FeoRequestInteractionService();
$ok('arbitrary text extracts request IDs', $interactionService->extractRequestIds('Текст 308710, заявка № 299726; потом 307409.') === ['308710','299726','307409']);
$ok('markdown table extracts IDs only from first column', $interactionService->extractRequestIds("| ID заявки | Адрес |\n| --- | --- |\n| 308710 | 305000, Курск |\n| 299726 | 123456, Москва |") === ['308710','299726']);
$ok('tabular paste ignores six-digit address values', $interactionService->extractRequestIds("308710\t305000, Курск\n299726\t123456, Москва") === ['308710','299726']);
$ok('seven digit number is not treated as request ID', $interactionService->extractRequestIds('1234567') === []);
$ok('separated digit groups are not glued together', $interactionService->extractRequestIds('123-456') === []);
$ok('letters around six digits do not break extraction', $interactionService->extractRequestIds('abc308710xyz') === ['308710']);
$ok('duplicate request IDs collapse in first-seen order', $interactionService->extractRequestIds('308710 299726 308710') === ['308710','299726']);
$ok('normalized ID filter is space-separated', $interactionService->normalizedIdFilter('308710, text 299726') === '308710 299726');

$controller = $read($root . '/app/Http/Controllers/Company/FeoRequestsController.php');
$ok('JSON data endpoint action exists', str_contains($controller, 'public function data(): void'));
$ok('controller extracts six-digit IDs before legacy batch service', str_contains($controller, 'extractRequestIds($rawIdFilter)'));
$ok('controller returns full filtered mass summary', str_contains($controller, "['total_mass_kg'] = \$this->interaction->filteredMassKg"));
$ok('detail endpoint uses common editable payload', str_contains($controller, 'detailPayload($row)'));
$ok('manual update endpoint exists', str_contains($controller, 'public function update(string $id): void'));
$ok('manual update uses interaction service', str_contains($controller, 'updateRequest($pdo, (int)$id, $_POST,'));
$ok('import analysis reads explicit overwrite checkbox', str_contains($controller, "!empty(\$_POST['overwrite_existing'])"));
$ok('analyze protects existing rows when overwrite is off', str_contains($controller, 'prepareImportRows($pdo, $rows, $overwriteExisting)'));
$ok('commit re-applies protected import semantics from preview', substr_count($controller, 'prepareImportRows($pdo, $rows, $overwriteExisting)') >= 2);
$ok('preview persists overwrite choice', str_contains($controller, "'overwrite_existing'=>\$overwriteExisting"));
$ok('restore controller action exists', str_contains($controller, 'public function restoreImport(): void'));
$ok('restore controller calls service', str_contains($controller, 'restoreRolledBackImport($pdo, $importId, $_SESSION,'));
$ok('all request mutation paths enqueue weight notifications', substr_count($controller, '$notifications->enqueue(') === 5);
$ok('weight notification dispatch runs after committed service calls', substr_count($controller, '$notifications->dispatch($pdo, $deliveryIds)') === 5);
$ok('index exposes freshness', str_contains($controller, 'freshnessState($pdo)'));

$route = $read($root . '/app/Http/Routes/company_feo_requests.php');
foreach (['FeoRequestInteractionService.php','/company/feo/requests/data','/{id}/update','import/rollback','import/restore','import/delete','registerFeoFreshnessNavigation'] as $needle) {
    $ok('route contains ' . $needle, str_contains($route, $needle));
}
$ok('restore route uses configurable FEO request page access', str_contains($route, "[\$feoController, 'restoreImport'], \$feoRequestsPage"));

$nav = $read($root . '/app/Support/feo_freshness_navigation.php');
$ok('menu freshness badge is global HTML-safe', str_contains($nav, 'data-feo-freshness-badge') && str_contains($nav, 'application/json'));
$ok('menu freshness badge uses ERP alert counter style', str_contains($nav, "nav-count is-alert"));
$ok('menu freshness covers all FEO roles', str_contains($nav, "['company_owner','senior_logist','logist']"));

$view = $read($root . '/app/View/pages/company_feo_requests.php');
foreach ([
    'Вставьте текст с 6-значными ID заявок',
    'Поиск по таблице',
    'Не найдены заявки:',
    'IntersectionObserver',
    'data-feo-sentinel',
    'История загрузок ФЭО',
    'Требуется актуальная загрузка ФЭО',
] as $needle) {
    $ok('view contains ' . $needle, str_contains($view, $needle));
}
$ok('browser confirm is forbidden', !str_contains($view, 'confirm('));
$ok('browser alert is forbidden', !str_contains($view, 'alert('));
$ok('pagination UI removed', !str_contains($view, 'Страница ') && !str_contains($view, '>Вперёд<') && !str_contains($view, 'feo-pagination'));
$ok('system ERP confirm modal used', str_contains($view, 'feo-history-confirm-modal') && str_contains($view, 'modal-overlay'));
$ok('history modal body has zero padding', str_contains($view, '.feo-history-modal .modal-body{padding:0!important'));
$ok('FEO workspace uses standard bounded wide-workspace card', !str_contains($view, '.content.content--wide-workspace{padding-left:0!important') && !str_contains($view, 'width:100%!important;max-width:none!important') && str_contains($view, '.feo-table-card{display:block;min-width:0}'));
$ok('FEO optional warning cannot consume the table grid row', str_contains($view, '.feo-table-card{display:block;min-width:0}') && str_contains($view, 'data-feo-search-message') && str_contains($view, 'data-feo-scroll'));
$ok('table horizontal overflow is disabled', str_contains($view, 'overflow-x:hidden!important'));
$ok('dense table uses six intrinsic FEO columns', str_contains($view, 'ID заявки') && str_contains($view, 'Масса, кг') && str_contains($view, 'МНО, Регион') && str_contains($view, 'МНО, МО') && str_contains($view, 'Грузоотправитель') && str_contains($view, 'Адрес погрузки'));
$ok('search calls server endpoint', str_contains($view, "app_url('/company/feo/requests/data')"));
$ok('request ID selector outranks global toolbar-search width and cannot shrink', str_contains($view, '.feo-result-toolbar .toolbar-right .feo-search-input--ids{width:660px;min-width:660px;max-width:660px;flex:0 0 660px}'));
$ok('search toolbar can wrap without clipping wide ID input', str_contains($view, '.feo-result-toolbar{min-height:36px;height:auto;padding:4px 8px;flex-wrap:wrap;gap:6px 12px}') && str_contains($view, '.feo-result-toolbar .toolbar-right{min-width:0;max-width:100%;overflow:visible;flex:0 0 auto}'));
$ok('filters auto-apply live with debounce', str_contains($view, "input.addEventListener('input',scheduleFilter)") && str_contains($view, 'setTimeout(function(){resetLoad();},280)'));
$ok('full table paste is compacted client-side before GET', str_contains($view, "idInput.addEventListener('paste'") && str_contains($view, 'normalizedClientIdFilter') && str_contains($view, 'safeIdFilter'));
$ok('client structured paste reads first column only', str_contains($view, "split('\\t',1)") && str_contains($view, "split('|',1)"));
$ok('table scroll gets stable viewport height', str_contains($view, 'min-height:360px') && str_contains($view, "scroll.style.height=height+'px'"));
$ok('partial ID results are replaced only after successful response', !str_contains($view, "state.hasMore=true;body.innerHTML=''") && str_contains($view, "if(reset)body.innerHTML='';rows.forEach(addRow);setDiagnostics(data,reset)"));
$ok('multiple missing IDs stay visible as one diagnostic list', str_contains($view, "missing.join(', ')"));
$ok('summary is exactly count plus filtered total weight without legacy duplicate text', str_contains($view, 'заявок общим весом <b data-feo-total-mass>0</b> кг.') && !str_contains($view, 'Всего по текущим условиям:') && !str_contains($view, 'data-feo-filter-info'));
$ok('summary shows filtered count and total weight', str_contains($view, 'data-feo-total') && str_contains($view, 'data-feo-total-mass') && str_contains($view, 'total_mass_kg'));
$ok('detail modal removes white body padding', str_contains($view, '.feo-detail-modal .modal-body{padding:0!important'));
$ok('detail view supports edit save and cancel', str_contains($view, 'data-feo-detail-edit') && str_contains($view, 'data-feo-detail-save') && str_contains($view, 'data-feo-detail-cancel'));
$ok('detail footer uses standard ERP modal actions without individual delete', str_contains($view, 'modal-foot-actions') && str_contains($view, 'data-feo-detail-close') && !str_contains($view, 'data-feo-detail-delete'));
$ok('detail save posts to update endpoint', str_contains($view, "/update',{method:'POST'"));
$ok('overwrite checkbox is opt-in and unchecked by default', str_contains($view, 'name="overwrite_existing" value="1"') && !str_contains($view, 'name="overwrite_existing" value="1" checked'));
$ok('import analysis shows protected existing rows', str_contains($view, 'data-stat="skipped_existing_count"') && str_contains($view, 'не перезапишется'));
$ok('import popup auto-analyzes selected file and has no extra analyze button', str_contains($view, "fileInput.addEventListener('change',analyzeSelectedFile)") && !str_contains($view, 'feo-analyze-btn'));
$ok('history modal is thirty percent wider than modal-lg', str_contains($view, '.feo-history-modal{width:1066px;max-width:calc(100vw - 32px)}'));
$ok('history rows and actions do not wrap', str_contains($view, 'white-space:nowrap;overflow-wrap:normal') && str_contains($view, '.feo-history-actions{display:flex;gap:4px;flex-wrap:nowrap'));

printf("FEO_REQUESTS_CONTRACT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
