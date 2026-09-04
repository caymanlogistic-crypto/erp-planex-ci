#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import os
import shutil
import stat
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TOOL = ROOT / "tools" / "runner" / "sync_runner_helpers.py"
spec = importlib.util.spec_from_file_location("runner_sync", TOOL)
assert spec and spec.loader
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

manifest = m.load_manifest()
assert manifest["schema"] == "erp-planex-runner-helpers/v1"
assert manifest["version"] == "2026-09-05.1"
assert manifest["runner_root"] == "~/erp-planex-test"
assert manifest["destination"] == "bin"
assert tuple(row["name"] for row in manifest["files"]) == m.EXPECTED_NAMES
m.validate_source(manifest)

# Manifest is deterministic and exactly matches the versioned helper source.
for row in manifest["files"]:
    source = ROOT / "tools" / "runner" / row["source"]
    assert source.is_file() and not source.is_symlink()
    assert m.sha256_file(source) == row["sha256"]
    assert row["mode"] == "0700"

# Installer cannot be redirected outside the canonical runner root.
for bad in [Path("/tmp/erp-planex-test/bin"), Path.home() / "runner-other" / "bin", Path.home() / "erp-planex-test" / "worktrees" / "bin"]:
    try:
        m.validate_destination(bad)
    except m.SyncRefused:
        pass
    else:
        raise AssertionError(f"outside destination was accepted: {bad}")

# Canonical checksum verifier can detect both a matching deployed copy and drift.
with tempfile.TemporaryDirectory(prefix="runner-helper-contract-") as td:
    deployed = Path(td)
    for row in manifest["files"]:
        shutil.copyfile(ROOT / "tools" / "runner" / row["source"], deployed / row["name"])
        os.chmod(deployed / row["name"], 0o700)
    assert m.verify_directory(deployed, manifest, require_mode=True) == []
    (deployed / "new-task").write_text("drift\n", encoding="utf-8")
    assert any(p.startswith("new-task:sha256:") for p in m.verify_directory(deployed, manifest, require_mode=True))

helpers = ROOT / "tools" / "runner" / "helpers"

# Versioned helper sources remain syntactically executable.
for name in ("new-task", "task-db", "test-db-slot", "clean-test-db", "runner-env"):
    subprocess.run(["bash", "-n", str(helpers / name)], check=True)
for name in ("preflight-task", "push-task"):
    compile((helpers / name).read_text(encoding="utf-8"), str(helpers / name), "exec")

clean = (helpers / "clean-test-db").read_text(encoding="utf-8")
new_task = (helpers / "new-task").read_text(encoding="utf-8")
push_task = (helpers / "push-task").read_text(encoding="utf-8")

# Cleaner is pinned to runner PHP, not arbitrary system php.
assert '"$ROOT/bin/php" <<\'PHP\'' in clean
assert "\nphp <<'PHP'" not in clean

# Branch/direct-force governance semantics remain fail-closed.
assert 'CANONICAL="chatgpt/production-stabilization-20260802"' in new_task
assert 'case "$BRANCH" in\n    chatgpt/*)' in new_task
assert 'if [ "$BRANCH" = "$CANONICAL" ]' in new_task
assert 'CANONICAL = "chatgpt/production-stabilization-20260802"' in push_task
assert 'if branch == CANONICAL:' in push_task
assert 'PUSH_TASK_BLOCK=CANONICAL_DRIFT' in push_task
assert 'if sys.argv[2] != "--dry-run":' in push_task
assert 'PUSH_TASK_BLOCK=INVALID_ARGUMENT' in push_task
assert '"--force"' not in push_task

print("runner_helpers_contract_test: PASS")
