#!/usr/bin/env python3
"""Autonomous rootless SentinelX cutover with fail-closed rollback."""
from __future__ import annotations

import argparse
import json
import os
import shutil
import signal
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path

HERE=Path(__file__).resolve().parent
RUNNER_ROOT=Path.home()/"erp-planex-test"
LIVE=RUNNER_ROOT/"sentinelx-agent"
STATE=RUNNER_ROOT/"state"/"sentinelx-cutover.json"
HUB="https://mcp.sentinelx.app"
CONNECTED_MARKER="connected; session="

class CutoverRefused(RuntimeError): pass


def now_tag() -> str:
    return datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")


def write_state(payload: dict) -> None:
    STATE.parent.mkdir(mode=0o700,exist_ok=True)
    tmp=STATE.with_suffix(".tmp")
    tmp.write_text(json.dumps(payload,sort_keys=True,indent=2)+"\n")
    os.chmod(tmp,0o600)
    os.replace(tmp,STATE); os.chmod(STATE,0o600)


def safe_candidate(path: Path) -> Path:
    p=path.expanduser().absolute()
    if p.parent != RUNNER_ROOT or not p.name.startswith("sentinelx-agent.candidate") or p==LIVE or p.is_symlink():
        raise CutoverRefused("SENTINELX_CUTOVER_REFUSED=CANDIDATE_PATH")
    if not p.is_dir(): raise CutoverRefused("SENTINELX_CUTOVER_REFUSED=CANDIDATE_MISSING")
    return p


def verify(root: Path, profile: str, skip_runtime: bool=False, pre_relocation: bool=False) -> None:
    cmd=[sys.executable,str(HERE/"verify_pin.py"),"--agent-root",str(root),"--profile",profile]
    if skip_runtime: cmd.append("--skip-runtime-state")
    if pre_relocation: cmd.append("--pre-relocation-repair")
    subprocess.run(cmd,check=True)


def read_agent_pid(root: Path) -> int:
    p=root/"runtime"/"pid"
    try: pid=int(p.read_text().strip())
    except Exception as exc: raise CutoverRefused("SENTINELX_CUTOVER_REFUSED=INVALID_PID") from exc
    cmdline=Path("/proc")/str(pid)/"cmdline"
    if not cmdline.is_file(): raise CutoverRefused("SENTINELX_CUTOVER_REFUSED=PID_NOT_RUNNING")
    text=cmdline.read_bytes().replace(b"\0",b" ").decode(errors="replace")
    if " -m sentinelx_core " not in f" {text} " or str(root/".venv"/"bin"/"python") not in text:
        raise CutoverRefused("SENTINELX_CUTOVER_REFUSED=PID_IDENTITY_MISMATCH")
    return pid


def stop_pid(pid: int, timeout: float=8.0) -> None:
    try: os.kill(pid,signal.SIGTERM)
    except ProcessLookupError: return
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        try: os.kill(pid,0)
        except ProcessLookupError: return
        time.sleep(0.2)
    try: os.kill(pid,signal.SIGKILL)
    except ProcessLookupError: return
    time.sleep(0.3)


def copy_runtime_identity(src: Path,dst: Path) -> None:
    runtime=dst/"runtime"; runtime.mkdir(mode=0o700,exist_ok=True)
    for name in ("config.yaml","identity.json","host_id"):
        source=src/"runtime"/name
        if not source.is_file(): raise CutoverRefused(f"SENTINELX_CUTOVER_REFUSED=MISSING_RUNTIME:{name}")
        shutil.copyfile(source,runtime/name); os.chmod(runtime/name,0o600)


