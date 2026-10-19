<?php

declare(strict_types=1);

$baseline = __DIR__ . '/feo_placement_contract_baseline.php';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($baseline);
passthru($cmd, $baselineCode);
if ($baselineCode !== 0) {
    exit($baselineCode);
}

require_once __DIR__ . '/../app/Service/FeoRequestInteractionService.php';

use App\Service\FeoRequestInteractionService;

$pass = 0;
$fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) {
        $pass++;
        return;
    }
    $fail++;
    fwrite(STDERR, "FAIL: {$name}\n");
};
$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($path) ?: '';

$view = $read($root . '/app/View/pages/company_feo_map_placement.php');
$layout = $read($root . '/app/View/layouts/main.php');
$configPos = strpos($view, 'window.FEO_PLACEMENT_CONFIG');
$scriptPos = strpos($view, "app_url('/assets/js/feo-map-placement.js')");
$ok(
    'map-placement page preserves multiline search input even if paste interception is bypassed',
    preg_match('/<textarea\b[^>]*\brows="1"[^>]*\bdata-placement-block-search\b[^>]*><\/textarea>/i', $view) === 1
    && preg_match('/<input\b[^>]*\bdata-placement-block-search\b[^>]*>/i', $view) === 0
);
$ok('map-placement search uses the approved search role while retaining multiline input', str_contains($view, 'class="toolbar-search registry-ui-search"') && str_contains($view, 'data-placement-block-search'));
$ok('map-placement search remains responsive with shared toolbar rules', str_contains($view, 'registry-ui-toolbar') && str_contains($view, 'registry-ui-search-group'));
$ok(
    'map-placement page owns the placement script instead of loading it globally',
    $scriptPos !== false
    && !str_contains($layout, 'feo-map-placement.js')
);
$ok(
    'placement config is declared before the page-specific script',
    $configPos !== false
    && $scriptPos !== false
    && $configPos < $scriptPos
);
$ok(
    'placement config exposes canonical base and csrf before JS boot',
    str_contains($view, "base: <?= json_encode(app_url('/company/feo/requests/map-placement')")
    && str_contains($view, 'csrf: <?= json_encode(csrfToken()')
);
$ok(
    'placement script include is cache-busted with filemtime',
    str_contains($view, "filemtime(base_path('public/assets/js/feo-map-placement.js'))")
);

$parser = new FeoRequestInteractionService();
$ok('search accepts one request id', $parser->extractRequestIds('302545') === ['302545']);
$ok('search accepts space-separated ids', $parser->extractRequestIds('302545 285664 281861') === ['302545','285664','281861']);
$ok('search accepts comma-separated ids', $parser->extractRequestIds('302545, 285664, 281861') === ['302545','285664','281861']);
$ok('search accepts multiline ids', $parser->extractRequestIds("302545\n285664\n281861") === ['302545','285664','281861']);
$ok(
    'production regression multiline ids stay distinct',
    $parser->extractRequestIds("257419\n\n281469\n257551") === ['257419','281469','257551']
);
$ok(
    'canonical visible value is comma-space separated',
    implode(', ', $parser->extractRequestIds("257419\n\n281469\n257551")) === '257419, 281469, 257551'
);
$ok(
    'search TSV uses first column only',
    $parser->extractRequestIds("302545\t123456\t777777\n285664\t654321\t888888") === ['302545','285664']
);
$ok(
    'search markdown table uses first column only',
    $parser->extractRequestIds("| request | other |\n| --- | --- |\n| 302545 | 123456 |\n| 285664 | 654321 |") === ['302545','285664']
);
$ok(
    'search extracts standalone ids from arbitrary text',
    $parser->extractRequestIds('Найти 302545, затем 285664 и 281861; число 1234567 не заявка.') === ['302545','285664','281861']
);
$ok(
    'search deduplicates repeated ids',
    $parser->extractRequestIds('302545 285664 302545 281861 285664') === ['302545','285664','281861']
);
$ok('invalid input produces no ids', $parser->extractRequestIds('нет заявок 12345 1234567') === []);

