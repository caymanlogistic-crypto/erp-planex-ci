# ERP PLANEX — текущее состояние проекта

Актуально на 2026-08-19.

## Каноническое состояние

Repository: `planexerp/erp-planex`.
Default/canonical branch: `chatgpt/production-stabilization-20260802`.
Control-plane baseline HEAD: `d58d415ae8d961c9310829f9ab07b4ed83275884`.
Production: `https://plan-ex.ru/erpv2/`.
Legacy `/erp` — запрещено менять.
Old `caymanlogistic-crypto/erp-planex` — read-only insurance only.

## Engineering flow

`branch -> PR -> safe CI -> merge`

Merge never deploys.

## Production control plane

- `.github/workflows/ci.yml` — automatic non-production engineering gate.
- `.github/workflows/erpv2_controlled_deploy.yml` — only standard production deploy workflow.
- Production deploy is manual-only (`workflow_dispatch`), exact SHA, confirmation contract, explicit OWNER approval.
- Automatic production writes from `push`, `pull_request`, `schedule` or `workflow_run` are prohibited.
- Standard deploy does not run DB migrations.
- Production migrations/reconciliation are separate manual-only OWNER-approved operations.

Repository HEAD, merged HEAD and deployed production SHA are independent states and must be reported separately.

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

Priority: OWNER decisions + current code/schema + actual GitHub control plane -> `README.md`/`AGENTS.md` -> current handoffs/state/task -> reference -> historical evidence.
