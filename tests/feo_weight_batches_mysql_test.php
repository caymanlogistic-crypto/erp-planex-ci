<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Service/FeoTransferService.php';
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
cleanCompanyPoolDatabase($pdo);
try {
    $service = new FeoWeightBatchService();
    $ok(!$service->ready($pdo), 'Pre-migration state');
    foreach (['122_create_feo_requests.sql', '133_create_max_integration.sql', '136_create_max_event_notifications.sql', '160_create_feo_weight_batches.sql'] as $file) $pdo->exec(file_get_contents(BASE_PATH.'/database/migrations-local/'.$file));
    $ok($service->ready($pdo), 'Schema ready');
    $pdo->exec("INSERT INTO feo_requests(zayavka_id,mass_netto,mass_brutto) VALUES('100001',1.2500,1.5000),('100002',2.0000,2.3000)");
    $actor = ['created_by_user_id'=>11, 'created_by_display_name'=>'Менеджер'];
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
    $repeat = $service->create($pdo, $preview, $targets, $actor, hash('sha256','apply'), '', '');
    $ok(!empty($repeat['already_applied']) && $repeat['batch_id'] === $batchId, 'Retry is idempotent');
    $pdo->exec("UPDATE feo_requests SET mass_brutto=2.5000 WHERE zayavka_id='100002'");
    $reject(fn()=>$service->revert($pdo, $batchId, $actor), 'Later gross change prevents whole-block revert');
    $ok($pdo->query("SELECT mass_netto FROM feo_requests WHERE zayavka_id='100001'")->fetchColumn() === '1.3000', 'Rejected revert rolls back earlier request');
    $pdo->exec("UPDATE feo_requests SET mass_brutto=2.4000 WHERE zayavka_id='100002'");
    $returned = $service->revert($pdo, $batchId, $actor);
    $ok($returned['count'] === 2, 'Whole block reverted');
    $rows = $pdo->query('SELECT zayavka_id,mass_netto,mass_brutto FROM feo_requests ORDER BY zayavka_id')->fetchAll(PDO::FETCH_ASSOC);
    $ok($rows[0]['mass_netto'] === '1.2500' && $rows[0]['mass_brutto'] === '1.6000' && $rows[1]['mass_brutto'] === '2.3000', 'Original net and gross restored');
    $ok(!empty($service->revert($pdo, $batchId, $actor)['already_reverted']), 'Revert retry is idempotent');
    echo "FEO_WEIGHT_BATCHES_MYSQL_PASS checks=$count\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
