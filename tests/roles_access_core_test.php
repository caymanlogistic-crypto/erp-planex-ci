<?php

$root = dirname(__DIR__);
require_once $root . '/app/Support/helpers.php';
require_once $root . '/app/Security/AccessStateProvider.php';
require_once $root . '/app/Security/AccessRequestContext.php';
require_once $root . '/app/Security/MembershipAccessSnapshot.php';
require_once $root . '/app/Security/EffectiveAccess.php';
require_once $root . '/app/Security/AccessRuntime.php';
require_once $root . '/app/Security/PageRegistry.php';
require_once $root . '/app/Security/EffectiveAccessResolver.php';
require_once $root . '/app/Service/AccessControlService.php';
require_once $root . '/app/Support/http_runtime.php';
require_once $root . '/app/Http/Router.php';

use App\Http\Router;
use App\Security\AccessRequestContext;
use App\Security\AccessStateProvider;
use App\Security\EffectiveAccessResolver;
use App\Security\MembershipAccessSnapshot;
use App\Security\PageRegistry;
use App\Service\AccessControlService;

final class TestAccessProvider implements AccessStateProvider
{
    public bool $ready = true;
    public bool $throw = false;
    public ?MembershipAccessSnapshot $snapshot = null;

    public function isCompanyAccessReady(int $companyId): bool
    {
        if ($this->throw) {
            throw new RuntimeException('provider unavailable');
        }
        return $this->ready;
    }

