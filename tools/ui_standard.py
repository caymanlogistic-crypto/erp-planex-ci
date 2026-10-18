#!/usr/bin/env python3
"""Discover and extend the OWNER-approved ERP UI component catalog."""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "docs/ui/approved-components.json"
ID = re.compile(r"^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$")


def safe_file(value: str) -> Path:
    path = (ROOT / value).resolve()
    if not path.is_relative_to(ROOT) or not path.is_file():
        raise ValueError(f"missing or unsafe repository file: {value}")
    return path


def load() -> dict:
    return json.loads(CATALOG.read_text(encoding="utf-8"))


def validate(data: dict) -> list[str]:
    errors: list[str] = []
    if data.get("schema_version") != 1:
        errors.append("schema_version must be 1")
    families: dict[str, dict] = {}
    for family in data.get("families", []):
        name = family.get("id", "")
        if not ID.fullmatch(name) or name in families:
            errors.append(f"invalid or duplicate family: {name}")
        families[name] = family
        if not family.get("approval") or not family.get("reference_route", "").startswith("/erpv2/"):
            errors.append(f"{name}: approval and ERP reference route required")
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
            else:
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
                if item.get("view") and (ROOT / item["view"]).is_file():
                    source = safe_file(item["view"]).read_text(encoding="utf-8")
                    if "registry-ui-card" not in source:
                        errors.append(f"pages/{name}: view lacks registry-ui-card")
    registered = {item.get("view") for item in data.get("pages", [])}
    for view in (ROOT / "app/View").rglob("*.php"):
        if "registry-ui-card" in view.read_text(encoding="utf-8") and view.relative_to(ROOT).as_posix() not in registered:
            errors.append(f"unregistered registry view: {view.relative_to(ROOT).as_posix()}")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("check", help="validate catalog and page coverage")
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
        if args.command == "register":
            CATALOG.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            print(f"UI_STANDARD_REGISTERED={args.kind}:{entry['id']}")
        elif args.command == "check":
            print("UI_STANDARD_CATALOG=PASS")
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
