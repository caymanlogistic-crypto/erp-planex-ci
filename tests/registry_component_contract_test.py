"""Guard the approved registry component boundary for current and future pages."""

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


errors: list[str] = []
pages = {
    "app/View/pages/company_route_executors.php": "app/Http/Controllers/Company/RouteExecutorActions/index.php",
    "app/View/pages/company_waste_trips.php": None,
    "app/View/pages/company_waste_trip_closing_registry.php": None,
    "app/View/partials/finance_received_paid_ledger.php": "app/View/pages/company_finance_received_paid.php",
}
required_roles = ("registry-ui-card", "registry-ui-toolbar", "registry-ui-search", "registry-ui-table", "registry-ui-sticky-table")

for path, script_owner in pages.items():
    source = read(path)
    for role in required_roles:
        if role not in source:
            errors.append(f"{path}: missing {role}")
    if "registry_ui_head.php" not in source or "registryHeadTitle" not in source:
        errors.append(f"{path}: render the approved registry_ui_head.php partial")
    if "registry-components.css" not in source and "registry-components.css" not in read(script_owner or ""):
        errors.append(f"{path}: missing registry-components.css")
    js_source = read(script_owner or path)
    if "registry-components.js" not in js_source:
        errors.append(f"{path}: missing shared toolbar measurement JS")
    if "table-reference-select.js" not in js_source:
        errors.append(f"{path}: missing shared native-backed select JS")

for path in (ROOT / "app/View").rglob("*.php"):
    relative = path.relative_to(ROOT).as_posix()
    if "registry-ui-card" in path.read_text(encoding="utf-8") and relative not in pages:
        errors.append(f"{relative}: register this registry in registry_component_contract_test.py")

css_paths = sorted(path for path in (ROOT / "public/assets/css").glob("*.css") if path.name != "registry-components.css")
shared_properties = re.compile(r"(?:^|;)\s*(?:position\s*:\s*sticky|top\s*:|--(?:waste|registry)-toolbar-height\s*:)", re.I)
shared_targets = re.compile(r"(?:\.rp-ledger-v015[^{}]*\.table-toolbar|\.rp-ledger-v015[^{}]*\.rp-v010-table\s+thead|\.waste-trip-toolbar|\.lab-grid\s+\.lab-table\s+thead)")
for path in css_paths:
    content = path.read_text(encoding="utf-8")
    if "--waste-toolbar-height" in content:
        errors.append(f"{path}: legacy toolbar height duplicates registry-components.js")
    for match in re.finditer(r"([^{}]+)\{([^{}]*)\}", content):
        selector, declarations = match.groups()
        if ".registry-ui-" in selector and ":not(.registry-ui-card)" not in selector:
            errors.append(f"{path}: shared registry-ui-* selector belongs in registry-components.css: {selector.strip()[:90]}")
        if shared_targets.search(selector) and shared_properties.search(declarations) and ":not(.registry-ui-card)" not in selector:
            errors.append(f"{path}: duplicated shared sticky geometry: {selector.strip()[:90]}")
        if ".closing-registry-card" in selector and "background:" in declarations and ":not(.registry-ui-card)" not in selector:
            errors.append(f"{path}: registry card background belongs in registry-components.css")

shared_css = read("public/assets/css/registry-components.css")
for required in (".registry-ui-card.table-card", ".registry-ui-sticky-table", ".registry-ui-toolbar.table-toolbar", ".registry-ui-table thead th"):
    if required not in shared_css:
        errors.append(f"registry-components.css: missing {required}")

if errors:
    raise SystemExit("REGISTRY_COMPONENT_CONTRACT=FAIL\n" + "\n".join(errors))
print("REGISTRY_COMPONENT_CONTRACT=PASS")