def reinstall_local_distributions(root: Path) -> None:
    """Regenerate local dist metadata and console scripts for root's final path."""
    python=root/".venv"/"bin"/"python"
    if not python.is_file():
        raise CutoverRefused("SENTINELX_RELOCATION_REFUSED=VENV_PYTHON_MISSING")
    for local in ("protocol","core"):
        source=root/local
        if not source.is_dir():
            raise CutoverRefused(f"SENTINELX_RELOCATION_REFUSED=SOURCE_MISSING:{local}")
        subprocess.run([
            str(python),"-m","pip","install","--disable-pip-version-check",
            "--force-reinstall","--no-deps","--no-build-isolation","--no-index",str(source)
        ],check=True)


def repair_live() -> None:
    """Repair a previously relocated final install without restarting the agent."""
    verify(LIVE,"final",pre_relocation=True)
    tag=now_tag(); rollback=RUNNER_ROOT/f"sentinelx-agent.repair-rollback.{tag}"; failed=RUNNER_ROOT/f"sentinelx-agent.repair-failed-venv.{tag}"
    if rollback.exists() or failed.exists():
        raise CutoverRefused("SENTINELX_REPAIR_REFUSED=STATE_PATH_EXISTS")
    shutil.copytree(LIVE,rollback,symlinks=True)
    try:
        reinstall_local_distributions(LIVE)
        verify(LIVE,"final")
        write_state({"status":"REPAIR_PASS","rollback_image":str(rollback),"timestamp":tag})
        print(f"SENTINELX_REPAIR_LIVE=PASS rollback={rollback}")
    except Exception as exc:
        live_venv=LIVE/".venv"; rollback_venv=rollback/".venv"
        try:
            if live_venv.exists(): os.replace(live_venv,failed)
            shutil.copytree(rollback_venv,live_venv,symlinks=True)
            verify(LIVE,"final",pre_relocation=True)
        except Exception as rollback_exc:
            write_state({"status":"REPAIR_BLOCKER","error":str(exc),"rollback_error":str(rollback_exc),"rollback_image":str(rollback),"timestamp":tag})
            raise CutoverRefused(f"SENTINELX_REPAIR_BLOCKER repair={exc} rollback={rollback_exc}") from rollback_exc
        write_state({"status":"REPAIR_ROLLED_BACK","error":str(exc),"rollback_image":str(rollback),"timestamp":tag})
        raise CutoverRefused(f"SENTINELX_REPAIR_ROLLED_BACK={exc}")


def start_agent(root: Path) -> int:
    runtime=root/"runtime"; runtime.mkdir(mode=0o700,exist_ok=True)
    log=runtime/"sentinelx.log"; nohup=runtime/"nohup.out"
    env=os.environ.copy(); env["PYTHONPATH"]=os.pathsep.join([str(root/"core"/"src"),str(root/"protocol"/"python")])
    argv=[str(root/".venv"/"bin"/"python"),"-m","sentinelx_core","--hub",HUB,"--identity",str(runtime/"identity.json"),"--config",str(runtime/"config.yaml"),"--log-file",str(log)]
    out=nohup.open("ab",buffering=0)
    proc=subprocess.Popen(argv,cwd=RUNNER_ROOT,env=env,stdin=subprocess.DEVNULL,stdout=out,stderr=subprocess.STDOUT,start_new_session=True)
    (runtime/"pid").write_text(str(proc.pid)+"\n"); os.chmod(runtime/"pid",0o640)
    return proc.pid


def wait_marker(log: Path,pid: int,start_offset: int,timeout: float) -> bool:
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        try: os.kill(pid,0)
        except ProcessLookupError: return False
        if log.exists():
            with log.open("rb") as f:
                f.seek(min(start_offset,log.stat().st_size)); data=f.read()
            if CONNECTED_MARKER.encode() in data: return True
        time.sleep(0.5)
    return False


def move_layout(candidate: Path,previous: Path) -> None:
    if previous.exists(): raise CutoverRefused("SENTINELX_CUTOVER_REFUSED=PREVIOUS_EXISTS")
    os.replace(LIVE,previous)
    try: os.replace(candidate,LIVE)
    except Exception:
        os.replace(previous,LIVE); raise


