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
    source = path.read_text()
    for call, argc in calls(source):
        route = re.search(r"\(\s*['\"](/company[^'\"]*)", call, re.S)
        if route is None: continue
        item = (path.relative_to(ROOT).as_posix(), route.group(1))
        company_routes.append(item)
        if argc < 3: unmapped.append(item)

assert len(company_routes) == 310, f"company route inventory drift: {len(company_routes)}"
assert unmapped == [("app/Http/Routes/company_contractor_assignments.php", "/company/contractor-assignments/{id}/assign")], unmapped
contractor_source = (ROOT / unmapped[0][0]).read_text()
assert "entity_access_grants" in contractor_source and "company.roles_access" not in contractor_source
dashboard_source = (ROOT / "app/Http/Routes/company_dashboard.php").read_text()
assert dashboard_source.count("AccessRuntime::current()->isActive()") >= 2
assert dashboard_source.count("http_response_code(410)") >= 2
assert "$uri !== '/company/switch'" in (ROOT / "app/Http/Router.php").read_text()
index_source = (ROOT / "public/index.php").read_text()
assert "ERP_ROLES_ACCESS_ENABLED" in index_source and "if ($rolesAccessEnabled)" in index_source
activation = index_source[index_source.index("if ($rolesAccessEnabled)"):index_source.index("// Route registration")]
assert "catch (" not in activation
for route_file in ("company_document_generator.php", "company_switch.php", "company_roles_access.php"):
    assert route_file in index_source
print("roles access complete route registry: PASS (310 routes; 1 retired legacy writer)")
