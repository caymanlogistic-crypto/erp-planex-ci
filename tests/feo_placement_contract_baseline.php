<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/FeoPlacementInputParser.php';
require_once __DIR__ . '/../app/Service/FeoPlacementAuthorService.php';

use App\Service\FeoPlacementAuthorService;
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
$ok('responsible short name uses surname-first full name', FeoPlacementAuthorService::shortName('Спугов Владимир', 'Spugov') === 'Владимир С.');
$ok('responsible short name ignores optional patronymic', FeoPlacementAuthorService::shortName('Спугов Владимир Владимирович', 'Spugov') === 'Владимир С.');
foreach ([
    'Гладких Карина Артуровна' => 'Карина Г.',
    'Глухова Светлана' => 'Светлана Г.',
    'Сидоров Михаил' => 'Михаил С.',
    'Шибанов Антон' => 'Антон Ш.',
    'Дюма Александр' => 'Александр Д.',
    'Ильич Антон' => 'Антон И.',
    'Петров-Сидоров Антон-Михаил' => 'Антон-Михаил П.',
    'Ли Ян необычное отчество' => 'Ян Л.',
    "\u{00A0}Спугов\t Владимир\nВладимирович\u{00A0}" => 'Владимир С.',
    'Спугов' => 'Спугов',
] as $fullName => $expectedName) {
    $ok('canonical responsible name: ' . $fullName, FeoPlacementAuthorService::shortName($fullName) === $expectedName);
}
$ok('missing responsible name and login use dash', FeoPlacementAuthorService::shortName(" \t\u{00A0}", ' ') === '—');
$ok('responsible short name falls back to login', FeoPlacementAuthorService::shortName('', 'Spugov') === 'Spugov');

$makeXlsx = static function (array $sheets): string {
    $path = tempnam(sys_get_temp_dir(), 'feo-placement-xlsx-');
    if ($path === false) throw new RuntimeException('cannot allocate temporary XLSX fixture');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('cannot create XLSX fixture');
    $sheetTags = [];
    $relTags = [];
    foreach (array_values($sheets) as $index => $sheet) {
        $number = $index + 1;
        $state = !empty($sheet['hidden']) ? ' state="hidden"' : '';
        $sheetTags[] = '<sheet name="Sheet' . $number . '" sheetId="' . $number . '"' . $state . ' r:id="rId' . $number . '"/>';
        $relTags[] = '<Relationship Id="rId' . $number . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $number . '.xml"/>';
        $rowTags = [];
        foreach (array_values((array)($sheet['rows'] ?? [])) as $rowIndex => $row) {
            $cells = [];
            foreach ((array)$row as $column => $value) {
                $column = strtoupper((string)$column);
                if (!preg_match('/^[A-Z]+$/D', $column)) continue;
                $ref = $column . ($rowIndex + 1);
                $text = htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $cells[] = '<c r="' . $ref . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
            }
            $rowTags[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet' . $number . '.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $rowTags) . '</sheetData></worksheet>');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . implode('', $sheetTags) . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $relTags) . '</Relationships>');
    $zip->close();
    return $path;
};

$xlsx = $makeXlsx([['rows'=>[['A'=>'Номер заявки','B'=>'Неважный заголовок'],['A'=>'308710','B'=>'299726']]]]);
try { $xlsxParsed = $parser->parseFile($xlsx, 'single.xlsx'); } finally { @unlink($xlsx); }
$ok('single-sheet XLSX accepts a header and reads only first column', $xlsxParsed['ids'] === ['308710']);

$xlsx = $makeXlsx([['rows'=>[['A'=>'308710']]],['rows'=>[['A'=>'299726']]]]);
try { $parser->parseFile($xlsx, 'two-sheets.xlsx'); $multiSheetRejected = false; } catch (RuntimeException $e) { $multiSheetRejected = $e->getMessage() === 'В файле должен быть ровно один лист.'; } finally { @unlink($xlsx); }
$ok('XLSX with more than one worksheet is rejected before import', $multiSheetRejected);

