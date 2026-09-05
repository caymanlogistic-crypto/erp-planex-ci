#!/usr/bin/env python3
"""Read-only verifier for the ERP PLANEX SentinelX source/dependency pin."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import subprocess
import tarfile
import tomllib
from urllib.parse import unquote, urlparse
from pathlib import Path

HERE = Path(__file__).resolve().parent
MANIFEST = HERE / "manifest.json"
LOCK_MANIFEST = HERE / "dependency-lock.json"
REQ_LOCK = HERE / "requirements.lock"
BOOTSTRAP_LOCK = HERE / "bootstrap.lock"
SCHEMA = "erp-planex-sentinelx-source-pin/v2"
LOCK_SCHEMA = "erp-planex-sentinelx-dependency-lock/v1"


def norm(name: str) -> str:
    return re.sub(r"[-_.]+", "-", name).lower()


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def fail(message: str) -> None:
    raise SystemExit(f"SENTINELX_PIN_VERIFY_FAIL: {message}")


def verify_archive_tree(archive: Path, source_dir: Path, expected_prefix: str) -> None:
    if not archive.is_file():
        fail(f"missing source archive: {archive}")
    if not source_dir.is_dir():
        fail(f"missing source directory: {source_dir}")
    with tarfile.open(archive, "r:gz") as handle:
        archived_files: set[Path] = set()
        root_name = expected_prefix.rstrip("/")
        for member in handle.getmembers():
            if member.name != root_name and not member.name.startswith(expected_prefix):
                fail(f"archive member outside expected prefix: {member.name}")
            if not member.isfile():
                continue
            relative = Path(member.name[len(expected_prefix):])
            archived_files.add(relative)
            live_path = source_dir / relative
            if not live_path.is_file():
                fail(f"missing extracted source file: {live_path}")
            extracted = handle.extractfile(member)
            if extracted is None:
                fail(f"cannot read archive member: {member.name}")
            if hashlib.sha256(extracted.read()).digest() != hashlib.sha256(live_path.read_bytes()).digest():
                fail(f"source differs from pinned archive: {live_path}")
    ignored_parts = {"__pycache__", ".pytest_cache", "dist", "build"}
    extra_files = {
        path.relative_to(source_dir)
        for path in source_dir.rglob("*")
        if path.is_file() and not ignored_parts.intersection(path.parts) and not path.name.endswith((".pyc", ".pyo"))
    } - archived_files
    if extra_files:
        fail(f"unexpected files in extracted source: {sorted(map(str, extra_files))[:5]}")


def file_mode(path: Path) -> int:
    return stat.S_IMODE(path.stat().st_mode)


def expected_lock_text(rows: list[dict]) -> str:
    lines = [
        "# Generated canonical hash lock for ERP PLANEX SentinelX.",
        "# Install with: pip install --require-hashes --no-deps -r <this-file>",
    ]
    for row in rows:
        lines.append(f"{row['name']}=={row['version']} --hash=sha256:{row['sha256']}")
    return "\n".join(lines) + "\n"


def verify_dependency_contract(manifest: dict) -> dict:
    lock = json.loads(LOCK_MANIFEST.read_text(encoding="utf-8"))
    contract = manifest.get("dependency_contract", {})
    if lock.get("schema") != LOCK_SCHEMA or contract.get("schema") != LOCK_SCHEMA:
        fail("dependency lock schema mismatch")
    if lock.get("version") != contract.get("version"):
        fail("dependency lock version mismatch")
    if contract.get("manifest") != "tools/sentinelx/dependency-lock.json":
        fail("dependency manifest path mismatch")
    if contract.get("requirements_lock") != "tools/sentinelx/requirements.lock" or contract.get("bootstrap_lock") != "tools/sentinelx/bootstrap.lock":
        fail("dependency requirements paths mismatch")
    rows = lock.get("external_artifacts")
    bootstrap = lock.get("bootstrap_artifacts")
    if not isinstance(rows, list) or not isinstance(bootstrap, list):
        fail("dependency artifact lists invalid")
    seen: set[str] = set()
    for row in rows + bootstrap:
        name = norm(str(row.get("name", "")))
        if not name or name in seen:
            fail(f"duplicate/invalid dependency name: {name}")
        seen.add(name)
        if not re.fullmatch(r"[0-9a-f]{64}", str(row.get("sha256", ""))):
            fail(f"invalid dependency artifact hash: {name}")
        if row.get("provenance") != "pypi-index-wheel" or not str(row.get("artifact", "")).endswith(".whl"):
            fail(f"invalid dependency provenance: {name}")
    if REQ_LOCK.read_text(encoding="utf-8") != expected_lock_text(rows):
        fail("requirements.lock does not match dependency manifest")
    if BOOTSTRAP_LOCK.read_text(encoding="utf-8") != expected_lock_text(bootstrap):
        fail("bootstrap.lock does not match dependency manifest")
    roles = {row.get("role") for row in rows + bootstrap}
    if not roles <= {"managed_runtime", "managed_build", "allowed_bootstrap"} or "allowed_bootstrap" not in roles:
        fail("dependency roles invalid")
    locals_ = lock.get("local_distributions")
    if not isinstance(locals_, list) or {norm(row.get("name", "")) for row in locals_} != {"sentinelx-cloud-protocol", "sentinelx-cloud-core"}:
        fail("local distribution contract invalid")
    return lock



def verify_artifact_cache(root: Path, lock: dict, profile: str) -> None:
    cfg = lock.get("profiles", {}).get(profile, {})
    cache = root / lock.get("artifact_cache_dir", "artifacts")
    required = bool(cfg.get("artifact_cache_required", False))
    if not cache.exists():
        if required:
            fail(f"required artifact cache missing: {cache}")
        return
    if not cache.is_dir() or cache.is_symlink():
        fail("artifact cache is not a regular directory")
    expected = {row["artifact"]: row["sha256"] for row in lock["external_artifacts"] + lock["bootstrap_artifacts"]}
    actual = {path.name for path in cache.iterdir() if path.is_file() and not path.is_symlink()}
    if actual != set(expected):
        fail(f"artifact cache inventory mismatch missing={sorted(set(expected)-actual)} unexpected={sorted(actual-set(expected))}")
    for name, digest in sorted(expected.items()):
        path = cache / name
        if sha256(path) != digest:
            fail(f"artifact cache SHA256 mismatch: {name}")

def venv_snapshot(root: Path) -> dict:
    python = root / ".venv" / "bin" / "python"
    if not python.is_file():
        fail(f"missing venv Python: {python}")
    code = r'''import json
from importlib import metadata
from pathlib import Path
rows=[]
for d in metadata.distributions():
    name=d.metadata.get("Name") or ""
    p=Path(d._path)
    direct=p/"direct_url.json"
    rows.append({"name":name,"version":d.version,"direct_url":json.loads(direct.read_text()) if direct.exists() else None})
eps={}
for e in metadata.entry_points(group="console_scripts"):
    if e.name.startswith("sentinelx-"):
        eps[e.name]=e.value
print(json.dumps({"distributions":rows,"entry_points":eps},sort_keys=True))'''
    cp = subprocess.run([str(python), "-c", code], check=False, capture_output=True, text=True)
    if cp.returncode != 0:
        fail(f"cannot inspect venv closure: {cp.stderr.strip()}")
    try:
        return json.loads(cp.stdout)
    except json.JSONDecodeError:
        fail("invalid venv closure output")


def runtime_reported_version(root: Path) -> str:
    env = os.environ.copy()
    env["PYTHONPATH"] = os.pathsep.join([str(root / "core" / "src"), str(root / "protocol" / "python")])
    cp = subprocess.run(
        [str(root / ".venv" / "bin" / "python"), "-c", "import sentinelx_core; print(sentinelx_core.AGENT_VERSION)"],
        check=False, capture_output=True, text=True, env=env,
    )
    if cp.returncode != 0:
        fail(f"cannot read runtime-reported version: {cp.stderr.strip()}")
    return cp.stdout.strip()


def validate_closure_snapshot(root: Path, lock: dict, profile: str, snap: dict, reported_version: str, allow_relocation_drift: bool = False) -> None:
    profiles = lock.get("profiles", {})
    if profile not in profiles:
        fail(f"unknown dependency profile: {profile}")
    cfg = profiles[profile]
    installed: dict[str, dict] = {}
    for row in snap["distributions"]:
        key = norm(row["name"])
        if key in installed:
            fail(f"duplicate installed distribution: {key}")
        installed[key] = row
    expected_names = {norm(name) for name in cfg.get("expected_distributions", [])}
    if set(installed) != expected_names:
        missing = sorted(expected_names - set(installed))
        unexpected = sorted(set(installed) - expected_names)
        fail(f"installed closure mismatch missing={missing} unexpected={unexpected}")
    expected_versions = {}
    for row in lock["external_artifacts"] + lock["bootstrap_artifacts"] + lock["local_distributions"]:
        expected_versions[norm(row["name"])] = str(row["version"])
    for name in sorted(expected_names):
        if installed[name]["version"] != expected_versions[name]:
            fail(f"installed version mismatch: {name} expected={expected_versions[name]} actual={installed[name]['version']}")
    for local in lock["local_distributions"]:
        name = norm(local["name"])
        if name not in installed:
            continue
        direct = installed[name].get("direct_url")
        if not isinstance(direct, dict) or not isinstance(direct.get("url"), str):
            fail(f"local distribution source metadata missing: {name}")
        expected_url = (root / local["source"]).resolve().as_uri()
        if direct["url"] != expected_url:
            if not allow_relocation_drift:
                fail(f"local distribution source path mismatch: {name} expected={expected_url} actual={direct['url']}")
            parsed = urlparse(direct["url"])
            if parsed.scheme != "file":
                fail(f"local distribution source is not file provenance: {name}")
            source_path = Path(unquote(parsed.path))
            runner_root = Path.home() / "erp-planex-test"
            try:
                relative = source_path.relative_to(runner_root)
            except ValueError:
                fail(f"local distribution source outside runner root: {name}")
            if len(relative.parts) != 2 or relative.parts[1] != local["source"]:
                fail(f"local distribution source path mismatch: {name}")
            if not relative.parts[0].startswith("sentinelx-agent.candidate"):
                fail(f"local distribution source provenance root mismatch: {name}")
    core_name = "sentinelx-cloud-core"
    if cfg.get("core_distribution") == "absent" and core_name in installed:
        fail("core distribution unexpectedly installed in rollback-source profile")
    if cfg.get("core_distribution") == "installed" and core_name not in installed:
        fail("core distribution missing in final profile")
    for script in cfg.get("required_console_scripts", []):
        target = root / ".venv" / "bin" / script
        if not target.is_file() or not (file_mode(target) & 0o111):
            fail(f"required console script missing/non-executable: {script}")
        if script not in snap.get("entry_points", {}):
            fail(f"console script entry point not registered: {script}")
    expected_eps = {
        "sentinelx-cloud-core": "sentinelx_core.__main__:main",
        "sentinelx-pensa-safe-edit": "sentinelx_core.vendored.pensa_safe_edit:main",
    }
    if profile == "final":
        for name, value in expected_eps.items():
            if snap.get("entry_points", {}).get(name) != value:
                fail(f"console entry point mismatch: {name}")
    if reported_version != cfg.get("runtime_reported_version"):
        fail(f"runtime-reported version mismatch expected={cfg.get('runtime_reported_version')} actual={reported_version}")


def verify_console_scripts(root: Path, lock: dict, profile: str) -> None:
    cfg = lock.get("profiles", {}).get(profile, {})
    for script in cfg.get("required_console_scripts", []):
        target = root / ".venv" / "bin" / script
        try:
            cp = subprocess.run([str(target), "--help"], check=False, capture_output=True, text=True, timeout=10)
        except OSError as exc:
            fail(f"console script execution failed: {script}: {exc}")
        if cp.returncode != 0:
            fail(f"console script execution failed: {script}: rc={cp.returncode}: {(cp.stderr or cp.stdout).strip()}")


def verify_installed_closure(root: Path, manifest: dict, lock: dict, profile: str, allow_relocation_drift: bool = False) -> None:
    validate_closure_snapshot(
        root, lock, profile, venv_snapshot(root), runtime_reported_version(root),
        allow_relocation_drift=allow_relocation_drift,
    )
    if not allow_relocation_drift:
        verify_console_scripts(root, lock, profile)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--agent-root", type=Path, required=True)
    parser.add_argument("--profile", choices=("rollback-source", "final"), default="final")
    parser.add_argument("--skip-runtime-state", action="store_true")
    parser.add_argument("--pre-relocation-repair", action="store_true")
    args = parser.parse_args()
    if args.pre_relocation_repair and args.profile != "final":
        fail("--pre-relocation-repair is valid only with final profile")
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    if manifest.get("schema") != SCHEMA:
        fail("unsupported manifest schema")
    lock = verify_dependency_contract(manifest)
    root = args.agent_root.expanduser().resolve()
    core = manifest["upstream"]["core"]
    protocol = manifest["upstream"]["protocol"]
    if sha256(root / core["archive_name"]) != core["archive_sha256"]:
        fail("core archive SHA256 mismatch")
    if sha256(root / protocol["archive_name"]) != protocol["archive_sha256"]:
        fail("protocol archive SHA256 mismatch")
    verify_archive_tree(root / core["archive_name"], root / "core", f"sentinelx-cloud-core-{core['commit']}/")
    verify_archive_tree(root / protocol["archive_name"], root / "protocol", f"sentinelx-cloud-protocol-{protocol['package_version']}/")
    core_project = tomllib.loads((root / "core" / "pyproject.toml").read_text(encoding="utf-8"))
    protocol_project = tomllib.loads((root / "protocol" / "pyproject.toml").read_text(encoding="utf-8"))
    if core_project["project"]["name"] != "sentinelx-cloud-core" or core_project["project"]["version"] != core["source_project_version"]:
        fail("core project identity/version mismatch")
    if protocol_project["project"]["name"] != "sentinelx-cloud-protocol" or protocol_project["project"]["version"] != protocol["package_version"]:
        fail("protocol package identity/version mismatch")
    if core_project["project"]["requires-python"] != manifest["python"]["requires"]:
        fail("core Python requirement mismatch")
    expected_scripts = {"sentinelx-cloud-core": "sentinelx_core.__main__:main", "sentinelx-pensa-safe-edit": "sentinelx_core.vendored.pensa_safe_edit:main"}
    if core_project["project"].get("scripts") != expected_scripts:
        fail("pinned core console-script contract mismatch")
    protocol_init = (root / "protocol" / "python" / "sentinelx_protocol" / "__init__.py").read_text(encoding="utf-8")
    match = re.search(r'^PROTOCOL_VERSION\s*=\s*["\\\']([^"\\\']+)["\\\']', protocol_init, re.MULTILINE)
    if not match or match.group(1) != protocol["wire_protocol_version"]:
        fail("wire protocol version mismatch")
    pyvenv = (root / ".venv" / "pyvenv.cfg").read_text(encoding="utf-8")
    expected_version = manifest["python"]["installed_version"]
    if f"version = {expected_version}" not in pyvenv:
        fail("venv Python version mismatch")
    verify_artifact_cache(root, lock, args.profile)
    verify_installed_closure(root, manifest, lock, args.profile, allow_relocation_drift=args.pre_relocation_repair)
    if not args.skip_runtime_state:
        for relative in ("runtime/config.yaml", "runtime/identity.json", "runtime/host_id"):
            path = root / relative
            if not path.is_file():
                fail(f"missing runtime file: {path}")
            if file_mode(path) != 0o600:
                fail(f"unsafe mode for {path}: {file_mode(path):04o}")
    print("SENTINELX_PIN_VERIFY_PASS")
    print(f"profile={args.profile}")
    print(f"dependency_lock_version={lock['version']}")
    print(f"core_commit={core['commit']}")
    print(f"protocol_commit={protocol['commit']}")
    print(f"wire_protocol={protocol['wire_protocol_version']}")
    print("dependency_closure=PASS")
    if args.pre_relocation_repair:
        print("relocation_pre_repair=PASS_NOT_FINAL")


if __name__ == "__main__":
    main()
