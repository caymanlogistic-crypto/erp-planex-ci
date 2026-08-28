<?php

$root = dirname(__DIR__);

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$must = static function (bool $ok, string $message) use ($fail): void {
    if (!$ok) $fail($message);
};
$same = static function (mixed $actual, mixed $expected, string $message) use ($fail): void {
    if ($actual !== $expected) $fail($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
};
$near = static function (float $actual, float $expected, float $epsilon, string $message) use ($fail): void {
    if (abs($actual - $expected) > $epsilon) $fail($message . " expected={$expected} actual={$actual}");
};
$read = static function (string $relative) use ($root, $fail): string {
    $path = $root . '/' . ltrim($relative, '/');
    $text = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($text)) $fail('cannot read ' . $relative);
    return $text;
};

require_once $root . '/app/Service/FeoPlacementInputParser.php';
require_once $root . '/app/Service/FeoRequestCostService.php';

/* Parser contract: each line decides plain vs first-column structured semantics. */
$parser = new \App\Service\FeoPlacementInputParser();
$mixed = $parser->parseManual("Нужно забрать заявку 302112\n306645\tООО Ромашка\t1250\nТакже добавить 308710");
$same($mixed['ids'], ['302112','306645','308710'], 'mixed manual input must preserve plain and tabular lines');
$same($mixed['duplicates'], [], 'mixed manual input duplicates');
$duplicates = $parser->parseManual("123456\tA\n654321 plain\n| 111111 | X |\n123456");
$same($duplicates['ids'], ['123456','654321','111111'], 'mixed parser id order');
$same($duplicates['duplicates'], ['123456'], 'mixed parser duplicate detection');
$firstColumn = $parser->parseManual("| 123456 | embedded 999999 |\n654321\t888888");
$same($firstColumn['ids'], ['123456','654321'], 'structured lines must use first column only');
echo "FEO_REQUEST_ID_MIXED_PARSER=PASS\n";

/* Screen/runtime contract: this protects the production failure mode from 2026-08-28. */
$controller = $read('app/Http/Controllers/Company/FeoRequestCostController.php');
$view = $read('app/View/pages/company_feo_request_costs.php');
$layout = $read('app/View/layouts/main.php');
$routes = $read('app/Http/Routes/company_feo_request_costs.php');
$entry = $read('public/index.php');
$js = $read('public/assets/js/feo-request-costs.js');

$must(str_contains($controller, "require base_path('app/View/layouts/main.php')"), 'cost screen must render through standard main layout');
$must(substr_count($controller, "requireRole(['company_owner','senior_logist','logist'])") >= 8, 'all cost endpoints must keep role gate');
$must(str_contains($controller, 'moduleReady($pdo)') && str_contains($controller, 'ещё не инициализирован'), 'cost controller must fail closed when schema is absent');
$must(str_contains($layout, "assets/css/app.css") && str_contains($layout, "assets/css/erp-ui.css"), 'main layout must load standard ERP styles');
$must(str_contains($layout, 'modal-shell.js') && str_contains($layout, 'csrf-fetch.js'), 'main layout must load standard runtime assets');
$must(str_contains($entry, 'verifyCsrfRequest();'), 'entrypoint CSRF verification must remain enabled');
$must(str_contains($view, "app_url('/assets/js/feo-request-costs.js')"), 'cost page JS must use standard app_url base-path helper');
$must(!str_contains($view, 'asset_url('), 'cost page must not call undefined asset_url helper');
$must(str_contains($view, 'data-cost-page-ready'), 'cost screen ready marker must exist');
$must(str_contains($view, 'id="feo-cost-create-modal" class="modal-overlay"'), 'create UI must use system modal overlay');
$must(str_contains($view, 'id="feo-cost-detail-modal" class="modal-overlay"'), 'detail/edit UI must use system modal overlay');
$must(str_contains($view, 'id="feo-cost-confirm-modal" class="modal-overlay"'), 'confirmation UI must use system modal overlay');
$must(substr_count($view, 'aria-hidden="true"') >= 3, 'all cost modals must be closed by default');
$must(str_contains($view, 'Действия') && str_contains($view, 'data-cost-edit-block-id'), 'main table must expose edit action');
$must(str_contains($view, 'Стоимость заявок ещё не добавлена.'), 'empty state must be explicit');
$must(str_contains($view, 'data-cost-type-choice="rate_34_5"') && str_contains($view, 'data-cost-type-choice="rate_38"') && str_contains($view, 'data-cost-type-choice="agreement"'), 'create modal must expose all three pricing modes');
$must(str_contains($view, 'data-cost-confirm-copy') && str_contains($view, 'data-cost-confirm-submit'), 'create/edit must use confirmation modal');
$must(str_contains($view, 'csrfField()'), 'cost forms must contain CSRF field');
$must(!str_contains($js, 'const structured='), 'client parser must not switch whole paste into one structured mode');
$must(str_contains($js, ".split('\\n').forEach"), 'client parser must inspect input line by line');
$must(str_contains($js, "line.indexOf('\\t')") && str_contains($js, "line.indexOf('|')"), 'client parser must apply first-column semantics per structured line');
$must(str_contains($js, "addEventListener('click'"), 'block rows/actions must open on click');
foreach ([
    "'/company/feo/requests/costs'",
    "'/company/feo/requests/costs/validate'",
    "'/company/feo/requests/costs/analyze'",
    "'/company/feo/requests/costs/commit'",
    "'/company/feo/requests/costs/search'",
    "'/company/feo/requests/costs/blocks/{id}/json'",
    "'/company/feo/requests/costs/blocks/{id}/edit/analyze'",
    "'/company/feo/requests/costs/edit/commit'",
] as $needle) $must(str_contains($routes, $needle), 'missing route ' . $needle);
echo "FEO_REQUEST_COSTS_UI_LAYOUT_CONTRACT=PASS\n";

