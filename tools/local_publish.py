#!/usr/bin/env python3
"""Serialize, verify and publish one exact local PLANEX candidate without a remote runner."""
from __future__ import annotations

import argparse
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import time

REPOSITORY = "caymanlogistic-crypto/erp-planex-ci"
CANONICAL = "chatgpt/production-stabilization-20260802"
EXPECTED_REMOTE = f"https://github.com/{REPOSITORY}"
SCHEMA = "planex-local-publish/v1"
HEX40 = re.compile(r"[0-9a-f]{40}")
TASK_ID = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,95}")


class Refused(RuntimeError):
    pass


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise Refused(reason)


def run(worktree: Path, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    process = subprocess.run(
        list(args), cwd=worktree, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE
    )
    if check and process.returncode:
        raise Refused(f"COMMAND_FAILED:{args[0]}:{process.stderr.strip() or process.stdout.strip()}")
    return process


def git(worktree: Path, *args: str) -> str:
    return run(worktree, "git", *args).stdout.strip()


def normalize_remote(value: str) -> str:
    value = value.strip().removesuffix(".git")
    if value.startswith("git@github.com:"):
        value = "https://github.com/" + value.removeprefix("git@github.com:")
    return value


def candidate_state(worktree: Path, expected_remote: str = EXPECTED_REMOTE) -> dict[str, str]:
    require(worktree.is_dir() and not worktree.is_symlink(), "WORKTREE_REQUIRED")
    require(not git(worktree, "status", "--porcelain"), "DIRTY_WORKTREE")
    branch = git(worktree, "branch", "--show-current")
    require(branch.startswith("chatgpt/") and branch != CANONICAL, "TASK_BRANCH_REQUIRED")
    require(normalize_remote(git(worktree, "remote", "get-url", "origin")) == normalize_remote(expected_remote),
            "WRONG_REPOSITORY")
    return {"branch": branch, "head": git(worktree, "rev-parse", "HEAD")}


def is_ancestor(worktree: Path, older: str, newer: str) -> bool:
    return run(worktree, "git", "merge-base", "--is-ancestor", older, newer, check=False).returncode == 0


def fetch_ref(worktree: Path, remote_ref: str, local_ref: str) -> str:
    run(worktree, "git", "fetch", "--no-tags", "origin", f"+{remote_ref}:{local_ref}")
    return git(worktree, "rev-parse", local_ref)


def remote_branch_head(worktree: Path, branch: str) -> str | None:
    process = run(worktree, "git", "ls-remote", "--heads", "origin", f"refs/heads/{branch}", check=False)
    require(process.returncode == 0, "REMOTE_LOOKUP_FAILED")
    rows = [line.split() for line in process.stdout.splitlines() if line.strip()]
    require(len(rows) <= 1, "AMBIGUOUS_REMOTE_BRANCH")
    return rows[0][0] if rows else None


def refresh_canonical(worktree: Path, start_head: str) -> str:
    canonical_ref = f"refs/remotes/origin/{CANONICAL}"
    remote = fetch_ref(worktree, f"refs/heads/{CANONICAL}", canonical_ref)
    require(is_ancestor(worktree, start_head, remote), "CANONICAL_HISTORY_REWRITTEN")
    head = git(worktree, "rev-parse", "HEAD")
    require(is_ancestor(worktree, start_head, head), "TASK_HISTORY_MISMATCH")
    if not is_ancestor(worktree, remote, head):
        merge = run(worktree, "git", "merge", "--no-edit", remote, check=False)
        if merge.returncode:
            run(worktree, "git", "merge", "--abort", check=False)
            raise Refused("CANONICAL_MERGE_CONFLICT")
        require(not git(worktree, "status", "--porcelain"), "DIRTY_AFTER_CANONICAL_MERGE")
    return remote


def run_preflight(worktree: Path, base: str, head: str, requested_class: str) -> tuple[str, bytes]:
    process = subprocess.run(
        [sys.executable, "tools/agent_preflight.py", "--class", requested_class, "--base", base, "--head", head],
        cwd=worktree,
        env={**os.environ, "APP_ENV": "test", "PYTHONDONTWRITEBYTECODE": "1"},
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
    )
    sys.stdout.buffer.write(process.stdout)
    sys.stdout.flush()
    lines = process.stdout.decode("utf-8", errors="replace").splitlines()
    require(process.returncode == 0 and lines and lines[-1] == "LOCAL_PREFLIGHT_PASS", "LOCAL_PREFLIGHT_FAILED")
    classes = [line.split("=", 1)[1] for line in lines if line.startswith("CLASS=")]
    require(len(classes) == 1 and classes[0] in {"FAST", "STANDARD", "CRITICAL"}, "PREFLIGHT_CLASS_MISSING")
    return classes[0], process.stdout


def atomic_write(path: Path, data: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=".publish-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


@contextmanager
def publish_lock(path: Path, task: str, timeout: float):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.touch(exist_ok=True)
    stream = path.open("r+b")
    deadline = time.monotonic() + timeout
    locked = False
    try:
        while not locked:
            try:
                stream.seek(0)
                if os.name == "nt":
                    import msvcrt
                    if path.stat().st_size == 0:
                        stream.write(b"\0")
                        stream.flush()
                    stream.seek(0)
                    msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)
                else:
                    import fcntl
                    fcntl.flock(stream.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                locked = True
            except (BlockingIOError, OSError):
                if time.monotonic() >= deadline:
                    raise Refused("PUBLISH_QUEUE_TIMEOUT")
                time.sleep(0.2)
        stream.seek(0)
        stream.truncate()
        stream.write((json.dumps({"task": task, "pid": os.getpid(), "acquired_at": int(time.time())}) + "\n").encode())
        stream.flush()
        yield
    finally:
        if locked:
            stream.seek(0)
            stream.truncate()
            stream.flush()
            if os.name == "nt":
                import msvcrt
                stream.seek(0)
                if path.stat().st_size == 0:
                    stream.write(b"\0")
                    stream.flush()
                stream.seek(0)
                msvcrt.locking(stream.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                import fcntl
                fcntl.flock(stream.fileno(), fcntl.LOCK_UN)
        stream.close()


def validate_paths(worktree: Path, local_root: Path, evidence: Path) -> None:
    local_root = local_root.resolve()
    evidence_parent = evidence.parent.resolve()
    require(local_root.is_dir() and not local_root.is_symlink(), "LOCAL_ROOT_REQUIRED")
    require(evidence_parent.is_relative_to((local_root / "evidence").resolve()), "EVIDENCE_OUTSIDE_LOCAL_ROOT")
    require(not evidence.exists(), "EVIDENCE_DIRECTORY_EXISTS")
    require(not evidence_parent.is_relative_to(worktree.resolve()), "EVIDENCE_INSIDE_WORKTREE")


def publish(*, task: str, worktree: Path, start_head: str, requested_class: str, local_root: Path,
            evidence: Path, dry_run: bool = False, timeout: float = 900,
            expected_remote: str = EXPECTED_REMOTE) -> dict[str, object]:
    require(TASK_ID.fullmatch(task) is not None, "INVALID_TASK_ID")
    require(HEX40.fullmatch(start_head) is not None, "EXACT_START_HEAD_REQUIRED")
    worktree = worktree.resolve()
    evidence = evidence.resolve()
    validate_paths(worktree, local_root, evidence)
    started = int(time.time())
    with publish_lock(local_root / "locks" / "publish.lock", task, timeout):
        initial = candidate_state(worktree, expected_remote)
        require(initial["head"] != start_head, "NO_CANDIDATE_CHANGE")
        final_base = ""
        final_class = ""
        preflight_log = b""
        for attempt in range(3):
            final_base = refresh_canonical(worktree, start_head)
            before = candidate_state(worktree, expected_remote)
            final_class, preflight_log = run_preflight(worktree, final_base, before["head"], requested_class)
            require(candidate_state(worktree, expected_remote) == before, "WORKTREE_CHANGED_DURING_PREFLIGHT")
            latest = fetch_ref(worktree, f"refs/heads/{CANONICAL}", f"refs/remotes/origin/{CANONICAL}")
            if latest == final_base:
                break
            require(attempt < 2, "CANONICAL_MOVED_REPEATEDLY")
        else:
            raise Refused("CANONICAL_REFRESH_FAILED")

        final = candidate_state(worktree, expected_remote)
        remote_before = remote_branch_head(worktree, final["branch"])
        if remote_before:
            fetched = fetch_ref(worktree, f"refs/heads/{final['branch']}", f"refs/remotes/origin/{final['branch']}")
            require(fetched == remote_before and is_ancestor(worktree, remote_before, final["head"]),
                    "NON_FAST_FORWARD_TASK_BRANCH")
        push_args = ["git", "push", "--porcelain"]
        if dry_run:
            push_args.append("--dry-run")
        push_args += ["origin", f"{final['head']}:refs/heads/{final['branch']}"]
        pushed = run(worktree, *push_args)
        if not dry_run:
            require(remote_branch_head(worktree, final["branch"]) == final["head"], "REMOTE_HEAD_MISMATCH_AFTER_PUSH")

        receipt = {
            "schema": SCHEMA,
            "task": task,
            "repository": REPOSITORY,
            "canonical_branch": CANONICAL,
            "start_head": start_head,
            "publish_base": final_base,
            "branch": final["branch"],
            "head": final["head"],
            "class": final_class,
            "dry_run": dry_run,
            "remote_branch_before": remote_before,
            "preflight_sha256": hashlib.sha256(preflight_log).hexdigest(),
            "push_output": pushed.stdout.strip(),
            "started_at": started,
            "finished_at": int(time.time()),
        }
        evidence.mkdir(parents=True)
        atomic_write(evidence / "preflight.log", preflight_log)
        atomic_write(evidence / "publish.json", (json.dumps(receipt, indent=2, sort_keys=True) + "\n").encode())
        print("LOCAL_PUBLISH_PASS=" + final["head"])
        print("PUBLISH_BASE=" + final_base)
        print("PUBLISH_CLASS=" + final_class)
        return receipt


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--task", required=True)
    parser.add_argument("--worktree", required=True)
    parser.add_argument("--start-head", required=True)
    parser.add_argument("--class", dest="requested_class", choices=["AUTO", "FAST", "STANDARD", "CRITICAL"], default="AUTO")
    parser.add_argument("--local-root", required=True)
    parser.add_argument("--evidence", required=True)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--queue-timeout", type=float, default=900)
    args = parser.parse_args()
    try:
        publish(
            task=args.task,
            worktree=Path(args.worktree),
            start_head=args.start_head,
            requested_class=args.requested_class,
            local_root=Path(args.local_root),
            evidence=Path(args.evidence),
            dry_run=args.dry_run,
            timeout=args.queue_timeout,
        )
        return 0
    except (Refused, OSError, subprocess.SubprocessError, ValueError) as exc:
        print("LOCAL_PUBLISH_REFUSED=" + str(exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
