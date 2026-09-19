# ERP PLANEX — agent governance

Current OWNER policy and current canonical executable implementation are different authority dimensions. Historical reports/handoffs are evidence, never current instructions.

## Default bootstrap

For every material ERP task:

1. Read Notion `ERP PLANEX — MASTER MEMORY` → `00 — START HERE` once.
2. Read canonical GitHub HEAD once and cache it as `START_HEAD`.
3. Determine MEMORY IMPACT from owning/changed paths and read only affected registry records.
4. Reuse already resolved repository, branch, file/blob, Notion page/data-source and PR identifiers.

Repository: `caymanlogistic-crypto/erp-planex-ci`.
Canonical branch: `chatgpt/production-stabilization-20260802`.
Production: `/erpv2`.
Legacy `/erp`: never touch.
Previous canonical `planexerp/erp-planex`: read-only insurance; do not push.
Old `caymanlogistic-crypto/erp-planex`: read-only insurance; not canonical.

## Source precedence by dimension

1. Latest OWNER decision — business/product policy and authorization.
2. Executable canonical GitHub code/workflow/schema — current implementation truth.
3. Generated deterministic contracts — derived physical truth.
4. Canonical active policy documentation.
5. Notion semantic memory.
6. Secondary documentation.
7. Historical evidence.

OWNER target and current implementation can legitimately differ when the record is explicitly TARGET or LEGACY_IMPLEMENTATION.

## Execution-state cache / anti-thrashing

Maintain one task state machine:

`START_HEAD → WORK_BRANCH → LOCAL_SIGNED_PREFLIGHT_PASS → FEATURE_HEAD → PR_NUMBER → CI_AGGREGATE_RESULT → MERGED_SHA → LIVE_NOTION_MEMORY_PASS → optional DEPLOY_RESULT`

For CRITICAL DB/schema work that impacts repository memory, finalize locally in two phases:

`IMPLEMENTATION_SHA → REPOSITORY_MEMORY_SHA → LOCAL_SIGNED_PREFLIGHT_PASS → one initial push`.

`IMPLEMENTATION_SHA` must be a real commit containing the finished implementation/generated artifacts. Repository-memory `Verified SHA` must reference that existing implementation commit, never a future PR/merge/latest/current SHA.

Do not re-fetch canonical HEAD after ordinary edits, tests or Notion writes. A second canonical-HEAD read is allowed only when:

- branch/base setup genuinely needs a fresh base and no branch exists;
- GitHub reports merge conflict/base drift;
- after merge, once, to capture `MERGED_SHA` / current canonical HEAD;
- at a deploy boundary only if the guarded mechanism does not already perform the exact check;
- evidenced external repository drift affects the task.

A successful lookup is cached. Repeat search/fetch is forbidden without ambiguity, drift or a state-changing event.

## Change class

- **FAST** — presentation/view/CSS/text/local UI only; no controller/business/state/finance/DB/security/integration/control-plane semantics.
- **STANDARD** — controller/service/business behavior without production DB/schema mutation and without critical finance/security semantics.
- **CRITICAL** — settlement/matching/debt, security/access, DB/schema, financial integrations, control plane or destructive operations.

Classify upward when genuinely ambiguous. `tools/agent_preflight.py` also fails closed with `CLASS_ESCALATION_REQUIRED=CRITICAL` for known DB/schema/control-plane path families passed as STANDARD.

## Local preflight / Git discipline

GitHub Actions is an **independent final verification gate**, not the normal iterative debugging environment. A ready candidate must pass the executable local preflight before the first branch push:

```bash
python3 tools/agent_preflight.py --class FAST|STANDARD|CRITICAL --base "$START_HEAD" --head HEAD
```

Use `--plan` to inspect deterministic changed scope and the calculated check plan without executing checks.

Rules:

- Normal development is LOCAL: edit, debug, DB/browser tests, memory checks and pre-push preflight run on Windows/local WSL. SentinelX is not a DEV/DEBUG/TEST fallback.
- Work in a task checkout and batch multi-file changes there. Local logical commits are allowed; do not create one remote commit/push per file.
- Normal multi-file work is `local edit → local tests → repository memory drift check → local commit(s) → signed full local PASS → one initial push`.
- A required check may not be silently skipped or deferred to the remote runner. Missing local capability is repaired locally or blocks publication.
- GitHub CI remains required after local PASS; local verification never replaces, disables or weakens repository CI/workflows.
- SentinelX begins only after `LOCAL_SIGNED_PREFLIGHT_PASS`, for signed evidence import, candidate transport and existing guarded publication operations.

