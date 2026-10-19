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
assert ui_standard.GALLERY.read_text(encoding="utf-8") == ui_standard.render_gallery(catalog)

for page_id in [p['id'] for p in catalog['pages'] if p['family']=='registry']:
    missing_runtime=copy.deepcopy(catalog)
    next(page for page in missing_runtime['pages'] if page['id']==page_id).pop('runtime_browser_test',None)
    if page_id not in ui_standard.RUNTIME_FIXTURE_DEBT:
        assert any(f'pages/{page_id}/runtime_browser_test' in error for error in ui_standard.validate(missing_runtime))

    missing_icon=copy.deepcopy(catalog)
    next(page for page in missing_icon['pages'] if page['id']==page_id).pop('header_icon')
    assert any(f'pages/{page_id}/header_icon' in error for error in ui_standard.validate(missing_icon))

    wrong_pack=copy.deepcopy(catalog)
    next(page for page in wrong_pack['pages'] if page['id']==page_id)['header_icon']='invented-pack:hand-drawn'
    assert any(f'pages/{page_id}/header_icon' in error for error in ui_standard.validate(wrong_pack))

    wrong_name=copy.deepcopy(catalog)
    next(page for page in wrong_name['pages'] if page['id']==page_id)['header_icon']['name']='hand-drawn'
    assert any(f'pages/{page_id}/header_icon' in error for error in ui_standard.validate(wrong_name))

    wrong_source=copy.deepcopy(catalog)
    next(page for page in wrong_source['pages'] if page['id']==page_id)['header_icon']['source']='app/View/components/tabler/outline/fake.svg'
    assert any(f'pages/{page_id}/header_icon' in error for error in ui_standard.validate(wrong_source))

    missing_inventory=copy.deepcopy(catalog)
    next(page for page in missing_inventory['pages'] if page['id']==page_id).pop('table_surfaces')
    assert any(f'pages/{page_id}: explicit scoped table_surfaces' in error for error in ui_standard.validate(missing_inventory))

    missing_state=copy.deepcopy(catalog)
    next(page for page in missing_state['pages'] if page['id']==page_id).pop('table_states')
    assert any(f'pages/{page_id}: table_states' in error for error in ui_standard.validate(missing_state))

    missing_async_decision=copy.deepcopy(catalog)
    next(page for page in missing_async_decision['pages'] if page['id']==page_id).pop('async_search')
    assert any(f'pages/{page_id}: explicit async_search' in error for error in ui_standard.validate(missing_async_decision))

missing_async_execution=copy.deepcopy(catalog)
next(page for page in missing_async_execution['pages'] if page['id']=='feo-map-placement')['async_search']['browser_test']='tests/registry_visual_gate_test.mjs'
assert any('pages/feo-map-placement/async_search' in error for error in ui_standard.validate(missing_async_execution))

missing_dynamic_state=copy.deepcopy(catalog)
next(page for page in missing_dynamic_state['pages'] if page['id']=='feo-map-placement')['table_states']=[{'id':'initial','visible':['main']}]
assert any('pages/feo-map-placement: async_search required_states' in error for error in ui_standard.validate(missing_dynamic_state))

new_registry=copy.deepcopy(catalog)
entry=copy.deepcopy(next(page for page in catalog['pages'] if page['id']=='contractors'))
entry['id']='new-registry';entry.pop('runtime_browser_test');entry.pop('header_icon')
new_registry['pages'].append(entry)
assert any('pages/new-registry/runtime_browser_test' in error for error in ui_standard.validate(new_registry))
assert any('pages/new-registry/header_icon' in error for error in ui_standard.validate(new_registry))
entry['header_icon']=copy.deepcopy(next(page for page in catalog['pages'] if page['id']=='contractors')['header_icon'])
assert not any('pages/new-registry/header_icon' in error for error in ui_standard.validate(new_registry))

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

without_specimen = copy.deepcopy(catalog)
without_specimen["components"][0].pop("specimen")
assert any("specimen" in error for error in ui_standard.validate(without_specimen))

new_legacy = copy.deepcopy(catalog)
new_legacy['pages'].append({**new_legacy['pages'][0], 'id':'new-table', 'adaptation_status':'legacy_shared_shell_pending_20261018'})
assert any('legacy exception is closed' in error for error in ui_standard.validate(new_legacy))

false_current = copy.deepcopy(catalog)
false_current['pages'][0]['adaptation_status']='current_20261018'
false_current['pages'][0]['view']='app/View/partials/registry_ui_head.php'
assert any('current canon requires registry-ui-data' in error for error in ui_standard.validate(false_current))
false_current['pages'][0]['data_roles']=[]
assert any('data_roles must cover' in error for error in ui_standard.validate(false_current))
false_current['pages'][0]['data_roles']=['primary']
assert any('data_roles must cover' in error for error in ui_standard.validate(false_current))

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
        "description": "Test fixture for gallery registration.",
        "specimen": "docs/ui/specimens/registry.composition.html",
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
