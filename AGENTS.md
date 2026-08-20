# ERP PLANEX — правила работы агентов

Актуально на 2026-08-20. Current code + latest OWNER decision имеют приоритет; исторические Pxx/E7 материалы — evidence, не инструкция.

## Быстрый bootstrap

Если доступен Notion `ERP PLANEX — MASTER MEMORY`, прочитать один раз в начале задачи: `00 — START HERE`, актуальный блок `01 — CURRENT STATE`, `11 — AGENT BOOTSTRAP PROTOCOL`, затем только релевантные записи/`23 — CHANGE PLAYBOOKS`. Не перечитывать всю документацию ERP без evidence of drift.

После этого проверить current canonical HEAD и изучать только затронутую цепочку `screen → route → controller/action → significant service → data/rules/state/access → relevant test contract`. Для чистого UI FAST-path цепочку сокращать до `screen/view → cheap UI contract`, если route/controller/service semantics не меняются.

Repository: `planexerp/erp-planex`.
Canonical branch: `chatgpt/production-stabilization-20260802`.
Old `caymanlogistic-crypto/erp-planex` = read-only insurance.
Production: `/erpv2`. Legacy `/erp` — never touch.

## CHANGE CLASS — определить до редактирования

### FAST
Только presentation/view/CSS/text/local UI. FAST допустим только если НЕ меняются route/controller behavior, business rules/state, finance amount/debt/settlement semantics, DB/schema, security/access, integrations или control plane.

Целевой путь: `bootstrap once → exact relevant file(s) → focused check → feature branch write → один PR → cheap CI → merge → minimal MEMORY delta → guarded deploy → one deploy-status check`.

Цель OWNER для микроправки порядка нескольких строк — стремиться к ~1 минуте wall-clock при свободных GitHub runners. Это performance target, а не основание пропускать safety gates.

### STANDARD
Controller/service/business behavior без production DB/schema mutation. Использовать только owning module/service/rule/test contracts. Path-triggered domain regressions обязательны, если они относятся к изменённой семантике.

### CRITICAL
Finance settlement/matching/debt semantics, security/access, DB/schema, integrations that create financial facts, control plane, destructive operations. Использовать полный релевантный gate set и fail-closed evidence. Production DB write всё равно требует отдельного task-specific OWNER approval.

## HARD WRITE SAFETY — canonical write запрещён

Рабочий путь: `feature branch → PR → safe CI → merge`.

- **Никогда не вызывать `create_file`, `update_file`, `delete_file` или эквивалентную write-операцию с canonical branch.**
- Перед первой file mutation сначала создать/подтвердить отдельную feature/fix branch.
- Для connector writes всегда явно указывать feature branch; отсутствие branch argument считается unsafe.
- Canonical разрешено менять только штатным merge PR.
- Нельзя создавать placeholder/temp/probe файл для проверки write-доступа.
- Нельзя делать empty/technical/release-checkpoint commit только ради retrigger CI/deploy.
- Если transient CI failed — rerun failed job/run, а не создавать новый commit без product change.
- Не создавать temporary verification PR/workflow для обычной postdeploy-проверки.

## FAST tool discipline

Если primary GitHub/Notion connector работает, **не переходить к `gh`, `git ls-remote`, web search, public HTTP или container fallback** для той же операции. Fallback допустим только после фактического failure primary connector и только если действительно нужен для результата.

Для FAST:
- не читать layout/main/controller/service «для уверенности», если view + cheap contract уже доказывают scope;
- не делать draft PR → ready; сразу открывать обычный PR;
- не дробить одну микроправку и её cheap contract на отдельные commits, если можно сделать атомарно;
- CI status смотреть агрегированно: обычно initial check + final check; не polling loop каждые секунды;
- не запускать тяжёлые finance/MySQL/Playwright regressions вручную без path/risk причины.

## Production deploy `/erpv2`

OWNER дал standing authorization агентам деплоить собственные merged code/UI изменения через guarded flow без отдельного OWNER-клика.

После merge:
1. Подтвердить exact current canonical HEAD один раз.
2. Если задача предназначена для production — сразу non-force передвинуть `deploy/erpv2-agent` на exact HEAD. Не poll-ить canonical CI вручную.
3. `ERPv2 Agent Deploy Request` сам ждёт exact-SHA canonical `ERP PLANEX CI`, fail-closed останавливается при failure/timeout и повторно проверяет HEAD.
4. На PASS guard dispatch-ит `ERPv2 Controlled Deploy` exact SHA.
5. Controlled Deploy публикует commit status context `erpv2/deploy`; агент проверяет этот exact-SHA status вместо web/HTTP/connector archaeology.
6. `erpv2/deploy = success` означает, что built-in deploy evidence прошёл: exact marker, artifact, HTTP 200, DB fingerprint unchanged, legacy `/erp` unchanged, `.env`/runtime preserved, migrations=false.
7. Дополнительные P44/P46/P47/P49/P50/P93 запускать вручную только для реально соответствующего STANDARD/CRITICAL риска.

Merge != deploy. Deploy request != deploy PASS. Если canonical HEAD ушёл вперёд — stale SHA не deploy-ить.

## Production database boundary

Standing code/UI deploy authorization НЕ включает production migrations, seeds, reconciliation, backfills, destructive SQL или иные production data/schema writes. Они требуют отдельного task-specific OWNER approval. Standard deploy migrations не выполняет.

## Finance / security invariants

Finance доступен только `company_owner`, пока OWNER не изменит policy. Tenant isolation, auditability, fail-closed reconciliation и decimal money обязательны. Не менять production finance rows ради тестов. Uncertain matching не должен автоматически создавать money/debt mutation.

## Notion / Memory Gate — FAST whitelist

В начале задачи память читается один раз по минимальному relevant slice. В процессе Notion не обновлять.

Для `FAST + SCREEN/UI` финальный MEMORY PASS по умолчанию ограничен:
1. affected Screen record;
2. affected cheap Test/Contract record — только если он реально изменён;
3. один короткий delta handoff.

**Не обновлять для обычной FAST UI-задачи:** `00 — START HERE`, `01 — CURRENT STATE`, Module Registry, Service Catalog, Business Rules, broad Memory Health или другие каталоги только потому, что canonical SHA изменился.

`00/01` хранят operational checkpoint, но exact current HEAD всегда проверяется в GitHub. Они обновляются только при meaningful architecture/control-plane/current-state change, а не после каждой микроправки.

Для FAST использовать уже найденные page IDs; не делать повторные semantic search перед каждым write. Цель — примерно 3–5 Notion reads total и 2–3 writes total максимум.

STANDARD/CRITICAL обновляют только реально затронутые rules/state/security/flow/health records. `Verified` нельзя механически переносить на незатронутые области только потому, что HEAD вырос.

Если Notion SQL quota ограничена — direct fetch по известному ID → scoped search только при необходимости; не повторять SQL/search циклически.

**Definition of Done: `CODE PASS + CI PASS + MEMORY PASS`.** Read-only/no-durable-state задача может иметь `MEMORY IMPACT: NONE` и не требует Notion write.

## Source precedence

`latest OWNER decision → current GitHub code/schema/control plane → current MASTER MEMORY → current governing docs → historical evidence`.
