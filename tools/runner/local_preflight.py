#!/usr/bin/env python3
"""Signed local preflight evidence. Import/verification never executes tests."""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile
import time

sys.dont_write_bytecode = True

REPOSITORY = "caymanlogistic-crypto/erp-planex-ci"
CANONICAL = "chatgpt/production-stabilization-20260802"
SCHEMA = "planex-local-preflight/v1"
MAX_AGE = 24 * 3600
ROOT = Path.home() / "erp-planex-test"
HEX40 = re.compile(r"[0-9a-f]{40}")
HEX64 = re.compile(r"[0-9a-f]{64}")
IDENTIFIER = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,95}")


class Refused(RuntimeError):
    pass


def require(condition, reason):
    if not condition:
        raise Refused(reason)


def sha(data):
    return hashlib.sha256(data).hexdigest()


def git(worktree, *args):
    return subprocess.check_output(["git", *args], cwd=worktree, text=True, stderr=subprocess.PIPE).strip()


def read_file(path, limit):
    require(path.is_file() and not path.is_symlink(), "REGULAR_FILE_REQUIRED")
    require(path.stat().st_size <= limit, "EVIDENCE_TOO_LARGE")
    data = path.read_bytes()
    require(len(data) <= limit, "EVIDENCE_TOO_LARGE")
    return data


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "DUPLICATE_JSON_KEY")
        result[key] = value
    return result


def state(worktree):
    require(not git(worktree, "status", "--porcelain"), "DIRTY_WORKTREE")
    branch = git(worktree, "branch", "--show-current")
    require(branch.startswith("chatgpt/") and branch != CANONICAL, "TASK_BRANCH_REQUIRED")
    origin = git(worktree, "remote", "get-url", "origin")
    require(origin in (f"https://github.com/{REPOSITORY}.git", f"https://github.com/{REPOSITORY}",
                       f"git@github.com:{REPOSITORY}.git"), "WRONG_REPOSITORY")
    return {"branch": branch, "head": git(worktree, "rev-parse", "HEAD"),
            "tree": git(worktree, "rev-parse", "HEAD^{tree}"),
            "preflight_blob": git(worktree, "rev-parse", "HEAD:tools/agent_preflight.py")}


