# ERP PLANEX — правила работы агентов

Актуально на 2026-08-20. Current code + latest OWNER decision имеют приоритет; исторические Pxx/E7 материалы — evidence, не инструкция.

## Быстрый bootstrap

Если доступен Notion `ERP PLANEX — MASTER MEMORY`, прочитать один раз в начале задачи: `00 — START HERE`, актуальный блок `01 — CURRENT STATE`, `11 — AGENT BOOTSTRAP PROTOCOL`, затем только релевантные каталоги/`23 — CHANGE PLAYBOOKS`. Не перечитывать всю документацию ERP без evidence of drift.

После этого проверить current canonical HEAD и изучать только затронутую цепочку `screen → route → controller/action → significant service → data/rules/state/access → relevant test contract`.

Repository: `planexerp/erp-planex`.
Canonical branch: `chatgpt/production-stabilization-20260802`.
Old `caymanlogistic-crypto/erp-planex` = read-only insurance.
Production: `/erpv2`. Legacy `/erp` — never touch.

## CHANGE CLASS — определить до редактирования

### FAST
Только presentation/view/CSS/text/local UI. FAST допустим только если НЕ меняются route/controller behavior, business rules/state, finance amount/debt/settlement semantics, DB/schema, security/access, integrations или control plane.

Путь: `bootstrap once → relevant screen/code → focused/local check → один осмысленный commit/push где возможно → PR → ERP PLANEX CI + только path-triggered contracts → merge → one final MEMORY delta → guarded deploy → built-in deploy evidence`.

Обычно `MEMORY IMPACT: SCREEN + UI`. Не запускать нерелевантные MySQL/finance/browser regressions вручную.

### STANDARD
Controller/service/business behavior без production DB/schema mutation. Использовать только owning module/service/rule/test contracts. Path-triggered domain regressions обязательны, если они относятся к изменённой семантике.

### CRITICAL
Finance settlement/matching/debt semantics, security/access, DB/schema, integrations that create financial facts, control plane, destructive operations. Использовать полный релевантный gate set и fail-closed evidence. Production DB write всё равно требует отдельного task-specific OWNER approval.

## Git / CI discipline

Рабочий путь: `branch → PR → safe CI → merge`.

- Не делать feature-work напрямую в canonical.
- Не создавать commit на каждую микро-правку: сначала свести изменение и локально/статически проверить, затем push.
- **Запрещён empty/technical commit только ради retrigger CI/release.** Если такой commit кажется необходимым — это process bug; исправить workflow или rerun только failed job/run, если failure transient.
- Не создавать temporary branch/PR/workflow только ради обычной postdeploy-проверки.
- Не опрашивать Actions каждые несколько секунд. Сделать агрегированную проверку, разумно подождать, проверить ещё раз; при failure открыть конкретный failed job/log.
- Не rerun-ить уже успешные нерелевантные workflows.
- Main `ERP PLANEX CI` работает на PR и canonical push; feature-branch push сам по себе не запускает дублирующий main CI.
- Heavy domain regressions — PR-only и path-scoped; устаревшие PR runs отменяются через `concurrency/cancel-in-progress`.

## Production deploy `/erpv2`

OWNER дал standing authorization агентам деплоить собственные merged code/UI изменения через guarded flow без отдельного OWNER-клика.

После merge:
1. Подтвердить exact current canonical HEAD.
2. Если задача предназначена для production — **сразу** non-force передвинуть `deploy/erpv2-agent` на этот exact HEAD. Не ждать и не poll-ить canonical CI вручную.
3. `ERPv2 Agent Deploy Request` сам ждёт exact-SHA canonical `ERP PLANEX CI`, fail-closed останавливается при failure/timeout, затем повторно проверяет HEAD.
4. Только после PASS guard dispatch-ит `ERPv2 Controlled Deploy` exact SHA.
5. Routine deploy PASS подтверждается built-in evidence Controlled Deploy: exact marker, artifact SHA, HTTP 200, DB fingerprint unchanged, legacy `/erp` fingerprint unchanged, `.env`/runtime preservation, migrations=false.
6. Дополнительные P44/P46/P47/P49/P50/P93 Playwright/runtime workflows запускаются вручную только когда task-specific risk действительно этого требует. Они не являются обязательным fan-out после каждого deploy.

Если canonical HEAD изменился во время ожидания — guard обязан fail closed; старый SHA не выкатывать.

Merge сам не deploy-ит. CI сам не deploy-ит. Deploy request != deploy PASS.

## Production database boundary

Standing code/UI deploy authorization НЕ включает production migrations, seeds, reconciliation, backfills, destructive SQL или иные production data/schema writes. Они требуют отдельного task-specific OWNER approval. Standard deploy migrations не выполняет.

## Finance / security invariants

Finance доступен только `company_owner`, пока OWNER не изменит policy. Tenant isolation, auditability, fail-closed reconciliation и decimal money обязательны. Не менять production finance rows ради тестов. Uncertain matching не должен автоматически создавать money/debt mutation.

## Notion / Memory Gate — read once, write once

В начале задачи память читается один раз по минимальному relevant slice. В процессе Notion не обновлять, кроме реального blocker/drift, который нельзя отложить.

После merge сделать **один финальный delta MEMORY PASS**:
- подтвердить canonical HEAD;
- обновить только реально затронутые registry records и `01 — CURRENT STATE`, если нужен current-state delta;
- обновить health/flow/rules только если они действительно затронуты;
- добавить один короткий handoff.

FAST UI не требует переписывать Modules/Services/Business Rules, если они не изменились. `Verified` нельзя механически переносить на незатронутые области только потому, что canonical HEAD вырос.

Если Notion SQL quota ограничена — использовать fetch/view/search fallback из `22 — DEPLOY & NOTION LIMITS RUNBOOK`; не повторять SQL циклически.

**Definition of Done: `CODE PASS + CI PASS + MEMORY PASS`.** Read-only/no-durable-state задача может иметь `MEMORY IMPACT: NONE` и не требует Notion write.

## Source precedence

`latest OWNER decision → current GitHub code/schema/control plane → current MASTER MEMORY → current governing docs → historical evidence`.
