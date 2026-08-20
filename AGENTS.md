# ERP PLANEX — правила работы агентов

Актуально на 2026-08-20. Current code + latest OWNER decision имеют приоритет; исторические Pxx/E7 материалы — evidence, не инструкция.

## Быстрый bootstrap

**Notion обязателен** для каждой material ERP задачи. Default bootstrap теперь один: `ERP PLANEX — MASTER MEMORY` → `24 — AGENT BOOTSTRAP MANIFEST`.

Manifest хранит только stable coordinates, source precedence, change-class selector и direct registry/page IDs. Exact canonical HEAD всегда читать из GitHub; volatile SHA не является bootstrap datum.

После manifest открыть только task-owned records по direct IDs/routing. `01 — CURRENT STATE`, `11 — AGENT BOOTSTRAP PROTOCOL`, `22 — DEPLOY & NOTION LIMITS RUNBOOK`, `23 — CHANGE PLAYBOOKS` и historical handoffs читать **условно**, когда задача реально требует их контекста. Не делать broad-memory scan и не повторять semantic search, если ID уже известен.

Для чистого UI FAST-path: `Screen record → exact view/assets → cheap UI contract`. Для STANDARD/CRITICAL — только owning chain/impact set.

Repository: `planexerp/erp-planex`.
Canonical branch: `chatgpt/production-stabilization-20260802`.
Old `caymanlogistic-crypto/erp-planex` = read-only insurance.
Production: `/erpv2`. Legacy `/erp` — never touch.

## CHANGE CLASS

### FAST
Только presentation/view/CSS/text/local UI. FAST недопустим при изменении route/controller behavior, business/state, finance amount/debt/settlement semantics, DB/schema, security/access, integrations или control plane.

Для production FAST использовать специальный guarded pipeline:
`bootstrap + targeted Notion → branch fast/<slug> → one atomic change → PR title FAST: ... → ERPv2 FAST Release → auto squash merge → exact canonical HEAD → ERPv2 Controlled Deploy → erpv2/deploy status → mandatory tiny Notion MEMORY PASS`.

`ERPv2 FAST Release` работает только для OWNER same-repo PR, только из `fast/*`, только с `FAST:` title, не более 6 файлов / 120 changed lines и только по whitelist `app/View/pages/**`, `public/assets/css|js/**`, `tests/*_ui_contract_test.php`. Он проверяет changed PHP/JS + cheap UI contracts, fail-closed проверяет base drift, squash-merges exact PR head и dispatch-ит существующий exact-SHA Controlled Deploy. Production SSH secrets в FAST workflow отсутствуют.

Для такого FAST PR агент **не делает вручную** merge, `deploy/erpv2-agent`, canonical-CI polling или отдельный deploy workflow search. Основной full `ERP PLANEX CI` для корректно оформленного FAST PR/FAST merge пропускает runner job, чтобы не дублировать FAST gate.

Цель OWNER для микроправки в несколько строк — стремиться к ~1 минуте wall-clock при свободных runners. Это performance target, а не основание пропускать guards.

### STANDARD
Controller/service/business behavior без production DB/schema mutation и без finance settlement/matching/debt/security semantics.

**STANDARD execution discipline:**
- использовать `standard/<slug>` и один содержательный commit там, где это практично;
- bootstrap: owning Module/Screen → exact controller/service → Business Rule/State только если реально затронуты → owning Test Contract; повторно найденные Notion IDs не искать снова;
- `ERP PLANEX CI` проверяет только changed PHP/JS/Python, всегда оставляет быстрые global architecture/security/control-plane guards и запускает cheap domain regressions только когда их scope затронут;
- path-triggered owning workflows запускаются параллельно самим GitHub. Не запускать их вручную второй раз и не ждать каждый последовательно;
- после PR creation: один aggregated status read после разумного runner window. При failure открыть только failed job; transient failure rerun, а не новый commit;
- после всех required owning checks PASS: merge один раз → exact canonical HEAD один раз → сразу non-force `deploy/erpv2-agent`; guard сам ждёт lightweight exact-SHA canonical CI → один final `erpv2/deploy` read;
- Notion: один final delta write по реально затронутым Module/Screen/Service/Rule/Test records; `01` и broad Health только если durable current state действительно изменился.

