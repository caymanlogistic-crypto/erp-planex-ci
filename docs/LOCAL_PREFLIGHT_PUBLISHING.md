# Local preflight and publishing

The normal PLANEX publication route is fully local until GitHub receives the ready task branch:

`task worktree → focused checks → commit batch → local publish queue → exact final preflight → guarded task-branch push → PR/CI/merge`

SentinelX, SSH transport, evidence bundles and the persistent remote runner are not part of this route.

## Start once

Create a task from the actual configured canonical branch instead of assuming `main` or manually composing Git commands:

```powershell
& C:\plan-ex\ERP-PLANEX\local\New-PlanexTask.ps1 -TaskId <task-id>
```

The command records `START_HEAD`, creates `chatgpt/<task-id>` in its own worktree, validates the pinned Windows toolchain and runs a read-only MEMORY drift check. Windows Git is mandatory for these Windows worktrees; their `.git` pointer is not passed to WSL.

## One command

From Windows PowerShell:

```powershell
& C:\plan-ex\ERP-PLANEX\local\Publish-Task.ps1 `
  -TaskId <task-id> `
  -Worktree C:\plan-ex\ERP-PLANEX\worktrees\<task-id> `
  -StartHead <exact-40-hex-start-head>
```

Use `-DryRun` to exercise the complete queue, refresh and preflight path without changing the remote task branch. `-Class AUTO` is the default; explicit stricter classes remain available.

## Guard sequence

`tools/local_publish.py` performs the following under one machine-wide publish lock:

1. Requires a clean attached `chatgpt/*` task branch in the canonical repository.
2. Fetches the current canonical branch and proves that `START_HEAD` remains its ancestor.
3. If canonical advanced, merges it into the task branch. A conflict is aborted and refused.
4. Runs one complete local preflight for the refreshed exact candidate. `AUTO` resolves to FAST, STANDARD or CRITICAL using executable path and size rules.
5. Fetches canonical again. If it moved, refreshes and repeats the final preflight, with a bounded retry count.
6. Refuses non-fast-forward task-branch publication. Force-push is never used.
7. Pushes the exact verified commit and confirms the remote task-branch SHA.
8. Writes immutable task evidence under `local\evidence\<task>\...`.

The publisher does not create an application deploy and never authorizes production DB work.

## Development loop

Do not run this command after every correction. During development use only focused syntax, contract, DB or browser checks that cover the current diff. Publish once when the correction batch is ready.

Before the final publisher, finish every known code, test, generated-gallery and repository-memory/documentation change. Do not deliberately start a full preflight while planning another candidate edit. Collect review findings into one prioritized batch; send startup/access/data failures immediately. For UI transfers follow the preparation and stop criteria in `docs/ui/REGISTRY_WORKFLOW.md`: real production bootstrap precedes browser matrices, focused parity is diagnostic only, and final full coverage remains required. Batch closing documentation fixes into one PR when safe. Notion still updates after merge; do not manufacture repeated pushes merely to synchronize its final merge SHA into repository metadata.

Use `local\Invoke-LocalTests.ps1`; `-Static` avoids allocating a disposable schema only for tests proven not to touch MySQL. Playwright tests use `local\Invoke-LocalBrowserTest.ps1`, which pins Node, `NODE_PATH`, `PLAYWRIGHT_BROWSERS_PATH` and verifies the Chromium executable before running the test. Do not install dependencies ad hoc inside a task worktree.

The local environment exposes exact executables through `PLANEX_PHP`, `PLANEX_PYTHON`, `PLANEX_NODE`, `PLANEX_GIT`, `PLANEX_GZIP`, `PLANEX_TAR` and `PLANEX_MYSQL`. Nested Python subprocesses must use `PLANEX_PYTHON`/`sys.executable`, not a guessed `python3` command.

Mutable MySQL checks are launched through the trusted local wrapper. Every invocation receives a unique disposable schema and restricted per-run database user. Cleanup drops that exact schema and user in `finally`; it never touches persistent synthetic schemas or another task's database.

## GitHub phase

After `LOCAL_PUBLISH_PASS=<SHA>`:

1. Create or update one PR for the task branch.
2. Treat required GitHub workflows for that SHA as one aggregate.
3. Merge only after the aggregate passes.
4. Update impacted Notion records once after merge and read back critical control-plane writes.
5. Deploy only when production runtime changed. Governance, documentation and local tooling changes do not deploy the application.

## Fail-closed results

Important refusal markers include:

- `DIRTY_WORKTREE`
- `CANONICAL_HISTORY_REWRITTEN`
- `CANONICAL_MERGE_CONFLICT`
- `LOCAL_PREFLIGHT_FAILED`
- `CANONICAL_MOVED_REPEATEDLY`
- `NON_FAST_FORWARD_TASK_BRANCH`
- `PUBLISH_QUEUE_TIMEOUT:<owner>`
- `REMOTE_HEAD_MISMATCH_AFTER_PUSH`

Resolve the stated condition locally. Do not bypass the publisher with direct credentials or force-push.

## Historical compatibility

`tools/runner/` and `tools/sentinelx/` remain versioned historical recovery/infrastructure assets. Their presence does not make them an active development or publication dependency. Changes inside those paths still run their own dedicated contracts.