$xlsx = $makeXlsx([['rows'=>[['A'=>'308710']]],['hidden'=>true,'rows'=>[['A'=>'299726']]]]);
try { $parser->parseFile($xlsx, 'hidden-second-sheet.xlsx'); $hiddenSheetRejected = false; } catch (RuntimeException $e) { $hiddenSheetRejected = $e->getMessage() === 'В файле должен быть ровно один лист.'; } finally { @unlink($xlsx); }
$ok('hidden second XLSX worksheet still counts as a second tab', $hiddenSheetRejected);

$xlsx = $makeXlsx([['rows'=>[['A'=>'Номер заявки','B'=>'308710'],['A'=>'нет номера','B'=>'299726']]]]);
try { $parser->parseFile($xlsx, 'no-first-column-id.xlsx'); $firstColumnRejected = false; } catch (RuntimeException $e) { $firstColumnRejected = $e->getMessage() === 'В первом столбце не найден ни один номер заявки.'; } finally { @unlink($xlsx); }
$ok('XLSX requires at least one request number specifically in first column', $firstColumnRejected);

$biffMethod = (new ReflectionClass($parser))->getMethod('biffFirstColumn');
$twoBoundSheets = pack('vv', 0x0085, 0) . pack('vv', 0x0085, 0);
try { $biffMethod->invoke($parser, $twoBoundSheets); $xlsMultiSheetRejected = false; } catch (ReflectionException) { $xlsMultiSheetRejected = false; } catch (RuntimeException $e) { $xlsMultiSheetRejected = $e->getMessage() === 'В файле должен быть ровно один лист.'; }
$ok('binary XLS with more than one workbook tab is rejected', $xlsMultiSheetRejected);

$service = $read($root . '/app/Service/FeoPlacementService.php');
foreach ([
    'final class FeoPlacementService' => 'placement service exists',
    "SUM(COALESCE(r.mass_netto,0))*1000" => 'mass is authoritative from feo_requests.mass_netto',
    "SELECT GET_LOCK(?,10)" => 'mutations use tenant DB lock',
    'FeoImportTransaction::begin($pdo)' => 'mutations use the atomic tenant/legacy coordinator',
    'state_hash' => 'preview carries state hash',
    'hash_equals($expectedStateHash, $this->stateHash($pdo))' => 'commit rejects stale confirmation',
    'private function replay' => 'current set is replayed from blocks',
    "ORDER BY id ASC" => 'blocks replay in creation order',
    "DELETE FROM feo_placement_current" => 'materialized current set is rebuilt atomically',
    "['addition','exclusion']" => 'only addition/exclusion block types accepted',
    "['applied','disabled']" => 'only applied/disabled statuses accepted',
    'repeat_ids' => 'repeated operation diagnostics are returned',
    'missing_request_count' => 'orphan diagnostics are exposed',
    'request_ids_text' => 'block list exposes manual request ids for source column',
    'public function previewDelete' => 'block deletion is previewed',
    'public function deleteBlock' => 'block deletion commit exists',
    "\$this->replay(\$pdo, [\$blockId => 'disabled'])" => 'delete preview and commit simulate the block being absent from replay',
    "DELETE FROM feo_placement_blocks WHERE id=?" => 'delete mutation removes the selected block regardless of current status',
    'После удаления актуальный состав не прошёл контроль целостности.' => 'delete commit verifies replay/current integrity before commit',
] as $needle => $label) $ok($label, str_contains($service, $needle));

$legacyMirror = $read($root . '/app/Service/FeoLegacyPlacementSync.php');
$ok('every placement mutation invokes the legacy mirror', substr_count($service, '$this->mirrorLegacy(') === 4);
foreach ([
    "status_type='dostupno'" => 'legacy mirror changes only available blocks',
    'LEGACY_AVAILABLE_BLOCK_MIN_ID = 109' => 'legacy mirror preserves historical blocks',
    'FOR UPDATE' => 'legacy mirror locks rows used to derive its result',
    'DELETE FROM ' => 'legacy mirror removes empty available blocks',
    'INSERT INTO ' => 'legacy mirror creates an available block for restored requests',
    "['SELECT','INSERT','UPDATE','DELETE','TRIGGER']" => 'legacy mirror fails closed without complete legacy grants',
    '$this->assertMembership($added, 1)' => 'legacy mirror verifies additions exactly once',
    '$this->assertMembership($removed, 0)' => 'legacy mirror verifies removals are absent',
] as $needle => $label) $ok($label, str_contains($legacyMirror, $needle));

