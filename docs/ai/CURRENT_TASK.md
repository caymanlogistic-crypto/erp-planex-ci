# ERP PLANEX — текущая задача

Актуально на 2026-08-19.

## Статус

`OWNER_TASK_DRIVEN / MEMORY_BOOTSTRAP_READY`

Стабилизационный control-plane этап завершён. Репозиторий и документация должны использовать модель branch → PR → safe CI → merge; merge не deploy.

Новый агент не должен автоматически продолжать старый Pxx/finance plan только потому, что он указан в historical handoff. Конкретная функциональная задача определяется последним OWNER instruction.

## Режим работы

- Repository: `planexerp/erp-planex`
- Canonical/default branch: `chatgpt/production-stabilization-20260802`
- Control-plane baseline HEAD: `d58d415ae8d961c9310829f9ab07b4ed83275884`
- Old `caymanlogistic-crypto/erp-planex`: read-only insurance
- Production `/erpv2`: manual-only OWNER-approved deploy
- Legacy `/erp`: never touch

## Перед функциональным изменением

1. Прочитать `README.md` и `AGENTS.md`.
2. Прочитать `HANDOFF_FOR_NEW_AGENT.md` и `PROJECT_STATE.md`.
3. Прочитать только focused handoff/module docs для текущей задачи.
4. Проверить фактический current HEAD и релевантный code/schema path.
5. Создать отдельную branch, PR, получить safe CI, затем merge.

Не проводить новый полный аудит всего ERP без конкретного evidence of drift.

## Production / DB boundary

- Merge never deploys.
- Deploy `/erpv2` is a separate manual exact-SHA operation requiring explicit OWNER approval.
- Standard deploy does not run migrations.
- Production migration/reconciliation is a separate manual operation requiring explicit OWNER approval.
- Green CI, merge, deploy and migration are four distinct states.

## Общие инварианты

- Finance remains `company_owner` only unless OWNER explicitly changes access.
- Tenant isolation mandatory.
- Money is decimal numeric data; formatting must not mutate persisted value.
- Production data must not be modified to manufacture tests.
- Legacy `/erp` and persistent `storage/companies/{company_id}/...` are protected.
- Route executor / crews / vehicle-set and linear route-point invariants remain protected unless the task explicitly changes them.

## Следующее действие

Take the latest OWNER functional/fix request, inspect only the affected subsystem, and execute through branch → PR → safe CI → merge. Stop before production deploy or DB migration unless the OWNER explicitly authorizes that exact operation.
