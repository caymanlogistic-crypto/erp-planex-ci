# ERP PLANEX — agent governance

Latest OWNER decisions define policy. Canonical executable GitHub defines implementation truth. Historical reports are evidence, never current instructions.

## Minimal bootstrap

For one new ERP task:

1. Read workspace instructions and Notion `00 — START HERE` once.
2. Capture the live canonical HEAD once as `START_HEAD`.
3. Open only code and semantic-memory records owned by the changed paths.
4. Create one `chatgpt/*` branch/worktree. Never implement in the shared `source` checkout.

Do not read the whole repository, the whole Notion memory, release history or a stack of manuals before a scoped change.

Repository: `caymanlogistic-crypto/erp-planex-ci`.
Canonical branch: `chatgpt/production-stabilization-20260802`.
Production: `/erpv2`.
Legacy `/erp`: never touch.

## Fast and economical agent routing

- Default coordinator: Sol with medium reasoning.
- A small, ordered task stays with one agent. Do not create a subagent for a correction that fits one short run.
- Delegate only independent, bounded work. Two agents must never edit the same files or use the same mutable resource.
- At most two code-writing agents may work on one feature; an optional third agent is read-only review.
- Prefer Luna low for a bounded micro-edit, Sol low/medium for normal implementation, and Astra medium/high only for architecture, difficult diagnosis, security or database risk.
- `high`, `xhigh`, `max` and `ultra` require a concrete complexity or risk reason. Do not use them as defaults.
- Reuse the same task conversation for corrections to the same feature. Start a new conversation for a new independent feature.

Operational budgets are signals, not permission to skip correctness:

| Class | Normal wall time | Normal tool calls | Agents |
|---|---:|---:|---:|
| FAST | 5–8 minutes | up to 25 | 1 |
| STANDARD | 10–20 minutes | up to 60 | 1, exceptionally 2 |
| CRITICAL | risk-based | measured | up to 2 writers + 1 reviewer |

When a budget is exceeded, report the concrete blocker or coverage defect. Do not repeat unchanged reads or successful checks.

## Automatic change class

Use `AUTO` unless a stricter explicit class is required:

```bash
python3 tools/agent_preflight.py --class AUTO --base "$START_HEAD" --head HEAD --plan
```

- **FAST** — eligible presentation/view/CSS/text/UI-only diff within executable limits.
- **STANDARD** — normal controller/service/business behavior without critical paths.
- **CRITICAL** — finance, security/access, DB/schema, integrations, control plane or destructive behavior.

The executable classifier resolves `AUTO` fail-closed. Explicit FAST/STANDARD never bypasses critical-path escalation.

## Two loops

Development loop:

`edit → focused check → inspect diff → repeat`

- Run only checks relevant to the current change.
- Do not run full preflight, publish, CI, deploy or Notion writes after each correction.
- A successful check is not repeated while its inputs and candidate HEAD are unchanged.
- Batch related corrections in the same worktree and commit chain.

Publication loop, once per ready batch:

`START_HEAD → WORK_BRANCH → LOCAL_PREFLIGHT_PASS → LOCAL_PUBLISH_PASS → FEATURE_HEAD → PR_NUMBER → CI_AGGREGATE_RESULT → MERGED_SHA → LIVE_NOTION_MEMORY_PASS → optional DEPLOY_RESULT`

Normal publication is serialized by the local publisher. It refreshes canonical under the queue lock, merges non-conflicting canonical movement, runs one final exact-candidate preflight, rechecks canonical, and pushes only a clean fast-forward task branch. SentinelX and the persistent remote runner are outside the normal PLANEX task route.

For CRITICAL repository-memory work:

`IMPLEMENTATION_SHA → REPOSITORY_MEMORY_SHA → LOCAL_PREFLIGHT_PASS → LOCAL_PUBLISH_PASS`.

The implementation SHA must exist before repository-memory `Verified SHA` is updated.

## Parallel local safety

- One task owns one `TASK_ID`, branch, worktree, evidence directory and process set.
- Mutable MySQL tests use a unique disposable schema and restricted per-run user supplied by the trusted local wrapper. Direct use of shared slot 2 is legacy-only and prohibited for new task scripts.
- Dev/browser servers use task-local dynamic ports and state containing TASK_ID, PID, start time, executable, command line and worktree.
- Before stopping a process, verify all recorded identity fields. Never kill by process name.
- Never reset, delete or clean another task's branch, worktree, evidence, database, port or process.
- Do not change common Git `core.worktree`, global RDC, PATH, PHP or MySQL configuration for a task-local issue.
- `source` is a shared object-store checkout, not an implementation workspace.

## GitHub and correction discipline

- Canonical changes only through a PR merge. No direct canonical push or force-push.
- Observe required workflows for one `FEATURE_HEAD` as one aggregate.
- If CI fails, collect all terminal failures, fix one local batch, rerun relevant checks and final preflight, then make at most the normal corrective push.
- No placeholder/probe/dummy files, empty commits, checkpoint pushes or verification PRs.
- Before a third STANDARD/CRITICAL push, declare `PREFLIGHT_COVERAGE_DEFECT` and repair reproducible local coverage.

## Memory and production boundaries

- Notion writes happen only after merge and are serialized per Canonical ID. Update only impacted records and read back CRITICAL FINANCE/DB/SECURITY/CONTROL PLANE changes.
- Binding memory rules live in Notion `21 — MEMORY GOVERNANCE & INTEGRITY CONTRACT`.
- `VERIFIED` requires direct current evidence; owning-path drift requires revalidation or `NEEDS_REVERIFY`.
- Merge does not authorize deploy. Deploy does not authorize migration.
- Production migration, reconciliation, seed, backfill, destructive SQL and business-data writes require separate task-specific OWNER authorization.
- Docs/governance/control-plane-only changes do not require an application deploy.

## Definition of done

`CODE PASS + CI PASS + MEMORY PASS`.

Finish with a clean task worktree, exact local publication evidence, green aggregate CI, merged SHA, impacted memory readback and no leaked task database/server/lease.