Performance target для небольшой локальной STANDARD behavioral change при свободных runners: обычно **~2–4 минуты end-to-end**, без удаления owning regressions.

### CRITICAL
Finance settlement/matching/debt semantics, security/access, DB/schema, financial integrations, control plane, destructive operations.

**CRITICAL execution discipline:**
- использовать `critical/<slug>` и до edit зафиксировать impact set: authoritative service/data/rule/state/security/integration + owning test contracts;
- `full relevant gates` означает **все проверки затронутых критических областей**, а не весь исторический ERP test inventory;
- main `ERP PLANEX CI` остаётся incremental changed-file + global safety gate; Finance Matching / Obligations / Employee / Calendar и другие path-owned critical workflows запускаются GitHub параллельно по реальному diff;
- нельзя вручную сериализовать уже автоматически запущенные critical workflows или добавлять Pxx/browser/runtime «на всякий случай». Manual runtime нужен только если соответствующий Test Contract/риск прямо требует его;
- после PR creation сделать один aggregated status read после ожидаемого окна запуска. Если что-то упало — разбирать конкретный failed job; не делать широкую повторную диагностику green областей;
- merge только после PASS всех реально требуемых critical checks. После merge code-only/backward-compatible change может идти через обычный guarded deploy-ref flow;
- если code требует ещё не применённую production schema/data migration, **не deploy-ить несовместимый code**. Сначала выбрать backward-compatible sequencing либо остановиться перед production write и получить отдельное task-specific OWNER approval;
- production migration/seed/reconciliation/backfill/destructive SQL никогда не наследует code-deploy authorization;
- Memory Pass обязателен, но только по impact set: affected Module/Data/Rule/State/Security/Integration/Test + `01` только при durable current-state change. Не переаудировать соседние домены без evidence of drift.

Performance target для небольшого focused **CRITICAL code-only** change без production DB write при свободных runners: ориентир **~4–7 минут end-to-end**. Для задач с production migration время OWNER approval и самой migration не искусственно оптимизируется.

## HARD WRITE SAFETY

Рабочий путь: `feature branch → PR → safe gate → merge`.

- **Никогда не вызывать `create_file`, `update_file`, `delete_file` или эквивалентную write-операцию с canonical branch.**
- Перед file mutation сначала создать feature branch; connector write всегда явно target-ит её.
- Для FAST branch naming: `fast/<short-slug>`.
- Для STANDARD: `standard/<short-slug>`; для CRITICAL: `critical/<short-slug>`.
- Одна локальная задача + её owning contracts должны по возможности попасть в один содержательный commit, а не commit на каждую микро-правку.
- Canonical изменяется только штатным PR merge; FAST merge выполняет guarded workflow через GitHub merge API.
- Placeholder/temp/probe writes запрещены.
- Empty/technical/release-checkpoint commit ради CI/deploy запрещён.
- Temporary verification PR/workflow запрещён.

## Tool / polling discipline

При рабочем GitHub/Notion connector не переходить к `gh`, `git ls-remote`, web/public HTTP или container fallback для той же операции.

Для всех классов:
- reuse already-discovered connector schemas и known Notion page IDs внутри текущей задачи;
- успешный lookup не повторять без drift/ambiguity;
- Actions не poll-ить каждые несколько секунд: один initial/aggregated read и один terminal read; во время known `pending` использовать разумный backoff;
- не проверять green workflow повторно после того, как новый diff его не затронул;
- не создавать диагностический commit/PR ради observability.

Для FAST дополнительно:
- не читать layout/controller/service «для уверенности», если view + cheap contract уже доказывают scope;
- PR создавать сразу non-draft с title `FAST: <задача>`;
- после PR creation не poll-ить обычный CI: ждать terminal `erpv2/deploy` status exact merged SHA;
- не запускать тяжелые finance/MySQL/Playwright regressions без path/risk причины.

