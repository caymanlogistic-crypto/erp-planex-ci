#!/usr/bin/env python3
"""Fail-closed installer/verifier for ERP PLANEX persistent runner helpers."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

TOOL_DIR = Path(__file__).resolve().parent
MANIFEST_PATH = TOOL_DIR / "manifest.json"
SCHEMA = "erp-planex-runner-helpers/v2"
LEGACY_SCHEMA = "erp-planex-runner-helpers/v1"
EXPECTED_REPOSITORY = "caymanlogistic-crypto/erp-planex-ci"
CANONICAL = "chatgpt/production-stabilization-20260802"
EXPECTED_HELPERS = (
    "new-task", "task-db", "test-db-slot", "clean-test-db",
    "preflight-task", "push-task", "runner-env",
)
SUPPORT_NAME = "php"
SUPPORT_TARGET = "/usr/bin/php8.5"
EXPECTED_NAMES = EXPECTED_HELPERS + (SUPPORT_NAME,)
CANONICAL_RUNNER_ROOT = Path.home() / "erp-planex-test"
CANONICAL_DESTINATION = CANONICAL_RUNNER_ROOT / "bin"
HEX40 = re.compile(r"^[0-9a-f]{40}$")


class SyncRefused(RuntimeError):
    pass


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def _validate_v2_manifest(data: dict) -> dict:
    if data.get("schema") != SCHEMA:
        raise SyncRefused("RUNNER_MANIFEST_SCHEMA_REFUSED")
    if data.get("canonical_repository") != EXPECTED_REPOSITORY or data.get("canonical_branch") != CANONICAL:
        raise SyncRefused("RUNNER_MANIFEST_CANONICAL_COORDINATES_REFUSED")
    if data.get("runner_root") != "~/erp-planex-test" or data.get("destination") != "bin":
        raise SyncRefused("RUNNER_MANIFEST_DESTINATION_REFUSED")
    rows = data.get("entries")
    if not isinstance(rows, list):
        raise SyncRefused("RUNNER_MANIFEST_ENTRIES_INVALID")
    names = tuple(row.get("name") for row in rows)
    if names != EXPECTED_NAMES or len(set(names)) != len(EXPECTED_NAMES):
        raise SyncRefused("RUNNER_MANIFEST_EXPECTED_ENTRIES_MISMATCH")
    for row in rows:
        name = row["name"]
        if name in EXPECTED_HELPERS:
            if row.get("type") != "file" or row.get("managed") is not True or row.get("mode") != "0700":
                raise SyncRefused(f"RUNNER_MANIFEST_FILE_CONTRACT_REFUSED={name}")
            if not re.fullmatch(r"[0-9a-f]{64}", str(row.get("sha256", ""))):
                raise SyncRefused(f"RUNNER_MANIFEST_SHA_REFUSED={name}")
        elif row != {"name": SUPPORT_NAME, "type": "symlink", "managed": False, "target": SUPPORT_TARGET}:
            raise SyncRefused("RUNNER_MANIFEST_SUPPORT_CONTRACT_REFUSED")
    return data


def load_manifest(path: Path = MANIFEST_PATH) -> dict:
    return _validate_v2_manifest(json.loads(path.read_text(encoding="utf-8")))


def managed_rows(manifest: dict) -> list[dict]:
    return [row for row in manifest["entries"] if row.get("managed") is True]


def source_path(row: dict, tool_dir: Path = TOOL_DIR) -> Path:
    raw = row.get("source", "")
    rel = Path(raw)
    if rel.is_absolute() or ".." in rel.parts:
        raise SyncRefused("RUNNER_SOURCE_PATH_REFUSED")
    path = tool_dir / rel
    try:
        path.resolve(strict=False).relative_to(tool_dir.resolve())
    except ValueError as exc:
        raise SyncRefused("RUNNER_SOURCE_PATH_REFUSED") from exc
    return path


def validate_source(manifest: dict, tool_dir: Path = TOOL_DIR) -> None:
    declared = set()
    for row in managed_rows(manifest):
        path = source_path(row, tool_dir)
        if not path.is_file() or path.is_symlink():
            raise SyncRefused(f"RUNNER_SOURCE_FILE_INVALID={row['name']}")
        actual = sha256_file(path)
        if actual != row["sha256"]:
            raise SyncRefused(f"RUNNER_SOURCE_CHECKSUM_MISMATCH={row['name']} expected={row['sha256']} actual={actual}")
        declared.add(path.name)
    helper_dir = tool_dir / "helpers"
    actual_files = {p.name for p in helper_dir.iterdir() if p.is_file() and not p.is_symlink()}
    if actual_files != declared:
        raise SyncRefused("RUNNER_SOURCE_EXPECTED_FILES_MISMATCH=" + ",".join(sorted(actual_files ^ declared)))


def _assert_no_symlink(path: Path) -> None:
    if path.is_symlink():
        raise SyncRefused(f"RUNNER_SYMLINK_REFUSED={path}")


def validate_destination(destination: Path) -> Path:
    requested = destination.expanduser().absolute()
    expected = CANONICAL_DESTINATION.expanduser().absolute()
    if requested != expected:
        raise SyncRefused(f"RUNNER_DESTINATION_REFUSED={requested}")
    root = CANONICAL_RUNNER_ROOT.expanduser().absolute()
    if root != Path.home().absolute() / "erp-planex-test":
        raise SyncRefused("RUNNER_ROOT_REFUSED")
    if not root.is_dir():
        raise SyncRefused("RUNNER_ROOT_MISSING")
    _assert_no_symlink(root)
    _assert_no_symlink(expected)
    if root.resolve() != root:
        raise SyncRefused("RUNNER_ROOT_SYMLINK_ESCAPE_REFUSED")
    if expected.exists() and expected.resolve() != expected:
        raise SyncRefused("RUNNER_DESTINATION_SYMLINK_ESCAPE_REFUSED")
    try:
        expected.relative_to(root)
    except ValueError as exc:
        raise SyncRefused("RUNNER_DESTINATION_OUTSIDE_ROOT") from exc
    return expected


def _directory_names(directory: Path) -> set[str]:
    if not directory.is_dir() or directory.is_symlink():
        return set()
    return {entry.name for entry in os.scandir(directory)}


def verify_directory(directory: Path, manifest: dict, require_mode: bool = True) -> list[str]:
    problems: list[str] = []
    expected = {row["name"] for row in manifest["entries"]}
    actual = _directory_names(directory)
    for name in sorted(actual - expected):
        problems.append(f"inventory:unexpected:{name}")
    for name in sorted(expected - actual):
        problems.append(f"inventory:missing:{name}")
    by_name = {row["name"]: row for row in manifest["entries"]}
    for name in sorted(actual & expected):
        row = by_name[name]
        target = directory / name
        try:
            st = target.lstat()
        except OSError:
            problems.append(f"{name}:lstat-failed")
            continue
        if row["type"] == "file":
            if not stat.S_ISREG(st.st_mode) or target.is_symlink():
                problems.append(f"{name}:type:expected-file")
                continue
            actual_sha = sha256_file(target)
            if actual_sha != row["sha256"]:
                problems.append(f"{name}:sha256:{actual_sha}")
            if require_mode:
                actual_mode = stat.S_IMODE(st.st_mode)
                expected_mode = int(row["mode"], 8)
                if actual_mode != expected_mode:
                    problems.append(f"{name}:mode:{oct(actual_mode)}")
        elif row["type"] == "symlink":
            if not stat.S_ISLNK(st.st_mode):
                problems.append(f"{name}:type:expected-symlink")
                continue
            actual_target = os.readlink(target)
            if actual_target != row["target"]:
                problems.append(f"{name}:symlink-target:{actual_target}")
        else:
            problems.append(f"{name}:type:unsupported")
    return problems


def preinstall_inventory_guard(directory: Path, manifest: dict) -> None:
    expected = {row["name"] for row in manifest["entries"]}
    actual = _directory_names(directory)
    extras = sorted(actual - expected)
    if extras:
        raise SyncRefused("RUNNER_INSTALL_REFUSED=UNDECLARED_LIVE_ENTRY:" + ",".join(extras))
    support = directory / SUPPORT_NAME
    if SUPPORT_NAME not in actual:
        raise SyncRefused("RUNNER_INSTALL_REFUSED=MISSING_PHP_SUPPORT")
    if not support.is_symlink():
        raise SyncRefused("RUNNER_INSTALL_REFUSED=PHP_SUPPORT_WRONG_TYPE")
    if os.readlink(support) != SUPPORT_TARGET:
        raise SyncRefused("RUNNER_INSTALL_REFUSED=PHP_SUPPORT_WRONG_TARGET")


def _git(repo: Path, *args: str, check: bool = True, text: bool = True) -> subprocess.CompletedProcess:
    return subprocess.run(["git", "-C", str(repo), *args], check=check, capture_output=True, text=text)


def _repo_identity_ok(url: str) -> bool:
    url = url.strip()
    allowed = {
        "git@github.com:caymanlogistic-crypto/erp-planex-ci.git",
        "https://github.com/caymanlogistic-crypto/erp-planex-ci.git",
        "https://github.com/caymanlogistic-crypto/erp-planex-ci",
    }
    return url in allowed


def guard_current_canonical_source(repo: Path | None = None, *, remote_check: bool = True) -> tuple[Path, str]:
    if repo is None:
        cp = subprocess.run(["git", "-C", str(TOOL_DIR), "rev-parse", "--show-toplevel"], check=True, capture_output=True, text=True)
        repo = Path(cp.stdout.strip())
    repo = repo.resolve()
    origin = _git(repo, "remote", "get-url", "origin").stdout.strip()
    if not _repo_identity_ok(origin):
        raise SyncRefused("RUNNER_INSTALL_REFUSED=WRONG_REPOSITORY")
    branch = _git(repo, "symbolic-ref", "--quiet", "--short", "HEAD", check=False).stdout.strip()
    if not branch:
        raise SyncRefused("RUNNER_INSTALL_REFUSED=DETACHED_HEAD")
    if branch != CANONICAL:
        raise SyncRefused("RUNNER_INSTALL_REFUSED=WRONG_BRANCH")
    if _git(repo, "status", "--porcelain=v1", "--untracked-files=all").stdout.strip():
        raise SyncRefused("RUNNER_INSTALL_REFUSED=DIRTY_WORKTREE")
    fetch = _git(repo, "fetch", "--quiet", "origin", f"refs/heads/{CANONICAL}:refs/remotes/origin/{CANONICAL}", check=False)
    if fetch.returncode != 0:
        raise SyncRefused("RUNNER_INSTALL_REFUSED=FETCH_FAILED")
    local_head = _git(repo, "rev-parse", "HEAD").stdout.strip()
    remote_head = _git(repo, "rev-parse", f"origin/{CANONICAL}").stdout.strip()
    if local_head != remote_head:
        raise SyncRefused("RUNNER_INSTALL_REFUSED=STALE_CANONICAL_CHECKOUT")
    if _git(repo, "merge-base", "--is-ancestor", local_head, f"origin/{CANONICAL}", check=False).returncode != 0:
        raise SyncRefused("RUNNER_INSTALL_REFUSED=NONCANONICAL_ANCESTRY")
    tracked = ["tools/runner/manifest.json", "tools/runner/sync_runner_helpers.py"]
    tracked.extend(f"tools/runner/{row['source']}" for row in managed_rows(load_manifest(repo / "tools/runner/manifest.json")))
    for rel in tracked:
        if _git(repo, "cat-file", "-e", f"HEAD:{rel}", check=False).returncode != 0:
            raise SyncRefused(f"RUNNER_INSTALL_REFUSED=SOURCE_NOT_AT_HEAD:{rel}")
    if remote_check:
        cp = _git(repo, "ls-remote", "origin", f"refs/heads/{CANONICAL}", check=False)
        if cp.returncode != 0 or not cp.stdout.strip():
            raise SyncRefused("RUNNER_INSTALL_REFUSED=REMOTE_HEAD_UNAVAILABLE")
        live_remote = cp.stdout.split()[0]
        if live_remote != remote_head:
            raise SyncRefused("RUNNER_INSTALL_REFUSED=REMOTE_DRIFT")
    return repo, local_head


def _state_root() -> Path:
    state_parent = CANONICAL_RUNNER_ROOT / "state"
    _assert_no_symlink(state_parent)
    if state_parent.exists() and not state_parent.is_dir():
        raise SyncRefused("RUNNER_STATE_PATH_INVALID")
    state_parent.mkdir(mode=0o700, parents=False, exist_ok=True)
    state_root = state_parent / "runner-helper-sync"
    _assert_no_symlink(state_root)
    if state_root.exists() and not state_root.is_dir():
        raise SyncRefused("RUNNER_SYNC_STATE_PATH_INVALID")
    state_root.mkdir(mode=0o700, parents=False, exist_ok=True)
    os.chmod(state_root, 0o700)
    return state_root


def _write_marker(manifest: dict, source_commit: str, operation: str) -> None:
    marker = CANONICAL_RUNNER_ROOT / "state" / "runner-helper-version.json"
    payload = {
        "schema": manifest["schema"], "version": manifest["version"],
        "manifest_sha256": sha256_file(MANIFEST_PATH),
        "source_commit": source_commit, "operation": operation,
        "entries": {row["name"]: (row.get("sha256") or row.get("target")) for row in manifest["entries"]},
    }
    tmp = marker.with_suffix(".tmp")
    tmp.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    os.chmod(tmp, 0o600)
    os.replace(tmp, marker)
    os.chmod(marker, 0o600)


def _install_bytes(manifest: dict, dest: Path, payloads: dict[str, bytes], source_commit: str, operation: str) -> None:
    preinstall_inventory_guard(dest, manifest)
    state_root = _state_root()
    with tempfile.TemporaryDirectory(prefix="stage-", dir=state_root) as td:
        stage = Path(td)
        for row in managed_rows(manifest):
            data = payloads[row["name"]]
            if sha256_bytes(data) != row["sha256"]:
                raise SyncRefused(f"RUNNER_STAGE_CHECKSUM_MISMATCH={row['name']}")
            staged = stage / row["name"]
            staged.write_bytes(data)
            os.chmod(staged, int(row["mode"], 8))
        for row in managed_rows(manifest):
            target = dest / row["name"]
            if target.is_symlink() or (target.exists() and not target.is_file()):
                raise SyncRefused(f"RUNNER_LIVE_TARGET_INVALID={row['name']}")
            os.replace(stage / row["name"], target)
            os.chmod(target, int(row["mode"], 8))
    problems = verify_directory(dest, manifest, require_mode=True)
    if problems:
        raise SyncRefused("RUNNER_POST_INSTALL_VERIFY_FAIL=" + ";".join(problems))
    _write_marker(manifest, source_commit, operation)


def install(manifest: dict, destination: Path) -> None:
    validate_source(manifest)
    dest = validate_destination(destination)
    if not dest.is_dir():
        raise SyncRefused("RUNNER_INSTALL_REFUSED=DESTINATION_MISSING")
    repo, head = guard_current_canonical_source()
    payloads = {row["name"]: source_path(row).read_bytes() for row in managed_rows(manifest)}
    _install_bytes(manifest, dest, payloads, head, "install")


def _historical_manifest(raw: bytes) -> dict:
    data = json.loads(raw.decode("utf-8"))
    if data.get("schema") == SCHEMA:
        return _validate_v2_manifest(data)
    if data.get("schema") != LEGACY_SCHEMA:
        raise SyncRefused("RUNNER_ROLLBACK_MANIFEST_SCHEMA_REFUSED")
    rows = data.get("files")
    if not isinstance(rows, list) or tuple(row.get("name") for row in rows) != EXPECTED_HELPERS:
        raise SyncRefused("RUNNER_ROLLBACK_LEGACY_FILES_REFUSED")
    entries = []
    for row in rows:
        if row.get("mode") != "0700" or not re.fullmatch(r"[0-9a-f]{64}", str(row.get("sha256", ""))):
            raise SyncRefused(f"RUNNER_ROLLBACK_LEGACY_FILE_REFUSED={row.get('name')}")
        entries.append({"name": row["name"], "type": "file", "managed": True, "source": row["source"], "sha256": row["sha256"], "mode": "0700"})
    entries.append({"name": SUPPORT_NAME, "type": "symlink", "managed": False, "target": SUPPORT_TARGET})
    return {
        "schema": SCHEMA, "version": data.get("version"),
        "canonical_repository": EXPECTED_REPOSITORY, "canonical_branch": CANONICAL,
        "runner_root": "~/erp-planex-test", "destination": "bin", "entries": entries,
    }


def validate_rollback_target(repo: Path, commit: str) -> tuple[dict, dict[str, bytes]]:
    if not HEX40.fullmatch(commit):
        raise SyncRefused("RUNNER_ROLLBACK_REFUSED=INVALID_COMMIT")
    if _git(repo, "cat-file", "-e", f"{commit}^{{commit}}", check=False).returncode != 0:
        raise SyncRefused("RUNNER_ROLLBACK_REFUSED=UNKNOWN_COMMIT")
    if _git(repo, "merge-base", "--is-ancestor", commit, f"origin/{CANONICAL}", check=False).returncode != 0:
        raise SyncRefused("RUNNER_ROLLBACK_REFUSED=NONCANONICAL_COMMIT")
    cp = _git(repo, "show", f"{commit}:tools/runner/manifest.json", check=False, text=False)
    if cp.returncode != 0:
        raise SyncRefused("RUNNER_ROLLBACK_REFUSED=NO_MANIFEST")
    manifest = _historical_manifest(cp.stdout)
    payloads: dict[str, bytes] = {}
    for row in managed_rows(manifest):
        rel = row.get("source", "")
        if Path(rel).is_absolute() or ".." in Path(rel).parts:
            raise SyncRefused("RUNNER_ROLLBACK_REFUSED=SOURCE_PATH")
        blob = _git(repo, "show", f"{commit}:tools/runner/{rel}", check=False, text=False)
        if blob.returncode != 0:
            raise SyncRefused(f"RUNNER_ROLLBACK_REFUSED=MISSING_SOURCE:{row['name']}")
        if sha256_bytes(blob.stdout) != row["sha256"]:
            raise SyncRefused(f"RUNNER_ROLLBACK_REFUSED=CHECKSUM:{row['name']}")
        payloads[row["name"]] = blob.stdout
    return manifest, payloads


def rollback(commit: str, destination: Path, dry_run: bool = False) -> None:
    current = load_manifest()
    validate_source(current)
    dest = validate_destination(destination)
    repo, _ = guard_current_canonical_source()
    manifest, payloads = validate_rollback_target(repo, commit)
    preinstall_inventory_guard(dest, manifest)
    print(f"RUNNER_ROLLBACK_TARGET commit={commit} version={manifest['version']}")
    if dry_run:
        print("RUNNER_ROLLBACK_CONTRACT=PASS dry_run=1")
        return
    _install_bytes(manifest, dest, payloads, commit, "rollback")
    print(f"RUNNER_ROLLBACK=PASS commit={commit} version={manifest['version']}")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("verify-source", "verify-live", "install", "rollback"))
    parser.add_argument("--destination", default=str(CANONICAL_DESTINATION))
    parser.add_argument("--commit")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)
    try:
        manifest = load_manifest()
        if args.command == "verify-source":
            validate_source(manifest)
            print(f"RUNNER_HELPER_SOURCE_VERIFY=PASS version={manifest['version']}")
            return 0
        destination = validate_destination(Path(args.destination))
        if args.command == "verify-live":
            validate_source(manifest)
            problems = verify_directory(destination, manifest, require_mode=True)
            if problems:
                raise SyncRefused("RUNNER_LIVE_VERIFY_FAIL=" + ";".join(problems))
            print(f"RUNNER_HELPER_LIVE_VERIFY=PASS version={manifest['version']}")
            return 0
        if args.command == "install":
            if args.commit or args.dry_run:
                raise SyncRefused("RUNNER_INSTALL_REFUSED=INVALID_ARGUMENT")
            install(manifest, destination)
            print(f"RUNNER_HELPER_INSTALL=PASS version={manifest['version']}")
            return 0
        if not args.commit:
            raise SyncRefused("RUNNER_ROLLBACK_REFUSED=MISSING_COMMIT")
        rollback(args.commit, destination, args.dry_run)
        return 0
    except (OSError, ValueError, json.JSONDecodeError, subprocess.CalledProcessError, SyncRefused) as exc:
        print(f"RUNNER_HELPER_SYNC=REFUSED reason={exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
