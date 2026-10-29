<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Security/PageRegistry.php';
require_once $root . '/app/Security/EffectiveAccess.php';
require_once $root . '/app/Security/AccessRuntime.php';
require_once $root . '/app/Http/Router.php';

use App\Http\Router;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Security\PageRegistry;

function endpointExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** Return argument count for every $router->get()/post() declaration. */
function routeArgumentCounts(string $source): array
{
    $counts = [];
    preg_match_all('/\$router->(?:get|post)\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[0] as [$match, $offset]) {
        $j = $offset + strlen($match);
        $length = strlen($source);
        $paren = 1; $square = 0; $curly = 0; $commas = 0; $quote = ''; $lineComment = false; $blockComment = false;
        for (; $j < $length && $paren > 0; $j++) {
            $char = $source[$j]; $next = $source[$j + 1] ?? '';
            if ($lineComment) { if ($char === "\n") $lineComment = false; continue; }
            if ($blockComment) { if ($char === '*' && $next === '/') { $blockComment = false; $j++; } continue; }
            if ($quote !== '') { if ($char === '\\') { $j++; continue; } if ($char === $quote) $quote = ''; continue; }
            if ($char === '/' && $next === '/') { $lineComment = true; $j++; continue; }
            if ($char === '/' && $next === '*') { $blockComment = true; $j++; continue; }
            if ($char === "'" || $char === '"') { $quote = $char; continue; }
            if ($char === '(') $paren++;
            elseif ($char === ')') $paren--;
            elseif ($char === '[') $square++;
            elseif ($char === ']') $square--;
            elseif ($char === '{') $curly++;
            elseif ($char === '}') $curly--;
            elseif ($char === ',' && $paren === 1 && $square === 0 && $curly === 0) $commas++;
        }
        $counts[] = $commas + 1;
    }
    return $counts;
}

$routePages = [
    'company_bank_finance.php' => ['company.finance.bank_accounts', 'company.finance.bank_settings', 'company.finance.closing_documents'],
    'company_document_generator.php' => ['company.documents.create', 'company.documents.history', 'company.documents.templates'],
    'company_trip_documents.php' => ['company.documents.request', 'company.documents.collection'],
    'company_trip_completion.php' => ['company.documents.collection', 'company.registration_journal'],
    'company_trip_closing.php' => ['company.documents.trip_closing', 'company.documents.trip_closing_registry'],
    'company_documents.php' => ['company.clients', 'company.contractors', 'company.drivers', 'company.vehicle_sets', 'company.route_executors', 'company.trips.linear'],
    'company_feo_map.php' => ['company.feo.map', 'company.feo.utilizers'],
    'company_feo_request_costs.php' => ['company.feo.costs'],
    'company_feo_requests.php' => ['company.feo.requests', 'company.feo.map_placement'],
    'company_finance_cash.php' => ['company.finance.cash'],
    'company_finance_cash_flow_report.php' => ['company.finance.cash_flow'],
    'company_finance_dashboard.php' => ['company.finance.dashboard'],
    'company_finance_dds_categories.php' => ['company.finance.dds_categories'],
    'company_finance_employee_payments.php' => ['company.finance.employee_payments'],
    'company_finance_invoices.php' => ['company.finance.invoices'],
    'company_finance_management_balance.php' => ['company.finance.management_balance'],
    'company_finance_matching_rules.php' => ['company.finance.matching_rules'],
    'company_finance_operations.php' => ['company.finance.operations'],
    'company_finance_payment_calendar.php' => ['company.finance.payment_calendar'],
    'company_finance_payment_plan_fact.php' => ['company.finance.payment_plan_fact'],
    'company_route_executors.php' => ['company.route_executors'],
    'company_vehicle_sets.php' => ['company.vehicle_sets'],
];

$declarationCount = 0;
foreach ($routePages as $file => $pageKeys) {
    $source = (string) file_get_contents($root . '/app/Http/Routes/' . $file);
    $counts = routeArgumentCounts($source);
    endpointExpect($counts !== [], "{$file} has no routes");
    $publicArchive = in_array($file, ['company_trip_completion.php','company_trip_closing.php'], true)
        && str_contains($source, $file === 'company_trip_completion.php' ? "'/trip-review-package/{companyId}/{token}'" : "'/trip-closing-package/{companyId}/{token}'");
    endpointExpect(!in_array(1, $counts, true) && (count(array_filter($counts, static fn(int $count):bool=>$count===2)) === ($publicArchive ? 1 : 0)), "{$file} has an unmapped route declaration");
    foreach ($pageKeys as $pageKey) endpointExpect(str_contains($source, $pageKey), "{$file} missing {$pageKey}");
    $declarationCount += count($counts);
}
endpointExpect($declarationCount === 256, 'owned inventory must contain exactly 254 protected and two token-gated archive declarations');

