# ERP PLANEX — agent governance

Latest OWNER decisions define policy. Canonical executable GitHub defines implementation truth. Historical reports are evidence, never current instructions.

## Minimal bootstrap

For one new ERP task:

1. Read workspace instructions and Notion `00 — START HERE` once.
2. On the Windows host use `local\New-PlanexTask.ps1`; it captures the live canonical HEAD once, creates the task branch/worktree and performs early toolchain and MEMORY drift checks.
3. Open only code and semantic-memory records owned by the changed paths.
4. Never implement in the shared `source` checkout.

For any new or edited ERP page header, registry toolbar, tabs or table, read `docs/ui/REGISTRY_CANON.md` before coding. Treat its exact values and acceptance checks as a required design gate: compare actual computed styles and screenshots with the three approved references, preserve page behavior, and fix deviations before reporting completion. Older `FINAL3.html` examples and historical Notion sections do not override this OWNER-approved rule. Apply it with scoped CSS; do not globally restyle legacy pages.

Do not read the whole repository, the whole Notion memory, release history or a stack of manuals before a scoped change.

Do not re-fetch canonical HEAD after ordinary edits, tests or Notion writes. Repeat search/fetch is forbidden without ambiguity, drift or a state-changing event.

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
- Label an intentionally failing red-phase test as TDD evidence. Environment/setup failures are blockers, not product-test failures.

### Windows local toolchain

- Run `local\Test-LocalReadiness.ps1` once when a task starts or resumes. Reuse the result until the worktree or machine toolchain changes.
- Use `local\Invoke-LocalTests.ps1`; add `-Static` only for tests proven not to need MySQL. Use `local\Invoke-LocalBrowserTest.ps1` for Playwright so Node, `NODE_PATH`, browser storage and the executable are pinned.
- Child processes use `PLANEX_PHP`, `PLANEX_PYTHON`, `PLANEX_NODE`, `PLANEX_GIT`, `PLANEX_GZIP`, `PLANEX_TAR` and `PLANEX_MYSQL`; Python code uses `sys.executable`. Do not guess `python`, `python3` or another PATH runtime.
- Windows worktrees use Windows Git. Never run repository-aware Git/Python commands for a Windows `.git` worktree through WSL; create a native Linux clone/worktree if Linux filesystem semantics are genuinely required.
- Discover files with `rg --files` or `Get-ChildItem`, prove guessed paths with `Test-Path`, and pass patterns beginning with a dash after `rg --`. Do not pass PowerShell wildcard directory arguments to `rg`.
- Browser fixtures must be deterministic and scope selectors to one visible layout. Use domain fixture builders for valid entity states instead of incomplete hand-written records.

### РЕЖИМ РАЗРАБОТКА / DESIGN LAB

When the OWNER asks for `РЕЖИМ РАЗРАБОТКА`, `ПЕСОЧНИЦУ` or iterative page design before implementation, use the repository skill `.agents/skills/planex-design-lab/SKILL.md` and `docs/DESIGN_LAB.md`.

- Work in a localhost-only task session outside the repository; do not expose it to the LAN or Internet.
- Before the first correction, verify that the sandbox reproduces the current ERP page 1:1 visually and in user-visible behavior. Synthetic values may differ only if their data shape and UI states remain equivalent; safety blocks for production writes/network and external navigation stay enabled.
- Default to synthetic fixtures. An OWNER-approved local HTML snapshot is allowed only after secret/CSRF sanitization. Never connect the sandbox directly to production DB, APIs or write endpoints.
- Before showing a correction or saving a checkpoint, complete the session `ui-quality-checklist.md`: forms use one alignment grid, identical controls have identical dimensions and spacing, checkbox/radio semantics are correct, help/error/actions stay aligned, focus/keyboard behavior works, and desktop/mobile plus content/state variants have no overlap, clipping or accidental horizontal scroll.
- During iteration edit only session overrides/notes, make cheap focused checks and checkpoint meaningful variants. Do not commit, preflight, publish, deploy or write Notion after every visual correction.
- On explicit `ВНЕДРЯЙ`, require an accepted immutable checkpoint, implement it in the real ERP worktree, and run the normal publication loop once for the finished batch.
- Each sandbox server owns a dynamic loopback port and exact PID/start-time/executable/arguments/worktree/session state. Stop only that verified process.

