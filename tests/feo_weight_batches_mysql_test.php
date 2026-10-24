<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/FeoTransferService.php';
require_once BASE_PATH.'/app/Service/FeoXlsxParser.php';
require_once BASE_PATH.'/app/Service/FeoWeightChangeNotificationService.php';
require_once BASE_PATH.'/app/Service/FeoWeightBatchService.php';

use App\Service\FeoWeightBatchService;
use App\Service\FeoWeightChangeNotificationService;

$schema = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allowed = array_filter(array_map('trim', explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'))));
if (getenv('APP_ENV') !== 'test' || $schema === '' || !in_array($schema, $allowed, true)) throw new RuntimeException('Guarded disposable test database required.');
$pdo = new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306).';dbname='.$schema.';charset=utf8mb4', getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$count = 0;
$ok = static function (bool $condition, string $message) use (&$count): void { if (!$condition) throw new RuntimeException($message); $count++; };
$reject = static function (callable $action, string $message) use ($ok): void { try { $action(); } catch (RuntimeException) { $ok(true, $message); return; } throw new RuntimeException($message); };
$legacySettings = [];
foreach (['FEO_LEGACY_SYNC_TENANT_DATABASE','FEO_LEGACY_SYNC_DATABASE','FEO_LEGACY_SYNC_CONNECTION_B64'] as $key) $legacySettings[$key] = getenv($key);
cleanCompanyPoolDatabase($pdo);
try {
    $service = new FeoWeightBatchService();
    $ok(!$service->ready($pdo), 'Pre-migration state');
    foreach (['122_create_feo_requests.sql', '133_create_max_integration.sql', '136_create_max_event_notifications.sql', '160_create_feo_weight_batches.sql', '162_feo_weight_legacy_snapshot.sql'] as $file) $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/'.$file));
    $ok($service->ready($pdo), 'Schema ready');
    $ddl=(string)$pdo->query('SHOW CREATE TABLE feo_requests')->fetchColumn(1);
    $ddl=preg_replace('/,\n\s+CONSTRAINT[^\n]+/','',$ddl);
    $pdo->exec(str_replace('CREATE TABLE `feo_requests`','CREATE TABLE `feo`',$ddl));
    $pdo->exec('ALTER TABLE feo DROP COLUMN current_import_id, MODIFY mass_netto DECIMAL(10,3), MODIFY mass_brutto DECIMAL(10,2), ADD legacy_note VARCHAR(50) NULL');
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto,mass_brutto) VALUES('100001',1.2500,1.5000),('100002',2.0000,2.3000)");
    $pdo->exec("INSERT INTO feo(zayavka_id,mass_netto,mass_brutto,legacy_note) VALUES('100001',1.250,1.50,'Сохранить'),('100002',2.000,2.30,'Сохранить')");
    putenv('FEO_LEGACY_SYNC_TENANT_DATABASE='.$schema);
    putenv('FEO_LEGACY_SYNC_DATABASE='.$schema);
    putenv('FEO_LEGACY_SYNC_CONNECTION_B64');
    $actor = ['created_by_user_id'=>11, 'created_by_display_name'=>'Менеджер'];
    $parsedIds = FeoWeightBatchService::parseIds('100001, 100002, 100001, 100001');
    $ok($parsedIds['ids'] === ['100001','100002'] && $parsedIds['duplicates'] === 2, 'Duplicate summary counts removed occurrences');
    $ok(FeoWeightBatchService::tonnesFromKg('1 250,1') === '1.2501', 'Kilograms convert to decimal tonnes');
    $reject(fn()=>FeoWeightBatchService::tonnesFromKg('-10'), 'Negative mass rejected');
    $reject(fn()=>FeoWeightBatchService::tonnesFromKg('1.25'), 'Excess precision rejected');
    $reject(fn()=>$service->preview($pdo, ['100001', '999999']), 'Missing request rejects whole block');
    $preview = $service->preview($pdo, ['100001', '100002']);
    $pdo->exec("UPDATE feo_requests SET mass_brutto=1.6000 WHERE zayavka_id='100001'");
    $targets = ['100001'=>['netto_kg'=>'1300', 'brutto_kg'=>'1700'], '100002'=>['netto_kg'=>'2000', 'brutto_kg'=>'2400']];
    $reject(fn()=>$service->create($pdo, $preview, $targets, $actor, hash('sha256','stale'), '', ''), 'Stale preview rejected');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM feo_weight_batches')->fetchColumn() === 0, 'No partial stale block');
    $preview = $service->preview($pdo, ['100001', '100002']);
    $grossOnly = FeoWeightChangeNotificationService::change($preview['rows'][0], ['zayavka_id'=>'100001','mass_netto'=>'1.2500','mass_brutto'=>'1.7000']);
    $ok($grossOnly !== null && $grossOnly['gross_difference_kg'] === 100.0, 'Gross-only change creates MAX notification');
    $result = $service->create($pdo, $preview, $targets, $actor, hash('sha256','apply'), 'Клиентская корректировка', 'По просьбе клиента');
    $batchId = $result['batch_id'];
    $ok($result['count'] === 2, 'Per-request values saved in one block');
    $rows = $pdo->query('SELECT zayavka_id,mass_netto,mass_brutto FROM feo_requests ORDER BY zayavka_id')->fetchAll(PDO::FETCH_ASSOC);
    $ok($rows[0]['mass_netto'] === '1.3000' && $rows[0]['mass_brutto'] === '1.7000' && $rows[1]['mass_brutto'] === '2.4000', 'Both weights persisted separately');
    $legacyRows = $pdo->query('SELECT zayavka_id,mass_netto,mass_brutto,legacy_note FROM feo ORDER BY zayavka_id')->fetchAll(PDO::FETCH_ASSOC);
    $ok($legacyRows[0]['mass_netto']==='1.300' && $legacyRows[0]['mass_brutto']==='1.70' && $legacyRows[1]['mass_brutto']==='2.40' && $legacyRows[0]['legacy_note']==='Сохранить', 'Legacy weights mirrored without changing other fields');
    $snapshots=$service->items($pdo,$batchId);
    $ok(count($snapshots)===2 && json_decode($snapshots[0]['legacy_snapshot_json'],true)['before']['mass_brutto']==='1.50','Legacy before values saved per item');
    $repeat = $service->create($pdo, $preview, $targets, $actor, hash('sha256','apply'), '', '');
    $ok(!empty($repeat['already_applied']) && $repeat['batch_id'] === $batchId, 'Retry is idempotent');
    $pdo->exec("UPDATE feo_requests SET mass_brutto=2.5000 WHERE zayavka_id='100002'");
    $reject(fn()=>$service->revert($pdo, $batchId, $actor), 'Later gross change prevents whole-block revert');
    $ok($pdo->query("SELECT mass_netto FROM feo_requests WHERE zayavka_id='100001'")->fetchColumn() === '1.3000', 'Rejected revert rolls back earlier request');
    $ok($pdo->query("SELECT mass_netto FROM feo WHERE zayavka_id='100001'")->fetchColumn() === '1.300', 'Rejected revert retains legacy weight');
    $pdo->exec("UPDATE feo_requests SET mass_brutto=2.4000 WHERE zayavka_id='100002'");
    $pdo->exec("UPDATE feo SET mass_brutto=9.99 WHERE zayavka_id='100002'");
    $reject(fn()=>$service->revert($pdo, $batchId, $actor), 'Later legacy weight change prevents whole-block revert');
    $ok($pdo->query("SELECT mass_netto FROM feo_requests WHERE zayavka_id='100001'")->fetchColumn() === '1.3000', 'Legacy conflict rolls back tenant changes');
    $pdo->exec("UPDATE feo SET mass_brutto=2.40 WHERE zayavka_id='100002'");
    $returned = $service->revert($pdo, $batchId, $actor);
    $ok($returned['count'] === 2, 'Whole block reverted');
    $rows = $pdo->query('SELECT zayavka_id,mass_netto,mass_brutto FROM feo_requests ORDER BY zayavka_id')->fetchAll(PDO::FETCH_ASSOC);
    $ok($rows[0]['mass_netto'] === '1.2500' && $rows[0]['mass_brutto'] === '1.6000' && $rows[1]['mass_brutto'] === '2.3000', 'Original net and gross restored');
    $ok($pdo->query("SELECT mass_brutto FROM feo WHERE zayavka_id='100001'")->fetchColumn() === '1.50', 'Original legacy gross restored');
    $ok(!empty($service->revert($pdo, $batchId, $actor)['already_reverted']), 'Revert retry is idempotent');
    // The compact editor must preserve the service's distinction between empty, zero and 0.1 kg.
    putenv('FEO_LEGACY_SYNC_TENANT_DATABASE=');
    putenv('FEO_LEGACY_SYNC_DATABASE=');
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto,mass_brutto) VALUES('100003',NULL,NULL),('100004',0,0),('100005',0.0001,0.0002)");
    $edgePreview = $service->preview($pdo, ['100003', '100004', '100005']);
    $ok(FeoWeightBatchService::tonnesFromKg('') === null && FeoWeightBatchService::tonnesFromKg('0') === '0.0000' && FeoWeightBatchService::tonnesFromKg('0,1') === '0.0001', 'Blank, zero and tenths convert distinctly');
    $sameTargets = [
        '100003'=>['netto_kg'=>'', 'brutto_kg'=>''],
        '100004'=>['netto_kg'=>'0', 'brutto_kg'=>'0'],
        '100005'=>['netto_kg'=>'0,1', 'brutto_kg'=>'0,2'],
    ];
    $reject(fn()=>$service->create($pdo, $edgePreview, $sameTargets, $actor, hash('sha256','edge-no-change'), '', ''), 'No-change values reject block across NULL, zero and tenths');
    $ok((int)$pdo->query('SELECT COUNT(*) FROM feo_weight_batches')->fetchColumn() === 1, 'No-change attempt creates no second block');
    $edgeTargets = [
        '100003'=>['netto_kg'=>'0', 'brutto_kg'=>'0,1'],
        '100004'=>['netto_kg'=>'', 'brutto_kg'=>''],
        '100005'=>['netto_kg'=>'0,2', 'brutto_kg'=>'0,3'],
    ];
    $edgeResult = $service->create($pdo, $edgePreview, $edgeTargets, $actor, hash('sha256','edge-apply'), '', '');
    $ok($edgeResult['count'] === 3, 'Blank, zero and tenths are actual changes');
    $ok($pdo->query('SELECT name FROM feo_weight_batches WHERE id='.(int)$edgeResult['batch_id'])->fetchColumn() === 'Изменение веса заявок', 'Compact form uses existing default block name');
    $edgeRows = $pdo->query("SELECT zayavka_id,mass_netto,mass_brutto FROM feo_requests WHERE zayavka_id IN ('100003','100004','100005') ORDER BY zayavka_id")->fetchAll(PDO::FETCH_ASSOC);
    $ok($edgeRows[0]['mass_netto'] === '0.0000' && $edgeRows[0]['mass_brutto'] === '0.0001' && $edgeRows[1]['mass_netto'] === null && $edgeRows[1]['mass_brutto'] === null && $edgeRows[2]['mass_netto'] === '0.0002' && $edgeRows[2]['mass_brutto'] === '0.0003', 'Distinct edge values persisted exactly');
    $ok($service->revert($pdo, $edgeResult['batch_id'], $actor)['count'] === 3, 'Edge-value block reverts');
    echo "FEO_WEIGHT_BATCHES_MYSQL_PASS checks=$count\n";
} finally {
    foreach ($legacySettings as $key=>$value) putenv($value===false?$key:$key.'='.$value);
    cleanCompanyPoolDatabase($pdo);
}
