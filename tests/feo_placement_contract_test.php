<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/FeoPlacementInputParser.php';

use App\Service\FeoPlacementInputParser;

$pass = 0; $fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) { $pass++; return; }
    $fail++; fwrite(STDERR, "FAIL: {$name}\n");
};
$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($path) ?: '';

$parser = new FeoPlacementInputParser();
$manual = $parser->parseManual("Текст 308710 и 299726; повтор 308710");
$ok('manual arbitrary text extracts exact six-digit ids', $manual['ids'] === ['308710','299726']);
$ok('manual duplicate diagnostics are preserved', $manual['duplicates'] === ['308710']);
$table = $parser->parseManual("308710\t305000, Курск\n299726\t123456, Москва");
$ok('tabular manual input uses first column only', $table['ids'] === ['308710','299726']);
$markdown = $parser->parseManual("| 308710 | 123456 |\n| 299726 | 654321 |");
$ok('markdown-style manual input uses first column only', $markdown['ids'] === ['308710','299726']);
try { $parser->parseManual('1234567'); $strict = false; } catch (RuntimeException) { $strict = true; }
$ok('seven-digit values are rejected', $strict);
$fromCells = $parser->extractFromCells(['308710','abc 299726 xyz','1234567','308710']);
$ok('file cell parser keeps standalone six digits only', $fromCells['ids'] === ['308710','299726']);
$ok('file cell parser reports duplicate ids', $fromCells['duplicates'] === ['308710']);

$service = $read($root . '/app/Service/FeoPlacementService.php');
foreach ([
    'final class FeoPlacementService' => 'placement service exists',
    "SUM(COALESCE(r.mass_netto,0))*1000" => 'mass is authoritative from feo_requests.mass_netto',
    "SELECT GET_LOCK(?,10)" => 'mutations use tenant DB lock',
    'beginTransaction()' => 'mutations are transactional',
    'state_hash' => 'preview carries state hash',
    'hash_equals($expectedStateHash, $this->stateHash($pdo))' => 'commit rejects stale confirmation',
    'private function replay' => 'current set is replayed from blocks',
    "ORDER BY id ASC" => 'blocks replay in creation order',
    "DELETE FROM feo_placement_current" => 'materialized current set is rebuilt atomically',
    "['addition','exclusion']" => 'only addition/exclusion block types accepted',
    "['applied','disabled']" => 'only applied/disabled statuses accepted',
    'repeat_ids' => 'repeated operation diagnostics are returned',
    'missing_request_count' => 'orphan diagnostics are exposed',
] as $needle => $label) $ok($label, str_contains($service, $needle));

$controller = $read($root . '/app/Http/Controllers/Company/FeoPlacementController.php');
foreach ([
    "requireRole(['company_owner','senior_logist','logist'])" => 'all operational FEO roles are allowed',
    "parseManual" => 'manual parser wired',
    "parseFile" => 'file parser wired',
    "in_array(\$ext, ['xls','xlsx'], true)" => 'xls and xlsx upload extensions accepted',
    'previewCreate' => 'creation is previewed before commit',
    'createBlock' => 'block commit exists',
    'previewStatusChange' => 'enable/disable is previewed',
    'changeStatus' => 'enable/disable commit exists',
    'previewReconcile' => 'reconcile is previewed',
    'reconcile' => 'reconcile commit exists',
    'company_storage_path' => 'source files use company storage',
    'source_file_key' => 'saved source file key is persisted',
] as $needle => $label) $ok($label, str_contains($controller, $needle));

$fileParser = $read($root . '/app/Service/FeoPlacementInputParser.php');
foreach ([
    "'xlsx' => \$this->readXlsxFirstColumn" => 'xlsx first-column parser exists',
    "'xls' => \$this->readXlsFirstColumn" => 'xls first-column parser exists',
    "hex2bin('D0CF11E0A1B11AE1')" => 'binary OLE XLS signature is validated',
    'oleWorkbookStream' => 'XLS Compound File stream parser exists',
    'biffFirstColumn' => 'XLS BIFF first-column parser exists',
    "preg_match_all('/(?<!\\d)\\d{6}(?!\\d)/u'" => 'file parser uses exact six-digit semantics',
] as $needle => $label) $ok($label, str_contains($fileParser, $needle));

