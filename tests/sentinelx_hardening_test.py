#!/usr/bin/env python3
from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
import os
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
VERIFY=ROOT/"tools"/"sentinelx"/"verify_pin.py"
CUTOVER=ROOT/"tools"/"sentinelx"/"rootless_cutover.py"
BUILD=ROOT/"tools"/"sentinelx"/"build_candidate.py"

def load(name: str,path: Path):
    spec=importlib.util.spec_from_file_location(name,path); assert spec and spec.loader
    mod=importlib.util.module_from_spec(spec); spec.loader.exec_module(mod); return mod

v=load("sentinelx_verify",VERIFY)
c=load("sentinelx_cutover",CUTOVER)
b=load("sentinelx_build",BUILD)
manifest=json.loads((ROOT/"tools"/"sentinelx"/"manifest.json").read_text())
lock=json.loads((ROOT/"tools"/"sentinelx"/"dependency-lock.json").read_text())
assert manifest["schema"]=="erp-planex-sentinelx-source-pin/v2"
assert lock["schema"]=="erp-planex-sentinelx-dependency-lock/v1"
assert lock["version"]=="2026-09-05.2"
assert manifest["dependency_contract"]["version"]==lock["version"]
assert manifest["upstream"]["core"]["commit"]=="d6b33822b61e7fccf39547d5cce8add8bc7021f9"
assert manifest["upstream"]["protocol"]["commit"]=="2ffe1b6ae6bcae282b39f6939b9b50669c93b917"
assert manifest["relocation_contract"]["repair_command"].endswith("rootless_cutover.py repair-live")
assert "--no-deps" in manifest["relocation_contract"]["candidate_to_live"]
v.verify_dependency_contract(manifest)


def expect_fail(fn,needle: str):
    try: fn()
    except SystemExit as exc:
        assert needle in str(exc),(needle,str(exc))
    else: raise AssertionError(f"expected verifier failure: {needle}")


def snapshot(profile: str, root: Path) -> dict:
    cfg=lock["profiles"][profile]
    versions={v.norm(row["name"]):str(row["version"]) for row in lock["external_artifacts"]+lock["bootstrap_artifacts"]+lock["local_distributions"]}
    rows=[]
    for raw in cfg["expected_distributions"]:
        name=v.norm(raw); direct=None
        if name=="sentinelx-cloud-protocol": direct={"url":(root/"protocol").resolve().as_uri()}
        if name=="sentinelx-cloud-core": direct={"url":(root/"core").resolve().as_uri()}
        rows.append({"name":raw,"version":versions[name],"direct_url":direct})
    eps={}
    if profile=="final": eps={"sentinelx-cloud-core":"sentinelx_core.__main__:main","sentinelx-pensa-safe-edit":"sentinelx_core.vendored.pensa_safe_edit:main"}
    return {"distributions":rows,"entry_points":eps}

# Exact closure PASS for current rollback source profile.
with tempfile.TemporaryDirectory(prefix="sentinelx-closure-") as td:
    r=Path(td); (r/"protocol").mkdir(); (r/"core").mkdir(); (r/".venv"/"bin").mkdir(parents=True)
    v.validate_closure_snapshot(r,lock,"rollback-source",snapshot("rollback-source",r),"0.0.0+dev")

    drift=snapshot("rollback-source",r); drift["distributions"][0]["version"]="0.0.0"
    expect_fail(lambda:v.validate_closure_snapshot(r,lock,"rollback-source",drift,"0.0.0+dev"),"installed version mismatch")
    missing=snapshot("rollback-source",r); missing["distributions"].pop()
    expect_fail(lambda:v.validate_closure_snapshot(r,lock,"rollback-source",missing,"0.0.0+dev"),"installed closure mismatch")
    extra=snapshot("rollback-source",r); extra["distributions"].append({"name":"surprise-package","version":"1","direct_url":None})
    expect_fail(lambda:v.validate_closure_snapshot(r,lock,"rollback-source",extra,"0.0.0+dev"),"unexpected=['surprise-package']")
    wrong_source=snapshot("rollback-source",r)
    for row in wrong_source["distributions"]:
        if v.norm(row["name"])=="sentinelx-cloud-protocol": row["direct_url"]={"url":"file:///tmp/protocol"}
    expect_fail(lambda:v.validate_closure_snapshot(r,lock,"rollback-source",wrong_source,"0.0.0+dev"),"local distribution source path mismatch")

