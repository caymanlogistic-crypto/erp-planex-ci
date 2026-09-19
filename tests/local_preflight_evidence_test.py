#!/usr/bin/env python3
"""Real Git/OpenSSL producer, import and guarded-push regressions; no network."""
import argparse
import contextlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("evidence", ROOT / "tools/runner/local_preflight.py")
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


def run(*args, cwd=None, env=None):
    return subprocess.run(args, cwd=cwd, env=env, check=True, capture_output=True, text=True).stdout.strip()


def refused(call, expected):
    try:
        call()
    except m.Refused as exc:
        assert expected in str(exc), (expected, str(exc))
    else:
        raise AssertionError("Missing rejection: " + expected)


def quiet_local_run(args):
    # Nested fixtures must not inject fake preflight identity fields into a real log.
    with tempfile.TemporaryFile(mode="w+", encoding="utf-8") as output:
        with contextlib.redirect_stdout(output):
            m.local_run(args)


FIXTURE = '''import argparse,json,os,pathlib,sys
class ci:
    @staticmethod
    def classify(paths): return {}
    @staticmethod
    def critical_path(paths): return True
def plan(cls,paths,scopes): return ["FIXTURE_CHECK"]
if __name__ == "__main__":
    p=argparse.ArgumentParser()
    p.add_argument("--class",dest="cls");p.add_argument("--base");p.add_argument("--head")
    a=p.parse_args()
    marker=pathlib.Path(os.environ["FIXTURE_EXECUTION_MARKER"])
    marker.write_text(marker.read_text()+"run\\n" if marker.exists() else "run\\n")
    for k,v in [("CLASS",a.cls),("BASE",a.base),("HEAD",a.head)]: print(k+"="+v)
    for k,v in [("CHECKS_PLANNED",["FIXTURE_CHECK"]),("LOCAL_REQUIRED_CHECKS",["FIXTURE_CHECK"]),("LOCAL_REQUIRED_RESULTS",[{"check":"FIXTURE_CHECK","result":"PASS"}]),("REMOTE_REQUIRED_CHECKS",[]),("MISSING_CAPABILITIES",[]),("CHECKS_EXECUTED",["FIXTURE_CHECK"])]: print(k+"="+json.dumps(v))
    print("LOCAL_PREFLIGHT_PASS")
    if os.getenv("FIXTURE_DIRTY"): pathlib.Path("changed.php").write_text("changed during run")
    sys.exit(1 if os.getenv("FIXTURE_FAIL") else 0)
'''

