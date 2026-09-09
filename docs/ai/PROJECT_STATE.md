# ERP PLANEX — текущее состояние проекта

Актуально на 2026-08-23.

## Каноническое состояние

Repository: `caymanlogistic-crypto/erp-planex-ci`.
Default/canonical branch: `chatgpt/production-stabilization-20260802`.
Production: `https://plan-ex.ru/erpv2/`.
Legacy `/erp` — запрещено менять.
Previous canonical `planexerp/erp-planex` — read-only insurance only; do not push.
Old `caymanlogistic-crypto/erp-planex` — read-only insurance only; not canonical.

## Engineering flow

`branch -> PR -> safe CI -> merge`

Merge by itself never deploys. If a merged code/UI change is intended for production, an authorized agent may separately request deploy only through the guarded control plane.

## Production control plane

- `.github/workflows/ci.yml` — automatic non-production engineering gate.
- `.github/workflows/erpv2_controlled_deploy.yml` — only workflow that performs standard production `/erpv2` deployment; remains `workflow_dispatch` only for exact SHA.
- `.github/workflows/erpv2_agent_deploy_request.yml` — guarded agent-driven request gate.
- Deploy request ref: `deploy/erpv2-agent`.
- OWNER has granted standing authorization for agents to deploy their own merged code/UI changes without a separate OWNER click per release, only through the guarded flow.
- The standard request gate's executable predicate is owned exclusively by `.github/workflows/erpv2_agent_deploy_request.yml` and its Test/Contract + Integration records. Current implementation validates the protected request ref/current canonical SHA/required exact-SHA CI/HEAD drift and fails closed. Technical event actor identity is not the current standard request gate.
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

Money = decimal data. UI formatting must not alter persisted numeric values. Production finance rows must not be mutated to manufacture tests.

## Security / engineering gates

Finance role gate и tenant isolation — mandatory regression checks. Dynamic SQL identifier sites are guarded fail-closed. New/changed JS requires syntax check. Schema changes require fresh-schema + upgrade-path checks before any separately approved production migration.

## Source of truth

Priority: OWNER decisions + current code/schema + actual GitHub control plane -> generated deterministic contracts -> active policy documentation -> Notion semantic memory -> secondary documentation -> historical evidence.
