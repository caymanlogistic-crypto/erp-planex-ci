# ERP PLANEX persistent runner helpers

`tools/runner/` is the only Git-authoritative source for the persistent test-runner helpers deployed to `~/erp-planex-test/bin`.

## Exact live inventory

`manifest.json` schema `erp-planex-runner-helpers/v2` declares the complete allowed directory inventory, not only the managed files:

- seven managed regular helper files, exact SHA256 and mode `0700`;
- `php`, an infrastructure support symlink whose only accepted target is `/usr/bin/php8.5`.

`verify-live` fails on any undeclared file/executable/symlink, missing entry, wrong entry type, wrong PHP target, checksum drift, or mode drift. The installer also checks the complete live inventory before mutation and **refuses** undeclared drift; it never treats destructive cleanup of unknown files as an install step.

## Normal install

Normal install is intentionally stricter than source verification:

```bash
python3 tools/runner/sync_runner_helpers.py install
```

It is permitted only from a clean, attached checkout of `planexerp/erp-planex` on exact branch `chatgpt/production-stabilization-20260802`, after a fresh fetch, with local `HEAD == origin/<canonical>` and a second live remote-head check. Task branches, detached HEADs, dirty worktrees, stale canonical checkouts, wrong repositories and remote drift are refused with `RUNNER_INSTALL_REFUSED=...`.

The live helper destination remains fixed at `~/erp-planex-test/bin`; source is staged under the runner state directory, atomically replaced, and then verified again.

## Explicit rollback

Historical rollback is a separate operation and cannot happen by running normal `install` from an old checkout:

```bash
python3 tools/runner/sync_runner_helpers.py rollback \
  --commit <exact-40-hex-historical-canonical-sha> \
  --dry-run
```

Remove `--dry-run` only for an approved rollback. The rollback engine itself must run from the current exact canonical checkout. The target SHA must exist on canonical history and contain a valid runner manifest whose helper blobs verify against their declared hashes. The target commit/version is printed before mutation, the same exact live-inventory guard applies, and post-install `verify-live` is mandatory.

## Governed update flow

1. Change only canonical `tools/runner/` source and bump runner version.
2. Run source/contracts/preflight.
3. Guarded push, PR, required CI, governed merge.
4. Synchronize the persistent canonical checkout to the exact merged SHA.
5. Run normal install from that merged canonical source only.
6. Run `verify-live` and the autonomous disposable runner E2E.

Never commit DB credentials, SSH keys, SentinelX tokens or host-specific secrets here. The runner is test/pre-push infrastructure, not a production deploy surface and not a sandbox.
