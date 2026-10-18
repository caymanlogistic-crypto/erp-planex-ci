"""Prove common parallel-style escapes fail while old debt can be removed."""
from pathlib import Path
import sys
import tempfile
import json
import subprocess
import os

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tools"))
from ui_style_guard import violations, check

owners = {"public/assets/css/registry-components.css", "public/assets/css/erp-ui.css"}
bad = [
    ("public/assets/css/page.css", ".my-search {height:34px;background:#fff}"),
    ("public/assets/css/page.css", ".toolbar-select {width:180px}"),
    ("public/assets/css/page.css", "@media(max-width:700px){.page-head{padding:12px}}"),
    ("public/assets/css/page.css", ".some-alias{--font:Arial;color:var(--accent)}"),
    ("public/assets/css/page.css", ".other :is(input,select) {font:14px Arial!important}"),
    ("app/View/pages/example.php", '<style>.page-head{height:72px}</style>'),
    ("app/View/pages/example.php", '<input class="renamed" style="border:2px solid red">'),
    ("public/assets/js/example.js", "control.style.backgroundColor = '#fff';"),
    ("public/assets/js/example.js", "control.style.setProperty('--control-height', '40px');"),
    ("public/assets/js/example.js", "control.setAttribute('style', 'color:red');"),
    ("public/assets/js/example.js", "sheet.insertRule('.alias{color:red}');"),
    ("public/assets/js/example.js", "document.createElement('style');"),
    ("public/assets/js/example.mjs", "Object.assign(control.style, {background:'red'});"),
    ("public/assets/css/page.css", '@import "parallel.css";'),
    ("public/assets/css/page.css", '@layer reused; .copy{color:red}'),
]
for path, source in bad:
    assert violations(path, "", source, owners), (path, source)
old = ".old { color:red; padding:3px; }"
assert not violations("public/assets/css/page.css", old, "/* format */ .old{color:red;padding:3px}", owners)
assert not violations("public/assets/css/page.css", old, ".old{color:red}", owners)
assert violations("public/assets/css/page.css", old, old + old, owners)
assert violations("public/assets/css/page.css", old, old.replace(".old", ".copy"), owners)
assert not violations("public/assets/css/page.css", "", ".business-column{width:20%;text-align:right}", owners)
assert not violations("public/assets/css/registry-components.css", "", old, owners)
assert not violations('public/assets/js/registry-components.js','','head.style.transform = value;',owners)
assert not violations('public/assets/js/registry-components.js','',"table.style.setProperty('--registry-id-width', value);",owners)
assert violations('public/assets/js/registry-components.js','','head.style.color = value;',owners)
assert violations('public/assets/js/another.js','','head.style.transform = value;',owners)
# Quoted semicolons/braces, URLs and media contexts must not confuse the inventory.
assert not violations("public/assets/css/page.css", '.old{background:url("a;b{c}.svg")}', '.old { background: url("a;b{c}.svg"); }', owners)
assert violations("public/assets/css/page.css", old, "@media(max-width:700px){" + old + "}", owners)

# Real Git comparison catches untracked sources and catalog removal, not only a
# caller-supplied list. A new family cannot exempt its parallel stylesheet.
with tempfile.TemporaryDirectory(prefix="planex-ui-guard-") as temporary:
    root = Path(temporary)
    def run(*args):
        return subprocess.check_output([os.environ.get("PLANEX_GIT", "git"), "-C", str(root), *args], text=True).strip()
    def write(path, text):
        target = root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text, encoding="utf-8")
    run("init", "-q")
    run("config", "user.email", "test@example.invalid")
    run("config", "user.name", "UI guard fixture")
    catalog = {"families": [{"styles": "public/assets/css/registry-components.css"}], "pages": [{"view": "app/View/pages/list.php"}]}
    write("docs/ui/approved-components.json", json.dumps(catalog))
    write("app/View/pages/list.php", '<div class="registry-ui-card"></div>')
    run("add", "."); run("commit", "-qm", "baseline")
    base = run("rev-parse", "HEAD")
    write("public/assets/css/new.css", ".renamed-search{height:35px}")
    assert any("height" in error for error in check(base, root=root))
    catalog["families"].append({"styles": "public/assets/css/new.css"})
    catalog["pages"] = []
    write("docs/ui/approved-components.json", json.dumps(catalog))
    errors = check(base, root=root)
    assert any("height" in error for error in errors)
    assert any("removing" in error for error in errors)
    write("app/View/pages/another.php", '<table><tr><td>Business data</td></tr></table>')
    assert any("new table page" in error for error in check(base, root=root))
    run("add", "."); run("commit", "-qm", "candidate")
    assert any("height" in error for error in check(base, "HEAD", root))
    # Even a catalog family already present on canonical is not a CSS owner.
    run("add", ".")
    newer_base=run("rev-parse", "HEAD")
    write("public/assets/css/new.css", ".renamed-search{height:39px}")
    assert any("height" in error for error in check(newer_base, root=root))

print("UI_STYLE_GUARD_TEST=PASS")
