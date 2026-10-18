#!/usr/bin/env python3
"""Discover and extend the OWNER-approved ERP UI component catalog."""
from __future__ import annotations

import argparse
import html
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "docs/ui/approved-components.json"
GALLERY = ROOT / "docs/ui/approved-components.html"
ID = re.compile(r"^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$")
LEGACY_PAGES = frozenset({'route-executors','trips-departures','finance-received-paid','logistics-closing-documents'})
DATA_ROLES = frozenset({'primary','name','secondary','money','id','trip','missing','due','status','total','reason'})
PAGE_DATA_ROLES = {
    'route-executors': {'primary','name','secondary','status'},
    'trips-departures': {'primary','name','secondary','money','id','trip','missing','status'},
    'finance-received-paid': {'primary','name','secondary','money','id','trip','missing','due','total'},
    'logistics-closing-documents': {'primary','name','secondary','id','trip','missing','status'},
}


def safe_file(value: str) -> Path:
    path = (ROOT / value).resolve()
    if not path.is_relative_to(ROOT) or not path.is_file():
        raise ValueError(f"missing or unsafe repository file: {value}")
    return path


def load() -> dict:
    return json.loads(CATALOG.read_text(encoding="utf-8"))


def validate(data: dict) -> list[str]:
    errors: list[str] = []
    if data.get("schema_version") != 2:
        errors.append("schema_version must be 2")
    try:
        decisions=json.loads(safe_file(data.get('decisions','')).read_text(encoding='utf-8'))
        if decisions.get('schema')!='planex-approved-ui-decisions/v1' or not decisions.get('references'):
            errors.append('recorded OWNER decisions and immutable reference versions required')
    except (ValueError, OSError) as exc:
        errors.append(f'decisions: {exc}')
    families: dict[str, dict] = {}
    for family in data.get("families", []):
        name = family.get("id", "")
        if not ID.fullmatch(name) or name in families:
            errors.append(f"invalid or duplicate family: {name}")
        families[name] = family
        if not family.get("approval") or not family.get("description") or not family.get("reference_route", "").startswith("/erpv2/"):
            errors.append(f"{name}: approval, description and ERP reference route required")
        for value in [family.get("canon", ""), family.get("styles", ""), *family.get("behavior", [])]:
            try:
                safe_file(value)
            except ValueError as exc:
                errors.append(f"{name}: {exc}")
    for kind in ("components", "pages"):
        seen: set[str] = set()
        for item in data.get(kind, []):
            name = item.get("id", "")
            if not ID.fullmatch(name) or name in seen:
                errors.append(f"invalid or duplicate {kind} id: {name}")
            seen.add(name)
            family = families.get(item.get("family"))
            if family is None:
                errors.append(f"{kind}/{name}: unknown family")
                continue
            if kind == "components":
                selector = item.get("selector", "")
                if not item.get("role") or not item.get("approval") or not selector.startswith("."):
                    errors.append(f"components/{name}: role, approval and class selector required")
                elif selector not in safe_file(family["styles"]).read_text(encoding="utf-8"):
                    errors.append(f"components/{name}: selector absent from shared stylesheet")
                if item.get("markup"):
                    try:
                        safe_file(item["markup"])
                    except ValueError as exc:
                        errors.append(f"components/{name}: {exc}")
                if not item.get("description"):
                    errors.append(f"components/{name}: gallery description required")
                try:
                    specimen = safe_file(item.get("specimen", ""))
                    if specimen.suffix != ".html" or not specimen.relative_to(ROOT).as_posix().startswith("docs/ui/specimens/"):
                        errors.append(f"components/{name}: specimen must be in docs/ui/specimens")
                    elif selector.split()[-1].lstrip(".") not in specimen.read_text(encoding="utf-8") and not (name == "registry.select" and "data-table-reference-select" in specimen.read_text(encoding="utf-8")):
                        errors.append(f"components/{name}: specimen lacks shared selector")
                except ValueError as exc:
                    errors.append(f"components/{name}/specimen: {exc}")
            else:
                status=item.get('adaptation_status')
                if status!='current_20261018' and not (name in LEGACY_PAGES and status=='legacy_shared_shell_pending_20261018'):
                    errors.append(f'pages/{name}: new pages must adopt current_20261018; legacy exception is closed')
                if status=='current_20261018':
                    roles=item.get('data_roles')
                    minimum=PAGE_DATA_ROLES.get(name, {'primary'})
                    if not isinstance(roles,list) or not roles or any(not isinstance(role,str) or role not in DATA_ROLES for role in roles) or len(set(roles))!=len(roles) or not minimum.issubset(roles):
                        errors.append(f'pages/{name}: data_roles must cover applicable canonical roles, including {sorted(minimum)}')
                if not item.get("route", "").startswith("/erpv2/"):
                    errors.append(f"pages/{name}: ERP route required")
                if item.get("family") == "registry" and (type(item.get("sticky")) is not bool or type(item.get("reference_select")) is not bool):
                    errors.append(f"pages/{name}: sticky and reference_select must be explicit booleans")
                for field in ("view", "browser_test", "asset_owner"):
                    if field == "asset_owner" and not item.get(field):
                        continue
                    try:
                        safe_file(item.get(field, ""))
                    except ValueError as exc:
                        errors.append(f"pages/{name}/{field}: {exc}")
                if item.get("family") == "registry" and item.get("browser_test") and (ROOT / item["browser_test"]).is_file():
                    test_source = safe_file(item["browser_test"]).read_text(encoding="utf-8")
                    if "assertRegistryVisualContract" not in test_source:
                        errors.append(f"pages/{name}: browser test must invoke shared registry visual contract")
                    if status=='current_20261018' and ('assertRegistryDataContract' not in test_source or name not in test_source):
                        errors.append(f'pages/{name}: current browser test must invoke assertRegistryDataContract with catalog page id')
                if item.get("view") and (ROOT / item["view"]).is_file():
                    source = safe_file(item["view"]).read_text(encoding="utf-8")
                    if "registry-ui-card" not in source:
                        errors.append(f"pages/{name}: view lacks registry-ui-card")
                    if status=='current_20261018':
                        for role in ('registry-ui-data','data-registry-id','data-registry-state-scope','data-registry-field','data-registry-default','registry-ui-reset','registry-ui-search-group'):
                            if role not in source:
                                errors.append(f'pages/{name}: current canon requires {role}')
    registered = {item.get("view") for item in data.get("pages", [])}
    for view in (ROOT / "app/View").rglob("*.php"):
        if "registry-ui-card" in view.read_text(encoding="utf-8") and view.relative_to(ROOT).as_posix() not in registered:
            errors.append(f"unregistered registry view: {view.relative_to(ROOT).as_posix()}")
    return errors