## Parallel LOCAL agents / shared-machine safety

- One task owns one `TASK_ID`, branch, worktree, evidence directory and process set. `source` is never an implementation workspace.
- Before changing a shared process/port, prove ownership from PID, command line and task/worktree metadata. Never kill by process name.
- Port 8016 is single-owner local state; a foreign `php-server.json` is not permission to stop it. Use a task-local free port/server when required.
- Mutable local MySQL slot 2 is serialized by its existing lock/wrapper. Never bypass the lock or clean another task; persistent slots 3–4 are not cleanup targets.
- Do not reset/delete another task's branch/worktree/evidence or change global RDC/runtime configuration to solve a task-local issue.
- MEMORY drift/integrity is checked before publication so task-owned repository memory is repaired in the same change whenever possible.

## GitHub CI aggregate / failure handling

- Observe all required workflows for one `FEATURE_HEAD` as one aggregate gate. While they are running, do not react to the first red job with a new push.
- After all required checks for that SHA are terminal, if PASS continue to merge. If FAIL, enumerate **all** failed required workflows/jobs, read logs only for those failures, and build one remediation set.
- Apply the complete remediation set locally, rerun the full relevant local checks and fresh signed preflight, and only after `LOCAL_SIGNED_PREFLIGHT_PASS` make a corrective push.
- The forbidden normal loop is `fix job A → push → fix job B → push → fix job C → push`.

### Remote push budget (operational SLO)

- FAST: normal = 1 push.
- STANDARD: normal = 1 initial push; exception = +1 corrective push.
- CRITICAL: normal = 1 initial push of the ready commit chain; exception = +1 corrective push.
- Before a third STANDARD/CRITICAL remote push, declare `PREFLIGHT_COVERAGE_DEFECT` and explain why the previous local PASS missed a deterministic failure. If reproducible, repair the preflight coverage together with the candidate before another push. Safety emergencies remain fail-safe exceptions, not a reason to normalize thrashing.

## Git / CI discipline

- Work on a feature branch; canonical changes only through PR merge.
- No placeholder/probe/temp/dummy files, empty/checkpoint commits or temporary verification PRs.
- Do not duplicate a regression already triggered by GitHub.
- Do not perform Actions archaeology after a green aggregate result.

## Production boundary

FAST uses the guarded FAST release when eligible. STANDARD/CRITICAL merged code/UI uses the guarded `deploy/erpv2-agent` request path.

The current standard request workflow itself validates the dedicated request ref, exact current canonical SHA, exact-SHA canonical `ERP PLANEX CI`, rechecks canonical HEAD immediately before dispatch, then dispatches exact-SHA `ERPv2 Controlled Deploy`. **Technical integration actor identity is not the current standard request gate.** Do not manually repeat machine guards three times.

Merge != deploy. Deploy != migration.

Production migration/reconciliation/seed/backfill/destructive SQL requires separate task-specific OWNER authorization. Standing code deploy authorization does not grant DB authority.

## Semantic-memory integrity

Binding semantic-memory rules live in Notion `21 — MEMORY GOVERNANCE & INTEGRITY CONTRACT`; do not create a competing copy here.

Operational minimum:

- semantic class and truth status are independent;
- implementation `Verified SHA` is a real full 40-hex commit, never an OWNER ID;
- `VERIFIED` requires direct current evidence, never memory/search snippet/handoff;
- one volatile fact has one authoritative canonical home;
- memory impact is derived from changed paths → owning records;
- path-aware drift: old SHA remains valid when owning paths did not change; relevant owning-path diff requires reverify or `NEEDS_REVERIFY`;
- closed migration ranges such as `042–076` are forbidden drift scopes; use future-proof ownership such as `database/migrations-local/**`;
- FINANCE / DB / SECURITY / CONTROL PLANE semantic writes require direct readback;
- body + provenance metadata are one atomic semantic update;
- `01 — CURRENT SNAPSHOT` is current durable state, not release history;
- stale critical `VERIFIED` is a P0 memory defect.

GitHub provides a small deterministic integrity contract, not a Notion mirror:

```bash
python3 tests/memory_integrity_checker_test.py
python3 tools/memory_integrity_check.py docs/memory/critical_records.json --head HEAD
```

## Definition of Done

`CODE PASS + CI PASS + MEMORY PASS`.

`MEMORY PASS` requires an impact set, affected record repair, stale current record reverify/supersession, critical readback, zero known stale critical VERIFIED in scope, and a passing integrity checker.

Application deploy is required only when the task changes production runtime and current policy requires it. Docs/Notion/tooling-only governance work does not justify an application deploy by itself.
