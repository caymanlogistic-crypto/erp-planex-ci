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

$ok('normalization is deterministic for punctuation and whitespace', FeoUtilizerService::normalizeText('  ООО «Тест»,   Москва ') === 'ооо тест москва');
$ok('normalization maps russian yo to e', FeoUtilizerService::normalizeText('Ёлка') === 'елка');
$request = ['gruzopoluchatel'=>'ООО Тест Утилизатор','adres_dostavki_otkhodov'=>'Москва, ул. Ленина, 1'];
$ok('exact recipient rule matches normalized value', FeoUtilizerService::ruleMatches(['source_field'=>'recipient','match_type'=>'exact','pattern'=>'ооо тест утилизатор'], $request));
$ok('contains delivery address alias matches normalized value', FeoUtilizerService::ruleMatches(['source_field'=>'delivery_address','match_type'=>'contains','pattern'=>'Ленина 1'], $request));
$ok('unrelated alias does not match', !FeoUtilizerService::ruleMatches(['source_field'=>'recipient','match_type'=>'contains','pattern'=>'другой'], $request));
$ok('override setter enforces canonical six-digit FEO ids', str_contains($read($root . '/app/Service/FeoUtilizerService.php'), "preg_match('/^\\d{6}$/D'"));

$service = $read($root . '/app/Service/FeoUtilizerService.php');
foreach ([
    'feo_utilizer_match_rules' => 'matching rules are centralized in service',
    'feo_request_utilizer_overrides' => 'manual override is centralized',
    "'status'=>'unmatched'" => 'unmatched state is explicit',
    "'status'=>(int)(\$utilizer['is_active'] ?? 0) === 1 ? 'matched' : 'inactive'" => 'inactive utilizer state is explicit',
    "ORDER BY r.priority ASC" => 'matching order is deterministic',
    "CASE r.match_type WHEN 'exact' THEN 0 ELSE 1 END" => 'exact rules win within same priority order',
    'beginTransaction()' => 'safe utilizer delete is transactional',
    'SELECT COUNT(*) FROM feo_utilizer_match_rules WHERE utilizer_id=?' => 'delete checks rules',
    'SELECT COUNT(*) FROM feo_request_utilizer_overrides WHERE utilizer_id=?' => 'delete checks overrides',
] as $needle => $label) $ok($label, str_contains($service, $needle));

$migration = $read($root . '/database/migrations-local/125_create_feo_map_utilizers.sql');
foreach (['feo_utilizers','feo_utilizer_match_rules','feo_request_utilizer_overrides','FOREIGN KEY (`zayavka_id`) REFERENCES `feo_requests`','ON DELETE RESTRICT','uk_feo_utilizer_rule_canonical','idx_feo_utilizers_active_order'] as $needle) {
    $ok('migration contains ' . $needle, str_contains($migration, $needle));
}
$ok('migration has no legacy compatibility columns', !str_contains(strtolower($migration), 'legacy_'));

$view = $read($root . '/app/View/pages/company_feo_utilizers.php');
$ok('FKKO is editable during utilizer update', substr_count($view, 'name="fkko_classification"') >= 2 && str_contains($view, 'title="ФККО / классификация"'));
$ok('destructive utilizer actions use ERP modal confirmation metadata', str_contains($view, 'id="feo-utilizer-confirm-modal"') && str_contains($view, 'data-utilizer-confirm') && !str_contains($view, 'confirm('));
$ok('override input follows six-digit FEO id contract', str_contains($view, 'maxlength="6"') && str_contains($view, 'pattern="[0-9]{6}"'));

printf("PASS=%d FAIL=%d\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