if (!in_array('mysql', \PDO::getAvailableDrivers(), true)) {
    echo "FEO_REQUEST_COSTS_MYSQL=REMOTE_REQUIRED\n";
    echo "FEO_REQUEST_COSTS_REGRESSION=PASS_WITH_REMOTE_GATE\n";
    exit(0);
}

$host = getenv('PLANEX_SCHEMA_DB_HOST') ?: '127.0.0.1';
$port = (int)(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306);
$user = getenv('PLANEX_SCHEMA_DB_USER') ?: 'planex_ci';
$pass = getenv('PLANEX_SCHEMA_DB_PASSWORD') ?: 'planex_ci';
$admin = new \PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);
$dbName = 'planex_feo_cost_' . getmypid() . '_' . bin2hex(random_bytes(3));
$quotedDb = '`' . str_replace('`', '``', $dbName) . '`';
$admin->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("CREATE TABLE feo_requests (zayavka_id VARCHAR(64) NOT NULL PRIMARY KEY, mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE feo_placement_current (zayavka_id VARCHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $service = new \App\Service\FeoRequestCostService();
    $must(!$service->moduleReady($pdo), 'moduleReady must fail closed before cost tables exist');

    $migration = (string)file_get_contents($root . '/database/migrations-local/124_create_feo_request_costs.sql');
    foreach (preg_split('/;\s*(?:\R|$)/', $migration) ?: [] as $statement) { $statement=trim($statement); if ($statement!=='') $pdo->exec($statement); }
    $must($service->moduleReady($pdo), 'cost module must be ready after migration 124 schema');

    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto) VALUES ('123456',1.250000),('654321',0.500000),('111111',0.250000)");
    $pdo->exec("INSERT INTO feo_placement_current(zayavka_id) VALUES ('123456'),('654321'),('111111')");

    $preview345 = $service->previewCreate($pdo, ['123456','654321'], 'rate_34_5', null);
    $same($preview345['selected_count'], 2, 'preview request count');
    $near((float)$preview345['selected_mass_kg'], 1750.000, 0.0005, 'preview mass');
    $near((float)$preview345['total_cost'], 60375.00, 0.005, '34.5 calculation');
    $preview38 = $service->previewCreate($pdo, ['123456','654321'], 'rate_38', null);
    $near((float)$preview38['total_cost'], 66500.00, 0.005, '38 calculation');
    $previewAgreement = $service->previewCreate($pdo, ['123456','654321'], 'agreement', '71234,56');
    $near((float)$previewAgreement['total_cost'], 71234.56, 0.005, 'agreement manual calculation');

    $created = $service->createBlock($pdo, [
        'ids'=>['123456','654321'],'pricing_type'=>'rate_34_5','manual_total'=>null,'comment'=>'initial','created_by_user_id'=>7,'created_by_role'=>'logist',
    ], (string)$preview345['selection_fingerprint']);
    $blockId=(int)$created['block_id'];
    $near((float)$created['snapshot_total_mass_kg'],1750.000,0.0005,'created snapshot mass');
    $near((float)$created['total_cost'],60375.00,0.005,'created total cost');

    $detail=$service->block($pdo,$blockId);
    $must(is_array($detail),'created block detail');
    $same($detail['snapshot_request_count'],2,'snapshot request count');
    $same(array_column($detail['requests'],'zayavka_id'),['123456','654321'],'snapshot composition');
    $near((float)$detail['requests'][0]['snapshot_mass_kg'],1250.000,0.0005,'request 123456 snapshot mass');
    $near((float)$detail['requests'][1]['snapshot_mass_kg'],500.000,0.0005,'request 654321 snapshot mass');

    $validation=$service->validateSelection($pdo,['123456','654321']);
    $must(!$validation['valid'],'already priced selection must be invalid');
    $same(array_map('strval',$validation['already_priced_ids']),['123456','654321'],'already priced ids');

    $insertBlock=$pdo->prepare("INSERT INTO feo_request_cost_blocks(pricing_type,rate_per_kg,snapshot_request_count,snapshot_total_mass_kg,total_cost) VALUES('agreement',NULL,1,250.000,10000.00)");
    $insertBlock->execute();$secondBlockId=(int)$pdo->lastInsertId();$duplicateBlocked=false;
    try{$q=$pdo->prepare('INSERT INTO feo_request_cost_block_requests(block_id,zayavka_id,snapshot_mass_kg,position_no) VALUES(?,?,?,?)');$q->execute([$secondBlockId,'123456',1250.000,1]);}catch(\PDOException $e){$duplicateBlocked=(($e->errorInfo[1]??null)===1062);}
    $must($duplicateBlocked,'DB UNIQUE must reject duplicate pricing membership');

    $editPreview=$service->previewEdit($pdo,$blockId,'agreement','70000','corrected');
    $edited=$service->editBlock($pdo,$blockId,['pricing_type'=>'agreement','manual_total'=>'70000','comment'=>'corrected'],(string)$editPreview['edit_fingerprint']);
    $same($edited['pricing_type'],'agreement','explicit agreement edit type');
    $near((float)$edited['total_cost'],70000.00,0.005,'explicit agreement edit total');

    $afterEdit=$service->block($pdo,$blockId);
    $same($afterEdit['snapshot_request_count'],2,'edit must not mutate snapshot count');
    $near((float)$afterEdit['snapshot_total_mass_kg'],1750.000,0.0005,'edit must not mutate snapshot total mass');
    $same(array_column($afterEdit['requests'],'zayavka_id'),['123456','654321'],'edit must not mutate composition');

    $stalePreview=$service->previewEdit($pdo,$blockId,'agreement','71000','stale preview');
    $pdo->exec("UPDATE feo_request_cost_blocks SET comment='external mutation', updated_at=DATE_ADD(updated_at,INTERVAL 1 SECOND) WHERE id=".$blockId);
    $staleBlocked=false;
    try{$service->editBlock($pdo,$blockId,['pricing_type'=>'agreement','manual_total'=>'71000','comment'=>'stale preview'],(string)$stalePreview['edit_fingerprint']);}catch(\RuntimeException $e){$staleBlocked=str_contains($e->getMessage(),'изменён после открытия');}
    $must($staleBlocked,'stale edit fingerprint must be rejected');

    $pdo->exec("UPDATE feo_requests SET mass_netto=1.300000 WHERE zayavka_id='123456'");
    $drift=$service->block($pdo,$blockId);
    $must($drift['requests'][0]['mass_changed']===true,'live mass change must surface drift');
    $near((float)$drift['requests'][0]['snapshot_mass_kg'],1250.000,0.0005,'live mass change must not mutate snapshot');
    $near((float)$drift['total_cost'],70000.00,0.005,'live mass change must not auto-reprice');

    $pdo->exec("DELETE FROM feo_placement_current WHERE zayavka_id='654321'");
    $removed=$service->block($pdo,$blockId);
    $must($removed['requests'][1]['in_current_placement']===false,'placement removal must surface integrity drift');
    $same($removed['snapshot_request_count'],2,'placement removal must preserve history');

    $ratePreview=$service->previewEdit($pdo,$blockId,'rate_38',null,'rate edit');
    $near((float)$ratePreview['total_cost'],66500.00,0.005,'rate edit must use stored snapshot total mass');
    $rateEdit=$service->editBlock($pdo,$blockId,['pricing_type'=>'rate_38','manual_total'=>null,'comment'=>'rate edit'],(string)$ratePreview['edit_fingerprint']);
    $near((float)$rateEdit['total_cost'],66500.00,0.005,'rate 38 edit total');

    echo "FEO_REQUEST_COSTS_MYSQL=PASS\n";
    echo "FEO_REQUEST_COSTS_REGRESSION=PASS\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS {$quotedDb}");
}
