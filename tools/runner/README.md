# ERP PLANEX persistent runner helpers

This directory is the canonical GitHub source-of-truth for the persistent ERP test-runner helper code deployed to `~/erp-planex-test/bin`.

## Authority flow

`canonical GitHub source -> manifest/checksum validation -> sync_runner_helpers.py -> ~/erp-planex-test/bin`

The live runner copy is a deployed instance, not an authoritative editing location. Never commit DB credentials, SSH private keys, SentinelX tokens, or host-specific secret environment files here.

## Files

- `helpers/` — exact versioned helper sources.
- `manifest.json` — schema/version, exact expected helper set, deployment mode and SHA256 per helper.
- `sync_runner_helpers.py` — fail-closed source verifier, live verifier and installer.

## Governed update procedure

1. Capture the current live helper bytes read-only and update `helpers/` intentionally.
2. Regenerate/verify the SHA256 values in `manifest.json`; bump `version`.
3. Run `python3 tools/runner/sync_runner_helpers.py verify-source` and `python3 tests/runner_helpers_contract_test.py`.
4. Complete CRITICAL preflight, guarded task push, GitHub CI and governed merge.
5. Only from the merged canonical checkout run `python3 tools/runner/sync_runner_helpers.py install`.
6. Run `python3 tools/runner/sync_runner_helpers.py verify-live` and the disposable runner E2E proof required by current governance.

The installer accepts only the exact destination `~/erp-planex-test/bin`, refuses symlink escapes, verifies the manifest before writing, stages within the runner root, installs only the declared helper set, applies mode `0700`, and verifies checksums after installation.

## Rollback

Rollback is Git-authoritative: select the previously approved merged canonical commit/version, run that commit's `verify-source`, then run that commit's installer and `verify-live`. Do not restore ad-hoc live edits as authoritative source. If the desired historical version predates this versioned mechanism, stop and obtain OWNER authorization rather than reconstructing helper code from production or secrets.
