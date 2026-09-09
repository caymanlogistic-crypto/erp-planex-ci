#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
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
assert manifest["schema"] == "erp-planex-runner-helpers/v2"
assert manifest["version"] == "2026-09-09.1"
assert manifest["canonical_repository"] == "caymanlogistic-crypto/erp-planex-ci"
assert manifest["canonical_branch"] == m.CANONICAL
assert tuple(row["name"] for row in manifest["entries"]) == m.EXPECTED_NAMES
assert tuple(row["name"] for row in m.managed_rows(manifest)) == m.EXPECTED_HELPERS
support = manifest["entries"][-1]
assert support == {"name": "php", "type": "symlink", "managed": False, "target": "/usr/bin/php8.5"}
m.validate_source(manifest)

for row in m.managed_rows(manifest):
    source = ROOT / "tools" / "runner" / row["source"]
    assert source.is_file() and not source.is_symlink()
    assert m.sha256_file(source) == row["sha256"]
    assert row["mode"] == "0700"

for bad in [Path("/tmp/erp-planex-test/bin"), Path.home() / "runner-other" / "bin", Path.home() / "erp-planex-test" / "worktrees" / "bin"]:
    try:
        m.validate_destination(bad)
    except m.SyncRefused:
        pass
    else:
        raise AssertionError(f"outside destination was accepted: {bad}")


def deploy_exact(dest: Path) -> None:
    for row in m.managed_rows(manifest):
        shutil.copyfile(ROOT / "tools" / "runner" / row["source"], dest / row["name"])
        os.chmod(dest / row["name"], 0o700)
    (dest / "php").symlink_to("/usr/bin/php8.5")


def assert_problem(mutator, needle: str) -> None:
    with tempfile.TemporaryDirectory(prefix="runner-inventory-") as td:
        dest = Path(td)
        deploy_exact(dest)
        mutator(dest)
        problems = m.verify_directory(dest, manifest, require_mode=True)
        assert any(needle in problem for problem in problems), (needle, problems)


# Expected exact directory passes, including the declared PHP support symlink.
with tempfile.TemporaryDirectory(prefix="runner-exact-") as td:
    dest = Path(td)
    deploy_exact(dest)
    assert m.verify_directory(dest, manifest, require_mode=True) == []
    m.preinstall_inventory_guard(dest, manifest)

def add_unknown_executable(d: Path) -> None:
    p=d / "unknown-helper"
    p.write_text("#!/bin/sh\n", encoding="utf-8")
    os.chmod(p, 0o700)

def add_unknown_file(d: Path) -> None:
    (d / "unknown-note").write_text("undeclared\n", encoding="utf-8")

assert_problem(add_unknown_executable, "inventory:unexpected:unknown-helper")
assert_problem(add_unknown_file, "inventory:unexpected:unknown-note")
assert_problem(lambda d: (d / "unexpected-link").symlink_to("/usr/bin/true"), "inventory:unexpected:unexpected-link")
assert_problem(lambda d: (d / "new-task").write_text("drift\n", encoding="utf-8"), "new-task:sha256:")
assert_problem(lambda d: os.chmod(d / "task-db", 0o755), "task-db:mode:")
assert_problem(lambda d: ((d / "php").unlink(), (d / "php").symlink_to("/usr/bin/php")), "php:symlink-target:")
assert_problem(lambda d: ((d / "php").unlink(), (d / "php").write_text("not a link\n")), "php:type:expected-symlink")
assert_problem(lambda d: ((d / "new-task").unlink(), (d / "new-task").symlink_to("/usr/bin/true")), "new-task:type:expected-file")

# Installer refuses undeclared drift before mutation rather than deleting it.
with tempfile.TemporaryDirectory(prefix="runner-extra-") as td:
    dest = Path(td)
    deploy_exact(dest)
    extra = dest / "clean-test-db.pre-full"
    extra.write_text("legacy\n", encoding="utf-8")
    os.chmod(extra, 0o700)
    try:
        m.preinstall_inventory_guard(dest, manifest)
    except m.SyncRefused as exc:
        assert "RUNNER_INSTALL_REFUSED=UNDECLARED_LIVE_ENTRY:clean-test-db.pre-full" in str(exc)
        assert extra.exists()
    else:
        raise AssertionError("undeclared live executable was not refused")

helpers = ROOT / "tools" / "runner" / "helpers"
for name in ("new-task", "task-db", "test-db-slot", "clean-test-db", "runner-env"):
    subprocess.run(["bash", "-n", str(helpers / name)], check=True)
for name in ("preflight-task", "push-task"):
    compile((helpers / name).read_text(encoding="utf-8"), str(helpers / name), "exec")
clean = (helpers / "clean-test-db").read_text(encoding="utf-8")
new_task = (helpers / "new-task").read_text(encoding="utf-8")
push_task = (helpers / "push-task").read_text(encoding="utf-8")
assert '"$ROOT/bin/php" <<\'PHP\'' in clean
assert "\nphp <<'PHP'" not in clean
assert 'CANONICAL="chatgpt/production-stabilization-20260802"' in new_task
assert 'case "$BRANCH" in\n    chatgpt/*)' in new_task
assert 'if [ "$BRANCH" = "$CANONICAL" ]' in new_task
assert 'CANONICAL = "chatgpt/production-stabilization-20260802"' in push_task
assert 'if branch == CANONICAL:' in push_task
assert 'PUSH_TASK_BLOCK=CANONICAL_DRIFT' in push_task
assert 'if sys.argv[2] != "--dry-run":' in push_task
assert 'PUSH_TASK_BLOCK=INVALID_ARGUMENT' in push_task
assert '"--force"' not in push_task


