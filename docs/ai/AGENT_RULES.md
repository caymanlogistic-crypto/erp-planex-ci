# ERP PLANEX — правила агента

Актуально на 2026-08-19. Это техническое дополнение к `AGENTS.md`.

## Обязательное чтение

Перед изменениями: `README.md` → `AGENTS.md` → `HANDOFF_FOR_NEW_AGENT.md` → `PROJECT_STATE.md` → `CURRENT_TASK.md` → `DECISIONS.md` → `DOCUMENTATION_INDEX.md`. Для UI дополнительно `docs/ui/DESIGN_STANDARD.md`.

## Git / branch discipline

- Repository: `planexerp/erp-planex`.
- Canonical/default branch: `chatgpt/production-stabilization-20260802`.
- Baseline control-plane HEAD: `d58d415ae8d961c9310829f9ab07b4ed83275884`.
- Standard development path: `branch -> PR -> safe CI -> merge`.
- Merge never deploys.
- Old `caymanlogistic-crypto/erp-planex` is read-only insurance only.
- Не работать из старых Pxx branches и не использовать recovery evidence как development/deploy source.
- Не force-push canonical без отдельного recovery решения.

## Production discipline

- Active runtime: `/erpv2`; legacy `/erp` запрещено менять.
- `.github/workflows/ci.yml` — автоматический non-production gate.
- `.github/workflows/erpv2_controlled_deploy.yml` — единственный standard production deploy workflow.
- Production deploy = manual `workflow_dispatch`, exact SHA, confirmation contract, explicit OWNER approval.
- Любой `push`/`pull_request`/`schedule`/`workflow_run` production write запрещён.
- Standard deploy не выполняет migrations.
- Production migrations/reconciliation = отдельная manual-only OWNER-approved operation.
- Нельзя объявлять SHA deployed без exact marker + focused post-deploy evidence.

## Schema / tenant rules

- Central DB и `erp_company_{id}` имеют разные обязанности.
- Schema change только numbered migration.
- Local migration numbers уникальны.
- Нормализованные migration names: `057_create_crew_drivers.sql`, `058_create_linear_route_points.sql`.
- Старые journal names `029_create_crew_drivers.sql` и `052_create_linear_route_points.sql` — только через controlled reconciliation.
- Нельзя выполнять migration DDL из UI/controller/temp web code.

## Protected domain rules

- Route executor = contractor + driver(s) + vehicle set через `driver_vehicle_blocks` + `crews`.
- Использовать `driver_vehicle_blocks.vehicle_set_id`, не `vehicle_id`.
- Finance = `company_owner` only, включая AJAX/JSON/POST endpoints.
- Tenant isolation и `entity_access_grants` нельзя ослаблять ради UI convenience.
- `storage/companies/*` — persistent user data.

## UI lock

ERP PLANEX: desktop-first, minimum 1440px, table-first, compact industrial density, strict borders/small radii, brown/copper system. Generic SaaS/AdminLTE/Bootstrap restyle запрещён без прямой UI-задачи OWNER.

## Acceptance

Перед merge: focused checks + green CI. Для production acceptance merge недостаточно: нужен отдельный OWNER-approved deploy и runtime verification. Schema work дополнительно требует OWNER-approved migration/reconciliation и tenant journal verification.

## Documentation

CURRENT docs должны описывать только текущую реальность. Исторические E/P reports не переписываются; при конфликте они уступают OWNER decisions, current code/schema и current GitHub control plane.
