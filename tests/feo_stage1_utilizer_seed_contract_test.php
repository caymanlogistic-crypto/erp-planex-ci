<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Service/FeoUtilizerService.php';

use App\Service\FeoUtilizerService;

$pass = 0; $fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) { $pass++; return; }
    $fail++; fwrite(STDERR, "FAIL: {$name}\n");
};
$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($path) ?: '';
$seed = require $root . '/scripts/feo_stage1_utilizer_seed_data.php';
$utilizers = $seed['utilizers'] ?? [];
$rules = $seed['rules'] ?? [];

$ok('legacy utilizer seed has exactly eight rows', is_array($utilizers) && count($utilizers) === 8);
$ok('explicit rule seed has expected cardinality', is_array($rules) && count($rules) === 38);
$shortNames = array_column($utilizers, 'short_name');
$ok('legacy short names preserved', $shortNames === ['КУБАНЬЦВЕТМЕТ','ЛИМПЭК','МЕРКОМ','НЭК','ЭКО+','ПРОМХИМЛАБ','ЛИПЕЦКЭКОХИМ','ФРЕГАТ']);
$adr = [];
foreach ($utilizers as $u) $adr[(string)$u['short_name']] = (int)$u['adr'];
$ok('legacy ADR flags preserved', $adr === ['КУБАНЬЦВЕТМЕТ'=>0,'ЛИМПЭК'=>1,'МЕРКОМ'=>0,'НЭК'=>0,'ЭКО+'=>1,'ПРОМХИМЛАБ'=>1,'ЛИПЕЦКЭКОХИМ'=>1,'ФРЕГАТ'=>0]);
$ok('all utilizer colors are explicit hex', count(array_filter($utilizers, static fn(array $u): bool => preg_match('/^#[0-9A-F]{6}$/D', (string)$u['color_hex']) === 1)) === 8);

$canon = [];
foreach ($rules as $rule) {
    $key = $rule['source_field'] . '|' . $rule['match_type'] . '|' . FeoUtilizerService::normalizeText((string)$rule['pattern']);
    $canon[$key] = ($canon[$key] ?? 0) + 1;
}
$ok('seed rule canonical keys are unique', max($canon) === 1 && count($canon) === count($rules));
$unsafeContains = array_values(array_filter($rules, static fn(array $r): bool => $r['match_type'] === 'contains' && in_array($r['pattern'], ['НЭК','ЭКО+'], true)));
$ok('ambiguous short aliases are not contains rules', $unsafeContains === []);
foreach ($utilizers as $u) {
    $short = (string)$u['short_name'];
    $ok("{$short}: exact full-name rule", count(array_filter($rules, static fn(array $r): bool => $r['utilizer'] === $short && $r['source_field'] === 'recipient' && $r['match_type'] === 'exact' && $r['pattern'] === $u['full_name'])) === 1);
    $ok("{$short}: exact delivery-address rule", count(array_filter($rules, static fn(array $r): bool => $r['utilizer'] === $short && $r['source_field'] === 'delivery_address' && $r['match_type'] === 'exact' && $r['pattern'] === $u['address'])) === 1);
}

$writer = $read($root . '/scripts/feo_seed_stage1_utilizers.php');
$smoke = $read($root . '/scripts/feo_smoke_stage1_map.php');
$workflow = $read($root . '/.github/workflows/feo_stage1_utilizers_production_seed.yml');
$contractWorkflow = $read($root . '/.github/workflows/feo_stage1_utilizers_seed_contract.yml');

$ok('writer hard-targets PLANEX company 25', str_contains($writer, '$targetCompanyId !== 25') && str_contains($writer, "'spugovxsim_1'"));
$ok('writer requires migration 125 journal', str_contains($writer, '125_create_feo_map_utilizers.sql'));
$ok('writer uses advisory lock and transaction', str_contains($writer, 'GET_LOCK') && str_contains($writer, 'beginTransaction()') && str_contains($writer, 'rollBack()'));
$ok('writer is additive only', !preg_match('/\bDELETE\s+FROM\b/i', $writer) && !preg_match('/\bUPDATE\s+feo_utilizers\b/i', $writer) && !preg_match('/\bUPDATE\s+feo_utilizer_match_rules\b/i', $writer));
$ok('writer refuses drift instead of overwriting', str_contains($writer, 'refusing to overwrite'));
$ok('writer does not touch overrides', !str_contains($writer, 'INSERT INTO feo_request_utilizer_overrides'));
$ok('smoke runs canonical map service', str_contains($smoke, 'new App\\Service\\FeoMapService') && str_contains($smoke, 'availableRows($pdo)') && str_contains($smoke, 'buildPayload'));
$ok('smoke reports unmatched diagnostics', str_contains($smoke, 'unmatched_samples') && str_contains($smoke, 'missing_coordinates_count') && str_contains($smoke, 'invalid_coordinates_count'));
$ok('production seed workflow is manual-only', str_contains($workflow, "\n  workflow_dispatch:") && !str_contains($workflow, "\n  push:") && !str_contains($workflow, "\n  pull_request:") && !str_contains($workflow, "\n  workflow_run:") && !str_contains($workflow, "\n  schedule:"));
$ok('production seed workflow has explicit confirmation token', str_contains($workflow, 'APPLY_FEO_STAGE1_UTILIZERS'));
$ok('production workflow snapshots before and after', str_contains($workflow, 'Snapshot before') && str_contains($workflow, 'Seed PLANEX utilizer master-data and rules') && str_contains($workflow, 'Smoke after and verify invariants'));
$ok('production workflow emits evidence and HTTP acceptance', str_contains($workflow, 'Upload seed evidence') && str_contains($workflow, 'HTTP acceptance'));
$ok('contract workflow covers writer smoke data and workflow', str_contains($contractWorkflow, 'feo_stage1_utilizer_seed_contract_test.php') && str_contains($contractWorkflow, 'feo_stage1_utilizers_production_seed.yml'));

printf("PASS=%d FAIL=%d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