## Production deploy `/erpv2`

OWNER дал standing authorization агентам деплоить собственные merged code/UI изменения без отдельного OWNER-клика.

- **FAST:** `ERPv2 FAST Release` сам выполняет gate → squash merge → exact HEAD → dispatch `ERPv2 Controlled Deploy`.
- **STANDARD/CRITICAL code deploy:** прежний guarded `deploy/erpv2-agent` flow остаётся действующим; агент двигает ref сразу после exact merged HEAD, не ждёт canonical CI вручную.
- `ERPv2 Agent Deploy Request` сам ждёт exact-SHA canonical `ERP PLANEX CI`, повторно проверяет HEAD и fail-closed dispatch-ит Controlled Deploy.
- `ERPv2 Controlled Deploy` остаётся единственным workflow с production write engine и SSH secrets.
- `erpv2/deploy = success` на exact SHA означает PASS встроенных marker/artifact/HTTP/DB fingerprint/legacy `/erp`/env/runtime/migrations=false checks.
- P44/P46/P47/P49/P50/P93 — только task-specific runtime acceptance.

Merge != deploy. Deploy dispatch != deploy PASS.

## Production database boundary

Standing code/UI deploy authorization НЕ включает production migrations, seeds, reconciliation, backfills, destructive SQL или иные production data/schema writes. Они требуют отдельного task-specific OWNER approval. FAST pipeline никогда не выполняет migrations.

## Finance / security invariants

Finance доступен только `company_owner`, пока OWNER не изменит policy. Tenant isolation, auditability, fail-closed reconciliation и decimal money обязательны. Не менять production finance rows ради тестов.

## Notion / Memory Gate

**Notion обязателен.** Ускорение любого класса не отменяет MEMORY PASS.

Default start = один read `24 — AGENT BOOTSTRAP MANIFEST`, затем exact affected records по direct IDs/routing или максимум одному scoped search, если route ещё не существует. `01`, `11`, `22`, `23` не являются обязательными default reads. В процессе Notion не обновлять, кроме доказанного drift/blocker.

### FAST whitelist
После successful FAST deploy выполнить минимальный подтверждённый MEMORY PASS:
1. affected Screen record — **обязательный write** с current semantics/evidence/exact merged SHA;
2. affected cheap Test/Contract record — только если тест реально изменён;
3. handoff для микроправки не обязателен, если Screen record сам полностью хранит delta.

Для обычного FAST не обновлять `00`, `01`, Module/Service/Business Rules/broad Health только из-за нового SHA. Target FAST Notion budget: обычно 1–3 relevant reads и 1–2 writes.

### STANDARD
Обычно достаточно owning Module/Screen/Service/Rule/Test records. Target — примерно **2–5 relevant reads / 1–3 writes**, если задача локальная. Handoff/`01` только при реальном durable state/continuation change.

### CRITICAL
Сначала определить impact set, затем читать/писать только его: Module/Data/Rule/State/Security/Integration/Test. Типичный focused budget — **4–8 relevant reads / 2–5 writes**. Более широкий Memory Pass оправдан только если diff реально пересекает несколько critical domains или обнаружен drift.

`Verified SHA` конкретного record — provenance его семантики, а не global current HEAD. Не переписывать соседние records только потому, что canonical SHA продвинулся.

После write считать MEMORY PASS завершённым, если connector вернул однозначный successful result. Direct readback делать только при ambiguous result или для critical semantics, где независимое подтверждение оправдано. Никогда не заявлять MEMORY PASS без подтверждённого Notion write.

**Definition of Done: `CODE PASS + CI PASS + MEMORY PASS`; production code/UI task additionally requires `DEPLOY PASS`.**

## Source precedence

`latest OWNER decision → current GitHub code/schema/control plane → current MASTER MEMORY → current governing docs → historical evidence`.
