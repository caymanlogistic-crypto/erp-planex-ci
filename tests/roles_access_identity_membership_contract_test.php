<?php

if (!class_exists('PDO')) {
    class PDO
    {
        public const FETCH_DEFAULT = 0;
        public const FETCH_ASSOC = 2;

        public function __construct()
        {
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            return false;
        }
    }

    class PDOStatement
    {
        public function execute(?array $params = null): bool
        {
            return true;
        }

        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return [];
        }
    }
}

final class ScriptedPdo extends PDO
{
    /** @param callable(string,array<int,mixed>):array<int,array<string,mixed>> $responder */
    public function __construct(private $responder)
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new ScriptedPdoStatement($query, $this->responder);
    }
}

final class ScriptedPdoStatement extends PDOStatement
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];

    /** @param callable(string,array<int,mixed>):array<int,array<string,mixed>> $responder */
    public function __construct(private string $query, private $responder)
    {
    }

    /** @param array<int,mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $this->rows = ($this->responder)($this->query, $params ?? []);
        return true;
    }

    /** @return list<array<string,mixed>> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

require_once __DIR__ . '/../app/Service/IdentityNormalizer.php';
require_once __DIR__ . '/../app/Service/CompanyRoleService.php';
require_once __DIR__ . '/../app/Service/CompanyMembershipService.php';

use App\Service\CompanyMembershipService;
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

$queries = [];
$pdo = new ScriptedPdo(static function (string $query, array $params) use (&$queries): array {
    $queries[] = [$query, $params];
    expect($params === [7], 'every administration read must bind the selected company');

    if (str_contains($query, 'COUNT(m.id) AS membership_count')) {
        expect(str_contains($query, 'WHERE r.company_id = ?'), 'role list must be company scoped');
        return [
            ['id' => '1', 'name' => 'Руководитель', 'system_key' => 'company_owner', 'is_system' => '1', 'membership_count' => '1'],
            ['id' => '2', 'name' => 'Логист', 'system_key' => null, 'is_system' => '0', 'membership_count' => '1'],
        ];
    }
    if (str_contains($query, 'FROM role_page_permissions')) {
        expect(str_contains($query, 'WHERE r.company_id = ?'), 'role page read must be company scoped');
        return [
            ['role_id' => '2', 'page_key' => 'company.dashboard', 'is_allowed' => '1'],
            ['role_id' => '2', 'page_key' => 'company.roles_access', 'is_allowed' => '0'],
            ['role_id' => '999', 'page_key' => 'company.dashboard', 'is_allowed' => '1'],
        ];
    }
    if (str_contains($query, 'FROM role_data_scopes')) {
        expect(str_contains($query, 'WHERE r.company_id = ?'), 'role data-scope read must be company scoped');
        return [
            ['role_id' => '2', 'entity_type' => 'client', 'scope' => 'own'],
            ['role_id' => '999', 'entity_type' => 'client', 'scope' => 'all'],
        ];
    }
    if (str_contains($query, 'SELECT m.id, m.account_id')) {
        expect(str_contains($query, 'WHERE m.company_id = ?'), 'membership list must be company scoped');
        expect(!str_contains($query, 'password_hash'), 'membership list must not select password hashes');
        return [
            ['id' => '10', 'account_id' => '100', 'full_name' => 'Иванов', 'login' => 'owner', 'email' => null, 'phone' => null, 'status' => 'active', 'role_id' => '1', 'role_name' => 'Руководитель', 'role_system_key' => 'company_owner'],
            ['id' => '11', 'account_id' => '101', 'full_name' => 'Петров', 'login' => 'logist', 'email' => 'p@example.test', 'phone' => null, 'status' => 'active', 'role_id' => '2', 'role_name' => 'Логист', 'role_system_key' => null],
            ['id' => '999', 'account_id' => '999', 'full_name' => 'Чужая компания', 'login' => 'foreign', 'email' => null, 'phone' => null, 'status' => 'active', 'role_id' => '999', 'role_name' => 'Чужая роль', 'role_system_key' => null],
        ];
    }
    if (str_contains($query, 'FROM membership_page_overrides')) {
        expect(str_contains($query, 'WHERE m.company_id = ?'), 'membership page override read must be company scoped');
        return [
            ['membership_id' => '10', 'page_key' => 'company.dashboard', 'effect' => 'deny'],
            ['membership_id' => '11', 'page_key' => 'company.dashboard', 'effect' => 'deny'],
            ['membership_id' => '11', 'page_key' => 'company.roles_access', 'effect' => 'allow'],
            ['membership_id' => '999', 'page_key' => 'company.dashboard', 'effect' => 'allow'],
        ];
    }
    if (str_contains($query, 'FROM membership_data_scope_overrides')) {
        expect(str_contains($query, 'WHERE m.company_id = ?'), 'membership data override read must be company scoped');
        return [
            ['membership_id' => '11', 'entity_type' => 'client', 'scope' => 'all'],
            ['membership_id' => '999', 'entity_type' => 'client', 'scope' => 'all'],
        ];
    }
    throw new RuntimeException('Unexpected list query: ' . $query);
});

$pageKeys = ['company.dashboard', 'company.roles_access'];
$roles = (new CompanyRoleService($pdo))->listForCompany(7, $pageKeys);
expect(count($roles) === 2, 'role list must contain only current-company roles');
expect($roles[0]['pages'] === ['company.dashboard' => true, 'company.roles_access' => true], 'owner page map must truthfully expose all current pages');
expect($roles[0]['data_scopes'] === array_fill_keys(CompanyRoleService::regulatedEntities(), 'all'), 'owner data map must truthfully expose all data');
expect($roles[1]['pages'] === ['company.dashboard' => true, 'company.roles_access' => false], 'ordinary role page settings must round-trip exactly');
expect($roles[1]['data_scopes']['client'] === 'own' && $roles[1]['data_scopes']['driver'] === 'none', 'ordinary role data settings must round-trip exactly');

$memberships = (new CompanyMembershipService($pdo))->listForCompany(7, $pageKeys, $roles);
expect(count($memberships) === 2, 'unknown/cross-company membership rows must not leak');
expect($memberships[1]['page_overrides'] === ['company.dashboard' => 'deny', 'company.roles_access' => 'allow'], 'page overrides must round-trip exactly');
expect($memberships[1]['data_scope_overrides'] === ['client' => 'all'], 'data overrides must round-trip exactly');
expect($memberships[1]['effective_pages'] === ['company.dashboard' => false, 'company.roles_access' => true], 'effective pages must include personal overrides');
expect($memberships[1]['effective_data_scopes']['client'] === 'all', 'effective data scopes must include personal overrides');
expect($memberships[1]['personal_settings_count'] === 3, 'personal settings count must support the compact users table');
expect($memberships[0]['effective_pages']['company.dashboard'] === true, 'protected owner must remain effectively unrestricted');
expect(!str_contains(json_encode([$roles, $memberships]) ?: '', 'password_hash'), 'administration payload must never contain password hashes');

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
expect(str_contains($switchSource, "\$session['role_code']"), 'switch must clear derived compatibility role for the next company');
expect(str_contains($authSource, "unset(\$session['role_code']"), 'global login must not cache company role');
expect(str_contains($authControllerSource, 'AccessStateProvider'), 'global auth must be gated by the access-state provider');
expect(str_contains($authControllerSource, 'tenant-local'), 'legacy tenant auth bypass guard missing');
expect(str_contains($adminControllerSource, 'AccessRuntime::current()->isSystemOwner()'), 'admin writes must require effective system owner');
expect(str_contains($adminControllerSource, 'requestJsonBody()'), 'admin endpoints must accept atomic JSON payloads');
expect(str_contains($adminControllerSource, "'page_tree' => \$registry->grantableTree()"), 'snapshot must expose only grantable canonical Page Registry pages');
expect(str_contains($adminControllerSource, "'pages' => \$registry->grantablePages()"), 'snapshot must not expose owner-only system tools as editable permissions');
expect(str_contains($adminControllerSource, "require base_path('app/View/pages/company/roles_access.php')"), 'HTML controller must prepare the future roles-access view');

$adminRouteSource = file_get_contents(__DIR__ . '/../app/Http/Routes/company_roles_access.php');
expect(str_contains($adminRouteSource, "get('/company/roles-access', [\$rolesAccessController, 'index'], \$rolesAccessPage)"), 'roles-access HTML route missing or not protected by its page key');

echo "roles access identity/membership contract: PASS\n";