def render_gallery(data: dict, *, hosted: bool = False) -> str:
    """One complete live composition; the role index never repeats controls."""
    asset_prefix = "/assets/" if hosted else "../../public/assets/"
    gallery_prefix = "/__ui_standard__/" if hosted else ""
    css_paths = ["public/assets/css/erp-ui.css", "public/assets/css/route-executor-review-workflow.css", "public/assets/css/route-executor-list-v016.css", *(x["styles"] for x in data["families"])]
    css_links = ''.join(f'<link rel="stylesheet" href="{html.escape(asset_prefix + path.removeprefix("public/assets/"), quote=True)}">' for path in css_paths)
    js_links = ''.join(f'<script src="{html.escape(asset_prefix + path.removeprefix("public/assets/"), quote=True)}"></script>' for path in dict.fromkeys(path for family in data["families"] for path in family["behavior"]))
    js_links += f'<script src="{asset_prefix}js/route-executor-list-v016.js"></script>'
    specimen = safe_file("docs/ui/specimens/registry.composition.html").read_text(encoding="utf-8")
    document = ('<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'+css_links+f'<link rel="stylesheet" href="{gallery_prefix}preview.css"></head><body><main data-specimen-root>'+specimen+'</main>'+js_links+f'<script src="{gallery_prefix}gallery.js"></script></body></html>')
    roles = ''.join(f'<li><strong>{html.escape(item["role"])}</strong> — {html.escape(item["description"])} <code>{html.escape(item["id"])}</code></li>' for item in data['components'])
    return ('<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>PLANEX — согласованный канон</title>'
            +f'<link rel="stylesheet" href="{asset_prefix}css/erp-ui.css"><link rel="stylesheet" href="{gallery_prefix}gallery.css"></head><body><main class="gallery-shell">'
            +'<header class="gallery-intro"><p class="gallery-kicker">PLANEX · решения OWNER 18.10.2026</p><h1>Согласованный вид реестров</h1><p>Одна цельная композиция за раз. Поиск, списки, наведение и «Сбросить» работают на вымышленных данных. Это образцы общих ролей; состав и расчёты реальных страниц сохраняются при адаптации.</p>'
            +'<nav aria-label="Примеры реестров">'+''.join(f'<button type="button" data-gallery-scene="{key}" aria-pressed="{str(key=="executors").lower()}">{label}</button>' for key,label in [('executors','Исполнители'),('finance','Получено/оплачено'),('trips','Выезды'),('documents','Закрывающие документы')])+'</nav></header>'
            +f'<iframe class="gallery-preview" data-preview="registry.composition" title="Цельный пример согласованного реестра" srcdoc="{html.escape(document,quote=True)}"></iframe>'
            +'<details class="gallery-family"><summary>Что здесь утверждено — роли и правила для агента</summary><ul>'+roles+'</ul><p>Используйте общий CSS/JS и docs/ui/REGISTRY_CANON.md. Новые визуальные решения: предложение → песочница → решение OWNER → общий компонент → тест → каталог и витрина.</p></details>'
            +'<section class="gallery-family"><h2>Границы согласования</h2><p>Утверждены роли и состояния четырёх реестров. Формы, подтверждение удаления, загрузки файлов и ожидание серверных операций требуют отдельных примеров. Компания/квартал руководителя: запрос записан, конкретный вариант ещё ожидает визуального подтверждения.</p><p>Подключённая страница не обязательно уже перенесена на все поправки от 18.10.2026: агент обязан проверить статус адаптации и сравнить результат с принятой песочницей. Витрина не утверждает сама себя.</p></section></main>'
            +f'<script src="{gallery_prefix}gallery.js"></script></body></html>\n')



