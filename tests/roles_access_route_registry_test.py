#!/usr/bin/env python3
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
PATTERN = re.compile(r"\$router->(?:get|post)\(")

def calls(text):
    pos = 0
    while True:
        match = PATTERN.search(text, pos)
        if match is None:
            return
        index, depth, quote, escaped, commas = match.end(), 1, None, False, 0
        while index < len(text) and depth:
            char = text[index]
            if quote is not None:
                if escaped: escaped = False
                elif char == "\\": escaped = True
                elif char == quote: quote = None
            else:
                if char in ("'", '"'): quote = char
                elif char in "([{": depth += 1
                elif char in ")]}": depth -= 1
                elif char == "," and depth == 1: commas += 1
            index += 1
        yield text[match.start():index], commas + 1
        pos = index

company_routes, unmapped = [], []
for path in sorted((ROOT / "app/Http/Routes").glob("*.php")):
    source = path.read_text(encoding="utf-8")
    for call, argc in calls(source):
        route = re.search(r"\(\s*['\"](/company[^'\"]*)", call, re.S)
        if route is None: continue
        item = (path.relative_to(ROOT).as_posix(), route.group(1))
        company_routes.append(item)
        if argc < 3: unmapped.append(item)

assert len(company_routes) == 441, f"company route inventory drift: {len(company_routes)}"
for endpoint in ('/company/driver-ts-data','/company/driver-ts-data/{id}/detail','/company/driver-ts-data/{id}/files/{documentId}','/company/driver-ts-data/{id}/archive'):
    assert ('app/Http/Routes/company_driver_ts_data.php',endpoint) in company_routes
assert ("app/Http/Routes/company_linear_trips.php", "/company/warehouses/addresses") in company_routes
assert ("app/Http/Routes/company_feo_transfers.php", "/company/feo/requests/transfers/{id}/delete") in company_routes
assert "[$feoTransferController,'deleteReturned'],$feoTransfersPage" in (ROOT / "app/Http/Routes/company_feo_transfers.php").read_text(encoding="utf-8")
for endpoint in ('/company/feo/requests/weights', '/company/feo/requests/weights/preview', '/company/feo/requests/weights/create', '/company/feo/requests/weights/{id}/json', '/company/feo/requests/weights/{id}/revert', '/company/feo/requests/weights/{id}/delete'):
    assert ('app/Http/Routes/company_feo_weights.php', endpoint) in company_routes
