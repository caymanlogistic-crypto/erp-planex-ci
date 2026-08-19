# ERP PLANEX — правила работы агентов

Актуально на 2026-08-19. Это текущий операционный контракт. Исторические E7–P40 документы — evidence, а не инструкция.

## Перед началом

Обязательно прочитать: `README.md`, `docs/ai/HANDOFF_FOR_NEW_AGENT.md`, `docs/ai/PROJECT_STATE.md`, `docs/ai/CURRENT_TASK.md`, `docs/ai/DECISIONS.md`. Для finance дополнительно `docs/ai/FINANCE_HANDOFF.md`; для UI — `docs/ui/DESIGN_STANDARD.md`.

## Каноническая линия

Repository: `planexerp/erp-planex`.
Canonical/default branch: `chatgpt/production-stabilization-20260802`.
Control-plane baseline: `d58d415ae8d961c9310829f9ab07b4ed83275884`.
Old `caymanlogistic-crypto/erp-planex` = read-only insurance only.
Production runtime: `https://plan-ex.ru/erpv2/`.
Legacy `/erp` must never be touched.

## Git / PR discipline

Рабочий путь: `branch -> PR -> safe CI -> merge`.

- Не делать feature-work напрямую в canonical branch.
- Не использовать старые Pxx/recovery branches как development/deploy source.
- Не force-push canonical без отдельного recovery решения.
- Merge не означает deploy.
- Historical reports/SHA are evidence only.

## Production control plane

- `.github/workflows/ci.yml` — автоматический non-production engineering gate.
- `.github/workflows/erpv2_controlled_deploy.yml` — единственный standard production deploy workflow.
- Production deploy допускается только вручную (`workflow_dispatch`) для exact SHA и только после явного OWNER approval.
- Любой production-write trigger через `push`, `pull_request`, `schedule` или `workflow_run` запрещён.
- Standard deploy не выполняет DB migrations.
- Production DB migrations/reconciliation — отдельные manual-only операции с явным OWNER approval.
- Нельзя сообщать, что SHA deployed, пока exact production marker и focused post-deploy verification фактически не подтверждены.

## БД

ERP multi-tenant: central DB + company operational DB `erp_company_{id}`. Любое DDL — только numbered migration. Нельзя ALTER/CREATE production schema из controller/temp web script/deploy. Local migration numbers уникальны; normalized names включают `057_create_crew_drivers.sql` и `058_create_linear_route_points.sql`. Historical journal aliases reconciled only controlled tooling.

## Finance

Finance доступен только `company_owner`. Tenant isolation, auditability и fail-closed reconciliation обязательны. Не менять production finance rows ради тестов. Money хранить/обрабатывать как decimal; UI formatting не должно менять numeric value. При web use любого service проверять runtime dependency chain и `app/Support/entrypoint_dependencies.php`.

Перед finance modifications читать `docs/ai/FINANCE_HANDOFF.md` и строить локальную карту только релевантной зоны, а не заново аудитить весь ERP.

## Защищённые доменные решения

`Исполнитель рейса` = подрядчик + водитель(и) + vehicle set через `driver_vehicle_blocks`/`crews`; `driver_vehicle_blocks` использует `vehicle_set_id`. Multi-driver crews поддерживаются. Linear route points поддерживают loading/unloading и обе операции в одной логической точке. Persistent `storage/companies/*` нельзя удалять deploy-ом.

## UI

Desktop-first industrial ERP, minimum production desktop width 1440px, table-first registries, compact density, strict borders/small radii, brown/copper system. Не заменять generic SaaS/Bootstrap/AdminLTE styling без прямого задания.

## Acceptance semantics

- commit != READY;
- green CI != deployed;
- merge != deploy;
- deploy != migration.

Для code/UI production acceptance требуется отдельный OWNER-approved deploy + focused runtime verification. Для schema work дополнительно требуется OWNER-approved migration/reconciliation + tenant journal verification.

## Документация

Приоритет: current code/schema + фактический GitHub control plane -> `README.md`/`AGENTS.md` -> current handoffs/state/task -> reference -> historical evidence. Если CURRENT-документ конфликтует с фактическим workflow/OWNER decision, фиксировать documentation drift и исправлять через обычный branch/PR/CI/merge путь.
