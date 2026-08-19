# ERP PLANEX — правила работы агентов

Актуально на 2026-08-20. Это текущий операционный контракт. Исторические E7–P40 документы — evidence, а не инструкция.

## Перед началом

Обязательно прочитать: `README.md`, `docs/GITHUB_CONTROL_PLANE.md`, `docs/ai/HANDOFF_FOR_NEW_AGENT.md`, `docs/ai/PROJECT_STATE.md`, `docs/ai/CURRENT_TASK.md`, `docs/ai/DECISIONS.md`. Для finance дополнительно `docs/ai/FINANCE_HANDOFF.md`; для UI — `docs/ui/DESIGN_STANDARD.md`.

Если подключён Notion ERP PLANEX MASTER MEMORY, сначала использовать `00 — START HERE`, `01 — CURRENT STATE`, `11 — AGENT BOOTSTRAP PROTOCOL`, затем только релевантные structured registries/maps. Notion не заменяет current code/schema: latest OWNER decision и фактический GitHub имеют приоритет.

## Каноническая линия

Repository: `planexerp/erp-planex`.
Canonical/default branch: `chatgpt/production-stabilization-20260802`.
Agent-driven deploy control-plane introduced at: `6e28266556e3342eff1a697633b2c7041f091c56`.
Old `caymanlogistic-crypto/erp-planex` = read-only insurance only.
Production runtime: `https://plan-ex.ru/erpv2/`.
Legacy `/erp` must never be touched.

## Git / PR discipline

Рабочий путь: `branch -> PR -> safe CI -> merge`.

- Не делать feature-work напрямую в canonical branch.
- Не использовать старые Pxx/recovery branches как development/deploy source.
- Не force-push canonical без отдельного recovery решения.
- Merge сам по себе не означает deploy.
- Historical reports/SHA are evidence only.

## Production control plane

- `.github/workflows/ci.yml` — автоматический non-production engineering gate.
- `.github/workflows/erpv2_controlled_deploy.yml` — единственный workflow, который фактически выполняет standard production deploy `/erpv2`; он остаётся `workflow_dispatch` only для exact SHA.
- `.github/workflows/erpv2_agent_deploy_request.yml` — разрешённый agent-driven deploy request gate.
- Dedicated deploy request ref: `deploy/erpv2-agent`.
- OWNER разрешил агентам самостоятельно деплоить **свои уже merged изменения** без отдельного OWNER-клика для каждого релиза, но только через guarded flow ниже.
- Разрешённая последовательность: `branch -> PR -> safe CI -> merge -> canonical ERP PLANEX CI PASS -> verify exact current canonical HEAD -> non-force update deploy/erpv2-agent -> guarded request -> ERPv2 Controlled Deploy -> verify evidence`.
- Agent deploy request обязан fail-closed проверить: owner actor, exact current canonical HEAD, successful canonical `ERP PLANEX CI` для того же SHA и повторно HEAD непосредственно перед dispatch.
- Нельзя двигать `deploy/erpv2-agent` до canonical CI PASS, нельзя force-move его назад и нельзя использовать иной push-branch как deploy trigger.
- Standard deploy не выполняет DB migrations.
- Production DB migrations/reconciliation остаются отдельными manual-only операциями и требуют отдельного task-specific OWNER authorization.
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

## Notion MASTER MEMORY / Memory Gate

Для material ERP work обязательна поддержка живой памяти проекта.

Перед работой:

1. Определить `MEMORY IMPACT`: `NONE | MODULE | SCREEN | DATABASE | BUSINESS_RULE | STATE_MACHINE | UI | SECURITY | INTEGRATION | FLOW | AUTOMATION | DECISION`.
2. Если Notion подключён, проверить релевантные записи `MODULE REGISTRY DB`, `SCREEN CATALOG DB`, `BUSINESS RULES DB`, `DATA ENTITY CATALOG DB`, `INTEGRATIONS DB` и `MEMORY HEALTH REGISTER DB`.
3. Если релевантная memory area имеет `NEEDS REVERIFY` или расходится с current GitHub, сначала выполнить локальную read-only верификацию и не продолжать из устаревшего предположения.

После merge, до финального ответа:

1. Подтвердить canonical HEAD.
2. Обновить `01 — CURRENT STATE`, если изменился HEAD/status.
3. Обновить все затронутые registry records: `Freshness`, `Verified SHA`, `Verified Date`, `Evidence` и фактическую семантику/связи.
4. При изменении flows/rules/state/security обновить соответствующие SYSTEM FLOW / FINANCE MAP / BUSINESS RULES / STATE MACHINES / ACCESS maps.
5. Обновить `MEMORY HEALTH REGISTER DB`; если область фактически не была перепроверена, ставить `NEEDS REVERIFY`, а не оставлять старый `VERIFIED`.
6. Добавить короткий delta-handoff в `07 — AGENT HANDOFFS` с `MEMORY IMPACT` и результатом `MEMORY PASS`.

**Definition of Done для material ERP task: `CODE PASS + CI PASS + MEMORY PASS`.**

Исключение: чистый read-only вопрос без изменения устойчивого состояния может иметь `MEMORY IMPACT: NONE`; в этом случае Notion write не нужен. Не копировать большие code snippets в Notion — хранить смысл, связи, пути, решения и evidence.

## Acceptance semantics

- commit != READY;
- green PR CI != deployed;
- merge != deploy;
- agent deploy request != deploy PASS;
- deploy != migration.

Для code/UI production acceptance после merge агент должен дождаться canonical `ERP PLANEX CI`, запросить deploy через `deploy/erpv2-agent` и подтвердить production marker + focused runtime verification. Для schema work дополнительно требуется отдельно OWNER-authorized migration/reconciliation + tenant journal verification.

## Документация

Приоритет: latest OWNER decision + current code/schema + фактический GitHub control plane -> current Notion MASTER MEMORY -> `README.md`/`AGENTS.md` -> current handoffs/state/task -> reference -> historical evidence. Если CURRENT-документ или Notion конфликтует с фактическим workflow/code/OWNER decision, фиксировать drift, исправлять через обычный branch/PR/CI/merge путь и завершать Memory Gate.