def restore_layout(previous: Path,failed: Path) -> None:
    if LIVE.exists(): os.replace(LIVE,failed)
    os.replace(previous,LIVE)


def recover_layout(previous: Path, failed: Path, rollback: Path) -> str:
    """Restore a rollback-source layout after any partial cutover state."""
    if previous.exists():
        restore_layout(previous, failed)
        return "previous"
    if LIVE.exists():
        # move_layout() restores LIVE itself if the candidate rename fails.
        verify(LIVE, "rollback-source")
        return "live-restored"
    if rollback.exists():
        # Last-resort autonomous recovery from the complete pre-stop image.
        os.replace(rollback, LIVE)
        verify(LIVE, "rollback-source")
        return "rollback-image"
    raise CutoverRefused("SENTINELX_CUTOVER_BLOCKER=NO_ROLLBACK_LAYOUT")


def self_test(root: Path) -> None:
    base=root.expanduser().absolute()
    if base.exists(): shutil.rmtree(base)
    base.mkdir(parents=True)
    try:
        live=base/"live"; candidate=base/"candidate"; previous=base/"previous"; failed=base/"failed"
        live.mkdir(); candidate.mkdir(); (live/"marker").write_text("rollback-source"); (candidate/"marker").write_text("candidate")
        os.replace(live,previous); os.replace(candidate,live)
        if (live/"marker").read_text()!="candidate": raise CutoverRefused("SELFTEST_SWAP_FAIL")
        if live.exists(): os.replace(live,failed)
        os.replace(previous,live)
        if (live/"marker").read_text()!="rollback-source": raise CutoverRefused("SELFTEST_ROLLBACK_LAYOUT_FAIL")
        log=base/"health.log"
        child=subprocess.Popen([sys.executable,"-c",f'import time; open({str(log)!r},"w").write("{CONNECTED_MARKER}selftest\\n"); time.sleep(2)'])
        if not wait_marker(log,child.pid,0,1.5): raise CutoverRefused("SELFTEST_HEALTH_FAIL")
        stop_pid(child.pid,1)
        bad=subprocess.Popen([sys.executable,"-c","raise SystemExit(2)"])
        if wait_marker(base/"missing.log",bad.pid,0,0.5): raise CutoverRefused("SELFTEST_FAILURE_DETECTION_FAIL")

        # Candidate rename failure path: LIVE has already been restored and
        # there is no previous directory left. Recovery must accept that state.
        recovered=base/"already-restored"; recovered.mkdir(); (recovered/"marker").write_text("rollback-source")
        original_live=globals()["LIVE"]; original_verify=globals()["verify"]
        try:
            globals()["LIVE"]=recovered
            def verify_recovered(path: Path, profile: str, skip_runtime: bool=False) -> None:
                if path != recovered or profile != "rollback-source":
                    raise CutoverRefused("SELFTEST_VERIFY_UNEXPECTED")
            globals()["verify"]=verify_recovered
            if recover_layout(base/"missing-previous",base/"failed-2",base/"missing-rollback")!="live-restored":
                raise CutoverRefused("SELFTEST_ALREADY_RESTORED_FAIL")
        finally:
            globals()["LIVE"]=original_live; globals()["verify"]=original_verify
    finally:
        shutil.rmtree(base,ignore_errors=True)
    print("SENTINELX_AUTONOMOUS_ROLLBACK_SELFTEST=PASS")