def git(cwd: Path, *args: str, check: bool = True) -> subprocess.CompletedProcess:
    return subprocess.run(["git", "-C", str(cwd), *args], check=check, capture_output=True, text=True)


def expect_refused(fn, needle: str) -> None:
    try:
        fn()
    except m.SyncRefused as exc:
        assert needle in str(exc), (needle, str(exc))
    else:
        raise AssertionError(f"expected refusal: {needle}")


# Normal-install git guard contracts are exercised against a disposable remote.
with tempfile.TemporaryDirectory(prefix="runner-git-contract-") as td:
    base = Path(td)
    remote = base / "remote.git"
    repo = base / "repo"
    subprocess.run(["git", "init", "--bare", str(remote)], check=True, capture_output=True)
    subprocess.run(["git", "init", "-b", m.CANONICAL, str(repo)], check=True, capture_output=True)
    git(repo, "config", "user.name", "Runner Contract")
    git(repo, "config", "user.email", "runner-contract@example.invalid")
    (repo / "tools").mkdir()
    shutil.copytree(ROOT / "tools" / "runner", repo / "tools" / "runner")
    (repo / "contract.txt").write_text("v1\n", encoding="utf-8")
    git(repo, "add", ".")
    git(repo, "commit", "-m", "runner contract baseline")
    first = git(repo, "rev-parse", "HEAD").stdout.strip()
    git(repo, "remote", "add", "origin", str(remote))
    git(repo, "push", "-u", "origin", m.CANONICAL)

    real_identity = m._repo_identity_ok
    m._repo_identity_ok = lambda _url: True
    try:
        _, guarded_head = m.guard_current_canonical_source(repo)
        assert guarded_head == first

        git(repo, "checkout", "-b", "chatgpt/contract-task")
        expect_refused(lambda: m.guard_current_canonical_source(repo), "RUNNER_INSTALL_REFUSED=WRONG_BRANCH")
        git(repo, "checkout", m.CANONICAL)

        git(repo, "checkout", "--detach", "HEAD")
        expect_refused(lambda: m.guard_current_canonical_source(repo), "RUNNER_INSTALL_REFUSED=DETACHED_HEAD")
        git(repo, "checkout", m.CANONICAL)

        (repo / "dirty.txt").write_text("dirty\n", encoding="utf-8")
        expect_refused(lambda: m.guard_current_canonical_source(repo), "RUNNER_INSTALL_REFUSED=DIRTY_WORKTREE")
        (repo / "dirty.txt").unlink()

        # A commit not on canonical history is rejected as a rollback target.
        git(repo, "checkout", "--orphan", "alien")
        git(repo, "rm", "-rf", ".")
        (repo / "alien.txt").write_text("alien\n", encoding="utf-8")
        git(repo, "add", ".")
        git(repo, "commit", "-m", "alien")
        alien = git(repo, "rev-parse", "HEAD").stdout.strip()
        git(repo, "checkout", m.CANONICAL)
        expect_refused(lambda: m.validate_rollback_target(repo, alien), "RUNNER_ROLLBACK_REFUSED=NONCANONICAL_COMMIT")
        expect_refused(lambda: m.validate_rollback_target(repo, "not-a-sha"), "RUNNER_ROLLBACK_REFUSED=INVALID_COMMIT")

        # A historical exact canonical commit with a valid manifest is an allowed rollback target.
        (repo / "contract.txt").write_text("v2\n", encoding="utf-8")
        git(repo, "add", "contract.txt")
        git(repo, "commit", "-m", "current canonical")
        second = git(repo, "rev-parse", "HEAD").stdout.strip()
        git(repo, "push", "origin", m.CANONICAL)
        rb_manifest, rb_payloads = m.validate_rollback_target(repo, first)
        assert rb_manifest["version"] == "2026-09-09.1"
        assert set(rb_payloads) == set(m.EXPECTED_HELPERS)

        # Force a remote-drift result after the fresh fetch without mutating the remote.
        real_git = m._git
        def drift_git(r: Path, *args: str, **kwargs):
            if args and args[0] == "ls-remote":
                return subprocess.CompletedProcess([], 0, stdout=("f" * 40) + f"\trefs/heads/{m.CANONICAL}\n", stderr="")
            return real_git(r, *args, **kwargs)
        m._git = drift_git
        expect_refused(lambda: m.guard_current_canonical_source(repo), "RUNNER_INSTALL_REFUSED=REMOTE_DRIFT")
        m._git = real_git

        # Advance remote while leaving local canonical stale; fresh fetch must refuse it.
        clone = base / "writer"
        subprocess.run(["git", "clone", "-b", m.CANONICAL, str(remote), str(clone)], check=True, capture_output=True)
        git(clone, "config", "user.name", "Runner Contract")
        git(clone, "config", "user.email", "runner-contract@example.invalid")
        (clone / "remote-new.txt").write_text("new remote\n", encoding="utf-8")
        git(clone, "add", ".")
        git(clone, "commit", "-m", "advance remote")
        git(clone, "push", "origin", m.CANONICAL)
        assert git(repo, "rev-parse", "HEAD").stdout.strip() == second
        expect_refused(lambda: m.guard_current_canonical_source(repo), "RUNNER_INSTALL_REFUSED=STALE_CANONICAL_CHECKOUT")
    finally:
        m._repo_identity_ok = real_identity

print("runner_helpers_contract_test: PASS")