def expected_plan(worktree, base, head, task_class):
    # Only pure classification is loaded: no capability probe, DB or test command.
    spec = importlib.util.spec_from_file_location("local_evidence_plan", worktree / "tools/agent_preflight.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    paths = git(worktree, "diff", "--name-only", "--diff-filter=ACMR", f"{base}..{head}").splitlines()
    require(task_class == "CRITICAL" or not module.ci.critical_path(paths), "CLASS_TOO_LOW")
    return module.plan(task_class, paths, module.ci.classify(paths))


def parse_log(data, receipt, planned):
    lines = data.decode("utf-8").splitlines()
    require(lines and lines[-1] == "LOCAL_PREFLIGHT_PASS", "FULL_LOCAL_PASS_REQUIRED")
    require(not any(line in ("LOCAL_PREFLIGHT_FAIL", "LOCAL_PREFLIGHT_ENV_ERROR", "PREFLIGHT_PLAN_ONLY",
                            "LOCAL_PREFLIGHT_PASS_WITH_REMOTE_GATES") for line in lines), "FAILED_OR_PARTIAL_RUN")
    values = {}
    keys = ("CLASS", "BASE", "HEAD", "CHECKS_PLANNED", "LOCAL_REQUIRED_CHECKS", "LOCAL_REQUIRED_RESULTS",
            "REMOTE_REQUIRED_CHECKS", "MISSING_CAPABILITIES", "CHECKS_EXECUTED")
    for key in keys:
        matches = [line[len(key) + 1:] for line in lines if line.startswith(key + "=")]
        require(len(matches) == 1, "AMBIGUOUS_LOG_FIELD:" + key)
        values[key] = matches[0] if key in ("CLASS", "BASE", "HEAD") else json.loads(matches[0], object_pairs_hook=unique_object)
    require(values["CLASS"] == receipt["class"] and values["BASE"] == receipt["base"]
            and values["HEAD"] == receipt["head"], "LOG_IDENTITY_MISMATCH")
    require(values["REMOTE_REQUIRED_CHECKS"] == [] and values["MISSING_CAPABILITIES"] == [], "REMOTE_GATES_FORBIDDEN")
    require(planned and all(values[k] == planned for k in ("CHECKS_PLANNED", "LOCAL_REQUIRED_CHECKS", "CHECKS_EXECUTED")),
            "CHECK_PLAN_MISMATCH")
    require(values["LOCAL_REQUIRED_RESULTS"] == [{"check": check, "result": "PASS"} for check in planned],
            "CHECK_RESULTS_MISMATCH")


def atomic_write(path, data):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, name = tempfile.mkstemp(prefix=".local-preflight-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(name, 0o600)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def local_run(args):
    worktree = Path(args.worktree).resolve()
    require(IDENTIFIER.fullmatch(args.task) and IDENTIFIER.fullmatch(args.key_id), "INVALID_IDENTIFIER")
    require(HEX40.fullmatch(args.base), "EXACT_BASE_REQUIRED")
    before = state(worktree)
    require(before["head"] != args.base, "NO_CANDIDATE_CHANGE")
    subprocess.run(["git", "merge-base", "--is-ancestor", args.base, before["head"]], cwd=worktree, check=True)
    output = Path(args.output).resolve()
    require(not output.is_relative_to(worktree), "EVIDENCE_MUST_BE_OUTSIDE_WORKTREE")
    require(not output.exists(), "EVIDENCE_DIRECTORY_EXISTS")
    receipt = {"schema": SCHEMA, "repository": REPOSITORY, "task": args.task, "class": args.task_class,
               "base": args.base, **before, "key_id": args.key_id, "started_at": int(time.time())}
    with tempfile.TemporaryDirectory(prefix="planex-local-run-") as temporary:
        env = os.environ.copy()
        env.update(APP_ENV="test", PYTHONDONTWRITEBYTECODE="1", PYTHONPYCACHEPREFIX=temporary)
        env.pop("PLANEX_SKIP_MYSQL", None)
        command = [sys.executable, "tools/agent_preflight.py", "--class", args.task_class,
                   "--base", args.base, "--head", before["head"]]
        process = subprocess.run(command, cwd=worktree, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    sys.stdout.buffer.write(process.stdout)
    require(process.returncode == 0, "LOCAL_RUN_FAILED")
    require(state(worktree) == before, "WORKTREE_CHANGED_DURING_RUN")
    planned = expected_plan(worktree, args.base, before["head"], args.task_class)
    parse_log(process.stdout, receipt, planned)
    receipt.update(finished_at=int(time.time()), log_sha256=sha(process.stdout), checks=planned)
    data = (json.dumps(receipt, sort_keys=True, separators=(",", ":")) + "\n").encode()
    output.mkdir(mode=0o700, parents=True)
    atomic_write(output / "receipt.json", data)
    atomic_write(output / "preflight.log", process.stdout)
    subprocess.run(["openssl", "dgst", "-sha256", "-sign", str(Path(args.key).resolve()),
                    "-out", str(output / "receipt.sig"), str(output / "receipt.json")], check=True)
    os.chmod(output / "receipt.sig", 0o600)
    print("LOCAL_SIGNED_PREFLIGHT_PASS=" + before["head"])


def read_meta(task, root=ROOT):
    require(IDENTIFIER.fullmatch(task), "INVALID_TASK_ID")
    path = root / "state/tasks" / (task + ".env")
    data = read_file(path, 16384).decode()
    result = {}
    for line in data.splitlines():
        if line and not line.startswith("#") and "=" in line:
            key, value = line.split("=", 1)
            require(key not in result, "DUPLICATE_METADATA_KEY")
            result[key] = value.strip().strip('"').strip("'")
    require(result.get("TASK_ID") == task, "TASK_METADATA_MISMATCH")
    worktree = Path(result.get("WORKTREE", "")).resolve()
    require(worktree.is_relative_to((root / "worktrees").resolve()) and worktree != (root / "worktrees").resolve(),
            "WORKTREE_OUTSIDE_RUNNER")
    return result, worktree


def validate_evidence(task, evidence, root=ROOT, now=None):
    meta, worktree = read_meta(task, root)
    current = state(worktree)
    require(current["branch"] == meta.get("BRANCH"), "BRANCH_MISMATCH")
    data = read_file(evidence / "receipt.json", 32768)
    receipt = json.loads(data, object_pairs_hook=unique_object)
    require(isinstance(receipt, dict) and receipt.get("schema") == SCHEMA, "INVALID_RECEIPT_SCHEMA")
    key_id = receipt.get("key_id", "")
    require(isinstance(key_id, str) and IDENTIFIER.fullmatch(key_id), "INVALID_KEY_ID")
    key = root / "state/local-preflight-trust" / (key_id + ".pem")
    require(not key.parent.is_symlink(), "UNSAFE_TRUST_PATH")
    public_key = read_file(key, 16384)
    if os.name != "nt":
        for path in (key.parent, key):
            require(path.stat().st_uid == os.getuid() and not path.stat().st_mode & (stat.S_IWGRP | stat.S_IWOTH),
                    "UNSAFE_TRUST_PERMISSIONS")
    signature = read_file(evidence / "receipt.sig", 16384)
    log = read_file(evidence / "preflight.log", 32 * 1024 * 1024)
    # Verify the exact bytes read above, not mutable inbox paths (TOCTOU).
    with tempfile.TemporaryDirectory(prefix="local-signature-", dir=root / "state") as td:
        signed = Path(td) / "receipt.json"
        sig = Path(td) / "receipt.sig"
        public = Path(td) / "trusted.pem"
        signed.write_bytes(data)
        sig.write_bytes(signature)
        public.write_bytes(public_key)
        result = subprocess.run(["openssl", "dgst", "-sha256", "-verify", str(public),
                                 "-signature", str(sig), str(signed)], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    require(result.returncode == 0, "INVALID_SIGNATURE")
    require(receipt.get("repository") == REPOSITORY and receipt.get("task") == task, "RECEIPT_IDENTITY_MISMATCH")
    require(receipt.get("base") == meta.get("START_HEAD") and isinstance(receipt.get("base"), str)
            and HEX40.fullmatch(receipt["base"]), "BASE_MISMATCH")
    require(all(receipt.get(key) == value for key, value in current.items()), "CANDIDATE_MISMATCH")
    require(receipt["head"] != receipt["base"], "NO_CANDIDATE_CHANGE")
    subprocess.run(["git", "merge-base", "--is-ancestor", receipt["base"], receipt["head"]], cwd=worktree, check=True)
    started, finished = receipt.get("started_at"), receipt.get("finished_at")
    now = int(time.time()) if now is None else now
    require(type(started) is int and type(finished) is int and started <= finished <= now + 300
            and now - started <= MAX_AGE and now - finished <= MAX_AGE, "EXPIRED_OR_INVALID_TIME")
    require(receipt.get("log_sha256") == sha(log), "LOG_DIGEST_MISMATCH")
    require(receipt.get("class") in ("FAST", "STANDARD", "CRITICAL"), "INVALID_CLASS")
    planned = expected_plan(worktree, receipt["base"], receipt["head"], receipt["class"])
    require(receipt.get("checks") == planned, "RECEIPT_PLAN_MISMATCH")
    parse_log(log, receipt, planned)
    return receipt, {"receipt.json": data, "receipt.sig": signature, "preflight.log": log}


def import_evidence(task, evidence, root=ROOT):
    require(evidence.resolve().is_relative_to(root.resolve()), "INBOX_OUTSIDE_RUNNER")
    receipt, files = validate_evidence(task, evidence, root)
    digest = sha(files["receipt.json"])
    archive = root / "state/local-preflight-archive" / task / digest
    for name, data in files.items():
        atomic_write(archive / name, data)
    proof = {"TASK_ID": task, "CLASS": receipt["class"], "BRANCH": receipt["branch"],
             "START_HEAD": receipt["base"], "CANDIDATE_HEAD": receipt["head"], "RESULT": "PASS",
             "CHECKED_AT": receipt["finished_at"], "PROOF_KIND": "LOCAL_SIGNED_V1", "RECEIPT_SHA256": digest}
    atomic_write(root / "state/tasks" / (task + ".preflight.env"),
                 ("".join(f"{key}={value}\n" for key, value in proof.items())).encode())
    print("LOCAL_PREFLIGHT_IMPORTED=" + receipt["head"])


def verify_imported(task, proof, root=ROOT):
    digest = proof.get("RECEIPT_SHA256", "")
    require(proof.get("PROOF_KIND") == "LOCAL_SIGNED_V1" and HEX64.fullmatch(digest), "INVALID_LOCAL_PROOF")
    receipt, files = validate_evidence(task, root / "state/local-preflight-archive" / task / digest, root)
    require(sha(files["receipt.json"]) == digest, "ARCHIVE_DIGEST_MISMATCH")
    for name, field in (("TASK_ID", "task"), ("CLASS", "class"), ("BRANCH", "branch"),
                        ("START_HEAD", "base"), ("CANDIDATE_HEAD", "head")):
        require(proof.get(name) == receipt[field], "PROOF_RECEIPT_MISMATCH")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="mode", required=True)
    local = commands.add_parser("run")
    local.add_argument("task")
    local.add_argument("task_class", choices=("FAST", "STANDARD", "CRITICAL"))
    for name in ("worktree", "base", "output", "key", "key-id"):
        local.add_argument("--" + name, required=True)
    incoming = commands.add_parser("import")
    incoming.add_argument("task")
    incoming.add_argument("evidence", type=Path)
    args = parser.parse_args()
    try:
        if args.mode == "run":
            local_run(args)
        else:
            import_evidence(args.task, args.evidence)
        return 0
    except (Refused, OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError) as exc:
        print("LOCAL_PREFLIGHT_REFUSED=" + str(exc), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
