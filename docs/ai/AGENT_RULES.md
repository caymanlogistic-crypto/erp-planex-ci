# ERP PLANEX — правила агента

Актуально на 2026-08-20. Это техническое дополнение к `AGENTS.md`.

## Обязательное чтение

Перед изменениями: `README.md` → `AGENTS.md` → `docs/GITHUB_CONTROL_PLANE.md` → `HANDOFF_FOR_NEW_AGENT.md` → `PROJECT_STATE.md` → `CURRENT_TASK.md` → `DECISIONS.md` → `DOCUMENTATION_INDEX.md`. Для UI дополнительно `docs/ui/DESIGN_STANDARD.md`.

## Git / branch discipline

- Repository: `planexerp/erp-planex`.
- Canonical/default branch: `chatgpt/production-stabilization-20260802`.
- Agent-driven deploy control-plane introduced at: `6e28266556e3342eff1a697633b2c7041f091c56`.
- Standard development path: `branch -> PR -> safe CI -> merge`.
- Merge by itself never deploys.
- Old `caymanlogistic-crypto/erp-planex` is read-only insurance only.
- Не работать из старых Pxx branches и не использовать recovery evidence как development/deploy source.
- Не force-push canonical без отдельного recovery решения.

## Production discipline

- Active runtime: `/erpv2`; legacy `/erp` запрещено менять.
- `.github/workflows/ci.yml` — автоматический non-production gate.
- `.github/workflows/erpv2_controlled_deploy.yml` — единственный workflow, выполняющий standard production deploy; `workflow_dispatch` only для exact SHA.
- `.github/workflows/erpv2_agent_deploy_request.yml` — единственный разрешённый agent-driven request gate.
- Deploy request ref: `deploy/erpv2-agent`.
- OWNER разрешил агентам самостоятельно деплоить свои merged изменения через guarded flow без отдельного OWNER-клика на каждый deploy.
- Перед ref update обязательны: PR merged, canonical `ERP PLANEX CI` PASS для exact merged SHA, SHA всё ещё current canonical HEAD.
- Ref update только non-force. Запрещено двигать deploy ref до CI PASS или назад.
- Request workflow обязан проверить owner actor + exact canonical HEAD + successful canonical CI и повторить HEAD check перед dispatch.
- Standard deploy не выполняет migrations.
- Production migrations/reconciliation = отдельная manual-only operation с отдельным task-specific OWNER authorization.
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

Перед merge: focused checks + green PR CI. Для production acceptance merge недостаточно: после merge дождаться green canonical `ERP PLANEX CI`, отдельно запросить deploy через `deploy/erpv2-agent`, затем подтвердить marker + focused runtime evidence. Schema work дополнительно требует отдельно OWNER-authorized migration/reconciliation и tenant journal verification.

## Documentation

CURRENT docs должны описывать только текущую реальность. Исторические E/P reports не переписываются; при конфликте они уступают OWNER decisions, current code/schema и current GitHub control plane.