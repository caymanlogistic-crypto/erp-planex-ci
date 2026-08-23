# ERP PLANEX — agent governance

Current OWNER policy and current canonical executable implementation are different authority dimensions. Historical reports/handoffs are evidence, never current instructions.

## Default bootstrap

For every material ERP task:

1. Read Notion `ERP PLANEX — MASTER MEMORY` → `00 — START HERE` once.
2. Read canonical GitHub HEAD once and cache it as `START_HEAD`.
3. Determine MEMORY IMPACT from owning/changed paths and read only affected registry records.
4. Reuse already resolved repository, branch, file/blob, Notion page/data-source and PR identifiers.

Repository: `planexerp/erp-planex`.
Canonical branch: `chatgpt/production-stabilization-20260802`.
Production: `/erpv2`.
Legacy `/erp`: never touch.
Old `caymanlogistic-crypto/erp-planex`: read-only insurance.

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

`START_HEAD → WORK_BRANCH → FEATURE_HEAD → PR_NUMBER → CI_RESULT → MERGED_SHA → optional DEPLOY_RESULT`

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

Classify upward when genuinely ambiguous.

## Git / CI discipline

- Work on a feature branch; canonical changes only through PR merge.
- No placeholder/probe/temp/dummy files, empty/checkpoint commits or temporary verification PRs.
- Do not duplicate a regression already triggered by GitHub.
- Observe parallel required checks as one aggregate gate. Poll aggregate state only while running; inspect individual jobs only after aggregate failure.
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
