# ERP PLANEX — текущее состояние проекта

Актуально на 2026-08-20.

## Каноническое состояние

Repository: `planexerp/erp-planex`.
Default/canonical branch: `chatgpt/production-stabilization-20260802`.
Agent-driven deploy control-plane introduced at: `6e28266556e3342eff1a697633b2c7041f091c56`.
Production: `https://plan-ex.ru/erpv2/`.
Legacy `/erp` — запрещено менять.
Old `caymanlogistic-crypto/erp-planex` — read-only insurance only.

## Engineering flow

`branch -> PR -> safe CI -> merge`

Merge by itself never deploys. If the merged code/UI change is intended for production, an authorized agent may separately request deploy only after exact canonical push CI PASS.

## Production control plane

- `.github/workflows/ci.yml` — automatic non-production engineering gate.
- `.github/workflows/erpv2_controlled_deploy.yml` — only workflow that performs standard production `/erpv2` deployment; remains `workflow_dispatch` only for exact SHA.
- `.github/workflows/erpv2_agent_deploy_request.yml` — guarded agent-driven request gate.
- Deploy request ref: `deploy/erpv2-agent`.
- OWNER has granted standing authorization for agents to deploy their own merged changes without a separate OWNER click per release, only through the guarded flow.
- Required sequence: PR/CI PASS -> merge -> canonical `ERP PLANEX CI` PASS for exact SHA -> confirm SHA is still current canonical HEAD -> non-force advance `deploy/erpv2-agent` -> request gate -> Controlled Deploy -> exact production evidence.
- The request gate fail-closes unless actor is repository owner, request SHA equals current canonical HEAD, successful canonical push CI exists for the exact SHA, and HEAD still matches immediately before dispatch.
- Deploy ref must not move before CI PASS and must never be force-moved backwards.
- Standard deploy does not run DB migrations.
- Production migrations/reconciliation remain separate manual-only OWNER-authorized operations and are not covered by standing agent code-deploy authorization.

Repository HEAD, merged HEAD, deploy-request SHA, deployed production SHA and migration state are independent states and must be reported separately.

## Архитектура данных

ERP multi-tenant: central DB для global/control data; company operational data — `erp_company_{id}`. Local migrations обслуживаются `LocalMigrationService`. Номера/journal identity должны быть однозначны. Normalized migration names включают `057_create_crew_drivers.sql` и `058_create_linear_route_points.sql`; historical aliases нельзя слепо повторно выполнять.

## Текущая продуктовая база

Система содержит модули контрагентов/клиентов/водителей/ТС/исполнителей рейса, multi-driver crews, linear trips с loading/unloading points, документы и finance/accounting. Persistent documents находятся в `storage/companies/{company_id}/...` и должны переживать deploy.

UI — desktop-first industrial ERP, table-first, compact, brown/copper.

## Finance / accounting

Finance доступен только `company_owner`. Tenant isolation, auditability и fail-closed reconciliation обязательны. Основные зоны: bank accounts/statements, XLSX parser/settings/import, invoices/payments/operations, allocations/actions/history, categories/reports/settings, trip-linked financial data. Historical cash code may still exist; new product behavior follows latest OWNER decisions and current code.

Ключевые места для targeted audit: `app/Http/Controllers/Company/BankFinance*`, `Finance*`, finance services, finance views/assets/routes, `app/Support/entrypoint_dependencies.php`, finance migrations/tables/tests.

Money = decimal data. UI formatting must not alter persisted numeric values. Production finance rows must not be mutated to manufacture tests.

## Security / engineering gates

Finance role gate и tenant isolation — mandatory regression checks. Dynamic SQL identifier sites are guarded fail-closed. New/changed JS requires syntax check. Schema changes require fresh-schema + upgrade-path checks before any separately approved production migration.

## Source of truth

Priority: OWNER decisions + current code/schema + actual GitHub control plane -> `docs/GITHUB_CONTROL_PLANE.md` -> `README.md`/`AGENTS.md` -> current handoffs/state/task -> reference -> historical evidence.
