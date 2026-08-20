# ERP PLANEX — правила работы агентов

Актуально на 2026-08-20. Current code + latest OWNER decision имеют приоритет; исторические Pxx/E7 материалы — evidence, не инструкция.

## Default bootstrap

**Notion обязателен** для каждой material ERP задачи.

Default bootstrap = `ERP PLANEX — MASTER MEMORY` → **`00 — START HERE` один раз**. `00` хранит stable coordinates, source precedence, change-class selector и direct registry data-source IDs. Exact current HEAD всегда читать из GitHub.

После `00` открыть только task-owned records. `01 — CURRENT STATE`, `11 — AGENT BOOTSTRAP PROTOCOL`, `22 — DEPLOY & NOTION LIMITS RUNBOOK`, `23 — CHANGE PLAYBOOKS`, handoffs и historical docs читаются только когда задача реально требует их контекста. Broad startup/document archaeology запрещён.

Repository: `planexerp/erp-planex`.
Canonical branch: `chatgpt/production-stabilization-20260802`.
Production: `/erpv2`.
Old `caymanlogistic-crypto/erp-planex` = read-only insurance.
Legacy `/erp` = never touch.

## CHANGE CLASS

### FAST
Presentation/view/CSS/text/local UI only. FAST запрещён при route/controller behavior, business/state, finance settlement/debt, DB/schema, security/access, integration/control-plane semantics.

Production FAST uses guarded `fast/*` + `FAST:` pipeline. **FAST whitelist** remains machine-enforced by `ERPv2 FAST Release`; agent does not manually merge, move deploy ref or run unrelated heavy regressions.

### STANDARD
Controller/service/business behavior without production DB/schema mutation and without critical settlement/security semantics.

Use owning Module/Screen → exact controller/service → Rule/State only if affected → owning Test Contract. Main CI is incremental; path-owned regressions run in parallel. Do not manually duplicate or serialize them. Small local target remains roughly 2–4 min when runners are free.

### CRITICAL
Finance settlement/matching/debt, security/access, DB/schema, financial integrations, control plane, destructive operations.

Define impact set before edit: authoritative Service/Data/Rule/State/Security/Integration + owning Test Contracts. `Full relevant gates` means all gates protecting impacted critical domains, not every historical ERP suite. Do not remove finance/security/DB guards for speed. Code that depends on unapplied production schema must remain backward-compatible or stop before incompatible deploy pending separate OWNER approval.

## HARD WRITE SAFETY

Working path: `feature branch → PR → required gates → merge`.

- **Никогда не вызывать `create_file`, `update_file`, `delete_file` или эквивалентную write-операцию с canonical branch.**
- Create/confirm feature branch before mutation.
- Branch prefixes: `fast/`, `standard/`, `critical/`.
- Placeholder/probe writes, empty/technical/release-checkpoint commits and temporary verifier PR/workflows are forbidden.
- Prefer one meaningful task commit + owning contracts where practical.

## Tool / Actions discipline

- Reuse discovered connector schemas and Notion IDs.
- A successful lookup is not repeated without ambiguity/drift.
- Do not fall back to `gh`, `git ls-remote`, web/public HTTP or container for an operation a working connector already provides.
- Observe parallel required checks as an aggregate gate; do not poll every few seconds.
- On failure inspect only failed jobs/contracts; do not reopen unrelated green areas.

## Production deploy `/erpv2`

- FAST: guarded FAST release → exact squash merge → `ERPv2 Controlled Deploy`.
- STANDARD/CRITICAL code/UI: merged exact canonical HEAD → non-force `deploy/erpv2-agent` → guard waits exact canonical CI and dispatches Controlled Deploy.
- `ERPv2 Controlled Deploy` remains the only production write/SSH engine.
- `erpv2/deploy=success` on exact SHA is routine production PASS evidence.
- Backup, DB fingerprint, legacy `/erp` fingerprint, `.env`/runtime protection and `migrations=false` contract must not be weakened for speed.

## Production DB boundary

Standing code/UI deploy authorization does **not** include production migrations, reconciliation, seeds, backfills, destructive SQL or other data/schema writes. They require separate task-specific OWNER approval.

## Protected invariants

Finance remains `company_owner` only unless OWNER changes policy. Tenant isolation, auditability, fail-closed reconciliation and decimal money are protected. Production finance rows are never mutated for tests.

## Notion / MEMORY PASS

Notion is mandatory but impact-scoped.

Default read: `00` once + exact affected records via its direct registry IDs or one scoped search when no route exists.

- FAST: affected Screen; changed cheap Test/Contract if applicable.
- STANDARD: affected Module/Screen/Service/Rule/Test only.
- CRITICAL: explicit affected Module/Data/Rule/State/Security/Integration/Test set.
- `01`, handoff and broad Health update only for real durable state/continuation/health change, never only because HEAD advanced.
- Per-record `Verified SHA` is evidence provenance, not a live repository HEAD mirror.
- Ordinary successful targeted write does not require readback unless response is ambiguous; critical DB/security/finance semantics should preferably be read back.

Definition of Done: `CODE PASS + CI PASS + MEMORY PASS`; production code/UI task additionally requires `DEPLOY PASS`.

## Source precedence

`latest OWNER decision → current GitHub code/schema/control plane → current MASTER MEMORY → governing docs → historical evidence`.
