#!/usr/bin/env python3
"""Read-only verifier for the ERP PLANEX SentinelX source-mode pin."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import stat
import tarfile
import tomllib
from pathlib import Path


HERE = Path(__file__).resolve().parent
MANIFEST = HERE / "manifest.json"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def fail(message: str) -> None:
    raise SystemExit(f"SENTINELX_PIN_VERIFY_FAIL: {message}")


def verify_archive_tree(archive: Path, source_dir: Path, expected_prefix: str) -> None:
    with tarfile.open(archive, "r:gz") as handle:
        archived_files: set[Path] = set()
        root_name = expected_prefix.rstrip("/")
        for member in handle.getmembers():
            if member.name != root_name and not member.name.startswith(expected_prefix):
                fail(f"archive member outside expected prefix: {member.name}")
            if not member.isfile():
                continue
            relative = Path(member.name[len(expected_prefix) :])
            archived_files.add(relative)
            live_path = source_dir / relative
            if not live_path.is_file():
                fail(f"missing extracted source file: {live_path}")
            extracted = handle.extractfile(member)
            if extracted is None:
                fail(f"cannot read archive member: {member.name}")
            if hashlib.sha256(extracted.read()).digest() != hashlib.sha256(
                live_path.read_bytes()
            ).digest():
                fail(f"source differs from pinned archive: {live_path}")

    extra_files = {
        path.relative_to(source_dir)
        for path in source_dir.rglob("*")
        if path.is_file() and "__pycache__" not in path.parts
    } - archived_files
    if extra_files:
        fail(f"unexpected files in extracted source: {sorted(map(str, extra_files))[:5]}")


def file_mode(path: Path) -> int:
    return stat.S_IMODE(path.stat().st_mode)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--agent-root", type=Path, required=True)
    args = parser.parse_args()

    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    if manifest.get("schema") != "erp-planex-sentinelx-source-pin/v1":
        fail("unsupported manifest schema")

    root = args.agent_root.expanduser().resolve()
    core = manifest["upstream"]["core"]
    protocol = manifest["upstream"]["protocol"]

    core_archive = root / core["archive_name"]
    protocol_archive = root / protocol["archive_name"]
    if sha256(core_archive) != core["archive_sha256"]:
        fail("core archive SHA256 mismatch")
    if sha256(protocol_archive) != protocol["archive_sha256"]:
        fail("protocol archive SHA256 mismatch")

    verify_archive_tree(
        core_archive,
        root / "core",
        f"sentinelx-cloud-core-{core['commit']}/",
    )
    verify_archive_tree(
        protocol_archive,
        root / "protocol",
        f"sentinelx-cloud-protocol-{protocol['package_version']}/",
    )

    core_project = tomllib.loads((root / "core" / "pyproject.toml").read_text(encoding="utf-8"))
    protocol_project = tomllib.loads(
        (root / "protocol" / "pyproject.toml").read_text(encoding="utf-8")
    )
    if core_project["project"]["version"] != core["source_project_version"]:
        fail("core project version mismatch")
    if protocol_project["project"]["version"] != protocol["package_version"]:
        fail("protocol package version mismatch")
    if core_project["project"]["requires-python"] != manifest["python"]["requires"]:
        fail("core Python requirement mismatch")

    protocol_init = (
        root / "protocol" / "python" / "sentinelx_protocol" / "__init__.py"
    ).read_text(encoding="utf-8")
    match = re.search(
        r'^PROTOCOL_VERSION\s*=\s*["\']([^"\']+)["\']',
        protocol_init,
        re.MULTILINE,
    )
    if not match or match.group(1) != protocol["wire_protocol_version"]:
        fail("wire protocol version mismatch")

    for relative in ("runtime/config.yaml", "runtime/identity.json", "runtime/host_id"):
        path = root / relative
        if not path.is_file():
            fail(f"missing runtime file: {path}")
        if file_mode(path) != 0o600:
            fail(f"unsafe mode for {path}: {file_mode(path):04o}")

    pyvenv = (root / ".venv" / "pyvenv.cfg").read_text(encoding="utf-8")
    expected_version = manifest["python"]["installed_version"]
    if f"version = {expected_version}" not in pyvenv:
        fail("venv Python version mismatch")

    print("SENTINELX_PIN_VERIFY_PASS")
    print(f"core_commit={core['commit']}")
    print(f"protocol_commit={protocol['commit']}")
    print(f"wire_protocol={protocol['wire_protocol_version']}")


if __name__ == "__main__":
    main()