# Final closure requires exact installed core + exact console entry points.
with tempfile.TemporaryDirectory(prefix="sentinelx-final-closure-") as td:
    r=Path(td); (r/"protocol").mkdir(); (r/"core").mkdir(); bindir=r/".venv"/"bin"; bindir.mkdir(parents=True)
    for name in ("sentinelx-cloud-core","sentinelx-pensa-safe-edit"):
        f=bindir/name; f.write_text("#!/bin/sh\nexit 0\n"); os.chmod(f,0o700)
    v.validate_closure_snapshot(r,lock,"final",snapshot("final",r),"0.11.14")
    no_core=snapshot("final",r); no_core["distributions"]=[row for row in no_core["distributions"] if v.norm(row["name"])!="sentinelx-cloud-core"]
    expect_fail(lambda:v.validate_closure_snapshot(r,lock,"final",no_core,"0.11.14"),"installed closure mismatch")
    expect_fail(lambda:v.validate_closure_snapshot(r,lock,"final",snapshot("final",r),"0.0.0+dev"),"runtime-reported version mismatch")
    real_run=v.subprocess.run
    try:
        v.subprocess.run=lambda argv,**kwargs: __import__('subprocess').CompletedProcess(argv,0,stdout="help",stderr="")
        v.verify_console_scripts(r,lock,"final")
        def missing_exec(argv,**kwargs):
            raise FileNotFoundError(2,"No such file or directory",argv[0])
        v.subprocess.run=missing_exec
        expect_fail(lambda:v.verify_console_scripts(r,lock,"final"),"console script execution failed")
    finally:
        v.subprocess.run=real_run

# Relocation helper must force-reinstall only exact local sources without dependency/network resolution.
with tempfile.TemporaryDirectory(prefix="sentinelx-relocate-") as td:
    r=Path(td); py=r/".venv"/"bin"/"python"; py.parent.mkdir(parents=True); py.write_text("python"); (r/"protocol").mkdir(); (r/"core").mkdir()
    calls=[]; real_run=c.subprocess.run
    try:
        c.subprocess.run=lambda argv,**kwargs: calls.append((list(argv),kwargs)) or __import__('subprocess').CompletedProcess(argv,0)
        c.reinstall_local_distributions(r)
    finally:
        c.subprocess.run=real_run
    assert len(calls)==2
    for argv,kwargs in calls:
        assert "--force-reinstall" in argv and "--no-deps" in argv and "--no-build-isolation" in argv and "--no-index" in argv
        assert kwargs.get("check") is True

# Artifact cache hashes are part of the verifier, not documentation only.
with tempfile.TemporaryDirectory(prefix="sentinelx-artifacts-") as td:
    r=Path(td); cache=r/"artifacts"; cache.mkdir(); good=b"canonical-wheel"
    tiny={"artifact_cache_dir":"artifacts","external_artifacts":[{"artifact":"x.whl","sha256":hashlib.sha256(good).hexdigest()}],"bootstrap_artifacts":[],"profiles":{"final":{"artifact_cache_required":True}}}
    (cache/"x.whl").write_bytes(good); v.verify_artifact_cache(r,tiny,"final")
    (cache/"x.whl").write_bytes(b"tampered")
    expect_fail(lambda:v.verify_artifact_cache(r,tiny,"final"),"artifact cache SHA256 mismatch")

# A changed artifact hash without regenerating the pip lock is rejected.
with tempfile.TemporaryDirectory(prefix="sentinelx-lock-") as td:
    d=Path(td)
    paths={name:d/name for name in ("manifest.json","dependency-lock.json","requirements.lock","bootstrap.lock")}
    for name,path in paths.items(): path.write_bytes((ROOT/"tools"/"sentinelx"/name).read_bytes())
    bad=json.loads(paths["dependency-lock.json"].read_text()); bad["external_artifacts"][0]["sha256"]="f"*64; paths["dependency-lock.json"].write_text(json.dumps(bad))
    old=(v.MANIFEST,v.LOCK_MANIFEST,v.REQ_LOCK,v.BOOTSTRAP_LOCK)
    v.MANIFEST,v.LOCK_MANIFEST,v.REQ_LOCK,v.BOOTSTRAP_LOCK=paths["manifest.json"],paths["dependency-lock.json"],paths["requirements.lock"],paths["bootstrap.lock"]
    try: expect_fail(lambda:v.verify_dependency_contract(manifest),"requirements.lock does not match dependency manifest")
    finally: v.MANIFEST,v.LOCK_MANIFEST,v.REQ_LOCK,v.BOOTSTRAP_LOCK=old

# Candidate builder cannot target live or leave the runner root.
for bad in (b.LIVE_ROOT,Path("/tmp/sentinelx-agent.candidate"),b.RUNNER_ROOT/"not-a-candidate"):
    try: b.safe_candidate(bad)
    except b.BuildRefused: pass
    else: raise AssertionError(f"unsafe candidate path accepted: {bad}")

# The autonomous rollback watchdog is exercised without touching the live agent.
with tempfile.TemporaryDirectory(prefix="sentinelx-cutover-contract-") as td:
    c.self_test(Path(td)/"selftest")

print("sentinelx_hardening_test: PASS")
