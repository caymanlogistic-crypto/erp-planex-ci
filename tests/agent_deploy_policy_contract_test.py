"""Run the exact read-only GitHub deploy-policy script before publication."""
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import textwrap

ROOT = Path(__file__).resolve().parents[1]
workflow = (ROOT / ".github/workflows/erpv2_agent_deploy_policy.yml").read_text(encoding="utf-8")
match = re.search(r"python3 - <<'PY'\n(.*?)^\s*PY\s*$", workflow, re.S | re.M)
assert match, "the actual deploy-policy Python contract must remain discoverable"
script = textwrap.dedent(match[1])
def run(root):
    return subprocess.run([sys.executable,"-c",script],cwd=root,text=True,capture_output=True)
result = run(ROOT)
assert result.returncode == 0, result.stdout + result.stderr
assert "ERPV2_AGENT_DEPLOY_POLICY=PASS" in result.stdout

# Prove the local gate detects the precise CI regression that escaped the old
# preflight, without editing the real worktree or weakening the remote policy.
with tempfile.TemporaryDirectory(prefix="planex-deploy-policy-") as temporary:
    root = Path(temporary)
    for relative in [".github/workflows/erpv2_controlled_deploy.yml", ".github/workflows/erpv2_agent_deploy_request.yml", ".github/workflows/erpv2_fast_release.yml", ".github/workflows/ci.yml", "docs/GITHUB_CONTROL_PLANE.md", "AGENTS.md"]:
        target=root/relative
        target.parent.mkdir(parents=True,exist_ok=True)
        source=(ROOT/relative).read_text(encoding="utf-8")
        if relative==".github/workflows/ci.yml":
            source=source.replace("fetch-depth: 2","fetch-depth: 0")
        target.write_text(source,encoding="utf-8")
    invalid=run(root)
    assert invalid.returncode != 0
    assert "missing incremental CI safeguard: fetch-depth: 2" in invalid.stderr

print("AGENT_DEPLOY_POLICY_LOCAL_CONTRACT=PASS")
