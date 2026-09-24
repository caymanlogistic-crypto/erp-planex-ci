<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/bootstrap/app.php';
require_once BASE_PATH.'/app/Support/company_database.php';
require_once BASE_PATH.'/app/Core/Database.php';
require_once BASE_PATH.'/app/Service/FeoXlsxParser.php';
require_once BASE_PATH.'/app/Service/FeoRequestService.php';
require_once BASE_PATH.'/app/Service/FeoShareStatisticsService.php';

use App\Service\FeoRequestService;
use App\Service\FeoShareStatisticsService;
use App\Service\FeoXlsxParser;

$db = trim((string)getenv('PLANEX_TEST_FIXED_DB'));
$allow = explode(',', (string)getenv('PLANEX_TEST_FIXED_DB_ALLOWLIST'));
if (getenv('APP_ENV') !== 'test' || !preg_match('/^[A-Za-z0-9_]+$/D', $db) || !in_array($db, $allow, true)) throw new RuntimeException('Isolated test database required');
$pdo = new PDO('mysql:host='.getenv('PLANEX_SCHEMA_DB_HOST').';port='.(getenv('PLANEX_SCHEMA_DB_PORT') ?: 3306).';dbname='.$db.';charset=utf8mb4', getenv('PLANEX_SCHEMA_DB_USER'), getenv('PLANEX_SCHEMA_DB_PASSWORD'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
cleanCompanyPoolDatabase($pdo);
try {
    foreach (['122_create_feo_requests.sql','123_create_feo_map_placement.sql','145_create_feo_share_statistics.sql'] as $migration) $pdo->exec((string)file_get_contents(BASE_PATH.'/database/migrations-local/'.$migration));
    if (!FeoShareStatisticsService::schemaReady($pdo)) throw new RuntimeException('Snapshot schema not ready');
    $service = new FeoRequestService([], new App\Core\Database([]));
    $row = static fn(string $id, string $kg, string $region): array => array_replace(array_fill_keys(FeoXlsxParser::fields(), null), ['zayavka_id'=>$id,'mass_netto'=>(string)((float)$kg/1000),'mno_region'=>$region]);
    $a = $service->applyImport($pdo, [$row('a','1000','Москва'),$row('b','2000','Москва')], 'full-2026-09-26.xlsx', hash('sha256','a'), []);
    $pdo->exec("INSERT INTO feo_placement_blocks(id,block_type,status) VALUES(1,'addition','applied')");
    $pdo->exec("INSERT INTO feo_placement_block_requests(block_id,zayavka_id) VALUES(1,'b')");
    $pdo->exec("INSERT INTO feo_placement_current(zayavka_id,source_block_id) VALUES('b',1)");
    $b = $service->applyImport($pdo, [$row('b','2500','Москва'),$row('c','3000','Алтайский край')], 'full-2026-09-27.xlsx', hash('sha256','b'), []);
    $totals = $pdo->query('SELECT * FROM feo_share_import_totals ORDER BY import_id')->fetchAll(PDO::FETCH_ASSOC);
    if (count($totals) !== 2 || (int)$totals[0]['feo_count'] !== 2 || (float)$totals[0]['feo_kg'] !== 3000.0 || (int)$totals[1]['company_count'] !== 1 || (float)$totals[1]['company_kg'] !== 2500.0) throw new RuntimeException('Import-time paired totals incorrect');
    $dashboard = FeoShareStatisticsService::dashboard($pdo);
    $latest = end($dashboard['snapshots']);
    if ($latest['added_count'] !== 1 || $latest['added_kg'] !== 3000.0 || $latest['gone_count'] !== 1 || $latest['gone_kg'] !== 1000.0) throw new RuntimeException('File-set movement incorrect');
    if ($dashboard['current']['own_count'] !== 1 || $dashboard['current']['own_kg'] !== 2500.0) throw new RuntimeException('Live company total incorrect');
    $service->rollbackLatest($pdo, $b['import_id'], []);
    $rolled = FeoShareStatisticsService::dashboard($pdo);
    if (count($rolled['snapshots']) !== count($dashboard['snapshots'])-1) throw new RuntimeException('Rolled-back import remained visible');
    echo "FEO_SHARE_STATISTICS_MYSQL=PASS\n";
} finally {
    cleanCompanyPoolDatabase($pdo);
}
