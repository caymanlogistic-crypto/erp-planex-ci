#!/usr/bin/env python3
"""Fail-closed installer/verifier for ERP PLANEX persistent runner helpers."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import stat
import sys
import tempfile
from pathlib import Path

TOOL_DIR = Path(__file__).resolve().parent
MANIFEST_PATH = TOOL_DIR / "manifest.json"
SCHEMA = "erp-planex-runner-helpers/v1"
EXPECTED_NAMES = (
    "new-task",
    "task-db",
    "test-db-slot",
    "clean-test-db",
    "preflight-task",
    "push-task",
    "runner-env",
)
CANONICAL_RUNNER_ROOT = Path.home() / "erp-planex-test"
CANONICAL_DESTINATION = CANONICAL_RUNNER_ROOT / "bin"


class SyncRefused(RuntimeError):
    pass


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def load_manifest(path: Path = MANIFEST_PATH) -> dict:
    data = json.loads(path.read_text(encoding="utf-8"))
    if data.get("schema") != SCHEMA:
        raise SyncRefused("RUNNER_MANIFEST_SCHEMA_REFUSED")
    if data.get("runner_root") != "~/erp-planex-test" or data.get("destination") != "bin":
        raise SyncRefused("RUNNER_MANIFEST_DESTINATION_REFUSED")
    rows = data.get("files")
    if not isinstance(rows, list):
        raise SyncRefused("RUNNER_MANIFEST_FILES_INVALID")
    names = tuple(row.get("name") for row in rows)
    if names != EXPECTED_NAMES or len(set(names)) != len(EXPECTED_NAMES):
        raise SyncRefused("RUNNER_MANIFEST_EXPECTED_FILES_MISMATCH")
    return data


def source_path(row: dict) -> Path:
    raw = row.get("source", "")
    rel = Path(raw)
    if rel.is_absolute() or ".." in rel.parts:
        raise SyncRefused("RUNNER_SOURCE_PATH_REFUSED")
    path = TOOL_DIR / rel
    try:
        path.resolve(strict=False).relative_to(TOOL_DIR.resolve())
    except ValueError as exc:
        raise SyncRefused("RUNNER_SOURCE_PATH_REFUSED") from exc
    return path


def validate_source(manifest: dict) -> None:
    declared = set()
    for row in manifest["files"]:
        if row.get("mode") != "0700":
            raise SyncRefused(f"RUNNER_MODE_REFUSED={row.get('name')}")
        path = source_path(row)
        if not path.is_file() or path.is_symlink():
            raise SyncRefused(f"RUNNER_SOURCE_FILE_INVALID={row.get('name')}")
        actual = sha256_file(path)
        if actual != row.get("sha256"):
            raise SyncRefused(
                f"RUNNER_SOURCE_CHECKSUM_MISMATCH={row.get('name')} expected={row.get('sha256')} actual={actual}"
            )
        declared.add(path.name)

    helper_dir = TOOL_DIR / "helpers"
    actual_files = {p.name for p in helper_dir.iterdir() if p.is_file() and not p.is_symlink()}
    if actual_files != declared:
        raise SyncRefused(
            "RUNNER_SOURCE_EXPECTED_FILES_MISMATCH="
            + ",".join(sorted(actual_files ^ declared))
        )


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


def verify_directory(directory: Path, manifest: dict, require_mode: bool = True) -> list[str]:
    problems: list[str] = []
    for row in manifest["files"]:
        target = directory / row["name"]
        if not target.is_file() or target.is_symlink():
            problems.append(f"{row['name']}:missing-or-invalid")
            continue
        actual = sha256_file(target)
        if actual != row["sha256"]:
            problems.append(f"{row['name']}:sha256:{actual}")
        if require_mode:
            actual_mode = stat.S_IMODE(target.stat().st_mode)
            if actual_mode != 0o700:
                problems.append(f"{row['name']}:mode:{oct(actual_mode)}")
    return problems


def install(manifest: dict, destination: Path) -> None:
    validate_source(manifest)
    dest = validate_destination(destination)
    dest.mkdir(mode=0o700, parents=False, exist_ok=True)

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

    with tempfile.TemporaryDirectory(prefix="stage-", dir=state_root) as td:
        stage = Path(td)
        for row in manifest["files"]:
            target = dest / row["name"]
            if target.is_symlink() or (target.exists() and not target.is_file()):
                raise SyncRefused(f"RUNNER_LIVE_TARGET_INVALID={row['name']}")
            staged = stage / row["name"]
            shutil.copyfile(source_path(row), staged)
            os.chmod(staged, 0o700)
            if sha256_file(staged) != row["sha256"]:
                raise SyncRefused(f"RUNNER_STAGE_CHECKSUM_MISMATCH={row['name']}")

        for row in manifest["files"]:
            os.replace(stage / row["name"], dest / row["name"])
            os.chmod(dest / row["name"], 0o700)

    problems = verify_directory(dest, manifest, require_mode=True)
    if problems:
        raise SyncRefused("RUNNER_POST_INSTALL_VERIFY_FAIL=" + ";".join(problems))

    marker = CANONICAL_RUNNER_ROOT / "state" / "runner-helper-version.json"
    payload = {
        "schema": manifest["schema"],
        "version": manifest["version"],
        "manifest_sha256": sha256_file(MANIFEST_PATH),
        "files": {row["name"]: row["sha256"] for row in manifest["files"]},
    }
    tmp = marker.with_suffix(".tmp")
    tmp.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    os.chmod(tmp, 0o600)
    os.replace(tmp, marker)
    os.chmod(marker, 0o600)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("verify-source", "verify-live", "install"))
    parser.add_argument("--destination", default=str(CANONICAL_DESTINATION))
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

        install(manifest, destination)
        print(f"RUNNER_HELPER_INSTALL=PASS version={manifest['version']}")
        return 0
    except (OSError, ValueError, json.JSONDecodeError, SyncRefused) as exc:
        print(f"RUNNER_HELPER_SYNC=REFUSED reason={exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
