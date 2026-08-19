# ERP PLANEX — handoff for the next agent

Updated: 2026-08-20.

## Mission

Enter through the current canonical repository and inspect only the area relevant to the user's task. Do not repeat a broad full-ERP audit unless evidence shows the memory/current docs are stale.

## Canonical product state

ERP PLANEX is a PHP/MySQL multi-tenant logistics ERP.

- Repository: `planexerp/erp-planex`
- Canonical/default branch: `chatgpt/production-stabilization-20260802`
- Agent-driven deploy control-plane introduced at: `6e28266556e3342eff1a697633b2c7041f091c56`
- Production: `https://plan-ex.ru/erpv2/`
- Legacy `/erp`: never touch
- `caymanlogistic-crypto/erp-planex`: read-only insurance only

Historical Pxx/recovery branches and reports are evidence, not development/deploy sources.

## Mandatory reading

1. `README.md`
2. `AGENTS.md`
3. `docs/GITHUB_CONTROL_PLANE.md`
4. this file
5. `PROJECT_STATE.md`
6. `CURRENT_TASK.md`
7. focused handoff for the module being changed (finance: `FINANCE_HANDOFF.md`)
8. `DECISIONS.md`
9. `DOCUMENTATION_INDEX.md`
10. `docs/ui/DESIGN_STANDARD.md` for UI work

Then inspect current code/schema for the requested area.

## Development flow

`branch -> PR -> safe CI -> merge`

Merge by itself does not deploy production. For a merged code/UI change intended for production, the agent may independently request deploy through the guarded deploy-ref flow after canonical CI passes.

## Production control plane

- `.github/workflows/ci.yml` is automatic and must not mutate production/data.
- `.github/workflows/erpv2_controlled_deploy.yml` is the only workflow that performs standard `/erpv2` deploy and remains `workflow_dispatch` only for exact SHA.
- `.github/workflows/erpv2_agent_deploy_request.yml` is the authorized agent-driven request gate.
- Deploy request ref: `deploy/erpv2-agent`.
- OWNER has standing authorization for agents to deploy **their own merged changes** without requiring an OWNER click for every release, but only via this guarded sequence:
  `branch -> PR -> safe CI -> merge -> canonical ERP PLANEX CI PASS -> confirm exact current canonical HEAD -> non-force update deploy/erpv2-agent -> request guard -> Controlled Deploy -> production evidence`.
- The request guard must confirm repository-owner actor, exact current canonical HEAD, successful canonical `ERP PLANEX CI` for the same SHA, then reconfirm HEAD immediately before dispatch.
- Never update the deploy ref before canonical CI PASS; never force-move it backwards; never use another branch as a production deploy trigger.
- Standard deploy does not run DB migrations.
- Production migrations/reconciliation remain separate manual-only operations requiring separate task-specific OWNER authorization.

Never conflate green PR CI, merge, canonical CI, deploy request, deploy PASS and migration.

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
