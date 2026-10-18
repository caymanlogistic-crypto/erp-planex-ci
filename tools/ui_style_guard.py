#!/usr/bin/env python3
"""Reject new parallel presentation outside the approved UI style owners.

Compare declaration inventories, not added lines: formatting an old rule is harmless;
copying, renaming or changing it is a new declaration. Existing debt may shrink only.
"""
from __future__ import annotations

import argparse
from collections import Counter
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
CATALOG = "docs/ui/approved-components.json"
STYLE_OWNERS = frozenset({"public/assets/css/erp-ui.css", "public/assets/css/registry-components.css"})
# The existing shared behavior owns only measured sticky/column geometry. This
# does not exempt a page script, inline CSS or a newly registered family.
RUNTIME_OWNER = "public/assets/js/registry-components.js"
# These describe data geometry, not the appearance of a reusable control.
GEOMETRY = frozenset("width min-width max-width text-align vertical-align white-space overflow text-overflow word-break overflow-wrap".split())
# Only explicit terminal table cells/columns may own business geometry. A bare
# renamed class is not proof that it belongs to a column (it may be a control).
COLUMN_TARGET = re.compile(r"(?:^|[ >+~])(?:col|th|td)(?=[.#[:]|$)(?:[.#][\w-]+|\[[^\]{}]+\]|:nth-(?:child|of-type)\([^()]+\))*$")


def business_geometry(selector: str, prop: str) -> bool:
    return prop in GEOMETRY and bool(COLUMN_TARGET.search(selector))
SHARED = re.compile(r"\.(?:registry-ui-[\w-]+|page-head(?:-[\w-]+)?|page-title|page-summary|table-toolbar|toolbar-(?:right|select)|found-label|lab-reference-[\w-]+|btn(?:-[\w-]+)?|field-(?:input|select|label|textarea)|modal-(?:head|body|foot))\b")
SOURCE = re.compile(r"^(?:public/assets/.*\.(?:css|js|mjs)|app/(?:View|Http/Controllers)/.*\.php)$")


def split_css(text: str, separator: str):
    """Split outside strings, parentheses and brackets (URLs may contain ';')."""
    start = depth = 0
    quote = None
    escaped = False
    for i, char in enumerate(text):
        if escaped:
            escaped = False
        elif char == "\\":
            escaped = True
        elif quote:
            if char == quote:
                quote = None
        elif char in "\"'":
            quote = char
        elif char in "([":
            depth += 1
        elif char in ")]":
            depth -= 1
        elif char == separator and depth == 0:
            yield text[start:i]
            start = i + 1
    yield text[start:]


def normalize(text: str) -> str:
    return re.sub(r"\s+", " ", text.strip())


def declarations(text: str):
    for item in split_css(text, ";"):
        pieces = list(split_css(item, ":"))
        if len(pieces) >= 2:
            prop = pieces[0].strip().lower()
            if re.fullmatch(r"(?:--)?[a-z][\w-]*", prop):
                yield prop, normalize(":".join(pieces[1:]))


def css_inventory(text: str, context: str = "") -> Counter:
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    inventory = Counter()
    for imported in re.findall(r"(?im)^\s*(@import\s+[^;]+;)", text):
        inventory[(context, "stylesheet import", "@import", normalize(imported))] += 1
    text = re.sub(r"(?im)^\s*@import\s+[^;]+;", "", text)
    text = re.sub(r"(?im)^\s*@layer\s+[^;{}]+;", "", text)
    # Find balanced blocks; recursively handle media/supports/layers and CSS nesting.
    start = 0
    while start < len(text):
        quote = None
        escaped = False
        opening = None
        for i in range(start, len(text)):
            c = text[i]
            if escaped: escaped = False
            elif c == "\\": escaped = True
            elif quote:
                if c == quote: quote = None
            elif c in "\"'": quote = c
            elif c == "{": opening = i; break
        if opening is None:
            break
        depth = 1
        quote = None
        escaped = False
        end = opening + 1
        while end < len(text) and depth:
            c = text[end]
            if escaped: escaped = False
            elif c == "\\": escaped = True
            elif quote:
                if c == quote: quote = None
            elif c in "\"'": quote = c
            elif c == "{": depth += 1
            elif c == "}": depth -= 1
            end += 1
        if depth:
            raise ValueError("unbalanced CSS block")
        selector = normalize(text[start:opening])
        body = text[opening + 1:end - 1]
        if selector.startswith("@") and not selector.startswith(("@font-face", "@page")):
            inventory.update(css_inventory(body, context + selector + " / "))
        else:
            for target in split_css(selector, ","):
                for prop, value in declarations(body.split("{", 1)[0] if "{" in body else body):
                    inventory[(context, normalize(target), prop, value)] += 1
            if "{" in body:
                boundary = body.rfind(";", 0, body.index("{")) + 1
                inventory.update(css_inventory(body[boundary:], context + selector + " / "))
        start = end
    return inventory


