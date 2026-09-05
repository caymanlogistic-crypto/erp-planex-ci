# ERP PLANEX SentinelX pinned user-mode baseline

This directory is the canonical reproducibility and rollback contract for the SentinelX process used to reach the ERP PLANEX test runner. It contains no identity tokens, DB credentials, SSH keys, production secrets or live config.

## Exact upstream source

- Core: `pensados/sentinelx-cloud-core` commit `d6b33822b61e7fccf39547d5cce8add8bc7021f9`, project version `0.11.14`, archive SHA256 `0263a714e87cd43adf3ff440de063a3d016f60f4e5d4355de219ce321caf7152`.
- Protocol: `pensados/sentinelx-cloud-protocol` tag `v1.11.0`, commit `2ffe1b6ae6bcae282b39f6939b9b50669c93b917`, package `1.11.0`, wire protocol `1.10.0`, archive SHA256 `bb71a8f5310e7b79b1cb80fc0c24682aa9ddb6357bbce341a01fa8478b0de8f1`.
- Python: exact observed runtime `3.11.8`, requirement `>=3.11`.

No upstream commit or wire semantics are changed by this hardening. The final baseline installs the **same exact pinned core source as a local Python distribution** while continuing to execute it from the archive-verified source tree via `PYTHONPATH`. This makes the upstream-declared console entry point `sentinelx-pensa-safe-edit` reproducibly available and changes the reported agent version from the fallback `0.0.0+dev` to the correct project version `0.11.14`.

## Dependency closure

`dependency-lock.json` schema `erp-planex-sentinelx-dependency-lock/v1`, version `2026-09-05.2`, is machine-readable authority for the complete venv closure:

- `managed_runtime` external distributions;
- `managed_build` distributions intentionally retained in the venv;
- `allowed_bootstrap` (`pip`, `setuptools`) with exact versions rather than silently ignored extras;
- exact local protocol/core distributions tied to the pinned source archives.

`requirements.lock` and `bootstrap.lock` are pip-compatible `--require-hashes` locks. Every external wheel has an exact artifact filename and SHA256. Final candidates retain the exact wheelhouse under `artifacts/`, and `verify_pin.py` checks that inventory and every artifact hash in addition to the installed distribution closure. Missing, unexpected or version-drifted distributions fail verification.

## Deterministic candidate

Build in parallel; never overwrite the working agent during preparation:

```bash
python3 tools/sentinelx/build_candidate.py \
  --source-agent-root "$HOME/erp-planex-test/sentinelx-agent" \
  --candidate-root "$HOME/erp-planex-test/sentinelx-agent.candidate-<task>"
```

The builder verifies the source archives, extracts them without special entries, creates a Python 3.11 venv, downloads only hash-authorized wheels, verifies the retained wheelhouse, installs with `--require-hashes --no-deps`, installs local protocol/core with `--no-deps --no-build-isolation --no-index`, and runs the final-profile verifier. A candidate is not connected with the live host identity during preparation.

Read-only verification:

```bash
# Historical source-only rollback image/current pre-cutover runtime
python3 tools/sentinelx/verify_pin.py --agent-root "$HOME/erp-planex-test/sentinelx-agent" --profile rollback-source

# Final installed-core baseline
python3 tools/sentinelx/verify_pin.py --agent-root "$HOME/erp-planex-test/sentinelx-agent" --profile final
```

## Edit capability truth

Pinned core `0.11.14` declares `sentinelx-pensa-safe-edit = sentinelx_core.vendored.pensa_safe_edit:main`. In the historical source-only venv that console script was absent while `edit` was advertised. The final profile requires the exact local core distribution and both registered/executable core console scripts. No filesystem allowlist, sudo permission or production path is added. Acceptance must use real structured `sentinel_edit` on a disposable file inside a task worktree and confirm an outside-rw-path request is refused; `script_run` is not a substitute for that acceptance test.

## Rootless cutover and autonomous rollback

The shared host has no compatible root/systemd installer path. `rootless_cutover.py` is therefore the controlled user-mode switch mechanism. Before any live switch:

1. current `rollback-source` verifier PASS;
2. final candidate verifier PASS;
3. autonomous rollback self-test PASS;
4. a complete current installation copy is created as a rollback image before the old PID is stopped;
5. current identity/config/host ID are copied only into the verified candidate and kept mode `0600`.

For live cutover, launch the script detached from the SentinelX process so the watchdog survives the agent restart. It stops only the verified current SentinelX PID, atomically switches directories, starts the exact startup contract, waits for a fresh hub-connected marker, requires a stability window and final verifier PASS. Any candidate failure triggers automatic stop, directory restore, old-source restart and rollback-source verifier without OWNER/PuTTY.

Exact startup remains:

```bash
BASE="$HOME/erp-planex-test/sentinelx-agent"
export PYTHONPATH="$BASE/core/src:$BASE/protocol/python"
"$BASE/.venv/bin/python" -m sentinelx_core \
  --hub https://mcp.sentinelx.app \
  --identity "$BASE/runtime/identity.json" \
  --config "$BASE/runtime/config.yaml" \
  --log-file "$BASE/runtime/sentinelx.log"
```

The official upstream Linux installer remains incompatible with this shared-hosting topology because it requires root/systemd/system paths. SentinelX and the runner are **not a sandbox**: the shared Unix UID and `script_run` remain OWNER-accepted residual risks. They do not authorize production/legacy paths, production secrets or production DB access.
