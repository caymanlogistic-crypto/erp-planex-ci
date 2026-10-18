<?php

require __DIR__ . '/../app/Security/PageRegistry.php';
require __DIR__ . '/../app/Security/EffectiveAccess.php';
require __DIR__ . '/../app/Security/RegistryUiStateScope.php';

use App\Security\EffectiveAccess;
use App\Security\RegistryUiStateScope;

$one = EffectiveAccess::member(11, 4, ['company.trips.waste' => true, 'company.finance.invoices' => false], ['waste_trip' => 'own', 'client' => 'all']);
$reordered = EffectiveAccess::member(11, 4, ['company.finance.invoices' => false, 'company.trips.waste' => true], ['client' => 'all', 'waste_trip' => 'own']);
$grantChanged = EffectiveAccess::member(11, 4, ['company.trips.waste' => true, 'company.finance.invoices' => true], ['waste_trip' => 'own', 'client' => 'all']);
$scopeChanged = EffectiveAccess::member(11, 4, ['company.trips.waste' => true, 'company.finance.invoices' => false], ['waste_trip' => 'all', 'client' => 'all']);
$base = RegistryUiStateScope::fingerprint(7, $one);
$check = static function (bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
};
$check($base === RegistryUiStateScope::fingerprint(7, $reordered), 'permission and scope order must not change state key');
$check($base !== RegistryUiStateScope::fingerprint(7, $grantChanged), 'changed page grant must change state key');
$check($base !== RegistryUiStateScope::fingerprint(7, $scopeChanged), 'changed data scope must change state key');
$check($base !== RegistryUiStateScope::fingerprint(8, $one), 'changed account must change state key');
$check($base !== RegistryUiStateScope::fingerprint(7, EffectiveAccess::member(12, 4, ['company.trips.waste' => true], ['waste_trip' => 'own'])), 'changed membership must change state key');
$check($base !== RegistryUiStateScope::fingerprint(7, EffectiveAccess::member(11, 5, ['company.trips.waste' => true], ['waste_trip' => 'own'])), 'changed company must change state key');
echo "REGISTRY_UI_STATE_SCOPE=PASS\n";
