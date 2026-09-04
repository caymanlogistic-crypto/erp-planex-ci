<?php

require_once __DIR__ . '/../app/Service/IdentityNormalizer.php';
require_once __DIR__ . '/../app/Service/CompanyRoleService.php';

use App\Service\CompanyRoleService;
use App\Service\IdentityNormalizer;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expect(IdentityNormalizer::login('  User_Name  ') === 'user_name', 'login normalization failed');
expect(IdentityNormalizer::roleName("  Руководитель\t ") === 'руководитель', 'Cyrillic role normalization failed');
expect(IdentityNormalizer::roleName('  Старший   Логист ') === 'старший логист', 'role whitespace normalization failed');
expect(CompanyRoleService::regulatedEntities() === [
    'client', 'contractor', 'vehicle_unit', 'driver', 'linear_trip', 'waste_trip',
], 'regulated entity contract drift');

$membershipSource = file_get_contents(__DIR__ . '/../app/Service/CompanyMembershipService.php');
$roleSource = file_get_contents(__DIR__ . '/../app/Service/CompanyRoleService.php');
$switchSource = file_get_contents(__DIR__ . '/../app/Service/CompanySwitchService.php');
$authSource = file_get_contents(__DIR__ . '/../app/Service/AccountAuthenticationService.php');
$authControllerSource = file_get_contents(__DIR__ . '/../app/Http/Controllers/AuthController.php');
$adminControllerSource = file_get_contents(__DIR__ . '/../app/Http/Controllers/Company/RolesAccessController.php');

expect(str_contains($membershipSource, 'FOR UPDATE'), 'membership/last-owner writes must lock rows');
expect(str_contains($membershipSource, 'account_id = ? AND company_id = ?'), 'membership uniqueness must be account+company scoped');
expect(str_contains($membershipSource, 'membership_page_overrides'), 'atomic member save must include page overrides');
expect(str_contains($membershipSource, 'membership_data_scope_overrides'), 'atomic member save must include data overrides');
expect(str_contains($membershipSource, 'WHERE m.company_id = ?'), 'membership administration reads must be company scoped');
expect(!str_contains($membershipSource, 'SELECT m.*, a.*'), 'membership list must not expose full account records');
expect(str_contains($membershipSource, 'Руководителя нельзя ограничить'), 'owner override protection missing');
expect(str_contains($roleSource, 'is_system'), 'system role protection missing');
expect(str_contains($roleSource, 'Сначала назначьте им другую роль'), 'used-role delete guard missing');
expect(str_contains($switchSource, "\$session['account_id']"), 'switch must use global account id');
expect(!str_contains($switchSource, "\$session['role_id'] ="), 'switch must not cache role id');
expect(str_contains($authSource, "unset(\$session['role_code']"), 'global login must not cache company role');
expect(str_contains($authControllerSource, 'AccessStateProvider'), 'global auth must be gated by the access-state provider');
expect(str_contains($authControllerSource, 'tenant-local'), 'legacy tenant auth bypass guard missing');
expect(str_contains($adminControllerSource, 'AccessRuntime::current()->isSystemOwner()'), 'admin writes must require effective system owner');
expect(str_contains($adminControllerSource, 'requestJsonBody()'), 'admin endpoints must accept atomic JSON payloads');

echo "roles access identity/membership contract: PASS\n";