$ownedSources = '';
foreach (array_keys($routePages) as $file) $ownedSources .= file_get_contents($root . '/app/Http/Routes/' . $file);
foreach (glob($root . '/app/Http/Controllers/Company/{BankFinanceActions,DocumentActions,Finance*Actions,InvoiceActions,RouteExecutorActions,VehicleSetActions}/*.php', GLOB_BRACE) ?: [] as $file) {
    $ownedSources .= file_get_contents($file);
}
foreach (['ClosingDocumentsController.php','DocumentGeneratorController.php','FeoMapController.php','FeoPlacementController.php','FeoRequestCostController.php','FeoRequestsController.php','FeoUtilizerController.php','FinanceEmployeeInvoicePaymentController.php','FinanceEmployeePaymentsController.php','FinanceEmployeePersonalExpenseController.php'] as $file) {
    $ownedSources .= file_get_contents($root . '/app/Http/Controllers/Company/' . $file);
}
endpointExpect(!str_contains($ownedSources, 'requireRole('), 'owned endpoint families retain requireRole');
endpointExpect(!str_contains($ownedSources, 'requireFinanceAccess('), 'owned finance families retain requireFinanceAccess');

$documents = (string) file_get_contents($root . '/app/Http/Routes/company_documents.php');
endpointExpect(str_contains($documents, 'requireDocumentEntityPage'), 'shared documents must derive page context server-side');
endpointExpect(str_contains($documents, "'vehicle_unit' => ['company.vehicle_sets']"), 'vehicle document context must use the grantable ТС page');
endpointExpect(str_contains($documents, 'vehicleSetAccessibleUnitIds($pdo, $set)'), 'vehicle documents must honor direct set grants');
endpointExpect(str_contains($documents, 'RegulatedDataScopeService::canAccess'), 'shared documents must preserve data-scope');

$vehicleRoutes = (string) file_get_contents($root . '/app/Http/Routes/company_vehicle_sets.php');
$vehicleActions = implode('', array_map('file_get_contents', glob($root . '/app/Http/Controllers/Company/VehicleSetActions/*.php') ?: []));
$vehicleEditView = (string) file_get_contents($root . '/app/View/partials/company_vehicle_set_modal_edit.php');
$vehicleEditSubmit = (string) file_get_contents($root . '/app/Http/Controllers/Company/VehicleSetActions/modal_edit_submit.php');
endpointExpect(str_contains($vehicleRoutes, "canAccess(\$pdo, 'vehicle_unit', 'vehicle_units'"), 'VehicleSet must filter canonical vehicle_unit');
endpointExpect(str_contains($vehicleActions, "assertCanCreate('vehicle_unit')"), 'VehicleSet create must enforce vehicle_unit scope');
endpointExpect(str_contains($vehicleActions, "autoAssignCreator(\$VSC_lpdo,'vehicle_unit'"), 'VehicleSet create must auto-assign created vehicle units');
endpointExpect(!str_contains($vehicleActions, "entity_type='vehicle_set'"), 'VehicleSet must not use legacy grants or a new data-scope');
endpointExpect(str_contains($vehicleEditView, '<select name="set_type"'), 'VehicleSet edit must expose set_type selector');
endpointExpect(!str_contains($vehicleEditView, '<input type="hidden" name="set_type"'), 'VehicleSet edit must not lock set_type in a hidden field');
endpointExpect(str_contains($vehicleEditView, "setType.addEventListener('change'"), 'VehicleSet edit type selector must update dependent panels');
endpointExpect(str_contains($vehicleEditView, "capacityRow.classList.toggle('is-hidden'"), 'VehicleSet edit type selector must update capacity/volume visibility');
endpointExpect(str_contains($vehicleEditSubmit, "\$_POST['set_type']"), 'VehicleSet edit submit must use posted set_type');
endpointExpect(str_contains($vehicleEditSubmit, 'UPDATE vehicle_sets SET set_type=?,secondary_vehicle_unit_id=?'), 'VehicleSet edit submit must persist set_type topology');
endpointExpect(str_contains($vehicleEditSubmit, 'unit_type=:unit_type'), 'VehicleSet edit submit must align vehicle unit types');
endpointExpect(str_contains($vehicleEditSubmit, 'INSERT INTO vehicle_units'), 'VehicleSet edit must create a missing secondary vehicle unit when required');
endpointExpect(str_contains($vehicleEditSubmit, 'created_by_membership_id'), 'VehicleSet edit-created secondary unit must preserve creator membership');
endpointExpect(str_contains($vehicleEditSubmit, 'EntityAssignmentService::autoAssignCreator'), 'VehicleSet edit-created secondary unit must preserve creator assignment');
endpointExpect(!str_contains($vehicleEditSubmit, 'DELETE FROM vehicle_units'), 'VehicleSet type change must not delete detached vehicle units');