with tempfile.TemporaryDirectory(prefix="local-evidence-contract-") as td:
    home = Path(td)
    root = home / "erp-planex-test"
    repo = root / "worktrees/task"
    repo.mkdir(parents=True)
    run("git", "init", "-b", "chatgpt/evidence-test", cwd=repo)
    run("git", "config", "user.name", "Evidence Fixture", cwd=repo)
    run("git", "config", "user.email", "evidence@example.invalid", cwd=repo)
    run("git", "remote", "add", "origin", f"https://github.com/{m.REPOSITORY}.git", cwd=repo)
    (repo / "tools").mkdir()
    (repo / "tools/agent_preflight.py").write_text(FIXTURE)
    run("git", "add", ".", cwd=repo)
    run("git", "commit", "-m", "base", cwd=repo)
    base = run("git", "rev-parse", "HEAD", cwd=repo)
    (repo / "changed.php").write_text("<?php // fixture\n")
    run("git", "add", ".", cwd=repo)
    run("git", "commit", "-m", "candidate", cwd=repo)
    head = run("git", "rev-parse", "HEAD", cwd=repo)
    tasks = root / "state/tasks"
    tasks.mkdir(parents=True)
    (tasks / "task.env").write_text(f"TASK_ID=task\nWORKTREE={repo}\nBRANCH=chatgpt/evidence-test\nSTART_HEAD={base}\n")
    trust = root / "state/local-preflight-trust"
    trust.mkdir(mode=0o700)
    key = home / "private.pem"
    run("openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt", "rsa_keygen_bits:2048", "-out", str(key))
    run("openssl", "pkey", "-in", str(key), "-pubout", "-out", str(trust / "fixture.pem"))
    os.chmod(trust / "fixture.pem", 0o600)
    marker = home / "executed"
    previous = os.environ.get("FIXTURE_EXECUTION_MARKER")
    os.environ["FIXTURE_EXECUTION_MARKER"] = str(marker)
    inbox = root / "incoming/evidence"
    args = argparse.Namespace(task="task", task_class="CRITICAL", base=base, worktree=str(repo),
                              output=str(inbox), key=str(key), key_id="fixture")
    quiet_local_run(args)
    assert marker.read_text() == "run\n"
    m.import_evidence("task", inbox, root)
    proof = dict(line.split("=", 1) for line in (tasks / "task.preflight.env").read_text().splitlines())
    m.verify_imported("task", proof, root)
    assert marker.read_text() == "run\n", "Import/verification must never rerun tests"
    original = {name: (inbox / name).read_bytes() for name in ("receipt.json", "receipt.sig", "preflight.log")}

    def restore():
        for name, data in original.items():
            (inbox / name).write_bytes(data)

    def resign(changes=None, transform_log=None):
        receipt = json.loads(original["receipt.json"])
        receipt.update(changes or {})
        if transform_log:
            log = transform_log(original["preflight.log"])
            (inbox / "preflight.log").write_bytes(log)
            receipt["log_sha256"] = m.sha(log)
        (inbox / "receipt.json").write_text(json.dumps(receipt))
        run("openssl", "dgst", "-sha256", "-sign", str(key), "-out", str(inbox / "receipt.sig"), str(inbox / "receipt.json"))

    (inbox / "receipt.json").write_bytes(original["receipt.json"].replace(head.encode(), b"0" * 40))
    refused(lambda: m.validate_evidence("task", inbox, root), "INVALID_SIGNATURE")
    restore()
    (inbox / "preflight.log").write_bytes(original["preflight.log"] + b"tampered")
    refused(lambda: m.validate_evidence("task", inbox, root), "LOG_DIGEST_MISMATCH")
    restore()
    for changes, error in [
        ({"key_id": "unknown"}, "REGULAR_FILE_REQUIRED"),
        ({"task": "other"}, "RECEIPT_IDENTITY_MISMATCH"),
        ({"base": "0" * 40}, "BASE_MISMATCH"),
        ({"head": base}, "CANDIDATE_MISMATCH"),
        ({"branch": m.CANONICAL}, "CANDIDATE_MISMATCH"),
        ({"tree": "0" * 40}, "CANDIDATE_MISMATCH"),
        ({"started_at": int(time.time()) - m.MAX_AGE - 1}, "EXPIRED_OR_INVALID_TIME"),
        ({"finished_at": int(time.time()) + 3600}, "EXPIRED_OR_INVALID_TIME"),
        ({"class": "FAST"}, "CLASS_TOO_LOW"),
        ({"checks": []}, "RECEIPT_PLAN_MISMATCH"),
    ]:
        resign(changes)
        refused(lambda: m.validate_evidence("task", inbox, root), error)
        restore()
    for transform, error in [
        (lambda data: data.replace(b"LOCAL_PREFLIGHT_PASS", b"PREFLIGHT_PLAN_ONLY"), "FULL_LOCAL_PASS_REQUIRED"),
        (lambda data: data.replace(b"LOCAL_PREFLIGHT_PASS", b"LOCAL_PREFLIGHT_PASS_WITH_REMOTE_GATES"), "FULL_LOCAL_PASS_REQUIRED"),
        (lambda data: b"HEAD=ambiguous\n" + data, "AMBIGUOUS_LOG_FIELD:HEAD"),
        (lambda data: data.replace(b'"result": "PASS"', b'"result": "FAIL"'), "CHECK_RESULTS_MISMATCH"),
        (lambda data: data.replace(b'REMOTE_REQUIRED_CHECKS=[]', b'REMOTE_REQUIRED_CHECKS=["MYSQL"]'), "REMOTE_GATES_FORBIDDEN"),
    ]:
        resign(transform_log=transform)
        refused(lambda: m.validate_evidence("task", inbox, root), error)
        restore()
    (repo / "changed.php").write_text("dirty")
    refused(lambda: m.validate_evidence("task", inbox, root), "DIRTY_WORKTREE")
    run("git", "checkout", "--", "changed.php", cwd=repo)
    refused(lambda: m.validate_evidence("../task", inbox, root), "INVALID_TASK_ID")
    refused(lambda: m.verify_imported("task", {**proof, "RECEIPT_SHA256": "../x"}, root), "INVALID_LOCAL_PROOF")
    refused(lambda: m.verify_imported("task", {**proof, "BRANCH": "chatgpt/other"}, root), "PROOF_RECEIPT_MISMATCH")

    # Actual helper rejects invalid signed evidence before any simulated remote call.
    bin_dir = root / "bin"
    bin_dir.mkdir()
    shutil.copyfile(ROOT / "tools/runner/helpers/push-task", bin_dir / "push-task")
    module_path = root / "repo/tools/runner"
    module_path.mkdir(parents=True)
    shutil.copyfile(ROOT / "tools/runner/local_preflight.py", module_path / "local_preflight.py")
    git_bin = root / "tools/git/bin"
    git_bin.mkdir(parents=True)
    real_git = shutil.which("git")
    fake = git_bin / "git"
    fake.write_text('#!/usr/bin/env python3\nimport os,sys\na=sys.argv[1:]\n'
                    'if a[0]=="ls-remote": print(os.environ["FIXTURE_BASE"]+"\\trefs/heads/' + m.CANONICAL + '")\n'
                    'elif a[0]=="push": open(os.environ["FIXTURE_PUSH"],"w").write(" ".join(a))\n'
                    'else: os.execv(' + repr(real_git) + ',[' + repr(real_git) + ']+a)\n')
    fake.chmod(0o700)
    pushed = home / "push-args"
    env = {**os.environ, "HOME": str(home), "FIXTURE_BASE": base, "FIXTURE_PUSH": str(pushed)}
    result = run("python3", str(bin_dir / "push-task"), "task", "--dry-run", env=env)
    assert "PUSH_TASK_DRY_RUN=PASS" in result
    assert head + ":refs/heads/chatgpt/evidence-test" in pushed.read_text()

    # Unsigned/legacy proof is never sufficient for publication.
    proof_path = tasks / "task.preflight.env"
    signed_proof = proof_path.read_bytes()
    proof_path.write_text(
        f"TASK_ID=task\nCLASS=CRITICAL\nBRANCH=chatgpt/evidence-test\n"
        f"START_HEAD={base}\nCANDIDATE_HEAD={head}\nRESULT=PASS\n"
    )
    pushed.unlink()
    result = subprocess.run(
        ["python3", str(bin_dir / "push-task"), "task", "--dry-run"],
        env=env, capture_output=True, text=True,
    )
    assert result.returncode != 0 and "LOCAL_SIGNED_PROOF_REQUIRED" in result.stdout
    assert not pushed.exists()
    proof_path.write_bytes(signed_proof)

    archive = root / "state/local-preflight-archive/task" / proof["RECEIPT_SHA256"]
    (archive / "preflight.log").write_text("tampered")
    pushed.unlink(missing_ok=True)
    result = subprocess.run(["python3", str(bin_dir / "push-task"), "task"], env=env, capture_output=True, text=True)
    assert result.returncode != 0 and "LOG_DIGEST_MISMATCH" in result.stdout
    assert not pushed.exists()

    # A failed run or a checkout changed during the run must never issue evidence.
    args.output = str(root / "incoming/failed")
    os.environ["FIXTURE_FAIL"] = "1"
    refused(lambda: quiet_local_run(args), "LOCAL_RUN_FAILED")
    del os.environ["FIXTURE_FAIL"]
    assert not Path(args.output).exists()
    os.environ["FIXTURE_DIRTY"] = "1"
    refused(lambda: quiet_local_run(args), "DIRTY_WORKTREE")
    del os.environ["FIXTURE_DIRTY"]
    assert not Path(args.output).exists()
    if previous is None:
        del os.environ["FIXTURE_EXECUTION_MARKER"]
    else:
        os.environ["FIXTURE_EXECUTION_MARKER"] = previous

print("LOCAL_PREFLIGHT_EVIDENCE=PASS signature identity expiry complete-plan tamper dirty-checkout pinned-push no-remote-tests")
