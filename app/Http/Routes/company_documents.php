<?php

require_once base_path('app/Http/Controllers/Company/DocumentController.php');

$c = new \App\Http\Controllers\Company\DocumentController($config, $db);
if (!function_exists('documentEntityPageKeys')) {
    /** @return list<string> */
    function documentEntityPageKeys(string $entityType): array
    {
        return match ($entityType) {
            'client' => ['company.clients'],
            'contractor' => ['company.contractors'],
            'driver' => ['company.drivers'],
            'vehicle_unit' => ['company.vehicle_sets'],
            'vehicle_set' => ['company.vehicle_sets'],
            'driver_vehicle_block' => ['company.drivers', 'company.vehicle_sets'],
            'crew' => ['company.route_executors'],
            'linear_route' => ['company.trips.linear'],
            default => [],
        };
    }

    function requireDocumentEntityPage(string $entityType): void
    {
        $pageKeys = documentEntityPageKeys($entityType);
        if ($pageKeys === [] || !hasPageAccess($pageKeys)) {
            denyEntityAccess();
        }
    }

    function canAccessDocumentEntity(PDO $pdo, string $entityType, int $entityId): bool
    {
        if ($entityId <= 0) {
            return false;
        }
        if ($entityType === 'vehicle_unit') {
            if (\App\Service\RegulatedDataScopeService::canAccess($pdo, 'vehicle_unit', 'vehicle_units', $entityId)) {
                return true;
            }
            // The vehicle-set card can expose a unit through a direct set grant.
            // A document shown in that card must use the same unit visibility rule.
            $sets = $pdo->prepare('SELECT * FROM vehicle_sets WHERE primary_vehicle_unit_id = ? OR secondary_vehicle_unit_id = ?');
            $sets->execute([$entityId, $entityId]);
            while ($set = $sets->fetch(PDO::FETCH_ASSOC)) {
                if (isset(vehicleSetAccessibleUnitIds($pdo, $set)[$entityId])) {
                    return true;
                }
            }
            return false;
        }
        $regulated = [
            'client' => 'clients',
            'contractor' => 'contractors',
            'driver' => 'drivers',
            'linear_route' => 'linear_routes',
        ];
        if (isset($regulated[$entityType])) {
            $scopeType = $entityType === 'linear_route' ? 'linear_trip' : $entityType;
            return \App\Service\RegulatedDataScopeService::canAccess($pdo, $scopeType, $regulated[$entityType], $entityId);
        }

        // These aggregates are page-only in v1. Their concrete page context is
        // still checked independently above, so no client-supplied page key is trusted.
        return in_array($entityType, ['vehicle_set', 'driver_vehicle_block', 'crew'], true);
    }
}
$documentEntityPages = array_values(array_unique(array_merge(...array_map(
    'documentEntityPageKeys',
    ['client', 'contractor', 'driver', 'vehicle_unit', 'vehicle_set', 'driver_vehicle_block', 'crew', 'linear_route']
))));
$documentTypesPage = 'company.documents.templates';

$router->get('/company/documents', [$c, 'index'], $documentEntityPages);
$router->get('/company/documents/upload', [$c, 'uploadForm'], $documentEntityPages);
$router->post('/company/documents/upload', [$c, 'uploadSubmit'], $documentEntityPages);
$router->get('/company/documents/download', [$c, 'download'], $documentEntityPages);
$router->get('/company/documents/view', [$c, 'view'], $documentEntityPages);
$router->post('/company/documents/delete', [$c, 'delete'], $documentEntityPages);
$router->post('/company/documents/replace', [$c, 'replace'], $documentEntityPages);
$router->get('/company/document-types', [$c, 'docTypesIndex'], $documentTypesPage);
$router->get('/company/document-types/create', [$c, 'docTypesCreateForm'], $documentTypesPage);
$router->post('/company/document-types/create', [$c, 'docTypesCreateSubmit'], $documentTypesPage);
$router->get('/company/document-types/{id}/edit', [$c, 'docTypesEditForm'], $documentTypesPage);
$router->post('/company/document-types/{id}/edit', [$c, 'docTypesEditSubmit'], $documentTypesPage);
$router->post('/company/document-types/{id}/delete', [$c, 'docTypesDelete'], $documentTypesPage);

require_once base_path('app/Http/Routes/company_document_generator.php');
