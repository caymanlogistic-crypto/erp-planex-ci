# ERP PLANEX — текущая задача

Актуально на 2026-08-20.

## Статус

`OWNER_TASK_DRIVEN / MEMORY_BOOTSTRAP_READY / AGENT_DEPLOY_READY`

Стабилизационный control-plane этап завершён. Репозиторий и документация используют модель branch → PR → safe CI → merge; merge сам по себе не deploy. OWNER дополнительно разрешил агентам самостоятельно деплоить свои merged code/UI изменения через guarded agent-driven deploy flow без отдельного OWNER-клика на каждый релиз.

Новый агент не должен автоматически продолжать старый Pxx/finance plan только потому, что он указан в historical handoff. Конкретная функциональная задача определяется последним OWNER instruction.

## Режим работы

- Repository: `caymanlogistic-crypto/erp-planex-ci`
- Canonical/default branch: `chatgpt/production-stabilization-20260802`
- Agent-driven deploy control-plane introduced at: `6e28266556e3342eff1a697633b2c7041f091c56`
- Previous canonical `planexerp/erp-planex`: read-only insurance; do not push
- Old `caymanlogistic-crypto/erp-planex`: read-only insurance; not canonical
- Production `/erpv2`: agent-driven exact-SHA deploy allowed only through guarded deploy-ref flow after canonical CI PASS
- Deploy request ref: `deploy/erpv2-agent`
- Legacy `/erp`: never touch

## Перед функциональным изменением

1. Прочитать `README.md`, `AGENTS.md` и `docs/GITHUB_CONTROL_PLANE.md`.
2. Прочитать `HANDOFF_FOR_NEW_AGENT.md` и `PROJECT_STATE.md`.
3. Прочитать только focused handoff/module docs для текущей задачи.
4. Проверить фактический current HEAD и релевантный code/schema path.
5. Создать отдельную branch, PR, получить safe CI, затем merge.

Не проводить новый полный аудит всего ERP без конкретного evidence of drift.

## Production / DB boundary

- Merge by itself never deploys.
- Для merged code/UI task, intended for production, агент после merge обязан дождаться canonical `ERP PLANEX CI` PASS на exact SHA, убедиться что SHA всё ещё current canonical HEAD, затем non-force передвинуть `deploy/erpv2-agent` на этот SHA.
- `ERPv2 Agent Deploy Request` обязан проверить owner actor + exact current canonical HEAD + successful canonical CI для того же SHA и повторно проверить HEAD непосредственно перед dispatch.
- Только после downstream `ERPv2 Controlled Deploy` PASS, exact production marker и focused runtime verification агент может заявить, что изменение deployed.
- Standard deploy does not run migrations.
- Production migration/reconciliation is a separate manual operation requiring separate task-specific OWNER authorization. Standing agent deploy authorization does not include DB migrations.
- Green PR CI, merge, canonical CI, deploy request, deploy PASS and migration are distinct states.

## Общие инварианты

- Finance remains `company_owner` only unless OWNER explicitly changes access.
- Tenant isolation mandatory.
- Money is decimal numeric data; formatting must not mutate persisted value.
- Production data must not be modified to manufacture tests.
- Legacy `/erp` and persistent `storage/companies/{company_id}/...` are protected.
- Route executor / crews / vehicle-set and linear route-point invariants remain protected unless the task explicitly changes them.

## Следующее действие

Take the latest OWNER functional/fix request, inspect only the affected subsystem, execute branch → PR → safe CI → merge. If that merged code/UI change is intended for production, continue autonomously through canonical CI PASS → guarded deploy-ref request → Controlled Deploy → focused verification. Stop before any production DB migration/reconciliation unless the OWNER separately authorizes that operation.
