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
$check($base === RegistryUiStateScope::fingerprint(7, $one, 99, 888), 'member state must ignore extraneous technical context');
$technical = EffectiveAccess::technicalSuperadmin(array_column(\App\Security\PageRegistry::companyPages(), 'key'));
$technicalBase = RegistryUiStateScope::fingerprint(0, $technical, 91, 4);
$check($technicalBase !== '', 'resolved technical context must persist');
$check($technicalBase !== RegistryUiStateScope::fingerprint(0, $technical, 91, 5), 'technical company switch must change state key');
$check($technicalBase !== RegistryUiStateScope::fingerprint(0, $technical, 92, 4), 'technical actor switch must change state key');
$check(RegistryUiStateScope::fingerprint(0, $technical, 0, 4) === '', 'unresolved technical user must disable persistence');
$check(RegistryUiStateScope::fingerprint(0, $technical, 91, 0) === '', 'unresolved technical company must disable persistence');
echo "REGISTRY_UI_STATE_SCOPE=PASS\n";
