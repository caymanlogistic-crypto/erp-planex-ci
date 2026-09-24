<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root.'/app/Security/PageRegistry.php';
$page = (new App\Security\PageRegistry())->page('company.statistics.feo_share');
if (!is_array($page) || $page['path'] !== '/company/statistics/feo-share' || $page['section'] !== 'Статистика' || !$page['grantable']) throw new RuntimeException('FEO statistics page must be grantable in Statistics');
$routes = (string)file_get_contents($root.'/app/Http/Routes/company_statistics.php');
if (!str_contains($routes, "'company.statistics.feo_share'")) throw new RuntimeException('Protected route missing');
$data = json_decode((string)file_get_contents($root.'/resources/data/feo_share_baseline.json'), true, 512, JSON_THROW_ON_ERROR);
if (array_column($data['snapshots'], 'feo_count') !== [3066,2987,3127,3311]) throw new RuntimeException('Accepted real FEO history changed');
if (array_sum(array_column($data['districts'], 'feo_count')) !== 3311) throw new RuntimeException('Baseline geography differs from file');
$js = (string)file_get_contents($root.'/public/assets/js/feo-share-statistics.js');
if (!str_contains($js, "getElementById('feo-share-data')") || str_contains($js, 'Данные не обновляются автоматически')) throw new RuntimeException('Report must use live data');
$migration = (string)file_get_contents($root.'/database/migrations-local/145_create_feo_share_statistics.sql');
$workflow = (string)file_get_contents($root.'/.github/workflows/feo_share_145_production_migration.yml');
$runner = (string)file_get_contents($root.'/scripts/feo_share_migration_145.php');
if (substr_count($migration, 'CREATE TABLE IF NOT EXISTS') !== 2 || !str_contains($workflow, 'FEO_145_BUSINESS_DATA_MUTATION=0') || !str_contains($runner, 'APPLY_FEO_SHARE_145')) throw new RuntimeException('Guarded migration workflow missing');
echo "FEO_SHARE_STATISTICS_CONTRACT=PASS\n";
