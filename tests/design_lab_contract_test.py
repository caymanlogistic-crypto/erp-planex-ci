#!/usr/bin/env python3
from __future__ import annotations

import http.client
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
from urllib.request import build_opener, ProxyHandler

ROOT = Path(__file__).resolve().parents[1]
TOOL = ROOT / "tools" / "design_lab.py"
spec = importlib.util.spec_from_file_location("design_lab", TOOL)
assert spec and spec.loader
design_lab = importlib.util.module_from_spec(spec)
spec.loader.exec_module(design_lab)

waste_preset = design_lab.PRESETS["waste-trips"]
assert waste_preset["target_route"] == "/company/trips/departures"
waste_html = design_lab.render_preset(ROOT, waste_preset).decode("utf-8")
assert "Перевозка отходов" in waste_html
assert "data-waste-view=" in waste_html
assert "data-lab-modal=\"view\"" in waste_html
assert "data-lab-modal=\"edit\"" in waste_html
assert "ООО «Вектор»" in waste_html

closing_preset = design_lab.PRESETS["trip-closing-documents"]
assert closing_preset["target_route"] == "/company/trips/closing-documents"
closing_html = design_lab.render_preset(ROOT, closing_preset).decode("utf-8")
assert 'data-closing-count>35<' in closing_html
assert 'data-closing-filter="ready"' in closing_html
assert 'data-closing-filter="paid_complete"' in closing_html
assert 'payment-stage' not in closing_html
assert 'data-review-form' not in closing_html
assert 'data-closing-trip' in closing_html