def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("check", help="validate catalog and page coverage")
    sub.add_parser("render", help="regenerate the approved HTML gallery")
    sub.add_parser("html", help="render the same gallery for the localhost Design Lab")
    sub.add_parser("list", help="list approved families, components and pages")
    tests = sub.add_parser("tests", help="print registered browser tests, one path per line")
    test_selection = tests.add_mutually_exclusive_group(required=True)
    test_selection.add_argument("--family")
    test_selection.add_argument("--all", action="store_true")
    show = sub.add_parser("show", help="show one approved family, component or page")
    show.add_argument("id")
    register = sub.add_parser("register", help="register an approved family/component or adapted page")
    register.add_argument("kind", choices=["family", "component", "page"])
    register.add_argument("definition", type=Path, help="JSON object with the catalog fields")
    args = parser.parse_args()
    try:
        data = load()
        if args.command == "register":
            entry = json.loads(args.definition.read_text(encoding="utf-8"))
            if args.kind in ("family", "component") and not entry.get("approval"):
                raise ValueError("new visual pattern requires a recorded OWNER approval")
            key = {"family": "families", "component": "components", "page": "pages"}[args.kind]
            data[key].append(entry)
        errors = validate(data)
        if errors:
            raise ValueError("\n".join(errors))
        if args.command == "check" and (not GALLERY.is_file() or GALLERY.read_text(encoding="utf-8") != render_gallery(data)):
            raise ValueError("HTML gallery is missing or stale; run: python tools/ui_standard.py render")
        if args.command == "register":
            CATALOG.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            print(f"UI_STANDARD_REGISTERED={args.kind}:{entry['id']}")
        elif args.command == "check":
            print("UI_STANDARD_CATALOG=PASS")
        elif args.command == "render":
            GALLERY.write_text(render_gallery(data), encoding="utf-8", newline="\n")
            print(f"UI_STANDARD_GALLERY={GALLERY.relative_to(ROOT).as_posix()}")
        elif args.command == "html":
            sys.stdout.buffer.write(render_gallery(data, hosted=True).encode("utf-8"))
        elif args.command == "list":
            for kind in ("families", "components", "pages"):
                print(f"{kind}: " + ", ".join(item["id"] for item in data[kind]))
        elif args.command == "tests":
            if not args.all and args.family not in {item["id"] for item in data["families"]}:
                raise ValueError(f"unknown family: {args.family}")
            for path in dict.fromkeys(item["browser_test"] for item in data["pages"] if args.all or item["family"] == args.family):
                print(path)
        else:
            item = next((item for kind in ("families", "components", "pages") for item in data[kind] if item["id"] == args.id), None)
            if item is None:
                raise ValueError(f"unknown id: {args.id}")
            print(json.dumps(item, ensure_ascii=False, indent=2))
        return 0
    except (OSError, ValueError, KeyError, json.JSONDecodeError) as exc:
        print(f"UI_STANDARD_CATALOG=FAIL\n{exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