$routeExecutorActions = implode('', array_map('file_get_contents', glob($root . '/app/Http/Controllers/Company/RouteExecutorActions/*.php') ?: []));
$routeExecutorService = (string) file_get_contents($root . '/app/Service/RouteExecutorService.php');
endpointExpect(str_contains($routeExecutorActions, 'RouteExecutorService::filterVisibleCrews'), 'RouteExecutor list must enforce canonical crew visibility');
endpointExpect(str_contains($routeExecutorActions, 'RouteExecutorService::canAccessCrew'), 'RouteExecutor direct actions must enforce canonical crew visibility');
endpointExpect(str_contains($routeExecutorService, "RegulatedDataScopeService::canAccess(\$pdo, 'contractor'"), 'RouteExecutor contractor scope missing');
endpointExpect(str_contains($routeExecutorService, "RegulatedDataScopeService::canAccess(\$pdo, 'driver'"), 'RouteExecutor driver scope missing');
endpointExpect(str_contains($routeExecutorService, "RegulatedDataScopeService::canAccess(\$pdo, 'vehicle_unit'"), 'RouteExecutor vehicle scope missing');
endpointExpect(!str_contains($routeExecutorActions . $routeExecutorService, 'entity_access_grants'), 'RouteExecutor must not reintroduce legacy grants');
endpointExpect(!str_contains($routeExecutorActions . $routeExecutorService, '$isLogist'), 'RouteExecutor must not retain role-conditioned access');

// Focused Router acceptance: ordinary member allow, deny, POST/AJAX and server-owned OR mapping.
$registry = new PageRegistry();
$router = new Router($registry);
$router->get('/company/finance/ordinary', static fn (): string => 'finance', 'company.finance.dashboard');
$router->post('/company/finance/action', static fn (): string => 'posted', 'company.finance.dashboard');
$router->get('/company/shared-endpoint', static fn (): string => 'shared', ['company.clients', 'company.contractors']);
$access = EffectiveAccess::member(41, 7, [
    'company.finance.dashboard' => true,
    'company.clients' => false,
    'company.contractors' => true,
], array_fill_keys(['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'], 'none'));
$property = new ReflectionProperty(Router::class, 'effectiveAccess');
$property->setValue($router, $access);
AccessRuntime::setCurrent($access);
http_response_code(200);
endpointExpect($router->dispatch('GET', '/company/finance/ordinary') === 'finance', 'ordinary allowed finance page failed');
endpointExpect($router->dispatch('POST', '/company/finance/action') === 'posted', 'direct POST page authorization failed');
endpointExpect($router->dispatch('GET', '/company/shared-endpoint') === 'shared', 'shared endpoint OR authorization failed');

$denied = EffectiveAccess::member(41, 7, ['company.finance.dashboard' => false], array_fill_keys(['client','contractor','vehicle_unit','driver','linear_trip','waste_trip'], 'none'));
$property->setValue($router, $denied);
AccessRuntime::setCurrent($denied);
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
http_response_code(200);
ob_start();
$result = $router->dispatch('POST', '/company/finance/action');
$body = (string) ob_get_clean();
endpointExpect($result === null && http_response_code() === 403 && str_contains($body, 'access_denied'), 'denied AJAX POST must fail closed');
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
AccessRuntime::clear();

echo "PASS roles access endpoint integration ({$declarationCount} routes)\n";