def cutover(candidate: Path,health_timeout: float,stability: float) -> None:
    candidate=safe_candidate(candidate)
    tag=now_tag(); rollback=RUNNER_ROOT/f"sentinelx-agent.rollback.{tag}"; previous=RUNNER_ROOT/f"sentinelx-agent.previous.{tag}"; failed=RUNNER_ROOT/f"sentinelx-agent.failed.{tag}"
    if any(p.exists() for p in (rollback,previous,failed)): raise CutoverRefused("SENTINELX_CUTOVER_REFUSED=STATE_PATH_EXISTS")
    # Pre-cutover proof: exact current source-mode rollback baseline and exact final candidate.
    verify(LIVE,"rollback-source"); verify(candidate,"final",skip_runtime=True)
    old_pid=read_agent_pid(LIVE)
    copy_runtime_identity(LIVE,candidate)
    # Complete rollback image exists before the working agent is stopped.
    shutil.copytree(LIVE,rollback,symlinks=True)
    write_state({"status":"PREARMED","old_pid":old_pid,"candidate":str(candidate),"rollback_image":str(rollback),"timestamp":tag})
    candidate_pid=None
    try:
        stop_pid(old_pid)
        move_layout(candidate,previous)
        reinstall_local_distributions(LIVE)
        verify(LIVE,"final")
        log=LIVE/"runtime"/"sentinelx.log"; offset=log.stat().st_size if log.exists() else 0
        candidate_pid=start_agent(LIVE)
        if not wait_marker(log,candidate_pid,offset,health_timeout): raise CutoverRefused("SENTINELX_CANDIDATE_HEALTH_FAIL")
        time.sleep(stability)
        try: os.kill(candidate_pid,0)
        except ProcessLookupError as exc: raise CutoverRefused("SENTINELX_CANDIDATE_DIED_DURING_STABILITY") from exc
        verify(LIVE,"final")
        write_state({"status":"PASS","candidate_pid":candidate_pid,"previous_image":str(previous),"rollback_image":str(rollback),"timestamp":tag})
        print(f"SENTINELX_ROOTLESS_CUTOVER=PASS pid={candidate_pid} rollback={rollback} previous={previous}")
    except Exception as exc:
        if candidate_pid is not None: stop_pid(candidate_pid)
        rollback_ok=False; rollback_pid=None
        try:
            recovery_source=recover_layout(previous,failed,rollback)
            log=LIVE/"runtime"/"sentinelx.log"; offset=log.stat().st_size if log.exists() else 0
            rollback_pid=start_agent(LIVE)
            rollback_ok=wait_marker(log,rollback_pid,offset,health_timeout)
            if rollback_ok: verify(LIVE,"rollback-source")
        except Exception as rollback_exc:
            write_state({"status":"BLOCKER","error":str(exc),"rollback_error":str(rollback_exc),"rollback_image":str(rollback),"failed_image":str(failed),"timestamp":tag})
            raise CutoverRefused(f"SENTINELX_CUTOVER_BLOCKER candidate={exc} rollback={rollback_exc}") from rollback_exc
        if rollback_ok:
            write_state({"status":"ROLLED_BACK","error":str(exc),"recovery_source":recovery_source,"rollback_pid":rollback_pid,"rollback_image":str(rollback),"failed_image":str(failed),"timestamp":tag})
            raise CutoverRefused(f"SENTINELX_CUTOVER_ROLLED_BACK={exc}")
        raise CutoverRefused(f"SENTINELX_CUTOVER_BLOCKER={exc}")


def main() -> int:
    ap=argparse.ArgumentParser(); sub=ap.add_subparsers(dest="op",required=True)
    st=sub.add_parser("self-test"); st.add_argument("--root",type=Path,required=True)
    co=sub.add_parser("cutover"); co.add_argument("--candidate-root",type=Path,required=True); co.add_argument("--health-timeout",type=float,default=45); co.add_argument("--stability",type=float,default=5)
    sub.add_parser("repair-live")
    a=ap.parse_args()
    if a.op=="self-test": self_test(a.root)
    elif a.op=="repair-live": repair_live()
    else: cutover(a.candidate_root,a.health_timeout,a.stability)
    return 0

if __name__=="__main__":
    try: raise SystemExit(main())
    except (CutoverRefused,subprocess.CalledProcessError,OSError,ValueError,json.JSONDecodeError) as exc:
        print(str(exc),file=sys.stderr); raise SystemExit(1)
