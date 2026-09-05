#!/usr/bin/env python3
"""Build a parallel hash-locked ERP PLANEX SentinelX candidate."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import subprocess
import tarfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
MANIFEST = HERE / "manifest.json"
LOCK = HERE / "dependency-lock.json"
RUNNER_ROOT = Path.home() / "erp-planex-test"
LIVE_ROOT = RUNNER_ROOT / "sentinelx-agent"
PYTHON = Path("/usr/bin/python3.11")


class BuildRefused(RuntimeError):
    pass


def sha256(path: Path) -> str:
    h=hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda:f.read(1024*1024),b""):
            h.update(chunk)
    return h.hexdigest()


def safe_candidate(path: Path) -> Path:
    p=path.expanduser().absolute()
    if p == LIVE_ROOT or p == RUNNER_ROOT or p.parent != RUNNER_ROOT:
        raise BuildRefused("SENTINELX_CANDIDATE_PATH_REFUSED")
    if not p.name.startswith("sentinelx-agent.candidate"):
        raise BuildRefused("SENTINELX_CANDIDATE_NAME_REFUSED")
    if p.exists():
        raise BuildRefused("SENTINELX_CANDIDATE_ALREADY_EXISTS")
    return p


def extract_verified(archive: Path, destination: Path, prefix: str) -> None:
    destination.mkdir(mode=0o700)
    root_name=prefix.rstrip("/")
    with tarfile.open(archive,"r:gz") as tf:
        for member in tf.getmembers():
            if member.name != root_name and not member.name.startswith(prefix):
                raise BuildRefused(f"SENTINELX_ARCHIVE_PREFIX_REFUSED={member.name}")
            rel=member.name[len(prefix):]
            if not rel:
                continue
            target=destination/rel
            try:
                target.resolve(strict=False).relative_to(destination.resolve())
            except ValueError as exc:
                raise BuildRefused("SENTINELX_ARCHIVE_PATH_REFUSED") from exc
            if member.isdir():
                target.mkdir(parents=True,exist_ok=True)
                continue
            if not member.isfile():
                raise BuildRefused(f"SENTINELX_ARCHIVE_SPECIAL_ENTRY_REFUSED={member.name}")
            target.parent.mkdir(parents=True,exist_ok=True)
            src=tf.extractfile(member)
            if src is None:
                raise BuildRefused(f"SENTINELX_ARCHIVE_READ_FAIL={member.name}")
            target.write_bytes(src.read())
            os.chmod(target,member.mode & 0o777)


def run(*args: str, env: dict[str,str]|None=None) -> None:
    subprocess.run(list(args),check=True,env=env)


def verify_cache(cache: Path, lock: dict) -> None:
    expected={row["artifact"]:row["sha256"] for row in lock["external_artifacts"]+lock["bootstrap_artifacts"]}
    actual={p.name for p in cache.iterdir() if p.is_file()}
    if actual != set(expected):
        raise BuildRefused(f"SENTINELX_ARTIFACT_INVENTORY_MISMATCH missing={sorted(set(expected)-actual)} unexpected={sorted(actual-set(expected))}")
    for name,digest in expected.items():
        if sha256(cache/name)!=digest:
            raise BuildRefused(f"SENTINELX_ARTIFACT_HASH_MISMATCH={name}")


def main() -> int:
    ap=argparse.ArgumentParser()
    ap.add_argument("--source-agent-root",type=Path,required=True)
    ap.add_argument("--candidate-root",type=Path,required=True)
    args=ap.parse_args()
    manifest=json.loads(MANIFEST.read_text())
    lock=json.loads(LOCK.read_text())
    source=args.source_agent_root.expanduser().resolve()
    if source != LIVE_ROOT.resolve():
        raise BuildRefused("SENTINELX_SOURCE_ROOT_REFUSED")
    candidate=safe_candidate(args.candidate_root)
    if not PYTHON.is_file():
        raise BuildRefused("SENTINELX_PYTHON311_MISSING")

    core=manifest["upstream"]["core"]
    protocol=manifest["upstream"]["protocol"]
    for row in (core,protocol):
        src=source/row["archive_name"]
        if not src.is_file() or sha256(src)!=row["archive_sha256"]:
            raise BuildRefused(f"SENTINELX_SOURCE_ARCHIVE_MISMATCH={row['archive_name']}")

    candidate.mkdir(mode=0o700)
    try:
        for row in (core,protocol):
            shutil.copyfile(source/row["archive_name"],candidate/row["archive_name"])
        extract_verified(candidate/core["archive_name"],candidate/"core",f"sentinelx-cloud-core-{core['commit']}/")
        extract_verified(candidate/protocol["archive_name"],candidate/"protocol",f"sentinelx-cloud-protocol-{protocol['package_version']}/")
        run(str(PYTHON),"-m","venv",str(candidate/".venv"))
        py=candidate/".venv"/"bin"/"python"
        pip=[str(py),"-m","pip"]
        cache=candidate/lock["artifact_cache_dir"]
        cache.mkdir(mode=0o700)
        # Download through the canonical hash locks, then pin installation to the retained wheelhouse.
        run(*pip,"download","--disable-pip-version-check","--require-hashes","--no-deps","--only-binary=:all:","--dest",str(cache),"-r",str(HERE/"bootstrap.lock"))
        run(*pip,"install","--disable-pip-version-check","--require-hashes","--no-deps","--no-index","--find-links",str(cache),"-r",str(HERE/"bootstrap.lock"))
        run(*pip,"download","--disable-pip-version-check","--require-hashes","--no-deps","--only-binary=:all:","--dest",str(cache),"-r",str(HERE/"requirements.lock"))
        verify_cache(cache,lock)
        run(*pip,"install","--disable-pip-version-check","--require-hashes","--no-deps","--no-index","--find-links",str(cache),"-r",str(HERE/"requirements.lock"))
        # Local distributions are exact archive-verified sources; no dependency resolution or isolated downloads are allowed.
        for local in ("protocol","core"):
            run(*pip,"install","--disable-pip-version-check","--no-deps","--no-build-isolation","--no-index",str(candidate/local))
        run(str(Path(os.environ.get("PYTHON","python3"))),str(HERE/"verify_pin.py"),"--agent-root",str(candidate),"--profile","final","--skip-runtime-state")
    except Exception:
        shutil.rmtree(candidate,ignore_errors=True)
        raise
    print(f"SENTINELX_CANDIDATE_BUILD=PASS root={candidate}")
    return 0


if __name__=="__main__":
    try:
        raise SystemExit(main())
    except (BuildRefused,subprocess.CalledProcessError,OSError,ValueError,json.JSONDecodeError) as exc:
        print(f"SENTINELX_CANDIDATE_BUILD=REFUSED reason={exc}")
        raise SystemExit(1)