$migration = $read($root . '/database/migrations-local/123_create_feo_map_placement.sql');
foreach (['feo_placement_blocks','feo_placement_block_requests','feo_placement_current',"ENUM('addition','exclusion')","ENUM('applied','disabled')",'source_filename','source_file_key'] as $needle) {
    $ok('migration contains ' . $needle, str_contains($migration, $needle));
}
$ok('placement tables do not physically FK to mutable feo_requests rows', !str_contains($migration, 'REFERENCES `feo_requests`'));

$route = $read($root . '/app/Http/Routes/company_feo_requests.php');
foreach ([
    'FeoPlacementInputParser.php',
    'FeoPlacementService.php',
    'FeoPlacementController.php',
    '/company/feo/requests/map-placement',
    '/company/feo/requests/map-placement/validate-manual',
    '/company/feo/requests/map-placement/search-blocks',
    'preview-status',
    'reconcile/preview',
] as $needle) {
    $ok('route contains ' . $needle, str_contains($route, $needle));
}
$ok('analyze rejects duplicate ids inside one placement block', str_contains($route, 'Заявки повторяются внутри блока:') && str_contains($route, "!empty(\$parsed['duplicates'])"));
$ok('analyze rejects repeated add or exclusion against current placement state', str_contains($route, 'Повторная операция запрещена:') && str_contains($route, "!empty(\$preview['repeat_ids'])"));
$ok('repeat diagnostics include active block ids and names', str_contains($route, "'name'=>(string)(\$row['name'] ?? '')") && str_contains($route, 'активные блоки:'));
$ok('manual live validation checks FEO registry and materialized current set', str_contains($route, 'SELECT zayavka_id FROM feo_requests') && str_contains($route, 'SELECT zayavka_id,source_block_id FROM feo_placement_current'));
$ok('block membership search returns all placement blocks', str_contains($route, 'JOIN feo_placement_blocks b ON b.id=br.block_id') && str_contains($route, "'blocks'=>\$memberships[\$id] ?? []"));

$nav = $read($root . '/app/Support/feo_freshness_navigation.php');
$ok('FEO submenu contains map placement navigation', str_contains($nav, 'Размещение на карте') && str_contains($nav, 'data-feo-placement-nav'));
$ok('placement route becomes active in injected submenu', str_contains($nav, '/company/feo/requests/map-placement'));

$view = $read($root . '/app/View/pages/company_feo_map_placement.php');
foreach ([
    'Актуальный состав:', 'Создать блок', 'Добавление', 'Исключение', 'Применяется', 'Отключён',
    'Ручной ввод', 'XLS / XLSX', 'только первый столбец', 'feo-placement-confirm-modal',
    'Пересчитать состав', 'Актуальные заявки', 'Найти заявку в блоках',
    'data-placement-block-search', 'data-placement-live-validation',
] as $needle) $ok('view contains ' . $needle, str_contains($view, $needle));
$ok('placement table card outer border is removed', str_contains($view, '.feo-placement-table-card{display:block;min-width:0;border:0!important;box-shadow:none!important;background:transparent!important}'));
$ok('applied and disabled statuses use system green/red variables', str_contains($view, 'feo-placement-status--applied{color:var(--success') && str_contains($view, 'feo-placement-status--disabled{color:var(--danger'));

