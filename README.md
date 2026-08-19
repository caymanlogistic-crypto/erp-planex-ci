# ERP PLANEX

Production PHP/MySQL multi-tenant ERP for PLANEX logistics operations.

## Canonical state — 2026-08-20

Repository: `planexerp/erp-planex`.
Canonical/default branch: `chatgpt/production-stabilization-20260802`.
Agent-driven deploy control-plane introduced at: `6e28266556e3342eff1a697633b2c7041f091c56`.
Production runtime: `https://plan-ex.ru/erpv2/`.
Legacy `/erp` is separate and must never be changed.
Old `caymanlogistic-crypto/erp-planex` is read-only insurance/recovery evidence, not a development or deploy source.

## Development contract

Normal engineering flow:

`branch -> pull request -> safe CI -> merge`

Merge by itself never deploys production. After merge, an authorized agent may separately request deploy of the exact current canonical SHA through the guarded deploy-ref flow documented in `docs/GITHUB_CONTROL_PLANE.md`.

Do not develop directly in the read-only insurance repository. Do not use historical Pxx branches/reports as current instructions.

## Start here

Read in order:

1. `AGENTS.md`
2. `docs/GITHUB_CONTROL_PLANE.md`
3. `docs/ai/HANDOFF_FOR_NEW_AGENT.md`
4. `docs/ai/PROJECT_STATE.md`
5. `docs/ai/CURRENT_TASK.md`
6. `docs/ai/FINANCE_HANDOFF.md` for finance/accounting work
7. `docs/ai/DECISIONS.md` and `docs/ai/DOCUMENTATION_INDEX.md`
8. `docs/ui/DESIGN_STANDARD.md` for UI work

## CI / production control plane

- `.github/workflows/ci.yml` is the automatic, non-production engineering gate.
- `.github/workflows/erpv2_controlled_deploy.yml` is the only workflow that performs the standard production `/erpv2` deployment and remains `workflow_dispatch` only for exact SHA.
- `.github/workflows/erpv2_agent_deploy_request.yml` is the guarded request path that lets agents deploy their own merged changes without requiring the OWNER to open GitHub for each release.
- Dedicated deploy request ref: `deploy/erpv2-agent`.
- Allowed flow: `branch -> PR -> safe CI -> merge -> canonical ERP PLANEX CI PASS -> exact current canonical HEAD check -> non-force update deploy/erpv2-agent -> guarded request -> Controlled Deploy -> production evidence`.
- The guard requires repository-owner actor, exact current canonical HEAD and successful canonical `ERP PLANEX CI` for the same SHA before dispatch.
- Do not move the deploy ref before canonical CI passes and never force-move it backwards.
- Standard deploy does not run database migrations.
- Production DB migrations/reconciliation remain separate explicit manual-only operations and require separate task-specific OWNER authorization.

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
- Green PR CI: engineering checks passed on the PR.
- Merge: change entered the canonical branch.
- Green canonical CI: exact merged SHA passed `ERP PLANEX CI` on canonical push.
- Deploy request: agent explicitly advanced `deploy/erpv2-agent` to that exact current canonical SHA.
- Deploy PASS: controlled deploy marker and post-deploy verification prove production is on that SHA.
- Migration: separate OWNER-authorized production data/schema operation.

These states must never be conflated.