Publication loop, once per ready batch:

`START_HEAD → WORK_BRANCH → LOCAL_PREFLIGHT_PASS → LOCAL_PUBLISH_PASS → FEATURE_HEAD → PR_NUMBER → CI_AGGREGATE_RESULT → MERGED_SHA → LIVE_NOTION_MEMORY_PASS → optional DEPLOY_RESULT`

Normal publication is serialized by the local publisher. It refreshes canonical under the queue lock, merges non-conflicting canonical movement, runs one final exact-candidate preflight, rechecks canonical, and pushes only a clean fast-forward task branch. SentinelX and the persistent remote runner are outside the normal PLANEX task route.

Task-branch publication, PR creation and GitHub CI may proceed in parallel across isolated tasks. They do not require `erp-production-release`. Acquire that production lease only after the exact PR candidate has a terminal green aggregate and is ready to merge, immediately before its final canonical refresh. Hold it through any checks invalidated by canonical movement, merge, exact-SHA deploy, separately authorized migration and production verification, then release it in `finally`. A busy production lease means `QUEUED_FOR_RELEASE`: leave the green PR ready and do not start an independent merge, deploy or migration.

For CRITICAL repository-memory work:

`IMPLEMENTATION_SHA → REPOSITORY_MEMORY_SHA → LOCAL_PREFLIGHT_PASS → LOCAL_PUBLISH_PASS`.

The implementation SHA must exist before repository-memory `Verified SHA` is updated.

## Parallel LOCAL agents / shared-machine safety

- One task owns one `TASK_ID`, branch, worktree, evidence directory and process set.
- Mutable MySQL tests use a unique disposable schema and restricted per-run user supplied by the trusted local wrapper. Direct use of shared slot 2 is legacy-only and prohibited for new task scripts.
- Dev/browser servers use task-local dynamic ports and state containing TASK_ID, PID, start time, executable, command line and worktree.
- Before stopping a process, verify all recorded identity fields. Never kill by process name.
- Never reset, delete or clean another task's branch, worktree, evidence, database, port or process.
- Do not change common Git `core.worktree`, global RDC, PATH, PHP or MySQL configuration for a task-local issue.
- `source` is a shared object-store checkout, not an implementation workspace.
- Readiness, focused tests, task-branch publication, PRs and GitHub CI run in parallel per task. The local publisher serializes only its short canonical-refresh/preflight/push transaction. Production merge/deploy/migration and explicitly named external writes use their dedicated leases. A busy lease must report its owning TASK_ID/PID instead of inviting blind retries.

## GitHub and correction discipline

- Canonical changes only through a PR merge. No direct canonical push or force-push.
- Observe all required workflows for one `FEATURE_HEAD` as one aggregate gate.
- If CI fails, collect all terminal failures, fix one local batch, rerun relevant checks and final preflight, then make at most the normal corrective push.
- No placeholder/probe/temp/dummy files, empty/checkpoint commits or temporary verification PRs.
- Do not duplicate a regression already triggered by GitHub.
- Before a third STANDARD/CRITICAL push, declare `PREFLIGHT_COVERAGE_DEFECT` and repair reproducible local coverage.

Technical integration actor identity is not the current standard request gate.

## Memory and production boundaries

- Normal development is LOCAL: coding, debugging, database/browser tests and preflight run on the trusted Windows host; WSL is not a Git fallback for Windows worktrees.
- MEMORY drift/integrity is checked before publication and also at task start.
- Notion writes happen only after merge and are serialized per Canonical ID. Update only impacted records and read back CRITICAL FINANCE/DB/SECURITY/CONTROL PLANE changes.
- Binding memory rules live in Notion `21 — MEMORY GOVERNANCE & INTEGRITY CONTRACT`.
- `VERIFIED` requires direct current evidence; owning-path drift requires revalidation or `NEEDS_REVERIFY`.
- Merge does not authorize deploy. Deploy does not authorize migration.
- Production migration, reconciliation, seed, backfill, destructive SQL and business-data writes require separate task-specific OWNER authorization.
- Docs/governance/control-plane-only changes do not require an application deploy.

## Definition of done

`CODE PASS + CI PASS + MEMORY PASS`.

Finish with a clean task worktree, exact local publication evidence, green aggregate CI, merged SHA, impacted memory readback and no leaked task database/server/lease.
