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
    assert "Статистика" in (session / "baseline.html").read_text(encoding="utf-8")
    checklist = (session / "parity-checklist.md").read_text(encoding="utf-8")
    assert "базовая копия 1:1" in checklist
    assert "До первой дизайнерской правки" in checklist

    css = session / "workspace" / "overrides.css"
    css.write_text(".manager-statistics { --design-lab-contract: 1; }\n", encoding="utf-8")
    checkpoint = run("checkpoint", *common, "--note", "contract")
    assert "DESIGN_LAB_CHECKPOINT=v001" in checkpoint.stdout
    run("accept", *common, "--version", "v001")
    run("verify", *common)
    acceptance = json.loads((session / "acceptance.json").read_text(encoding="utf-8"))
    assert acceptance["approved_version"] == "v001"
    assert acceptance["owner_instruction"] == "ВНЕДРЯЙ"

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

print("design_lab_contract_test: PASS")
