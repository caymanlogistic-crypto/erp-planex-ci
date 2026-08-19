# ERP PLANEX — handoff for the next agent

Updated: 2026-08-19.

## Mission

Enter through the current canonical repository and inspect only the area relevant to the user's task. Do not repeat a broad full-ERP audit unless evidence shows the memory/current docs are stale.

## Canonical product state

ERP PLANEX is a PHP/MySQL multi-tenant logistics ERP.

- Repository: `planexerp/erp-planex`
- Canonical/default branch: `chatgpt/production-stabilization-20260802`
- Control-plane baseline HEAD: `d58d415ae8d961c9310829f9ab07b4ed83275884`
- Production: `https://plan-ex.ru/erpv2/`
- Legacy `/erp`: never touch
- `caymanlogistic-crypto/erp-planex`: read-only insurance only

Historical Pxx/recovery branches and reports are evidence, not development/deploy sources.

## Mandatory reading

1. `README.md`
2. `AGENTS.md`
3. this file
4. `PROJECT_STATE.md`
5. `CURRENT_TASK.md`
6. focused handoff for the module being changed (finance: `FINANCE_HANDOFF.md`)
7. `DECISIONS.md`
8. `DOCUMENTATION_INDEX.md`
9. `docs/ui/DESIGN_STANDARD.md` for UI work

Then inspect current code/schema for the requested area.

## Development flow

`branch -> PR -> safe CI -> merge`

Merge never deploys production.

## Production control plane

- `.github/workflows/ci.yml` is automatic and must not mutate production/data.
- `.github/workflows/erpv2_controlled_deploy.yml` is the only standard production deploy path.
- Deploy is manual `workflow_dispatch` only, exact SHA, confirmation contract, explicit OWNER approval.
- Any automatic production mutation from `push`, `pull_request`, `schedule` or `workflow_run` is prohibited.
- Standard deploy does not run DB migrations.
- Production migrations/reconciliation are separate manual-only operations requiring explicit OWNER approval.

Never conflate green CI, merge, deploy and migration.

## Data / DB invariants

Central DB stores global/control data; company operational data lives in `erp_company_{id}`. DDL requires numbered migrations. Never ALTER/CREATE production schema from controller/temp web code. Normalized migration identities include `057_create_crew_drivers.sql` and `058_create_linear_route_points.sql`.

## Finance invariants

Finance is `company_owner` only. Preserve tenant isolation, auditability and fail-closed behavior. Money is decimal numeric data; UI formatting must not change persisted values. Production finance rows must not be mutated to manufacture tests. When web code uses services, verify `app/Support/entrypoint_dependencies.php`.

## Protected domain invariants

Route executor = contractor + driver(s) + vehicle set through `driver_vehicle_blocks`/`crews`; `driver_vehicle_blocks` uses `vehicle_set_id`. Multi-driver crews and linear loading/unloading points are supported. Persistent user documents under `storage/companies/{company_id}/...` must survive deploys.

## UI contract

Desktop-first, table-first industrial ERP; compact density; strict borders/small radii; brown/copper visual system. Reuse system patterns; do not introduce a generic SaaS/AdminLTE/Bootstrap redesign without OWNER instruction.

## Expected agent bootstrap

Before modifying code, report only the task-relevant essentials:

1. exact canonical HEAD inspected;
2. relevant module/code path mapped;
3. affected DB ownership/invariants;
4. relevant tests/security gates;
5. contradictions or drift found;
6. confirmation that production/DB were not changed during familiarization.

Do not waste context by re-documenting the whole ERP if the task is local.
