# ERP PLANEX — правила работы агентов

Актуально на 2026-08-20. Current code + latest OWNER decision имеют приоритет; исторические Pxx/E7 материалы — evidence, не инструкция.

## Быстрый bootstrap

Notion обязателен для каждой material ERP задачи. В начале один раз прочитать `ERP PLANEX — MASTER MEMORY`: `00 — START HERE`, актуальный блок `01 — CURRENT STATE`, `11 — AGENT BOOTSTRAP PROTOCOL`, затем только релевантную запись/`23 — CHANGE PLAYBOOKS`. Не делать broad-memory scan.

После этого проверить current canonical HEAD и изучать только затронутую цепочку. Для чистого UI FAST-path: `Screen record → exact view/assets → cheap UI contract`.

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
Controller/service/business behavior без production DB/schema mutation. Использовать owning module/service/rule/test contracts. После merge — обычный guarded `deploy/erpv2-agent` flow.

### CRITICAL
Finance settlement/matching/debt semantics, security/access, DB/schema, financial integrations, control plane, destructive operations. Полный релевантный gate set. Production DB write всё равно требует отдельного task-specific OWNER approval.

## HARD WRITE SAFETY

Рабочий путь: `feature branch → PR → safe gate → merge`.

- **Никогда не вызывать `create_file`, `update_file`, `delete_file` или эквивалентную write-операцию с canonical branch.**
- Перед file mutation сначала создать feature branch; connector write всегда явно target-ит её.
- Для FAST branch naming: `fast/<short-slug>`.
- Одна микроправка + её cheap contract должны по возможности попасть в один atomic commit.
- Canonical изменяется только штатным PR merge; FAST merge выполняет guarded workflow через GitHub merge API.
- Placeholder/temp/probe writes запрещены.
- Empty/technical/release-checkpoint commit ради CI/deploy запрещён.
- Temporary verification PR/workflow запрещён.

## FAST tool discipline

При рабочем GitHub/Notion connector не переходить к `gh`, `git ls-remote`, web/public HTTP или container fallback для той же операции.

Для FAST:
- не читать layout/controller/service «для уверенности», если view + cheap contract уже доказывают scope;
- PR создавать сразу non-draft с title `FAST: <задача>`;
- после PR creation не poll-ить обычный CI: ждать terminal `erpv2/deploy` status exact merged SHA;
- не запускать тяжелые finance/MySQL/Playwright regressions без path/risk причины.

## Production deploy `/erpv2`

OWNER дал standing authorization агентам деплоить собственные merged code/UI изменения без отдельного OWNER-клика.

- **FAST:** `ERPv2 FAST Release` сам выполняет gate → squash merge → exact HEAD → dispatch `ERPv2 Controlled Deploy`.
- **STANDARD/CRITICAL code deploy:** прежний guarded `deploy/erpv2-agent` flow остаётся действующим.
- `ERPv2 Controlled Deploy` остаётся единственным workflow с production write engine и SSH secrets.
- `erpv2/deploy = success` на exact SHA означает PASS встроенных marker/artifact/HTTP/DB fingerprint/legacy `/erp`/env/runtime/migrations=false checks.
- P44/P46/P47/P49/P50/P93 — только task-specific runtime acceptance.

Merge != deploy. Deploy dispatch != deploy PASS.

## Production database boundary

Standing code/UI deploy authorization НЕ включает production migrations, seeds, reconciliation, backfills, destructive SQL или иные production data/schema writes. Они требуют отдельного task-specific OWNER approval. FAST pipeline никогда не выполняет migrations.

## Finance / security invariants

Finance доступен только `company_owner`, пока OWNER не изменит policy. Tenant isolation, auditability, fail-closed reconciliation и decimal money обязательны. Не менять production finance rows ради тестов.

## Notion / Memory Gate — FAST whitelist

**Notion обязателен.** Ускорение FAST не отменяет MEMORY PASS.

В начале — один bootstrap read + exact affected Screen/Test records по known IDs или одному scoped search. В процессе Notion не обновлять.

После successful FAST deploy выполнить минимальный подтверждённый MEMORY PASS:
1. affected Screen record — **обязательный write** с current semantics/evidence/exact merged SHA;
2. affected cheap Test/Contract record — только если тест реально изменён;
3. handoff для микроправки не обязателен, если Screen record сам полностью хранит delta.

Для обычного FAST не обновлять `00`, `01`, Module/Service/Business Rules/broad Health только из-за нового SHA. Exact HEAD всегда брать из GitHub.

Target FAST Notion budget: обычно 1–3 relevant reads и 1–2 writes. Использовать уже известные page IDs. После write считать MEMORY PASS только если tool вернул успешный result; при ambiguous response сделать один direct fetch/readback. Никогда не заявлять MEMORY PASS без подтверждённого Notion write.

STANDARD/CRITICAL обновляют только реально затронутые rules/state/security/flow/health records.

**Definition of Done: `CODE PASS + CI PASS + MEMORY PASS`; production code/UI task additionally requires `DEPLOY PASS`.**

## Source precedence

`latest OWNER decision → current GitHub code/schema/control plane → current MASTER MEMORY → current governing docs → historical evidence`.