$js = $read($root . '/public/assets/js/feo-map-placement.js');
foreach ([
    "confirmOperation('create'" => 'create uses ERP confirmation flow',
    "confirmOperation('status'" => 'status mutation uses ERP confirmation flow',
    "confirmOperation('reconcile'" => 'reconcile uses ERP confirmation flow',
    "addEventListener('dblclick'" => 'block detail opens on double click',
    "base+'/validate-manual'" => 'manual validation calls read-only validation endpoint',
    "base+'/search-blocks?'" => 'block search calls search endpoint',
    'extractClientRequestIds' => 'client reuses six-digit mapping semantics',
    "raw.indexOf('\\t')" => 'client recognizes tabular paste',
    "line.indexOf('|')" => 'client recognizes markdown table paste',
    "/(?<!\\d)\\d{6}(?!\\d)/g" => 'client mapping keeps standalone six-digit ids',
] as $needle => $label) $ok($label, str_contains($js, $needle));

$ok('browser confirm is forbidden', !str_contains($view, 'confirm(') && !str_contains($js, 'confirm('));
$ok('browser alert is forbidden', !str_contains($view, 'alert(') && !str_contains($js, 'alert('));
$ok(
    'create form submit is intercepted before browser navigation',
    str_contains($js, "createForm?.addEventListener('submit',analyzeCreate)") &&
    str_contains($js, 'async function analyzeCreate(e)') &&
    str_contains($js, 'e?.preventDefault()')
);
$ok(
    'analyze button routes through the protected submit handler',
    str_contains($js, "q('[data-placement-analyze]')?.addEventListener('click'") &&
    str_contains($js, 'createForm.requestSubmit()')
);
$ok('create confirmation does not duplicate effect in gray box', str_contains($js, "if(kind==='create'){") && str_contains($js, "}else{\n      if(kind==='status')") && !str_contains($js, "if(kind==='create')html+=`<div class=\"feo-placement-confirm-effect\""));
$ok('repeat operation prefix is rendered bold', str_contains($js, "const prefix='Повторная операция запрещена:'") && str_contains($js, 'strong.textContent=prefix'));
$ok('manual field validates on every edit with debounce', str_contains($js, "manualInput?.addEventListener('input',()=>scheduleManualValidation())") && str_contains($js, 'window.setTimeout(validateManual,immediate?0:280)'));
$ok('block type change reruns manual validation', str_contains($js, "typeRadios.forEach(r=>r.addEventListener('change',()=>scheduleManualValidation(true)))"));
$ok('search paste normalizes using the same FEO mapping', str_contains($js, "blockSearchInput?.addEventListener('paste'") && str_contains($js, "blockSearchInput.value=ids.join(' ')"));
$ok('block search result exposes block status and opens block detail', str_contains($js, 'statusHtml(block.status)') && str_contains($js, 'data-placement-search-block-id'));
$ok('block table has no actions column', !str_contains($view, '<th>Действия</th>'));

$manifest = json_decode($read($root . '/.github/agent_db_migration_request.json'), true);
$workflow = $read($root . '/.github/workflows/feo_123_production_migration.yml');
$apply123 = $read($root . '/scripts/feo_apply_123.php');
$verify123 = $read($root . '/scripts/feo_verify_123.php');
$ok('migration request points to 123', is_array($manifest) && ($manifest['migration_id'] ?? '') === '123' && ($manifest['workflow'] ?? '') === 'feo_123_production_migration.yml' && ($manifest['confirmation_value'] ?? '') === 'APPLY_FEO_123');
$ok('migration 123 workflow is manual-only', str_contains($workflow, 'workflow_dispatch:') && str_contains($workflow, 'APPLY_FEO_123') && !str_contains($workflow, "\n  push:") && !str_contains($workflow, "\n  pull_request:") && !str_contains($workflow, "\n  schedule:") && !str_contains($workflow, "\n  workflow_run:"));
$ok('migration 123 apply script is tenant guarded', str_contains($apply123, "name = '123_create_feo_map_placement.sql'") && str_contains($apply123, 'GET_LOCK') && str_contains($apply123, 'schema_migrations'));
$ok('migration 123 verify script is read-only snapshot', str_contains($verify123, 'FEO_123_SNAPSHOT=') && str_contains($verify123, 'FEO_123_READ_ONLY_OK'));

printf("FEO_PLACEMENT_CONTRACT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