$route = $read($root . '/app/Http/Routes/company_feo_requests.php');
$authorRequire = "require_once base_path('app/Service/FeoPlacementAuthorService.php');";
$placementControllerRequire = "require_once base_path('app/Http/Controllers/Company/FeoPlacementController.php');";
$ok(
    'placement route loads author service before placement controller',
    str_contains($route, $authorRequire)
    && str_contains($route, $placementControllerRequire)
    && strpos($route, $authorRequire) < strpos($route, $placementControllerRequire)
);
$ok(
    'search backend reuses canonical FEO request parser',
    str_contains($route, 'new \\App\\Service\\FeoRequestInteractionService()')
    && str_contains($route, '$ids = $parser->extractRequestIds')
);
$ok(
    'search history is newest first without changing default membership order',
    str_contains($route, 'bool $newestFirst = false')
    && str_contains($route, "\$sql .= \$newestFirst ? ' ORDER BY b.id DESC' : ' ORDER BY b.id ASC';")
    && str_contains($route, '$memberships = $feoPlacementMemberships($pdo, $ids, false, true);')
);
$ok(
    'search history exposes block creation timestamp as operation date',
    str_contains($route, 'b.status,b.created_at')
    && str_contains($route, "'created_at'=>(string)(\$row['created_at'] ?? '')")
);
$ok(
    'search result preserves each normalized requested id',
    str_contains($route, 'foreach ($ids as $id)')
    && str_contains($route, "'zayavka_id'=>\$id")
    && str_contains($route, "'blocks'=>\$memberships[\$id] ?? []")
);
$ok(
    'search result exposes registry and current-state flags',
    str_contains($route, "'exists_in_feo'=>isset(\$state['exists'][\$id])")
    && str_contains($route, "'is_current'=>isset(\$state['current'][\$id])")
    && str_contains($route, "'current_source_block_id'=>\$state['current'][\$id] ?? null")
);

$js = $read($root . '/public/assets/js/feo-map-placement.js');
$ok(
    'multiline paste handler canonicalizes the visible search value',
    str_contains($js, "blockSearchInput?.addEventListener('paste'")
    && str_contains($js, "blockSearchInput.value=ids.join(', ');")
);
$ok(
    'search canonicalizes visible value and request before GET',
    str_contains($js, 'const ids=extractClientRequestIds(raw);')
    && str_contains($js, "const normalized=ids.join(', ');")
    && str_contains($js, 'blockSearchInput.value=normalized;')
    && str_contains($js, 'new URLSearchParams({request_ids:normalized})')
);
$ok(
    'request id is rendered in every history row',
    substr_count($js, '<td class="registry-ui-id">${esc(item.zayavka_id)}</td>') === 2
    && !str_contains($js, 'index===0?')
);
$ok(
    'search results use four canonical columns with block taking remaining width',
    str_contains($js, '<table class="table registry-ui-table registry-ui-data feo-placement-search-table">')
    && str_contains($js, '<colgroup><col class="registry-ui-id"><col class="feo-placement-search-col--date"><col><col class="feo-placement-search-col--type"></colgroup>')
    && str_contains($js, 'aria-label="Заявка">ID</th><th>ДАТА ОПЕРАЦИИ</th><th>БЛОК</th><th>ТИП</th>')
    && !str_contains($js, '>Статус блока</th>')
    && !str_contains($js, '>Актуальный состав</th>')
    && str_contains($view, '.feo-placement-search-table col.feo-placement-search-col--date{width:160px}')
    && str_contains($view, '.feo-placement-search-table col.feo-placement-search-col--type{width:112px}')
);
$ok('search result table has no side or bottom padding', str_contains($view, '.feo-placement-block-search-results{padding:8px 0 0;'));
$registryCss = $read($root . '/public/assets/css/registry-components.css');
$ok('search textarea content is vertically centered by shared source', str_contains($registryCss, 'textarea.registry-ui-search {resize:none;overflow-x:auto;overflow-y:hidden;white-space:nowrap;padding-top:7px;padding-bottom:7px}'));
$ok(
    'search explicitly shows missing registry and no-block states',
    str_contains($js, 'Нет в реестре ФЭО')
    && str_contains($js, 'Не входит ни в один блок')
);
$ok(
    'block detail popup keeps upper summary and omits request listing table',
    str_contains($js, '<div class="feo-placement-detail-meta">')
    && !str_contains($js, '<div class="feo-placement-list"><table class="table">')
);
$ok(
    'existing block double-click behavior remains',
    str_contains($js, "addEventListener('dblclick'")
    && str_contains($js, 'data-placement-search-block-id')
);

printf("FEO_PLACEMENT_SEARCH_REGRESSION: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
