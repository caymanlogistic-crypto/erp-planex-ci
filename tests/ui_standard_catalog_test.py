"""Catalog registrations must stay tied to approved shared implementation."""
from __future__ import annotations

import copy
import json
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
import ui_standard

catalog = ui_standard.load()
assert ui_standard.validate(catalog) == []

unapproved = copy.deepcopy(catalog)
unapproved["components"].append({
    "id": "registry.unapproved",
    "family": "registry",
    "role": "Недопустимый новый компонент",
    "selector": ".registry-ui-card",
})
assert any("approval" in error for error in ui_standard.validate(unapproved))

local_copy = copy.deepcopy(catalog)
local_copy["components"].append({
    "id": "registry.local-copy",
    "family": "registry",
    "role": "Недопустимая копия",
    "selector": ".some-local-card",
    "approval": "OWNER test fixture",
})
assert any("selector absent from shared stylesheet" in error for error in ui_standard.validate(local_copy))

uncovered = copy.deepcopy(catalog)
uncovered["pages"].pop()
assert any("unregistered registry view" in error for error in ui_standard.validate(uncovered))

non_visual = copy.deepcopy(catalog)
non_visual["pages"][0]["browser_test"] = "tests/registry_sticky_scroll_browser_test.mjs"
assert any("shared registry visual contract" in error for error in ui_standard.validate(non_visual))

with tempfile.TemporaryDirectory(prefix="planex-ui-catalog-") as temp:
    root = Path(temp)
    catalog_path = root / "catalog.json"
    definition_path = root / "component.json"
    catalog_path.write_text(json.dumps(catalog, ensure_ascii=False), encoding="utf-8")
    definition_path.write_text(json.dumps({
        "id": "registry.test-variant",
        "family": "registry",
        "role": "Test fixture",
        "selector": ".registry-ui-card",
        "approval": "OWNER test fixture",
    }), encoding="utf-8")
    original_catalog, original_argv = ui_standard.CATALOG, sys.argv
    try:
        ui_standard.CATALOG = catalog_path
        sys.argv = ["ui_standard.py", "register", "component", str(definition_path)]
        assert ui_standard.main() == 0
        assert any(item["id"] == "registry.test-variant" for item in json.loads(catalog_path.read_text(encoding="utf-8"))["components"])
    finally:
        ui_standard.CATALOG, sys.argv = original_catalog, original_argv

print("UI_STANDARD_CATALOG_TEST=PASS")
