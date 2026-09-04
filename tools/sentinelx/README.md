# ERP PLANEX SentinelX build pin

This directory pins the exact **working user-mode SentinelX source build** used to reach the persistent ERP PLANEX test runner. It is a recovery/reproducibility contract only. It must not contain identity tokens, DB credentials, SSH keys, production secrets, or a copy of live config.

## Current pinned build

- Core upstream: `pensados/sentinelx-cloud-core`.
- Core exact commit: `d6b33822b61e7fccf39547d5cce8add8bc7021f9` (`pyproject` version `0.11.14`).
- Core local source archive SHA256: `0263a714e87cd43adf3ff440de063a3d016f60f4e5d4355de219ce321caf7152`.
- Runtime-reported agent version: `0.0.0+dev` because core is executed directly from source rather than installed as a distribution.
- Protocol upstream: `pensados/sentinelx-cloud-protocol`.
- Protocol tag: `v1.11.0`; exact commit: `2ffe1b6ae6bcae282b39f6939b9b50669c93b917`.
- Protocol package version: `1.11.0`; wire `PROTOCOL_VERSION`: `1.10.0`.
- Protocol local source archive SHA256: `bb71a8f5310e7b79b1cb80fc0c24682aa9ddb6357bbce341a01fa8478b0de8f1`.
- Python requirement: `>=3.11`; observed runtime venv: Python `3.11.8` from `/usr/bin/python3.11`.

The extracted `core/` and `protocol/` trees were independently verified byte-for-byte against those pinned local archives before this pin was created.

## Why the upstream Linux installer is not our upgrade path

The current official Linux installer requires root, `systemctl`, `/opt`, `/etc`, and creation of a separate `sentinelx` system user. ERP PLANEX is intentionally deployed on shared hosting in user mode with no root/systemd. Therefore the official Linux installer is not compatible with this host topology.

The SentinelX hub may advertise `0.11.13` because the running source tree reports `0.0.0+dev`; do not treat that hint as proof that this host is behind. The pinned core source is exact upstream commit `d6b3382...`, whose project version is `0.11.14`. Switching this host to an advertised version without comparing exact source would risk a downgrade.

## Live paths

- Install root: `~/erp-planex-test/sentinelx-agent`.
- Config: `~/erp-planex-test/sentinelx-agent/runtime/config.yaml`.
- Identity: `~/erp-planex-test/sentinelx-agent/runtime/identity.json`.
- Stable host id: `~/erp-planex-test/sentinelx-agent/runtime/host_id`.
- PID: `~/erp-planex-test/sentinelx-agent/runtime/pid`.
- Agent log: `~/erp-planex-test/sentinelx-agent/runtime/sentinelx.log`.
- Nohup output: `~/erp-planex-test/sentinelx-agent/runtime/nohup.out`.

`config.yaml`, `identity.json`, and `host_id` are runtime state and must stay mode `0600`. Never commit their contents.

## Exact startup contract

The current process runs from `~/erp-planex-test` with:

```bash
BASE="$HOME/erp-planex-test/sentinelx-agent"
export PYTHONPATH="$BASE/core/src:$BASE/protocol/python"
nohup "$BASE/.venv/bin/python" -m sentinelx_core \
  --hub https://mcp.sentinelx.app \
  --identity "$BASE/runtime/identity.json" \
  --config "$BASE/runtime/config.yaml" \
  --log-file "$BASE/runtime/sentinelx.log" \
  >"$BASE/runtime/nohup.out" 2>&1 &
echo $! >"$BASE/runtime/pid"
chmod 640 "$BASE/runtime/pid" "$BASE/runtime/sentinelx.log" "$BASE/runtime/nohup.out" 2>/dev/null || true
```

## Reproducible source-mode install

Build a candidate in parallel. Do **not** replace or stop the working agent during preparation.

1. Create a candidate directory inside `~/erp-planex-test`, for example `sentinelx-agent.candidate`.
2. Download the exact archives:

```bash
curl -fL \
  "https://github.com/pensados/sentinelx-cloud-core/archive/d6b33822b61e7fccf39547d5cce8add8bc7021f9.tar.gz" \
  -o "$CANDIDATE/core.tar.gz"
curl -fL \
  "https://github.com/pensados/sentinelx-cloud-protocol/archive/refs/tags/v1.11.0.tar.gz" \
  -o "$CANDIDATE/protocol.tar.gz"
```

3. Verify both SHA256 values against `manifest.json` before extraction. A mismatch is a hard stop.
4. Extract into `core/` and `protocol/` with one leading archive directory stripped.
5. Create the venv with Python 3.11 and install the exact observed dependency set from `requirements.lock`; then install the local pinned protocol directory. Do not install core as a Python distribution if the goal is exact reproduction of the current source-mode runtime:

```bash
python3.11 -m venv "$CANDIDATE/.venv"
"$CANDIDATE/.venv/bin/pip" install -r tools/sentinelx/requirements.lock
"$CANDIDATE/.venv/bin/pip" install "$CANDIDATE/protocol"
```

6. Before cutover, copy only the existing runtime identity/config/host-id into `candidate/runtime/` and restore mode `0600`. Do not generate or store new credentials in GitHub.
7. Run the read-only verifier against the candidate:

```bash
python3 tools/sentinelx/verify_pin.py --agent-root "$CANDIDATE"
```

8. Run import/version smoke checks with the candidate venv and exact `PYTHONPATH`. Do not connect a second process with the same host identity while the working agent is still connected.

### Current source-mode caveat

Because the core package itself is not installed into the venv, the runtime reports `0.0.0+dev`, and the console entry point `sentinelx-pensa-safe-edit` is absent. On the current host this means structured `sentinel_edit` fails with `pensa-safe-edit` missing, while the proven runner path uses the restricted helper commands and `script_run`. This task deliberately does not alter that behavior; repairing it would be a separate integration change requiring its own rollback/E2E proof.

## Controlled cutover and rollback

No cutover is authorized merely by preparing a candidate. Before any future switch, record the current PID, verify the current pin, and keep the entire old install directory intact as the rollback image.

At the actual approved cutover boundary:

1. Stop only the current SentinelX user process by the PID recorded in `runtime/pid`.
2. Atomically rename the current directory to `sentinelx-agent.rollback.<timestamp>` and the verified candidate to `sentinelx-agent`.
3. Start with the exact startup contract above.
4. Prove host connectivity and then prove `ChatGPT → SentinelX → runner helper → isolated test DB/preflight`.
5. If any step fails, stop the candidate process, move the failed candidate aside, rename the rollback directory back to `sentinelx-agent`, and start it with the same pinned startup contract.
6. Keep the rollback directory until the autonomous E2E proof and all relevant governance gates are PASS.

The current task does **not** perform this cutover because the working source-mode build already matches the pinned upstream source and there is no compatible rootless official Linux release path that improves it.

## Verification

Read-only local pin verification:

```bash
python3 tools/sentinelx/verify_pin.py \
  --agent-root "$HOME/erp-planex-test/sentinelx-agent"
```

For ERP runner integrity, the separate authoritative contract remains `tools/runner/manifest.json` + `tools/runner/sync_runner_helpers.py`; live `~/erp-planex-test/bin` is only the deployed copy.

## Hard locks and residual risk

Do not describe SentinelX or the shared-host runner as a sandbox. The shared Unix UID with production remains an OWNER-accepted residual risk, not technical filesystem isolation. `script_run` remains a residual execution risk. Neither one authorizes access to production shell paths, production DB, legacy `/erp`, production `.env`/secrets, or bypass of the Production Control Plane.