def run(*arguments: str, ok: bool = True) -> subprocess.CompletedProcess[str]:
    process = subprocess.run(
        [sys.executable, str(TOOL), *arguments],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    if ok:
        assert process.returncode == 0, process.stdout + process.stderr
    else:
        assert process.returncode == 2, process.stdout + process.stderr
    return process


with tempfile.TemporaryDirectory(prefix="planex-design-lab-contract-") as temporary:
    opener = build_opener(ProxyHandler({}))
    local_root = Path(temporary)
    task_id = "contract-design-lab"
    common = ("--task-id", task_id, "--local-root", str(local_root))
    created = run(
        "create",
        *common,
        "--worktree",
        str(ROOT),
        "--preset",
        "manager-statistics",
    )
    assert "DESIGN_LAB_MODE=SYNTHETIC" in created.stdout

    session = local_root / "design-lab" / task_id
    manifest = json.loads((session / "session.json").read_text(encoding="utf-8"))
    assert manifest["schema"] == design_lab.SCHEMA
    assert manifest["target_route"] == "/company/statistics/managers"
    assert manifest["data_mode"] == "synthetic"
    assert manifest["accepted_version"] is None
    assert manifest["parity_contract"] == design_lab.PARITY_CONTRACT
    assert manifest["parity_contract"]["baseline"] == "current-erp-page-visual-and-behavioral-1-to-1"
    assert manifest["ui_quality_contract"] == design_lab.UI_QUALITY_CONTRACT
    assert "shared-grid-and-aligned-edges" in manifest["ui_quality_contract"]["forms"]
    assert "Статистика" in (session / "baseline.html").read_text(encoding="utf-8")
    checklist = (session / "parity-checklist.md").read_text(encoding="utf-8")
    assert "базовая копия 1:1" in checklist
    assert "До первой дизайнерской правки" in checklist
    quality_checklist = (session / "ui-quality-checklist.md").read_text(encoding="utf-8")
    assert "качество форм и визуальная сетка" in quality_checklist
    assert "Одинаковые по смыслу элементы" in quality_checklist
    assert "до показа варианта OWNER" in quality_checklist

    css = session / "workspace" / "overrides.css"
    css.write_text(".manager-statistics { --design-lab-contract: 1; }\n", encoding="utf-8")
    checkpoint = run("checkpoint", *common, "--note", "contract")
    assert "DESIGN_LAB_CHECKPOINT=v001" in checkpoint.stdout
    run("accept", *common, "--version", "v001")
    run("verify", *common)
    acceptance = json.loads((session / "acceptance.json").read_text(encoding="utf-8"))
    assert acceptance["approved_version"] == "v001"
    assert acceptance["owner_instruction"] == "ВНЕДРЯЙ"

    # Recover existing explicit approval without touching another task's session.
    # The selected immutable files/assets remain identical, not re-rendered from
    # current source or replaced by its later mutable workspace.
    saved_manifest=(session/'session.json').read_bytes()
    saved_acceptance=(session/'acceptance.json').read_bytes()
    (session/'acceptance.json').unlink()
    pending_manifest=json.loads(saved_manifest)
    pending_manifest['accepted_version']=None
    (session/'session.json').write_text(json.dumps(pending_manifest),encoding='utf-8')
    original_manifest=(session/'session.json').read_bytes()
    css.write_text('/* later unapproved experiment */',encoding='utf-8')
    imported_common=('--task-id','owned-reference','--local-root',str(local_root))
    run('import-reference',*imported_common,'--source-task-id',task_id,'--worktree',str(ROOT),'--version','v001','--owner-instruction','OWNER: implement agreed pages','--decision-source','OWNER message 2026-10-19','--exclude-role','new forms')
    imported_session=local_root/'design-lab/owned-reference'
    imported_acceptance=json.loads((imported_session/'acceptance.json').read_text(encoding='utf-8'))
    assert imported_acceptance['reference_origin']['source_task_id']==task_id
    assert imported_acceptance['reference_origin']['excluded_roles']==['new forms']
    assert (imported_session/'workspace/overrides.css').read_bytes()==(session/'versions/v001/overrides.css').read_bytes()
    assert (session/'session.json').read_bytes()==original_manifest
    assert not (session/'acceptance.json').exists()
    run('verify',*imported_common)
    assert 'SESSION_ALREADY_EXISTS' in run('import-reference',*imported_common,'--source-task-id',task_id,'--worktree',str(ROOT),'--version','v001','--owner-instruction','x','--decision-source','x',ok=False).stderr
    # A new designer can continue a shown checkpoint without implementation
    # authorization. Copy the immutable version, never later workspace edits.
    def preview(destination_id: str, version: str = 'v001', ok: bool = True, source_task_id: str = task_id):
        return run('fork-preview', '--task-id', destination_id, '--source-task-id', source_task_id,
                   '--local-root', str(local_root), '--worktree', str(ROOT), '--version', version, ok=ok)

    source_before = {p.relative_to(session).as_posix(): design_lab.sha256(p.read_bytes())
                     for p in session.rglob('*') if p.is_file()}
    preview('discussion-copy')
    discussion = local_root/'design-lab/discussion-copy'
    discussion_manifest = json.loads((discussion/'session.json').read_text(encoding='utf-8'))
    assert discussion_manifest['task_id'] == 'discussion-copy'
    assert discussion_manifest['accepted_version'] is None
    assert 'accepted_at' not in discussion_manifest
    assert discussion_manifest['reference_origin']['mode'] == 'preview_only'
    assert 'owner_instruction' not in discussion_manifest['reference_origin']
    assert not (discussion/'acceptance.json').exists()
    assert (discussion/'workspace/overrides.css').read_bytes() == (session/'versions/v001/overrides.css').read_bytes()
    assert (discussion/'versions/v001/checkpoint.json').read_bytes() == (session/'versions/v001/checkpoint.json').read_bytes()
    assert {p.relative_to(session).as_posix(): design_lab.sha256(p.read_bytes())
            for p in session.rglob('*') if p.is_file()} == source_before
    run('verify', '--task-id', 'discussion-copy', '--local-root', str(local_root))
    assert 'SESSION_ALREADY_EXISTS' in preview('discussion-copy', ok=False).stderr
    assert 'VERSION_NOT_FOUND' in preview('missing-checkpoint', 'v099', ok=False).stderr
    assert not (local_root/'design-lab/missing-checkpoint').exists()
    assert 'INVALID_TASK_ID' in preview('../escape', ok=False).stderr
    assert not (local_root/'escape').exists()
    # Historical designer handoffs usually resume a later version (v005, v017),
    # not v001. The next number must follow that version, never count directories.
    for number in range(2, 6):
        result = run('checkpoint', '--task-id', 'discussion-copy', '--local-root', str(local_root))
        assert f'DESIGN_LAB_CHECKPOINT=v{number:03d}' in result.stdout
    later_checkpoint = (discussion/'versions/v005/checkpoint.json').read_bytes()
    preview('late-discussion-copy', 'v005', source_task_id='discussion-copy')
    late_common = ('--task-id', 'late-discussion-copy', '--local-root', str(local_root))
    assert 'DESIGN_LAB_CHECKPOINT=v006' in run('checkpoint', *late_common).stdout
    assert 'DESIGN_LAB_CHECKPOINT=v007' in run('checkpoint', *late_common).stdout
    assert (discussion/'versions/v005/checkpoint.json').read_bytes() == later_checkpoint
    assert not (local_root/'design-lab/late-discussion-copy/acceptance.json').exists()
    frozen = next(p for p in (session/'ui-assets').rglob('*') if p.is_file())
    frozen_original = frozen.read_bytes()
    try:
        frozen.write_bytes(frozen_original + b'corrupt')
        assert 'UI_ASSET_HASH_MISMATCH' in preview('corrupt-copy', ok=False).stderr
        assert not (local_root/'design-lab/corrupt-copy').exists()
    finally:
        frozen.write_bytes(frozen_original)
    (session/'session.json').write_bytes(saved_manifest)
    (session/'acceptance.json').write_bytes(saved_acceptance)
    css.write_bytes((session/'versions/v001/overrides.css').read_bytes())
    preview('approved-source-discussion')
    approved_copy = local_root/'design-lab/approved-source-discussion'
    assert not (approved_copy/'acceptance.json').exists()
    assert json.loads((approved_copy/'session.json').read_text(encoding='utf-8'))['accepted_version'] is None
    assert (session/'acceptance.json').read_bytes() == saved_acceptance

    ready = local_root / "ready.json"
    server = subprocess.Popen(
        [
            sys.executable,
            str(TOOL),
            "serve",
            *common,
            "--host",
            "127.0.0.1",
            "--port",
            "0",
            "--ready-file",
            str(ready),
        ],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )
    try:
        for _ in range(80):
            if ready.is_file():
                break
            assert server.poll() is None, "sandbox server exited before readiness"
            time.sleep(0.05)
        assert ready.is_file(), "sandbox server did not become ready"
        state = json.loads(ready.read_text(encoding="utf-8"))
        assert int(state["pid"]) > 0
        assert Path(state["session"]) == session
        port = int(state["port"])

        page = opener.open(f"http://127.0.0.1:{port}{manifest['target_route']}", timeout=3)
        body = page.read().decode("utf-8")
        headers = {key.lower(): value for key, value in page.headers.items()}
        assert page.status == 200
        assert "/__design_lab__/overrides.css" in body
        assert "/__design_lab__/overrides.js" in body
        assert "planex-design-lab-banner" not in body
        assert headers["cache-control"] == "no-store, private"
        assert "connect-src 'none'" in headers["content-security-policy"]
        assert "form-action 'none'" in headers["content-security-policy"]

        override = opener.open(f"http://127.0.0.1:{port}/__design_lab__/overrides.css", timeout=3).read().decode("utf-8")
        assert "--design-lab-contract: 1" in override
        assert "planex-design-lab-banner" not in override

        override_js = opener.open(f"http://127.0.0.1:{port}/__design_lab__/overrides.js", timeout=3).read().decode("utf-8")
        assert "planexDesignLab" in override_js
        assert "createElement('aside')" not in override_js

        status = json.loads(opener.open(f"http://127.0.0.1:{port}/__design_lab__/status.json", timeout=3).read())
        assert status["parity_required"] is True
        assert status["ui_quality_required"] is True

        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=3)
        connection.request("GET", manifest["target_route"], headers={"Host": "example.com"})
        assert connection.getresponse().status == 421
        connection.close()

        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=3)
        connection.request("POST", manifest["target_route"], body=b"x=1")
        assert connection.getresponse().status == 405
        connection.close()
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait(timeout=5)

    unsafe = local_root / "unsafe.html"
    unsafe.write_text('<html><body><input type="password"></body></html>', encoding="utf-8")
    refused = run(
        "create",
        "--task-id",
        "unsafe-snapshot",
        "--local-root",
        str(local_root),
        "--worktree",
        str(ROOT),
        "--mode",
        "snapshot",
        "--snapshot",
        str(unsafe),
        ok=False,
    )
    assert "SNAPSHOT_SENSITIVE_MARKER" in refused.stderr

source = TOOL.read_text(encoding="utf-8")
for token in [
    "LOOPBACK_BIND_REQUIRED",
    "DESIGN_LAB_READ_ONLY",
    "connect-src 'none'",
    "form-action 'none'",
    "LOCAL_ROOT_INSIDE_WORKTREE",
    "WORKTREE_INSIDE_LOCAL_ROOT",
]:
    assert token in source, token

for contract_path in [
    ROOT / "AGENTS.md",
    ROOT / "docs" / "DESIGN_LAB.md",
    ROOT / ".agents" / "skills" / "planex-design-lab" / "SKILL.md",
    ROOT / ".agents" / "skills" / "planex-design-lab" / "references" / "workflow.md",
]:
    contract = contract_path.read_text(encoding="utf-8")
    assert "1:1" in contract, contract_path
    normalized = contract.lower()
    assert "visual" in normalized or "визуальн" in normalized, contract_path
    assert "ui-quality-checklist.md" in normalized, contract_path

print("design_lab_contract_test: PASS")