    public function loadMembershipAccess(int $accountId, int $companyId): ?MembershipAccessSnapshot
    {
        if ($this->throw) {
            throw new RuntimeException('provider unavailable');
        }
        return $this->snapshot;
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function memberSnapshot(
    array $rolePages = [],
    array $pageOverrides = [],
    array $roleScopes = [],
    array $scopeOverrides = [],
    string $systemKey = '',
    string $status = 'active',
    string $compatibilityRoleCode = 'custom_role',
): MembershipAccessSnapshot {
    return new MembershipAccessSnapshot(91, 7, $status, 12, $systemKey, $rolePages, $pageOverrides, $roleScopes, $scopeOverrides, $compatibilityRoleCode);
}

function dispatchOutput(Router $router, string $method, string $uri, array $server = []): array
{
    $_SERVER = $server;
    http_response_code(200);
    ob_start();
    $result = $router->dispatch($method, $uri);
    $output = ob_get_clean();

    return [$result, $output, http_response_code()];
}

$registry = new PageRegistry();
$provider = new TestAccessProvider();
$resolver = new EffectiveAccessResolver($registry, $provider);

expect($registry->hasPage('company.vehicle_units'), 'Canonical vehicle_unit page is missing.');
expect($registry->hasPage('company.vehicle_sets'), 'VehicleSet must remain a separate page.');
expect(($registry->page('company.vehicle_units')['nav_visible'] ?? true) === false, 'Dead vehicle_unit page must stay hidden from navigation.');
expect(!$registry->isGrantable('company.vehicle_units'), 'Dead vehicle_unit page must stay hidden from role administration.');
expect(($registry->page('company.vehicle_sets')['label'] ?? '') === 'ТС', 'VehicleSet must be shown as ТС.');
expect(in_array('company.vehicle_sets', array_column($registry->navigationPages(), 'key'), true), 'VehicleSet must remain visible in navigation.');
expect(in_array('company.vehicle_sets', $registry->grantablePageKeys(), true), 'VehicleSet must remain configurable in role administration.');
expect(in_array('company.warehouses.addresses', $registry->grantablePageKeys(), true), 'Warehouse addresses must be configurable in role administration.');
expect(in_array('company.warehouses.addresses', array_column($registry->grantableTree()['Операции']['Склады'] ?? [], 'key'), true), 'Warehouse addresses must appear in the warehouse role group.');
expect(count($registry->pageKeys()) === count(array_unique($registry->pageKeys())), 'Page keys must be unique.');
expect(($registry->page('company.documents.trip_closing')['path'] ?? '') === '/company/trips/closing-documents', 'Original collection route must remain unchanged.');
expect(($registry->page('company.documents.trip_closing_registry')['path'] ?? '') === '/company/logistics/closing-documents', 'New logistics registry must have its own route.');
expect(in_array('company.documents.trip_closing_registry', $registry->grantablePageKeys(), true), 'Logistics registry must be configurable in roles.');

$provider->snapshot = memberSnapshot(['company.documents.trip_closing']);
$closingAccess = $resolver->resolve(new AccessRequestContext(4, 7));
expect($closingAccess->canAccessPage('company.documents.trip_closing') && $closingAccess->canAccessPage('company.documents.trip_closing_registry'), 'Existing collection grant must preserve access to both pages.');
$provider->snapshot = memberSnapshot(['company.documents.trip_closing'], ['company.documents.trip_closing_registry' => 'deny']);
$closingAccess = $resolver->resolve(new AccessRequestContext(4, 7));
expect($closingAccess->canAccessPage('company.documents.trip_closing') && !$closingAccess->canAccessPage('company.documents.trip_closing_registry'), 'Explicit registry denial must override compatibility grant.');

$provider->snapshot = memberSnapshot(['company.warehouses.stock']);
expect($resolver->resolve(new AccessRequestContext(4, 7))->canAccessPage('company.warehouses.addresses'), 'Existing warehouse stock grant must keep address access until the role is saved.');
$provider->snapshot = new MembershipAccessSnapshot(91, 7, 'active', 12, '', ['company.warehouses.stock'], [], [], [], 'logist', ['company.warehouses.stock', 'company.warehouses.addresses']);
$warehouseAccess = $resolver->resolve(new AccessRequestContext(4, 7));
expect($warehouseAccess->canAccessPage('company.warehouses.stock') && !$warehouseAccess->canAccessPage('company.warehouses.addresses'), 'Explicitly disabled warehouse address permission must not inherit stock access.');
$provider->snapshot = memberSnapshot(['company.warehouses.addresses']);
$warehouseAccess = $resolver->resolve(new AccessRequestContext(4, 7));
expect($warehouseAccess->canAccessPage('company.warehouses.addresses') && !$warehouseAccess->canAccessPage('company.warehouses.stock'), 'Address permission must work independently of warehouse stock.');
$provider->snapshot = memberSnapshot(['company.warehouses.stock'], ['company.warehouses.addresses'=>'deny']);
expect(!$resolver->resolve(new AccessRequestContext(4, 7))->canAccessPage('company.warehouses.addresses'), 'Personal address denial must override inherited warehouse access.');

expect(in_array('company.trips.gps_coverage', $registry->grantablePageKeys(), true), 'GPS page must be configurable in roles.');
$provider->snapshot = memberSnapshot(['company.trips.waste'], [], ['waste_trip'=>'own']);
$gpsAccess = $resolver->resolve(new AccessRequestContext(4, 7));
expect($gpsAccess->canAccessPage('company.trips.gps_coverage'), 'Waste trip manager must see GPS page.');
$provider->snapshot = memberSnapshot(['company.trips.waste'], ['company.trips.gps_coverage'=>'deny'], ['waste_trip'=>'own']);
expect(!$resolver->resolve(new AccessRequestContext(4, 7))->canAccessPage('company.trips.gps_coverage'), 'Explicit GPS denial must prevail.');
$provider->snapshot = memberSnapshot(['company.trips.waste'], [], ['waste_trip'=>'none']);
expect(!$resolver->resolve(new AccessRequestContext(4, 7))->canAccessPage('company.trips.gps_coverage'), 'Manager with no waste-trip scope must not see GPS page.');

$provider->snapshot = memberSnapshot(
    ['company.clients', 'company.drivers'],
    ['company.clients' => 'deny', 'company.contractors' => 'allow'],
    ['client' => 'own', 'driver' => 'all'],
    ['client' => 'all', 'driver' => 'none'],
);
$access = $resolver->resolve(new AccessRequestContext(4, 7));
expect(!$access->canAccessPage('company.clients'), 'Personal deny must override role allow.');
expect($access->canAccessPage('company.contractors'), 'Personal allow must override role deny.');
expect($access->dataScope('client') === 'all', 'Personal data scope must override role scope.');
expect($access->dataScope('driver') === 'none', 'Personal none scope must override role all.');
expect($access->dataScope('not_registered') === 'none', 'Unknown data entity must fail closed.');

$provider->snapshot = memberSnapshot(
    ['company.responsible_assignments','company.roles_access','company.clients'],
    ['company.responsible_assignments'=>'allow','company.roles_access'=>'allow'],
    ['client'=>'own']
);
$access = $resolver->resolve(new AccessRequestContext(4, 7));
expect($access->canAccessPage('company.clients'), 'Ordinary grantable page unexpectedly denied.');
expect(!$access->canAccessPage('company.responsible_assignments'), 'Owner-only responsibility tool accepted ordinary role grant.');
expect(!$access->canAccessPage('company.roles_access'), 'Owner-only roles-access page accepted ordinary override.');

$provider->snapshot = memberSnapshot([], ['unknown.page' => 'allow'], ['client' => 'invalid']);
$access = $resolver->resolve(new AccessRequestContext(4, 7));
expect(!$access->canAccessPage('unknown.page'), 'Unknown page key must fail closed.');
expect($access->dataScope('client') === 'none', 'Malformed data scope must fail closed.');

$provider->snapshot = memberSnapshot([], [], [], [], 'company_owner');
$owner = $resolver->resolve(new AccessRequestContext(4, 7));
expect($owner->isSystemOwner(), 'System owner key must identify owner.');
expect($owner->compatibilityRoleCode() === 'company_owner', 'Owner compatibility role must derive from system ownership.');
expect($owner->canAccessPage('company.finance.dashboard'), 'Owner must have every registered page.');
expect($owner->dataScope('waste_trip') === 'all', 'Owner must have all regulated data.');

$superadmin = $resolver->resolve(new AccessRequestContext(0, 0, true));
expect($superadmin->isTechnicalSuperadmin(), 'Technical superadmin must remain separate.');
expect($superadmin->membershipId() === null, 'Technical superadmin must not acquire membership.');
expect($superadmin->canAccessPage('company.clients'), 'Technical superadmin keeps system access.');

$provider->ready = false;
$denied = $resolver->resolve(new AccessRequestContext(4, 7));
expect(!$denied->isActive() && $denied->denialReason() === 'access_not_ready', 'Readiness gate must fail closed.');
$provider->ready = true;
$provider->throw = true;
$denied = $resolver->resolve(new AccessRequestContext(4, 7));
expect(!$denied->isActive() && $denied->denialReason() === 'access_state_error', 'Provider failure must fail closed.');
$provider->throw = false;
$provider->snapshot = memberSnapshot(status: 'blocked');
$denied = $resolver->resolve(new AccessRequestContext(4, 7));
expect(!$denied->isActive(), 'Inactive membership must fail closed.');

// Next-request resolution observes revocation without login/logout.
$provider->snapshot = memberSnapshot(['company.clients']);
expect($resolver->resolve(new AccessRequestContext(4, 7))->canAccessPage('company.clients'), 'Initial role page allow failed.');
$provider->snapshot = memberSnapshot([]);
expect(!$resolver->resolve(new AccessRequestContext(4, 7))->canAccessPage('company.clients'), 'Immediate page revocation was cached.');

$provider->snapshot = memberSnapshot(['company.clients'], compatibilityRoleCode: 'logist');
expect($resolver->resolve(new AccessRequestContext(4, 7))->compatibilityRoleCode() === 'logist', 'Preset compatibility role must survive request-scoped resolution.');
$provider->snapshot = memberSnapshot(['company.clients'], compatibilityRoleCode: 'company_owner');
expect($resolver->resolve(new AccessRequestContext(4, 7))->compatibilityRoleCode() === 'custom_role', 'Ordinary role must never masquerade as owner through compatibility metadata.');

// Runtime-facing domain contract.
$provider->snapshot = memberSnapshot(['company.clients'], [], ['client' => 'own']);
$router = new Router($registry);
$router->get('/company/clients', static fn (): string => 'allowed', 'company.clients');
$router->post('/company/clients/save', static fn (): string => 'saved', 'company.clients');
$router->post('/company/contractors/save', static fn (): string => 'must-not-run', 'company.contractors');
$router->get('/company/shared', static fn (): string => 'shared', ['company.clients', 'company.contractors']);
$router->get('/company/unknown', static fn (): string => 'must-not-run');
$router->get('/company/json', static fn (): string => 'must-not-run', 'company.contractors');
$router->post('/company/switch', static fn (): string => 'switched');
$router->enableCompanyAccess($resolver, new AccessRequestContext(4, 7));

[$result, $output, $status] = dispatchOutput($router, 'GET', '/company/clients');
expect($result === 'allowed' && $status === 200, 'Allowed direct URL was denied.');
[$result, $output, $status] = dispatchOutput($router, 'POST', '/company/clients/save', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
expect($result === 'saved' && $status === 200, 'Allowed POST/AJAX was denied.');
[$result, $output, $status] = dispatchOutput($router, 'POST', '/company/contractors/save?page_key=company.clients', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
expect($result === null && $status === 403, 'Client page_key must not bypass POST/AJAX protection.');
[$result, $output, $status] = dispatchOutput($router, 'GET', '/company/shared');
expect($result === 'shared' && $status === 200, 'Shared endpoint OR mapping failed.');
[$result, $output, $status] = dispatchOutput($router, 'GET', '/company/unknown');
expect($result === null && $status === 403, 'Unknown protected endpoint must deny.');
[$result, $output, $status] = dispatchOutput($router, 'GET', '/company/json', ['HTTP_ACCEPT' => 'application/json']);
expect($result === null && $status === 403 && $output === '{"error":"access_denied"}', 'JSON denial contract failed.');
[$result, $output, $status] = dispatchOutput($router, 'POST', '/company/switch');
expect($result === 'switched' && $status === 200, 'Company switch must reach membership-validating service without page authorization.');

expect(AccessControlService::effectiveMembershipId() === 91, 'Runtime membership id contract failed.');
expect(!AccessControlService::isEffectiveSystemOwner(), 'Ordinary member must not be owner.');
expect(AccessControlService::effectiveDataScope('client') === 'own', 'Runtime data scope contract failed.');
expect(AccessControlService::effectiveDataScope('unknown') === 'none', 'Runtime unknown scope must deny.');
expect(hasPageAccess(['company.contractors', 'company.clients']), 'Server-side OR page helper failed.');
$_SESSION = ['account_id' => 4, 'company_id' => 7, 'role_code' => 'logist', 'user_id' => 900];
$requestContext = currentAccessRequestContext();
expect($requestContext->accountId === 4 && $requestContext->companyId === 7, 'Request context must use global account_id and active company_id.');

// Metadata changes do not change the stable permission key.
$renamedRegistry = new PageRegistry([[
    'key' => 'company.clients',
    'label' => 'Заказчики',
    'section' => 'Справочники',
    'group' => 'CRM',
    'order' => 1,
    'path' => '/company/customers',
]]);
expect($renamedRegistry->hasPage('company.clients'), 'Rename/regroup must preserve page key.');

echo "roles_access_core_test: PASS\n";
