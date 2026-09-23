#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("local_publish", ROOT / "tools/local_publish.py")
assert spec and spec.loader
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


def command(cwd: Path, *args: str) -> str:
    return subprocess.check_output(list(args), cwd=cwd, text=True, stderr=subprocess.STDOUT).strip()


def commit(repo: Path, message: str) -> str:
    command(repo, "git", "add", "-A")
    command(repo, "git", "commit", "-m", message)
    return command(repo, "git", "rev-parse", "HEAD")


def refused(call, token: str) -> None:
    try:
        call()
    except p.Refused as exc:
        assert token in str(exc), (token, exc)
    else:
        raise AssertionError("expected refusal: " + token)


with tempfile.TemporaryDirectory(prefix="planex-local-publish-") as temporary:
    root = Path(temporary)
    remote = root / "remote.git"
    seed = root / "seed"
    task = root / "task"
    local = root / "local"
    (local / "evidence").mkdir(parents=True)
    command(root, "git", "init", "--bare", str(remote))
    command(root, "git", "init", str(seed))
    command(seed, "git", "config", "user.email", "test@example.invalid")
    command(seed, "git", "config", "user.name", "PLANEX test")
    (seed / "tools").mkdir()
    (seed / "tools" / "agent_preflight.py").write_text(
        "import argparse\n"
        "a=argparse.ArgumentParser();a.add_argument('--class',dest='c');a.add_argument('--base');a.add_argument('--head');x=a.parse_args()\n"
        "print('CLASS=' + ('STANDARD' if x.c == 'AUTO' else x.c))\n"
        "print('LOCAL_PREFLIGHT_PASS')\n",
        encoding="utf-8",
    )
    (seed / "base.txt").write_text("base\n", encoding="utf-8")
    command(seed, "git", "checkout", "-b", p.CANONICAL)
    start = commit(seed, "base")
    command(seed, "git", "remote", "add", "origin", str(remote))
    command(seed, "git", "push", "origin", f"HEAD:refs/heads/{p.CANONICAL}")

    command(root, "git", "clone", "--branch", p.CANONICAL, str(remote), str(task))
    command(task, "git", "config", "user.email", "test@example.invalid")
    command(task, "git", "config", "user.name", "PLANEX test")
    command(task, "git", "checkout", "-b", "chatgpt/test-task")
    (task / "task.txt").write_text("candidate\n", encoding="utf-8")
    first_head = commit(task, "candidate")

    dry = p.publish(
        task="test-task",
        worktree=task,
        start_head=start,
        requested_class="AUTO",
        local_root=local,
        evidence=local / "evidence" / "dry",
        dry_run=True,
        timeout=2,
        expected_remote=str(remote),
    )
    assert dry["head"] == first_head and dry["class"] == "STANDARD" and dry["dry_run"] is True
    assert p.remote_branch_head(task, "chatgpt/test-task") is None

    real = p.publish(
        task="test-task",
        worktree=task,
        start_head=start,
        requested_class="AUTO",
        local_root=local,
        evidence=local / "evidence" / "real",
        timeout=2,
        expected_remote=str(remote),
    )
    assert p.remote_branch_head(task, "chatgpt/test-task") == real["head"]

    (seed / "canonical.txt").write_text("advanced\n", encoding="utf-8")
    advanced = commit(seed, "advance canonical")
    command(seed, "git", "push", "origin", f"HEAD:refs/heads/{p.CANONICAL}")
    refreshed = p.publish(
        task="test-task",
        worktree=task,
        start_head=start,
        requested_class="AUTO",
        local_root=local,
        evidence=local / "evidence" / "refreshed",
        timeout=2,
        expected_remote=str(remote),
    )
    assert refreshed["publish_base"] == advanced
    assert p.is_ancestor(task, advanced, str(refreshed["head"]))
    assert p.remote_branch_head(task, "chatgpt/test-task") == refreshed["head"]

    (task / "dirty.txt").write_text("dirty\n", encoding="utf-8")
    refused(
        lambda: p.publish(
            task="test-task",
            worktree=task,
            start_head=start,
            requested_class="AUTO",
            local_root=local,
            evidence=local / "evidence" / "dirty",
            timeout=2,
            expected_remote=str(remote),
        ),
        "DIRTY_WORKTREE",
    )

print("local_publish_test: PASS")
