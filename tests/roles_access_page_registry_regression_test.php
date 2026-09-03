<?php

declare(strict_types=1);

use App\Service\PageRegistry;

require_once dirname(__DIR__) . '/app/Service/PageRegistry.php';

$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$pages = PageRegistry::pages();
$matrix = PageRegistry::matrixPages();

$assert(isset($pages['company.clients']), 'Stable clients page_key missing.');
$assert(($pages['company.clients']['title'] ?? '') === 'Клиенты', 'Clients title mismatch.');
$assert(($pages['company.clients']['group'] ?? null) === null, 'Clients grouping unexpectedly changed page identity.');
$assert(isset($matrix['company.clients']), 'Registered page must automatically appear in role matrix source.');
$assert(isset($matrix['company.roles_access']), 'Roles/access page missing from role matrix.');
$assert(isset($matrix['company.feo.map_placement']), 'FEO map placement must be separately configurable.');
$assert(isset($matrix['company.feo.request_costs']), 'FEO costs must be separately configurable.');

$assert(PageRegistry::pageKeysForRequest('GET', '/company/clients') === ['company.clients'], 'Direct clients GET mapping failed.');
$assert(PageRegistry::pageKeysForRequest('POST', '/company/clients/123/modal-edit') === ['company.clients'], 'Clients modal POST mapping failed.');
$assert(PageRegistry::pageKeysForRequest('GET', '/company/feo/requests/285664/json') === ['company.feo.requests'], 'FEO JSON mapping failed.');
$assert(PageRegistry::pageKeysForRequest('POST', '/company/feo/requests/map-placement/blocks/15/preview-delete') === ['company.feo.map_placement'], 'FEO map POST mapping failed.');
$assert(PageRegistry::pageKeysForRequest('GET', '/company/feo/requests/map-placement/blocks/15/download') === ['company.feo.map_placement'], 'FEO map file mapping failed.');
$assert(PageRegistry::pageKeysForRequest('POST', '/company/feo/requests/costs/blocks/7/edit/analyze') === ['company.feo.request_costs'], 'FEO costs action mapping failed.');
$assert(PageRegistry::pageKeysForRequest('POST', '/company/finance/closing-documents/upload-document') === ['company.finance.closing_documents'], 'Closing documents upload mapping failed.');
$assert(PageRegistry::pageKeysForRequest('GET', '/company/document-generator/templates/3/versions/9/download') === ['company.document_generator.templates'], 'Template download mapping failed.');
$assert(PageRegistry::pageKeysForRequest('POST', '/company/roles-access/assignments/toggle') === ['company.roles_access'], 'Roles/access AJAX mapping failed.');

$shared = PageRegistry::pageKeysForRequest('GET', '/company/requisites/lookup-by-inn');
sort($shared);
$assert($shared === ['company.clients', 'company.contractors'], 'Shared INN endpoint must expose exact server-owned OR-set.');

// Query/client page_key cannot affect server mapping.
$withClientPageKey = PageRegistry::pageKeysForRequest('GET', '/company/clients?page_key=company.finance.dashboard');
$assert($withClientPageKey === ['company.clients'], 'Client page_key must not expand endpoint mapping.');
$assert(PageRegistry::pageKeysForRequest('POST', '/company/not-registered') === [], 'Unknown protected action must have no mapping and fail closed in resolver.');
$assert(PageRegistry::pageKeysForRequest('GET', '/company/finance/dashboard') === ['company.finance.dashboard'], 'Finance dashboard must be normal registry page.');
$assert(PageRegistry::pageKeysForRequest('POST', '/company/finance/invoices/44/modal-edit') === ['company.finance.invoices'], 'Finance POST must map without owner-role semantics.');

$assert(PageRegistry::dataEntityForPage('company.clients') === 'client', 'Client data entity mapping mismatch.');
$assert(PageRegistry::dataEntityForPage('company.vehicles') === 'vehicle_unit', 'Canonical Vehicle entity must be vehicle_unit.');
$assert(PageRegistry::dataEntityForPage('company.vehicle_sets') === null, 'VehicleSet must not receive regulated Vehicle scope.');
$assert(PageRegistry::dataEntityForPage('company.feo.requests') === null, 'FEO remains page-only in this version.');

$onlyClients = PageRegistry::navigationFor(static fn(string $pageKey): bool => $pageKey === 'company.clients');
$assert(count($onlyClients) === 1, 'Navigation should remove empty sections.');
$assert(($onlyClients[0]['key'] ?? '') === 'operations', 'Clients should remain under operations section.');
$assert(count($onlyClients[0]['items'] ?? []) === 1, 'Expected one direct clients navigation item.');
$assert(($onlyClients[0]['groups'] ?? []) === [], 'Empty groups must not render.');

$onlyTrips = PageRegistry::navigationFor(static fn(string $pageKey): bool => $pageKey === 'company.trips.linear');
$assert(count($onlyTrips) === 1, 'Trip-only navigation should retain one section.');
$tripGroups = $onlyTrips[0]['groups'] ?? [];
$assert(isset($tripGroups['trips']), 'Trips parent group should appear when one descendant is visible.');
$assert(count($tripGroups['trips']['items'] ?? []) === 1, 'Trips group should contain only allowed descendant.');

$none = PageRegistry::navigationFor(static fn(string $pageKey): bool => false);
$assert($none === [], 'No accessible descendants must yield empty company navigation.');

$allKeys = array_keys($pages);
$assert(count($allKeys) === count(array_unique($allKeys)), 'Duplicate page_key detected.');
foreach ($pages as $pageKey => $page) {
    $assert(str_starts_with($pageKey, 'company.'), 'Company page key namespace violation: ' . $pageKey);
    $assert(trim((string) ($page['title'] ?? '')) !== '', 'Page title missing: ' . $pageKey);
    $assert(is_array($page['endpoints'] ?? null) && ($page['endpoints'] ?? []) !== [], 'Page endpoints missing: ' . $pageKey);
}

fwrite(STDOUT, "ROLES_ACCESS_PAGE_REGISTRY=PASS\n");
fwrite(STDOUT, "ROLES_ACCESS_ENDPOINT_MAPPING=PASS\n");
fwrite(STDOUT, "ROLES_ACCESS_SHARED_ENDPOINT_OR_SET=PASS\n");
fwrite(STDOUT, "ROLES_ACCESS_NAVIGATION_DERIVATION=PASS\n");
fwrite(STDOUT, "ROLES_ACCESS_FINANCE_REGISTRY_LAYER=PASS\n");