$controller = $read($root . '/app/Http/Controllers/Company/FeoPlacementController.php');
$authorService = $read($root . '/app/Service/FeoPlacementAuthorService.php');
$route = $read($root . '/app/Http/Routes/company_feo_requests.php');
$ok(
    'FEO placement access is registry-page based without a legacy role allowlist',
    !str_contains($controller, 'requireRole(')
    && str_contains($route, "\$feoPlacementPage = 'company.feo.map_placement';")
    && str_contains($route, "[\$feoPlacementController, 'index'], \$feoPlacementPage")
    && str_contains($route, "[\$feoPlacementController, 'commitCreate'], \$feoPlacementPage")
);
foreach ([
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
$ok('block creator is taken only from authenticated session at commit time', str_contains($controller, 'FeoPlacementAuthorService::creatorFromSession($_SESSION)') && str_contains($controller, '] + $creator,') && !str_contains($controller, "\$_POST['created_by_user_id']"));
$ok('block creation fails closed when authenticated creator cannot be resolved', str_contains($authorService, "if (\$id <= 0) throw new \\RuntimeException('Не удалось определить пользователя, создающего блок."));
$ok('block list is decorated with immutable creator identity for display', str_contains($controller, 'new FeoPlacementAuthorService') && str_contains($controller, "->decorate(\$pdo, (int)\$company['id'], \$state['blocks'])"));
$ok('author directory distinguishes owner, tenant and global-account identities', str_contains($authorService, "\$role === 'company_owner'") && str_contains($authorService, "elseif (\$role === '')") && str_contains($authorService, 'SELECT id,full_name,login FROM users') && str_contains($authorService, 'SELECT id,full_name,login FROM company_users') && str_contains($authorService, 'SELECT id,full_name,login FROM user_accounts'));
$ok('status changes do not overwrite immutable block creator', !preg_match('/UPDATE feo_placement_blocks SET[^\n]*created_by_/i', $service));

$fileParser = $read($root . '/app/Service/FeoPlacementInputParser.php');
foreach ([
    "'xlsx' => \$this->readXlsxFirstColumn" => 'xlsx first-column parser exists',
    "'xls' => \$this->readXlsFirstColumn" => 'xls first-column parser exists',
    "hex2bin('D0CF11E0A1B11AE1')" => 'binary OLE XLS signature is validated',
    'oleWorkbookStream' => 'XLS Compound File stream parser exists',
    'biffFirstColumn' => 'XLS BIFF first-column parser exists',
    "count(\$sheets) !== 1" => 'XLSX requires exactly one workbook sheet including hidden sheets',
    "\$recordId === 0x0085" => 'binary XLS counts BoundSheet tabs before reading first column',
    "if (\$sheetCount !== 1)" => 'binary XLS requires exactly one workbook tab',
    'В первом столбце не найден ни один номер заявки.' => 'file import fails when first column has no request number',
    "preg_match_all('/(?<!\\d)\\d{6}(?!\\d)/u'" => 'file parser uses exact six-digit semantics',
] as $needle => $label) $ok($label, str_contains($fileParser, $needle));

$migration = $read($root . '/database/migrations-local/123_create_feo_map_placement.sql');
foreach (['feo_placement_blocks','feo_placement_block_requests','feo_placement_current',"ENUM('addition','exclusion')","ENUM('applied','disabled')",'source_filename','source_file_key'] as $needle) {
    $ok('migration contains ' . $needle, str_contains($migration, $needle));
}
$ok('placement tables do not physically FK to mutable feo_requests rows', !str_contains($migration, 'REFERENCES `feo_requests`'));

foreach ([
    'FeoPlacementInputParser.php',
    'FeoPlacementService.php',
    'FeoPlacementController.php',
    '/company/feo/requests/map-placement',
    '/company/feo/requests/map-placement/validate-manual',
    '/company/feo/requests/map-placement/search-blocks',
    'preview-status',
    'preview-delete',
    'delete/commit',
    'reconcile/preview',
] as $needle) {
    $ok('route contains ' . $needle, str_contains($route, $needle));
}
$ok('analyze rejects duplicate ids inside one placement block', str_contains($route, 'Заявки повторяются внутри блока:') && str_contains($route, "!empty(\$parsed['duplicates'])"));
$ok('analyze rejects repeated add or exclusion with structured payload', str_contains($route, "'code'=>'repeat_operation'") && str_contains($route, "'message'=>'Повторная операция запрещена.'") && str_contains($route, "'repeat_blocks'=>\$memberships"));
$ok('repeat diagnostics include active block ids and names', str_contains($route, "'name'=>(string)(\$row['name'] ?? '')") && str_contains($route, "'id'=>(int)\$row['block_id']"));
$ok('manual live validation checks FEO registry and materialized current set', str_contains($route, 'SELECT zayavka_id FROM feo_requests') && str_contains($route, 'SELECT zayavka_id,source_block_id FROM feo_placement_current'));
$ok('block membership search returns all placement blocks', str_contains($route, 'JOIN feo_placement_blocks b ON b.id=br.block_id') && str_contains($route, "'blocks'=>\$memberships[\$id] ?? []"));
$ok('delete preview and commit use ERP preview token state', str_contains($route, "'kind'=>'delete'") && str_contains($route, "\$feoPlacementPreviewRecord(\$token, (int)\$company['id'], 'delete')"));
$ok('delete password is hardcoded and checked only on the server', str_contains($route, "hash_equals('7550055', \$deletePassword)") && str_contains($route, "'Неверный пароль удаления.'"));
$ok('delete commit removes saved source file from company storage', str_contains($route, "company_storage_path(\$company['storage_path'] ?? null") && str_contains($route, "!@unlink(\$path)"));

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
$ok('placement table uses the approved bordered registry card', str_contains($view, 'registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll') && !str_contains($view, 'border:0!important'));
$ok('applied and disabled statuses use approved semantic roles', str_contains($view, "'registry-ui-success' : 'registry-ui-error'"));
$ok('KPI boxes are removed and summary remains in toolbar', !str_contains($view, 'feo-placement-kpi') && !str_contains($view, 'feo-placement-summary') && str_contains($view, 'registry-ui-toolbar') && str_contains($view, 'Масса: <b>'));
$ok('source column shows filename or manual request list', str_contains($view, "\$block['request_ids_text']") && str_contains($view, "\$block['source_filename']") && str_contains($view, 'title="<?= e($sourceText) ?>"'));
$ok('block detail modal has no outer body padding or horizontal overflow', str_contains($view, '.feo-placement-detail-modal-body{padding:0!important;overflow:hidden!important}') && str_contains($view, 'overflow-y:auto;overflow-x:hidden'));
$ok('page placement table scrolls internally on narrow screens', str_contains($view, 'registry-ui-sticky-table--wide-scroll') && !str_contains($view, '.feo-placement-table-card .table-scroll{overflow-x:hidden}'));
$ok('repeat warning has structured rows', str_contains($view, '.feo-placement-repeat-item{display:grid') && str_contains($view, '.feo-placement-repeat-blocks{display:grid'));

$js = $read($root . '/public/assets/js/feo-map-placement.js');
foreach ([
    "confirmOperation('create'" => 'create uses ERP confirmation flow',
    "confirmOperation('status'" => 'status mutation uses ERP confirmation flow',
    "confirmOperation('delete'" => 'delete mutation uses ERP confirmation flow',
    "confirmOperation('reconcile'" => 'reconcile uses ERP confirmation flow',
    "addEventListener('dblclick'" => 'block detail opens on double click',
    "base+'/validate-manual'" => 'manual validation calls read-only validation endpoint',
    "base+'/search-blocks?'" => 'block search calls search endpoint',
    'extractClientRequestIds' => 'client reuses six-digit mapping semantics',
    "raw.indexOf('\\t')" => 'client recognizes tabular paste',
    "line.indexOf('|')" => 'client recognizes markdown table paste',
    "/(?<!\\d)\\d{6}(?!\\d)/g" => 'client mapping keeps standalone six-digit ids',
    'appendRepeatPanel' => 'repeat diagnostics are rendered structurally',
    "error.payload=data" => 'structured server error payload is preserved',
    "data-placement-delete" => 'block detail exposes delete action',
    "base+'/blocks/'+id+'/preview-delete'" => 'delete action previews before confirmation',
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
$ok('create confirmation does not duplicate effect in gray box', str_contains($js, "if(kind==='create'){") && !str_contains($js, "if(kind==='create')html+=`<div class=\"feo-placement-confirm-effect\""));
$ok('repeat operation prefix remains bold', str_contains($js, "title.textContent='Повторная операция запрещена:'") && str_contains($view, '.feo-placement-repeat-title{font-weight:700'));
$ok('manual field validates on every edit with debounce', str_contains($js, "manualInput?.addEventListener('input',()=>scheduleManualValidation())") && str_contains($js, 'window.setTimeout(validateManual,immediate?0:280)'));
$ok('block type change reruns manual validation', str_contains($js, "typeRadios.forEach(r=>r.addEventListener('change',()=>scheduleManualValidation(true)))"));
$ok('search paste normalizes using the same FEO mapping', str_contains($js, "blockSearchInput?.addEventListener('paste'") && str_contains($js, "blockSearchInput.value=ids.join(', ')"));
$ok('block search result opens block detail without redundant status column', !str_contains($js, 'statusHtml(block.status)') && str_contains($js, 'data-placement-search-block-id'));
$ok('delete action is a system danger button for both applied and disabled blocks', str_contains($js, "html+=`<button type=\"button\" class=\"btn btn-danger\" data-placement-delete>Удалить</button>`") && str_contains($js, "if(b.status==='applied')html+=`<button type=\"button\" class=\"btn btn-primary\" data-placement-toggle>Отключить блок</button>`") && str_contains($js, "else html+=`<button type=\"button\" class=\"btn btn-primary\" data-placement-toggle>Включить блок</button>`"));
$ok('delete confirmation uses a password field and explains irreversible replay impact', str_contains($js, 'data-placement-delete-password') && str_contains($js, 'type="password"') && str_contains($js, 'Блок будет удалён без возможности восстановления.') && str_contains($js, 'Изменение актуального состава:') && str_contains($js, "fd.append('delete_password'") && !str_contains($js, '7550055'));
$ok('block table has no actions column', !str_contains($view, '<th>Действия</th>'));
$ok('block table renders responsible immediately after date and before status', preg_match('/<th\\b[^>]*>Дата<\/th>\\s*<th\\b[^>]*>Ответств\\.<\/th>\\s*<th\\b[^>]*>Статус<\/th>/u', $view) === 1 && str_contains($view, "\$block['created_by_display_name']") && str_contains($view, 'colspan="9"'));
$ok('file upload hint states single-sheet and first-column requirements without header coupling', str_contains($view, 'В файле должен быть ровно один лист.') && str_contains($view, 'Названия колонок не проверяются.'));

$manifest = json_decode($read($root . '/.github/agent_db_migration_request.json'), true);
$workflow = $read($root . '/.github/workflows/feo_123_production_migration.yml');
$apply123 = $read($root . '/scripts/feo_apply_123.php');
$verify123 = $read($root . '/scripts/feo_verify_123.php');
$manifestId = is_array($manifest) ? (string) ($manifest['migration_id'] ?? '') : '';
$ok('agent migration request is not pinned behind placement migration 123', preg_match('/^\d{3}$/D', $manifestId) === 1 && (int) $manifestId >= 123);
$ok('migration 123 workflow is manual-only', str_contains($workflow, 'workflow_dispatch:') && str_contains($workflow, 'APPLY_FEO_123') && !str_contains($workflow, "\n  push:") && !str_contains($workflow, "\n  pull_request:") && !str_contains($workflow, "\n  schedule:") && !str_contains($workflow, "\n  workflow_run:"));
$ok('migration 123 apply script is tenant guarded', str_contains($apply123, "name = '123_create_feo_map_placement.sql'") && str_contains($apply123, 'GET_LOCK') && str_contains($apply123, 'schema_migrations'));
$ok('migration 123 verify script is read-only snapshot', str_contains($verify123, 'FEO_123_SNAPSHOT=') && str_contains($verify123, 'FEO_123_READ_ONLY_OK'));

printf("FEO_PLACEMENT_CONTRACT: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
