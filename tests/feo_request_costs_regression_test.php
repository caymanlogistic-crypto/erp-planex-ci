<?php

$root = dirname(__DIR__);
$fail = static function (string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); };
$must = static function (bool $ok, string $message) use ($fail): void { if (!$ok) $fail($message); };
$same = static function (mixed $actual, mixed $expected, string $message) use ($fail): void { if ($actual !== $expected) $fail($message . ' expected=' . var_export($expected,true) . ' actual=' . var_export($actual,true)); };
$near = static function (float $actual, float $expected, float $epsilon, string $message) use ($fail): void { if (abs($actual-$expected)>$epsilon) $fail($message . " expected={$expected} actual={$actual}"); };
$read = static function (string $relative) use ($root,$fail): string { $path=$root.'/'.ltrim($relative,'/'); $text=is_file($path)?file_get_contents($path):false; if(!is_string($text))$fail('cannot read '.$relative); return $text; };

require_once $root . '/app/Service/FeoPlacementInputParser.php';
require_once $root . '/app/Service/FeoRequestCostService.php';
require_once $root . '/app/Service/FeoRequestCostEditService.php';

if (($argv[1] ?? '') === 'race-worker') {
    $cfg=json_decode(base64_decode((string)($argv[2]??''),true)?:'',true);
    if (!is_array($cfg)) { fwrite(STDOUT,json_encode(['success'=>false,'message'=>'invalid race config'])); exit(0); }
    $pdo=new \PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
        $cfg['user'],$cfg['pass'],
        [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]
    );
    $edit=new \App\Service\FeoRequestCostEditService();
    $startAt=(float)$cfg['start_at'];
    while (microtime(true)<$startAt) usleep(1000);
    try {
        $result=$edit->editBlock(
            $pdo,(int)$cfg['block_id'],(array)$cfg['payload'],
            (string)$cfg['edit_fingerprint'],(array)$cfg['preview_contract']
        );
        fwrite(STDOUT,json_encode(['success'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    } catch (\Throwable $e) {
        fwrite(STDOUT,json_encode(['success'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    exit(0);
}

$parser = new \App\Service\FeoPlacementInputParser();
$mixed = $parser->parseManual("Нужно забрать заявку 302112\n306645\tООО Ромашка\t1250\nТакже добавить 308710");
$same($mixed['ids'],['302112','306645','308710'],'mixed manual input must preserve plain and tabular lines');
$same($mixed['duplicates'],[],'mixed manual input duplicates');
$duplicates=$parser->parseManual("123456\tA\n654321 plain\n| 111111 | X |\n123456");
$same($duplicates['ids'],['123456','654321','111111'],'mixed parser id order');
$same($duplicates['duplicates'],['123456'],'mixed parser duplicate detection');
$firstColumn=$parser->parseManual("| 123456 | embedded 999999 |\n654321\t888888");
$same($firstColumn['ids'],['123456','654321'],'structured lines must use first column only');
echo "FEO_REQUEST_ID_MIXED_PARSER=PASS\n";

$controller=$read('app/Http/Controllers/Company/FeoRequestCostController.php');
$view=$read('app/View/pages/company_feo_request_costs.php');
$layout=$read('app/View/layouts/main.php');
$routes=$read('app/Http/Routes/company_feo_request_costs.php');
$entry=$read('public/index.php');
$js=$read('public/assets/js/feo-request-costs.js');
$editService=$read('app/Service/FeoRequestCostEditService.php');

$must(str_contains($controller,"require base_path('app/View/layouts/main.php')"),'cost screen must render through standard main layout');
$must(substr_count($controller,"requireRole(['company_owner','senior_logist','logist'])")>=9,'all cost endpoints must keep role gate');
$must(str_contains($controller,'moduleReady($pdo)')&&str_contains($controller,'ещё не инициализирован'),'cost controller must fail closed when schema is absent');
$must(str_contains($controller,"'preview_contract'=>[")&&str_contains($controller,"'added_snapshot_fingerprint'"),'edit preview token must retain added live snapshot contract');
$must(str_contains($layout,'assets/css/app.css')&&str_contains($layout,'assets/css/erp-ui.css'),'main layout must load standard ERP styles');
$must(str_contains($layout,'modal-shell.js')&&str_contains($layout,'csrf-fetch.js'),'main layout must load standard runtime assets');
$must(str_contains($entry,'verifyCsrfRequest();'),'entrypoint CSRF verification must remain enabled');
$must(str_contains($view,"app_url('/assets/js/feo-request-costs.js')"),'cost page JS must use standard app_url base-path helper');
$must(!str_contains($view,'asset_url('),'cost page must not call undefined asset_url helper');
$must(str_contains($view,'data-cost-page-ready'),'cost screen ready marker must exist');
$must(str_contains($view,'id="feo-cost-create-modal" class="modal-overlay"'),'create UI must use system modal overlay');
$must(str_contains($view,'id="feo-cost-detail-modal" class="modal-overlay"'),'detail/edit UI must use system modal overlay');
$must(str_contains($view,'id="feo-cost-confirm-modal" class="modal-overlay"'),'confirmation UI must use system modal overlay');
$must(substr_count($view,'aria-hidden="true"')>=3,'all cost modals must be closed by default');
$must(!str_contains($view,'>Действия<')&&!str_contains($view,'data-cost-edit-block-id'),'main table must not expose actions column/button');
$must(str_contains($js,"qa('[data-cost-block-id]').forEach(row=>bindCostBlockRow(row,'costBlockId'))"),'main table rows must open edit on double click through the isolated row handler');
$must(!str_contains($js,"qa('[data-cost-block-id]').forEach(row=>row.addEventListener('click'"),'main table rows must not open on single click');
$must(str_contains($view,'$fmtMassTable = static fn($v): string => number_format((float)$v, 0')&&str_contains($view,'$fmtMassTable($block['),'main table mass must render to whole kilograms');
$must(str_contains($view,'Стоимость заявок ещё не добавлена.'),'empty state must be explicit');
$must(str_contains($view,'data-cost-edit-ids')&&str_contains($view,'Добавьте или удалите шестизначные ID заявок'),'edit modal must expose composition editing');
$must(str_contains($view,'snapshot-масса оставшихся заявок сохраняется'),'edit UI must explain retained snapshot semantics');
$must(str_contains($view,'data-cost-edit-type-choice="rate_34_5"')&&str_contains($view,'data-cost-edit-type-choice="rate_38"')&&str_contains($view,'data-cost-edit-type-choice="agreement"'),'edit modal must expose rate 34.5, rate 38 and agreement');
$must(str_contains($view,'data-cost-confirm-copy')&&str_contains($view,'data-cost-confirm-submit'),'create/edit must use confirmation modal');
$must(str_contains($view,'csrfField()'),'cost forms must contain CSRF field');
$must(!str_contains($js,'const structured='),'client parser must not switch whole paste into one structured mode');
$must(str_contains($js,".split('\\n').forEach"),'client parser must inspect input line by line');
$must(str_contains($js,"line.indexOf('\\t')")&&str_contains($js,"line.indexOf('|')"),'client parser must apply first-column semantics per structured line');
$must(str_contains($js,'added_ids')&&str_contains($js,'removed_ids')&&str_contains($js,'old_total_cost'),'edit confirmation must show composition and price diff');
$must(str_contains($js,'Заявок: <b>${esc(preview.old_request_count)} → ${esc(preview.selected_count)}</b>'),'edit confirmation must show old to new request count');
$must(str_contains($js,'Найдено по ${results.length} заявкам')&&str_contains($js,'exists_in_feo')&&str_contains($js,'is_current'),'search UI must support per-request multi-search information like map placement');
$must(str_contains($js,"data-cost-search-block-id")&&str_contains($js,"addEventListener('dblclick'"),'search results must open found block on double click');
$must(str_contains($editService,'addedSnapshotFingerprint')&&str_contains($editService,'assertPreviewMatchesCommit'),'preview to commit live snapshot integrity must be enforced');
$must(str_contains($editService,'Данные добавляемых заявок изменились после предпросмотра'),'live mass drift between preview and commit must fail closed');
$must(str_contains($editService,'$addedRows = $this->currentRows($pdo, $added')||str_contains($editService,'$addedRows=$this->currentRows($pdo,$added'),'only added requests must be refreshed from current placement');
$must(str_contains($editService,'snapshotRows($state['),'retained requests must reuse stored snapshot rows');
$must(str_contains($editService,'DELETE FROM feo_request_cost_block_requests WHERE block_id=?')&&str_contains($editService,'INSERT INTO feo_request_cost_block_requests'),'composition replacement must be explicit and transactional');
$must(str_contains($editService,'FOR UPDATE'),'edit composition commit must lock mutable state');
foreach ([
    "'/company/feo/requests/costs'",
    "'/company/feo/requests/costs/validate'",
    "'/company/feo/requests/costs/analyze'",
    "'/company/feo/requests/costs/commit'",
    "'/company/feo/requests/costs/search'",
    "'/company/feo/requests/costs/blocks/{id}/json'",
    "'/company/feo/requests/costs/blocks/{id}/edit/validate'",
    "'/company/feo/requests/costs/blocks/{id}/edit/analyze'",
    "'/company/feo/requests/costs/edit/commit'",
] as $needle) $must(str_contains($routes,$needle),'missing route '.$needle);
echo "FEO_REQUEST_COSTS_UI_LAYOUT_CONTRACT=PASS\n";

if (!in_array('mysql',\PDO::getAvailableDrivers(),true)) {
    echo "FEO_REQUEST_COSTS_MYSQL=REMOTE_REQUIRED\n";
    echo "FEO_REQUEST_COSTS_REGRESSION=PASS_WITH_REMOTE_GATE\n";
    exit(0);
}

$host=getenv('PLANEX_SCHEMA_DB_HOST')?:'127.0.0.1';
$port=(int)(getenv('PLANEX_SCHEMA_DB_PORT')?:3306);
$user=getenv('PLANEX_SCHEMA_DB_USER')?:'planex_ci';
$pass=getenv('PLANEX_SCHEMA_DB_PASSWORD')?:'planex_ci';
$admin=new \PDO("mysql:host={$host};port={$port};charset=utf8mb4",$user,$pass,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);
$dbName='planex_feo_cost_'.getmypid().'_'.bin2hex(random_bytes(3));
$quotedDb='`'.str_replace('`','``',$dbName).'`';
$admin->exec("CREATE DATABASE {$quotedDb} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo=new \PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4",$user,$pass,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec("CREATE TABLE feo_requests (zayavka_id VARCHAR(64) NOT NULL PRIMARY KEY, mass_netto DECIMAL(18,6) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE feo_placement_current (zayavka_id VARCHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $service=new \App\Service\FeoRequestCostService();
    $edit=new \App\Service\FeoRequestCostEditService();
    $must(!$service->moduleReady($pdo),'moduleReady must fail closed before cost tables exist');

    $migration=(string)file_get_contents($root.'/database/migrations-local/124_create_feo_request_costs.sql');
    foreach(preg_split('/;\s*(?:\R|$)/',$migration)?:[] as $statement){$statement=trim($statement);if($statement!=='')$pdo->exec($statement);}
    $must($service->moduleReady($pdo),'cost module must be ready after migration 124 schema');

    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto) VALUES ('123456',1.250000),('654321',0.500000),('111111',0.250000),('222222',0.400000),('333333',0.300000),('999999',0.100000),('444444',0.600000),('555555',0.700000),('666666',0.800000)");
    $pdo->exec("INSERT INTO feo_placement_current(zayavka_id) VALUES ('123456'),('654321'),('111111'),('222222'),('444444'),('555555'),('666666')");

    $preview345=$service->previewCreate($pdo,['123456','654321'],'rate_34_5',null);
    $same($preview345['selected_count'],2,'preview request count');
    $near((float)$preview345['selected_mass_kg'],1750.000,0.0005,'preview mass');
    $near((float)$preview345['total_cost'],60375.00,0.005,'34.5 calculation');
    $preview38=$service->previewCreate($pdo,['123456','654321'],'rate_38',null);
    $near((float)$preview38['total_cost'],66500.00,0.005,'38 calculation');
    $previewAgreement=$service->previewCreate($pdo,['123456','654321'],'agreement','71234,56');
    $near((float)$previewAgreement['total_cost'],71234.56,0.005,'agreement manual calculation');

    $created=$service->createBlock($pdo,['ids'=>['123456','654321'],'pricing_type'=>'rate_34_5','manual_total'=>null,'comment'=>'initial','created_by_user_id'=>7,'created_by_role'=>'logist'],(string)$preview345['selection_fingerprint']);
    $blockId=(int)$created['block_id'];
    $near((float)$created['snapshot_total_mass_kg'],1750.000,0.0005,'created snapshot mass');
    $near((float)$created['total_cost'],60375.00,0.005,'created total cost');

    $secondPreview=$service->previewCreate($pdo,['222222'],'agreement','10000');
    $second=$service->createBlock($pdo,['ids'=>['222222'],'pricing_type'=>'agreement','manual_total'=>'10000','comment'=>'other block','created_by_user_id'=>7,'created_by_role'=>'logist'],(string)$secondPreview['selection_fingerprint']);
    $secondBlockId=(int)$second['block_id'];

    $detail=$service->block($pdo,$blockId);
    $same($detail['snapshot_request_count'],2,'snapshot request count');
    $same(array_column($detail['requests'],'zayavka_id'),['123456','654321'],'snapshot composition');
    $near((float)$detail['requests'][0]['snapshot_mass_kg'],1250.000,0.0005,'request 123456 snapshot mass');
    $near((float)$detail['requests'][1]['snapshot_mass_kg'],500.000,0.0005,'request 654321 snapshot mass');

    $validation=$service->validateSelection($pdo,['123456','654321']);
    $must(!$validation['valid'],'already priced selection must be invalid');
    $same(array_map('strval',$validation['already_priced_ids']),['123456','654321'],'already priced ids');

    $duplicateBlocked=false;
    try{$q=$pdo->prepare('INSERT INTO feo_request_cost_block_requests(block_id,zayavka_id,snapshot_mass_kg,position_no) VALUES(?,?,?,?)');$q->execute([$secondBlockId,'123456',1250.000,2]);}catch(\PDOException $e){$duplicateBlocked=(($e->errorInfo[1]??null)===1062);}
    $must($duplicateBlocked,'DB UNIQUE must reject duplicate pricing membership');

    $search=$service->searchBlocks($pdo,['123456','111111','333333','999999']);
    $same(count($search),4,'multi-search must return one row per requested id');
    $same((string)$search[0]['zayavka_id'],'123456','search first id');
    $same((int)$search[0]['block']['block_id'],$blockId,'search must return assigned block');
    $must($search[0]['exists_in_feo']===true&&$search[0]['is_current']===true,'assigned request search state');
    $must($search[1]['block']===null&&$search[1]['exists_in_feo']===true&&$search[1]['is_current']===true,'current unpriced request search state');
    $must($search[2]['block']===null&&$search[2]['exists_in_feo']===true&&$search[2]['is_current']===false,'non-current unpriced request search state');
    $must($search[3]['block']===null&&$search[3]['exists_in_feo']===true&&$search[3]['is_current']===false,'extra unpriced request search state');

    $commitEdit=static function(int $id,array $ids,string $type,mixed $manual,string $comment,array $preview) use($edit,$pdo):array {
        return $edit->editBlock($pdo,$id,['ids'=>$ids,'pricing_type'=>$type,'manual_total'=>$manual,'comment'=>$comment],(string)$preview['edit_fingerprint'],$preview);
    };

    $editPreview=$edit->previewEdit($pdo,$blockId,['123456','654321'],'agreement','70000','corrected');
    $must($editPreview['composition_changed']===false,'price-only edit must not change composition');
    $edited=$commitEdit($blockId,['123456','654321'],'agreement','70000','corrected',$editPreview);
    $near((float)$edited['total_cost'],70000.00,0.005,'agreement edit total');
    $afterEdit=$service->block($pdo,$blockId);
    $same($afterEdit['snapshot_request_count'],2,'price-only edit must preserve snapshot count');
    $near((float)$afterEdit['snapshot_total_mass_kg'],1750.000,0.0005,'price-only edit must preserve snapshot total mass');
    $same(array_column($afterEdit['requests'],'zayavka_id'),['123456','654321'],'price-only edit must preserve composition');

    $pdo->exec("UPDATE feo_requests SET mass_netto=1.300000 WHERE zayavka_id='123456'");
    $drift=$service->block($pdo,$blockId);
    $must($drift['requests'][0]['mass_changed']===true,'live mass change must surface drift');
    $near((float)$drift['requests'][0]['snapshot_mass_kg'],1250.000,0.0005,'live mass change must not mutate snapshot');
    $near((float)$drift['total_cost'],70000.00,0.005,'live mass change must not auto-reprice');

    $ratePreview=$edit->previewEdit($pdo,$blockId,['123456','654321'],'rate_38',null,'rate edit');
    $near((float)$ratePreview['selected_mass_kg'],1750.000,0.0005,'rate edit without composition change must use stored snapshot mass');
    $near((float)$ratePreview['total_cost'],66500.00,0.005,'rate edit must use stored snapshot mass');
    $commitEdit($blockId,['123456','654321'],'rate_38',null,'rate edit',$ratePreview);

    $pdo->exec("DELETE FROM feo_placement_current WHERE zayavka_id='123456'");
    $compositionValidation=$edit->validateSelection($pdo,$blockId,['123456','111111']);
    $must($compositionValidation['valid']===true,'retained historical member may stay while a current request is added');
    $same($compositionValidation['added_ids'],['111111'],'composition added ids');
    $same($compositionValidation['removed_ids'],['654321'],'composition removed ids');
    $near((float)$compositionValidation['selected_mass_kg'],1500.000,0.0005,'new composition mass must keep retained snapshot and add current snapshot');

    $staleLivePreview=$edit->previewEdit($pdo,$blockId,['123456','111111'],'rate_38',null,'stale live mass');
    $near((float)$staleLivePreview['selected_mass_kg'],1500.000,0.0005,'live-stale preview mass');
    $near((float)$staleLivePreview['total_cost'],57000.00,0.005,'live-stale preview total');
    $pdo->exec("UPDATE feo_requests SET mass_netto=0.260000 WHERE zayavka_id='111111'");
    $liveMassBlocked=false;
    try{$commitEdit($blockId,['123456','111111'],'rate_38',null,'stale live mass',$staleLivePreview);}catch(\RuntimeException $e){$liveMassBlocked=str_contains($e->getMessage(),'Данные добавляемых заявок изменились после предпросмотра');}
    $must($liveMassBlocked,'added request mass change between preview and commit must be rejected');
    $afterRejectedLive=$service->block($pdo,$blockId);
    $same(array_column($afterRejectedLive['requests'],'zayavka_id'),['123456','654321'],'rejected live drift must preserve old composition');
    $near((float)$afterRejectedLive['snapshot_total_mass_kg'],1750.000,0.0005,'rejected live drift must preserve old snapshot mass');
    $near((float)$afterRejectedLive['total_cost'],66500.00,0.005,'rejected live drift must preserve old cost');
    $pdo->exec("UPDATE feo_requests SET mass_netto=0.250000 WHERE zayavka_id='111111'");

    $compositionPreview=$edit->previewEdit($pdo,$blockId,['123456','111111'],'rate_38',null,'composition edit');
    $must($compositionPreview['composition_changed']===true,'composition preview must flag change');
    $near((float)$compositionPreview['old_total_mass_kg'],1750.000,0.0005,'composition preview old mass');
    $near((float)$compositionPreview['selected_mass_kg'],1500.000,0.0005,'composition preview new mass');
    $near((float)$compositionPreview['total_cost'],57000.00,0.005,'composition fixed-rate recalculation');
    $compositionEdit=$commitEdit($blockId,['123456','111111'],'rate_38',null,'composition edit',$compositionPreview);
    $same($compositionEdit['added_ids'],['111111'],'commit added ids');
    $same($compositionEdit['removed_ids'],['654321'],'commit removed ids');
    $near((float)$compositionEdit['snapshot_total_mass_kg'],1500.000,0.0005,'commit new snapshot total mass');
    $near((float)$compositionEdit['total_cost'],57000.00,0.005,'commit recalculated total');

    $afterComposition=$service->block($pdo,$blockId);
    $same(array_column($afterComposition['requests'],'zayavka_id'),['123456','111111'],'composition commit member list');
    $near((float)$afterComposition['requests'][0]['snapshot_mass_kg'],1250.000,0.0005,'retained member must preserve old snapshot despite live mass drift');
    $near((float)$afterComposition['requests'][1]['snapshot_mass_kg'],250.000,0.0005,'added member must snapshot current mass');
    $same($afterComposition['snapshot_request_count'],2,'composition commit snapshot count');
    $near((float)$afterComposition['snapshot_total_mass_kg'],1500.000,0.0005,'composition commit snapshot mass');
    $must($afterComposition['requests'][0]['in_current_placement']===false,'retained historical member drift must remain visible');

    $conflict=$edit->validateSelection($pdo,$blockId,['123456','111111','222222']);
    $must($conflict['valid']===false&&in_array('222222',$conflict['already_priced_ids'],true),'cannot add request priced in another block');
    $same((int)$conflict['already_priced_blocks']['222222'],$secondBlockId,'conflict must expose owning block');
    $missingPlacement=$edit->validateSelection($pdo,$blockId,['123456','111111','333333']);
    $must($missingPlacement['valid']===false&&in_array('333333',$missingPlacement['missing_placement_ids'],true),'cannot add request outside current placement');

    $agreementPreview=$edit->previewEdit($pdo,$blockId,['123456','111111'],'agreement','55555,55','agreement after composition');
    $near((float)$agreementPreview['total_cost'],55555.55,0.005,'manual agreement can replace recalculated fixed price');
    $agreementEdit=$commitEdit($blockId,['123456','111111'],'agreement','55555,55','agreement after composition',$agreementPreview);
    $near((float)$agreementEdit['total_cost'],55555.55,0.005,'agreement edit commit');
    $near((float)$agreementEdit['snapshot_total_mass_kg'],1500.000,0.0005,'agreement edit without composition change must preserve snapshot mass');

    $stalePreview=$edit->previewEdit($pdo,$blockId,['123456','111111'],'agreement','56000','stale preview');
    $pdo->exec("UPDATE feo_request_cost_blocks SET comment='external mutation', updated_at=DATE_ADD(updated_at,INTERVAL 1 SECOND) WHERE id=".$blockId);
    $staleBlocked=false;
    try{$commitEdit($blockId,['123456','111111'],'agreement','56000','stale preview',$stalePreview);}catch(\RuntimeException $e){$staleBlocked=str_contains($e->getMessage(),'изменён после открытия');}
    $must($staleBlocked,'stale edit fingerprint must be rejected');

    $racePreviewA=$service->previewCreate($pdo,['444444'],'agreement','1000');
    $raceA=$service->createBlock($pdo,['ids'=>['444444'],'pricing_type'=>'agreement','manual_total'=>'1000','comment'=>'race A','created_by_user_id'=>7,'created_by_role'=>'logist'],(string)$racePreviewA['selection_fingerprint']);
    $racePreviewB=$service->previewCreate($pdo,['555555'],'agreement','1000');
    $raceB=$service->createBlock($pdo,['ids'=>['555555'],'pricing_type'=>'agreement','manual_total'=>'1000','comment'=>'race B','created_by_user_id'=>7,'created_by_role'=>'logist'],(string)$racePreviewB['selection_fingerprint']);
    $raceBlockA=(int)$raceA['block_id'];$raceBlockB=(int)$raceB['block_id'];
    $raceEditA=$edit->previewEdit($pdo,$raceBlockA,['444444','666666'],'rate_38',null,'race A add shared');
    $raceEditB=$edit->previewEdit($pdo,$raceBlockB,['555555','666666'],'rate_38',null,'race B add shared');
    $must(function_exists('proc_open'),'proc_open is required for true two-connection race regression');
    $startAt=microtime(true)+0.75;
    $baseCfg=['host'=>$host,'port'=>$port,'user'=>$user,'pass'=>$pass,'db'=>$dbName,'start_at'=>$startAt];
    $workerCfgs=[
        $baseCfg+['block_id'=>$raceBlockA,'payload'=>['ids'=>['444444','666666'],'pricing_type'=>'rate_38','manual_total'=>null,'comment'=>'race A add shared'],'edit_fingerprint'=>$raceEditA['edit_fingerprint'],'preview_contract'=>$raceEditA],
        $baseCfg+['block_id'=>$raceBlockB,'payload'=>['ids'=>['555555','666666'],'pricing_type'=>'rate_38','manual_total'=>null,'comment'=>'race B add shared'],'edit_fingerprint'=>$raceEditB['edit_fingerprint'],'preview_contract'=>$raceEditB],
    ];
    $processes=[];
    foreach($workerCfgs as $cfg){
        $arg=base64_encode(json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'race-worker',$arg],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $must(is_resource($proc),'failed to start race worker');$processes[]=[$proc,$pipes];
    }
    $raceResults=[];
    foreach($processes as [$proc,$pipes]){
        $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
        $must($code===0,'race worker process failed: '.$stderr);
        $decoded=json_decode((string)$stdout,true);$must(is_array($decoded),'race worker returned invalid JSON: '.$stdout);$raceResults[]=$decoded;
    }
    $wins=array_values(array_filter($raceResults,static fn(array $r):bool=>($r['success']??false)===true));
    $losses=array_values(array_filter($raceResults,static fn(array $r):bool=>($r['success']??false)!==true));
    $same(count($wins),1,'exactly one concurrent assignment must win');
    $same(count($losses),1,'exactly one concurrent assignment must lose');
    $must(str_contains((string)($losses[0]['message']??''),'другом блоке'),'losing concurrent assignment must report ownership conflict');
    $q=$pdo->query("SELECT COUNT(*) FROM feo_request_cost_block_requests WHERE zayavka_id='666666'");
    $same((int)$q->fetchColumn(),1,'shared request must have exactly one final cost membership after race');
    echo "FEO_REQUEST_COSTS_CONCURRENCY_RACE=PASS\n";

    echo "FEO_REQUEST_COSTS_MYSQL=PASS\n";
    echo "FEO_REQUEST_COSTS_REGRESSION=PASS\n";
} finally {
    $admin->exec("DROP DATABASE IF EXISTS {$quotedDb}");
}