assert ("app/Http/Routes/company_linear_trips.php", "/company/trips/gps-coverage") in company_routes
assert ("app/Http/Routes/company_linear_trips.php", "/company/trips/gps-coverage/{id}/coverage") in company_routes
assert ("app/Http/Routes/company_document_generator.php", "/company/document-generator/history/download") in company_routes
generator_routes = (ROOT / 'app/Http/Routes/company_document_generator.php').read_text(encoding='utf-8')
assert "[$documentGeneratorController, 'downloadHistoryArchive'], $documentHistoryPage" in generator_routes
closing_routes = (ROOT / 'app/Http/Routes/company_trip_closing.php').read_text(encoding='utf-8')
assert closing_routes.count('$tripClosingPage);') == 11, 'Closing document endpoints must retain their page gate'
assert closing_routes.count('$tripClosingRegistryPage);') == 10, 'Logistics registry endpoints must retain their dedicated page gate'
assert "'/company/logistics/closing-documents'" in closing_routes
assert ("app/Http/Routes/company_trip_closing.php", "/company/trips/closing-documents/archive.zip") in company_routes
assert '/company/trips/closing-documents/{tripId}/payment-stage' not in closing_routes
assert '/company/trips/closing-documents/{tripId}/invoices/link' in closing_routes
assert "/company/trips/closing-documents/{tripId}/diagnostic-card',[$tripClosingController,'uploadDiagnosticCard'],$tripClosingPage" in closing_routes
assert ("app/Http/Routes/company_finance_invoices.php", "/company/finance/invoices/import") in company_routes
assert ("app/Http/Routes/company_finance_invoices.php", "/company/finance/invoices/{id}/imported-file") in company_routes
invoice_routes = (ROOT / 'app/Http/Routes/company_finance_invoices.php').read_text(encoding='utf-8')
assert "[$controller, 'importSubmit'], $invoicesPage" in invoice_routes
assert "[$controller, 'importDownload'], $invoicesPage" in invoice_routes
assert ("app/Http/Routes/company_bank_finance.php", "/company/finance/bank-accounts/import/{id}/download") in company_routes
review_routes = (ROOT / 'app/Http/Routes/company_route_executors.php').read_text(encoding='utf-8')
assert review_routes.count('$reviewController,') == 4
assert review_routes.count('$routeExecutorsPage);') == 11, 'Review endpoints must retain the route executor role gate'
documents = (ROOT / 'app/Http/Routes/company_trip_documents.php').read_text(encoding="utf-8")
assert documents.count('$router->') == 16
assert "'company.documents.request'" in documents and "'company.documents.collection'" in documents
tn_review_routes = (ROOT / 'app/Http/Routes/company_trip_completion.php').read_text(encoding='utf-8')
assert tn_review_routes.count('$tnReviewPage);') == 10, 'Every TN reviewer endpoint needs its own role gate'
assert tn_review_routes.count('$tripCompletionPage);') == 11, 'Registration endpoints must retain their role gate'
trip_routes = (ROOT / "app/Http/Routes/company_linear_trips.php").read_text(encoding="utf-8")
assert "/company/trips/departures/synchronize" in trip_routes
assert "/company/trips/departures/{id}/delete" in trip_routes
assert "'/company/warehouses/stock'" in trip_routes and "'company.warehouses.stock'" in trip_routes
assert "'/company/warehouses/stock/correction-preview'" in trip_routes
assert "'/company/warehouses/stock/correction'" in trip_routes
assert "'/company/warehouses/stock/cancellation-preview'" in trip_routes
assert "'/company/warehouses/stock/cancellation'" in trip_routes
assert "'/company/warehouses/stock/exclude-issue'" in trip_routes
assert "'/company/warehouses/stock/restore-issue'" in trip_routes
assert "/company/trips/departures/legacy" not in trip_routes
statistics_routes = (ROOT / "app/Http/Routes/company_statistics.php").read_text(encoding="utf-8")
assert statistics_routes.count('$router->get(') == 4
assert "'/company/statistics/managers'" in statistics_routes and "'company.statistics.managers'" in statistics_routes
assert "'/company/statistics/work'" in statistics_routes and "'company.statistics.work'" in statistics_routes
assert "'/company/statistics/feo-share'" in statistics_routes and "'company.statistics.feo_share'" in statistics_routes
assert "'/company/statistics/cargo-balance'" in statistics_routes and "'company.statistics.cargo_balance'" in statistics_routes
max_routes = (ROOT / "app/Http/Routes/company_max_messaging.php").read_text(encoding="utf-8")
for action in ("saveEvent", "previewEvent", "retryEvent"):
    assert f"[$maxMessagingController, '{action}'], $maxSettingsPage" in max_routes, action
assert unmapped == [("app/Http/Routes/company_contractor_assignments.php", "/company/contractor-assignments/{id}/assign")], unmapped
contractor_source = (ROOT / unmapped[0][0]).read_text(encoding="utf-8")
assert "entity_access_grants" in contractor_source and "company.roles_access" not in contractor_source
dashboard_source = (ROOT / "app/Http/Routes/company_dashboard.php").read_text(encoding="utf-8")
assert dashboard_source.count("AccessRuntime::current()->isActive()") >= 2
assert dashboard_source.count("http_response_code(410)") >= 2
assert "$uri !== '/company/switch'" in (ROOT / "app/Http/Router.php").read_text(encoding="utf-8")
index_source = (ROOT / "public/index.php").read_text(encoding="utf-8")
assert "ERP_ROLES_ACCESS_ENABLED" in index_source and "if ($rolesAccessEnabled)" in index_source
assert "AccessRuntime::current()->compatibilityRoleCode()" in index_source
assert "$_SESSION['role_code'] = $compatibilityRoleCode" in index_source
http_runtime = (ROOT / "app/Support/http_runtime.php").read_text(encoding="utf-8")
assert "!in_array('superadmin', $allowed, true)" in http_runtime, "company ACL must never bypass technical superadmin role boundary"
activation = index_source[index_source.index("if ($rolesAccessEnabled)"):index_source.index("// Route registration")]
assert "catch (" not in activation
for route_file in ("company_document_generator.php", "company_switch.php", "company_roles_access.php"):
    assert route_file in index_source
print(f"roles access complete route registry: PASS ({len(company_routes)} routes; four review endpoints; four statistics ACLs; 1 retired legacy writer)")