def inventory(path: str, source: str) -> Counter:
    if path.endswith(".css"):
        return css_inventory(source)
    result = Counter()
    for block in re.findall(r"<style\b[^>]*>(.*?)</style\s*>", source, re.I | re.S):
        result.update(css_inventory(block))
    for attr in re.finditer(r"\bstyle\s*=\s*(?:([\"'])(.*?)\1|([^\s>]+))", source, re.I | re.S):
        for prop, value in declarations(attr[2] if attr[1] else attr[3]):
            result[("", "inline style", prop, value)] += 1
    # Also reject CSS injection and style mutations under different class names.
    js = re.sub(r"/\*.*?\*/|^[ \t]*//[^\n]*", "", source, flags=re.S | re.M)
    js = re.sub(r"\[\s*(['\"])([A-Za-z_$][\w$]*)\1\s*\]", lambda match: '.'+match[2], js)
    pattern = r"(?:\.style\s*(?:\.[\w]+|\[[^\]]+\])?\s*(?:=|\+=)|\.style\.(?:setProperty|removeProperty)\s*\(|Object\.assign\s*\([^,;]*\.style\s*,|\.(?:setAttribute|setAttributeNS)\s*\(\s*['\"]style['\"]|\.(?:insertRule|addRule|replaceSync)\s*\(|createElement\s*\(\s*['\"]style['\"]|adoptedStyleSheets\s*=)"
    for mutation in re.finditer(pattern, js):
        statement = normalize(js[mutation.start():].split(";", 1)[0].split("\n", 1)[0])
        result[("", "runtime style", "style-mutation", statement)] += 1
    return result


def violations(path: str, before: str, after: str, owners: set[str]) -> list[str]:
    if path in owners:
        return []
    errors = []
    for (context, selector, prop, value), count in (inventory(path, after) - inventory(path, before)).items():
        if path == RUNTIME_OWNER and prop == "style-mutation" and re.match(r"\.style\.(?:setProperty\(['\"]--registry-(?:toolbar-height|id-width)['\"]|transform\s*=)", value):
            continue
        # Column geometry is allowed; shared controls and all inline/runtime styling
        # must use their owner, including custom properties and !important overrides.
        if business_geometry(selector, prop) and not SHARED.search(selector + context):
            continue
        errors.append(f"{path}: {context}{selector} {{ {prop}: {value} }} — use the approved shared component ({count} new)")
    return errors


def git(*args: str, root: Path = ROOT, optional: bool = False) -> str:
    result = subprocess.run([os.environ.get("PLANEX_GIT", "git"), "-C", str(root), *args], capture_output=True, encoding="utf-8")
    if result.returncode:
        if optional: return ""
        raise ValueError(result.stderr.strip() or "git command failed")
    return result.stdout


def check(base: str, head: str | None = None, root: Path = ROOT) -> list[str]:
    git("rev-parse", "--verify", base + "^{commit}", root=root)
    if head:
        git("rev-parse", "--verify", head + "^{commit}", root=root)
    read = lambda revision, path: git("show", f"{revision}:{path}", root=root, optional=True) if revision else (root / path).read_text(encoding="utf-8") if (root / path).is_file() else ""
    data = json.loads(read(head, CATALOG))
    base_data = json.loads(read(base, CATALOG))
    # Only existing approved CSS owners can bypass the incremental guard. Adding a
    # new owner is a separate OWNER-approved control-plane change, never a CSS escape.
    owners = set(STYLE_OWNERS)
    paths = git("diff", "--no-renames", "--name-only", base, *([head] if head else []), root=root).splitlines()
    if head is None:
        paths += git("ls-files", "--others", "--exclude-standard", root=root).splitlines()
    errors = []
    registered = {p["view"] for p in data["pages"]}
    for path in sorted(set(paths)):
        if not SOURCE.fullmatch(path): continue
        before, after = read(base, path), read(head, path)
        errors.extend(violations(path, before, after, owners))
        if not before and path.startswith("app/View/pages/") and re.search(r"<table\b|\btable-card\b", after) and path not in registered:
            errors.append(f"{path}: new table page must use and register an approved UI family")
    for page in base_data["pages"]:
        if read(head, page["view"]) and page["view"] not in registered:
            errors.append(f"{page['view']}: removing an existing page from the approved catalog is forbidden")
        current = next((item for item in data['pages'] if item['view']==page['view']),None)
        if current and page.get('adaptation_status')=='current_20261018' and current.get('adaptation_status')!='current_20261018':
            errors.append(f"{page['view']}: downgrading an adapted page to legacy is forbidden")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", help="omit to check the current working tree, including untracked files")
    args = parser.parse_args()
    try:
        errors = check(args.base, args.head)
        if errors:
            print("UI_STYLE_GUARD=FAIL\n" + "\n".join(errors), file=sys.stderr)
            return 1
        print("UI_STYLE_GUARD=PASS")
        return 0
    except (OSError, ValueError, KeyError, json.JSONDecodeError) as exc:
        print("UI_STYLE_GUARD=ERROR\n" + str(exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
