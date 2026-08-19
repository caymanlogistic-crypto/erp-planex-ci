# ERP PLANEX

Production PHP/MySQL multi-tenant ERP for PLANEX logistics operations.

## Canonical state — 2026-08-19

Repository: `planexerp/erp-planex`.
Canonical/default branch: `chatgpt/production-stabilization-20260802`.
Canonical HEAD at the control-plane reset: `d58d415ae8d961c9310829f9ab07b4ed83275884`.
Production runtime: `https://plan-ex.ru/erpv2/`.
Legacy `/erp` is separate and must never be changed.
Old `caymanlogistic-crypto/erp-planex` is read-only insurance/recovery evidence, not a development or deploy source.

## Development contract

Normal engineering flow:

`branch -> pull request -> safe CI -> merge`

Merge never deploys production.

Do not develop directly in the read-only insurance repository. Do not use historical Pxx branches/reports as current instructions.

## Start here

Read in order:

1. `AGENTS.md`
2. `docs/ai/HANDOFF_FOR_NEW_AGENT.md`
3. `docs/ai/PROJECT_STATE.md`
4. `docs/ai/CURRENT_TASK.md`
5. `docs/ai/FINANCE_HANDOFF.md` for finance/accounting work
6. `docs/ai/DECISIONS.md` and `docs/ai/DOCUMENTATION_INDEX.md`
7. `docs/ui/DESIGN_STANDARD.md` for UI work

## CI / production control plane

- `.github/workflows/ci.yml` is the automatic, non-production engineering gate.
- `.github/workflows/erpv2_controlled_deploy.yml` is the only standard production deploy workflow and is `workflow_dispatch` only.
- Any production-write workflow must be manual-only. Automatic production mutation from `push`, `pull_request`, `schedule` or `workflow_run` is prohibited.
- Production `/erpv2` deploy requires an explicit OWNER decision for the exact SHA and the deploy confirmation contract.
- Merge never implies deploy.
- Standard deploy does not run database migrations.
- Production DB migrations/reconciliation are separate explicit manual-only operations and require OWNER approval.

Never report a merged SHA as deployed unless the exact production marker and post-deploy verification prove it.

## Database

Central DB stores global/control data; company operational data lives in `erp_company_{id}`. Schema changes require numbered migrations. Never mutate production schema ad hoc from web/controller code. Local migration identities must remain unique; normalized route/crew migrations include `057_create_crew_drivers.sql` and `058_create_linear_route_points.sql`.

## Finance

Finance remains restricted to `company_owner`; tenant isolation and auditability are mandatory. Current finance areas include bank accounts/statements and XLSX import, invoices/payments/operations, allocations/actions/history, cash-flow categories/reports/settings and trip-linked finance. Historical cash code may still exist in the repository; current product changes must follow the latest owner decisions and `docs/ai/FINANCE_HANDOFF.md`.

Money is decimal data. Never parse persisted decimal money by stripping non-digits. UI formatting must not alter persisted values. Do not mutate production financial rows to manufacture test fixtures.

## UI

ERP PLANEX is desktop-first, table-first and compact, with brown/copper accents, strict borders and small radii. Do not replace it with generic Bootstrap/AdminLTE/SaaS styling unless explicitly requested.

## Completion semantics

- Commit: source change exists.
- Green CI: engineering checks passed.
- Merge: change entered the canonical branch.
- Deploy: separate OWNER-approved production operation.
- Migration: separate OWNER-approved production data/schema operation.

These states must never be conflated.
