# ERP PLANEX

Production PHP/MySQL multi-tenant ERP for PLANEX logistics operations.

## Canonical coordinates

- Repository: `planexerp/erp-planex`
- Canonical branch: `chatgpt/production-stabilization-20260802`
- Production runtime: `https://plan-ex.ru/erpv2/`
- Legacy `/erp`: separate and never modified
- Old `caymanlogistic-crypto/erp-planex`: read-only insurance

## Start here

Agents start with `AGENTS.md` and Notion `ERP PLANEX — MASTER MEMORY` → `00 — START HERE`. Do **not** follow a fixed chain of historical handoffs/project-state files by default.

Current control-plane implementation details live in executable `.github/workflows/*` plus focused contract tests. `docs/GITHUB_CONTROL_PLANE.md` explains the stable operational model without redefining workflow predicates.

## Development and release

Normal engineering flow:

`feature branch → PR → relevant CI → merge`

Merge does not deploy. For non-FAST code/UI, the guarded `deploy/erpv2-agent` request path validates exact current canonical SHA, exact-SHA canonical CI and canonical-HEAD stability before dispatching `ERPv2 Controlled Deploy`. The executable workflow is authoritative for current implementation behavior; README does not duplicate volatile actor/if-condition gates.

Production DB migration/reconciliation/seed/backfill remains a separate task-specific OWNER-authorized operation. Standard deploy does not run migrations.

## Database

Central DB stores global/control data; company operational data lives in tenant databases `erp_company_{id}`. Physical tenant-schema truth is the canonical migration chain under `database/migrations-local/**` plus `LocalMigrationService`. Generated contracts under `docs/database/` are deterministic derivatives; Notion stores semantic meaning rather than a hand-maintained physical schema copy.

## Finance and safety

Finance remains restricted to `company_owner` unless OWNER changes policy. Tenant isolation, auditability and decimal-money semantics are mandatory. Do not mutate production finance rows to manufacture tests.

## Memory integrity

Notion is the semantic-memory authority layer. A small GitHub manifest/checker guards critical records against path-aware drift without mirroring Notion:

```bash
python3 tests/memory_integrity_checker_test.py
python3 tools/memory_integrity_check.py docs/memory/critical_records.json --head HEAD
```

An older Verified SHA is valid when its owning paths have not changed; relevant owning-path drift requires re-verification.

## Completion semantics

- Commit: source change exists.
- PR CI: engineering gates passed.
- Merge: change entered canonical.
- Deploy PASS: exact production evidence proves runtime deployment.
- Migration: separate OWNER-authorized DB operation.
- MEMORY PASS: affected semantic memory is reconciled and integrity checks pass.

These states are never interchangeable.
