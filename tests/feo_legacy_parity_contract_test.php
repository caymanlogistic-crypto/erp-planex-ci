<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Service/FeoLegacyParityService.php';

use App\Service\FeoLegacyParityService;

$pass = 0;
$ok = static function (bool $condition, string $message) use (&$pass): void {
    if (!$condition) throw new RuntimeException($message);
    $pass++;
};

$service = new FeoLegacyParityService();
$state = $service->compareRows([
    ['zayavka_id'=>'100001','mass_kg'=>1000.0,'source_block_id'=>1,'source_block_name'=>'A'],
    ['zayavka_id'=>'100002','mass_kg'=>2000.0,'source_block_id'=>2,'source_block_name'=>'B'],
    ['zayavka_id'=>'100003','mass_kg'=>3000.0,'source_block_id'=>3,'source_block_name'=>'C'],
], [
    ['zayavka_id'=>'100001','mass_netto'=>1.0],
    ['zayavka_id'=>'100002','mass_netto'=>2.5],
    ['zayavka_id'=>'100004','mass_netto'=>4.0],
]);

$ok($state['mismatch_count'] === 3, 'three request-level discrepancies must be counted');
$ok($state['new']['request_count'] === 3 && $state['legacy']['request_count'] === 3, 'equal aggregate counts remain factual');
$ok(abs((float)$state['new']['total_mass_kg'] - 6000.0) < 0.001, 'ERP total mass');
$ok(abs((float)$state['legacy']['total_mass_kg'] - 7500.0) < 0.001, 'legacy total mass');
$ok(array_column($state['only_new'], 'zayavka_id') === ['100003'], 'only ERP request is identified');
$ok(array_column($state['only_legacy'], 'zayavka_id') === ['100004'], 'only legacy request is identified');
$ok(array_column($state['mass_mismatches'], 'zayavka_id') === ['100002'], 'same-ID mass mismatch is identified');
$ok($state['only_new_summary']['request_count'] === 1 && abs((float)$state['only_new_summary']['total_mass_kg'] - 3000.0) < 0.001, 'only ERP summary');
$ok($state['only_legacy_summary']['request_count'] === 1 && abs((float)$state['only_legacy_summary']['total_mass_kg'] - 4000.0) < 0.001, 'only legacy summary');
$ok($state['is_consistent'] === false, 'mismatch state is not consistent');

$equal = $service->compareRows([
    ['zayavka_id'=>'200001','mass_kg'=>1250.0,'source_block_id'=>7,'source_block_name'=>'QA'],
], [
    ['zayavka_id'=>'200001','mass_netto'=>1.25],
]);
$ok($equal['is_consistent'] === true && $equal['mismatch_count'] === 0, 'identical snapshots pass');

$source = (string)file_get_contents($root . '/app/Service/FeoLegacyParityService.php');
$legacyConnector = (string)file_get_contents($root . '/app/Service/WasteTripLegacySourceService.php');
$view = (string)file_get_contents($root . '/app/View/pages/company_feo_map_placement.php');
$js = (string)file_get_contents($root . '/public/assets/js/feo-map-placement.js');
$nav = (string)file_get_contents($root . '/app/Service/NavigationCounterService.php');
$layout = (string)file_get_contents($root . '/app/View/layouts/main.php');

$ok(str_contains($source, "TABLE_NAME='feo'"), 'legacy source is fixed to legacy feo table');
$ok(str_contains($source, "['zayavka_id', 'Заявка, ID', 'Заявка ID', 'request_id']"), 'legacy request ID requires explicit semantic column aliases');
$ok(!str_contains($source, "['id',") && !str_contains($source, "'idColumn'=>'id'"), 'generic legacy id must never be guessed as request ID');
$ok(str_contains($source, 'WasteTripLegacySourceService::connectionFromEnvironment()'), 'existing legacy connection contract is reused');
$ok(str_contains($legacyConnector, 'SET SESSION TRANSACTION READ ONLY'), 'legacy PDO is read-only');
$ok(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b[^\n]*`feo`/i', $source), 'parity service never mutates legacy feo');
$ok(str_contains($source, 'feo_placement_current') && str_contains($source, 'source_block_id'), 'ERP comparison uses current placement and block ownership');
$ok(str_contains($source, 'feo_placement_block_requests') && str_contains($source, 'ORDER BY b.id DESC'), 'mismatch rows expose block history');
$ok(str_contains($nav, 'FeoLegacyParityService') && str_contains($nav, '$legacyProblem > 0 ? $legacyProblem : $internalProblem'), 'menu badge prefers request-level legacy mismatch count and preserves internal fallback');
$ok(str_contains($layout, 'Расхождения размещения со старой базой или блоками'), 'menu badge title explains combined attention source');
$ok(str_contains($view, 'data-placement-parity-warning') && str_contains($view, 'Показать расхождения'), 'yellow page warning exposes details action');
$ok(str_contains($view, 'feo-placement-parity-modal') && str_contains($view, 'data-placement-parity-body'), 'system modal exists');
$ok(str_contains($view, 'legacyParity: <?= json_encode($legacyParity'), 'server parity payload is passed to page script safely');
$ok(str_contains($js, 'renderLegacyParity') && str_contains($js, "openModal('feo-placement-parity-modal')"), 'system popup is rendered through existing modal flow');
$ok(str_contains($js, 'Есть только в новой ERP') && str_contains($js, 'Есть только в старой базе') && str_contains($js, 'Одинаковый ID, но отличается вес'), 'popup has all discrepancy sections');
$ok(str_contains($js, 'parityBlockText') && str_contains($js, 'Блок / история'), 'popup shows block placement/history');

printf("FEO_LEGACY_PARITY_CONTRACT=PASS checks=%d\n", $pass);
