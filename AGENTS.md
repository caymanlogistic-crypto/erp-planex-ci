# ERP PLANEX — правила работы агентов

Актуально на 2026-08-20. Current code + latest OWNER decision имеют приоритет; исторические Pxx/E7 материалы — evidence, не инструкция.

## Default bootstrap

**Notion обязателен** для каждой material ERP задачи.

Default startup — один read: `ERP PLANEX — MASTER MEMORY` → `00 — START HERE`.

`00` — компактный stable bootstrap: repository/branch boundaries, source precedence, FAST/STANDARD/CRITICAL selector, hard production locks и direct registry data-source IDs. Exact canonical HEAD всегда читать из GitHub; volatile SHA не является bootstrap datum.

После `00` открыть только task-owned records. `01 — CURRENT STATE`, `11 — AGENT BOOTSTRAP PROTOCOL`, `22 — DEPLOY & NOTION LIMITS RUNBOOK`, `23 — CHANGE PLAYBOOKS`, handoffs и historical reports читать только когда конкретная задача реально требует их контекста.

Не делать broad-memory scan. Если registry известен — scoped lookup внутри него; найденный page ID повторно не искать.

Repository: `planexerp/erp-planex`.
Canonical branch: `chatgpt/production-stabilization-20260802`.
Old `caymanlogistic-crypto/erp-planex` = read-only insurance.
Production: `/erpv2`. Legacy `/erp` — never touch.

## CHANGE CLASS

### FAST
Только presentation/view/CSS/text/local UI. FAST недопустим при route/controller behavior, business/state, finance amount/debt/settlement semantics, DB/schema, security/access, integrations или control plane.

Production FAST использует guarded `ERPv2 FAST Release`: owner same-repo `fast/*` + `FAST:` PR → strict scope/size gate → changed-file syntax + cheap UI contracts → exact squash merge → existing `ERPv2 Controlled Deploy`.

FAST whitelist остаётся machine-enforced; агент не расширяет FAST на controller/service/DB/security/finance semantics.

### STANDARD
Controller/service/business behavior без production DB/schema mutation и без critical finance/security semantics.

- branch `standard/<slug>`;
- owning Module/Screen → exact controller/service → Rule/State only if affected → owning Test Contract;
- main CI incremental; path-owned workflows запускаются GitHub параллельно;
- не дублировать автоматически запущенные regressions вручную;
- aggregate required checks instead of serial polling;
- после PASS → merge → exact canonical HEAD once → non-force `deploy/erpv2-agent` → final exact-SHA deploy status.

Target для небольшой локальной behavioral-задачи: примерно `2–4 min`, если runners свободны.

### CRITICAL
Finance settlement/matching/debt, security/access, DB/schema, financial integrations, control plane, destructive operations.

- branch `critical/<slug>`;
- до edit определить impact set: authoritative service/data/rule/state/security/integration + owning test contracts;
- `full relevant gates` = все required gates затронутых critical domains, а не весь исторический ERP inventory;
- heavy owning workflows остаются path-triggered и параллельными;
- runtime/browser acceptance только когда owning Test Contract/risk требует его;
- incompatible code не deploy-ить до required production schema/data operation;
- production migration/seed/reconciliation/backfill/destructive SQL требует отдельного task-specific OWNER approval.

Target focused code-only CRITICAL: примерно `4–7 min`; DB operation/OWNER approval не является speed KPI.

## HARD WRITE SAFETY

Рабочий путь: `feature branch → PR → required gates → merge`.

- **Никогда не вызывать `create_file`, `update_file`, `delete_file` или эквивалентную write-операцию с canonical branch.**
- Перед mutation сначала создать/подтвердить feature branch; connector write явно target-ит её.
- Placeholder/probe/temp files запрещены.
- Empty/technical/release-checkpoint commit ради CI/deploy запрещён.
- Temporary verification PR/workflow запрещён.
- Одна локальная задача + owning contracts должны по возможности попасть в один содержательный commit.

## Tool / Actions discipline

- При рабочем GitHub/Notion connector не переходить к `gh`, `git ls-remote`, web/public HTTP или container fallback для той же операции.
- Reuse discovered connector schemas и known Notion page IDs.
- Successful lookup не повторять без drift/ambiguity.
- Parallel Actions не ждать последовательно; один aggregated gate, затем разбор только failed job.
- Не переаудировать green unrelated domains без нового evidence.

## Production boundaries

- FAST: guarded FAST release.
- STANDARD/CRITICAL merged code/UI: existing guarded `deploy/erpv2-agent` flow.
- `ERPv2 Controlled Deploy` остаётся единственным production write/SSH engine.
- `erpv2/deploy=success` exact SHA = routine deploy PASS evidence.
- Merge != deploy. Deploy != migration.
- DB fingerprint, legacy `/erp` fingerprint, `.env`/runtime protection, backup и migrations=false contract не отключать ради скорости.
- Production migrations/reconciliation/seeds/backfills/destructive SQL не наследуют standing code deploy authorization.

## Finance / security invariants

Finance доступен только `company_owner`, пока OWNER не изменит policy. Tenant isolation, auditability, fail-closed reconciliation и decimal money обязательны. Production finance rows не менять ради тестов.

## Notion / Memory Gate

**Notion обязателен.** Ускорение любого класса не отменяет MEMORY PASS.

Default read = `00 — START HERE` + только affected registry records.

- FAST: affected Screen mandatory; cheap Test/Contract only if changed.
- STANDARD: affected Module/Screen/Service/Rule/Test only.
- CRITICAL: explicit affected Module/Data/Rule/State/Security/Integration/Test impact set.
- `01 — CURRENT STATE` только при durable project/current-state change, не потому что HEAD продвинулся.
- Handoff только при durable cross-agent continuation fact.
- Broad Memory Health только если реально изменился health/coverage scope.
- `Verified SHA` record = provenance его семантики, а не global current HEAD.
- ordinary successful targeted update не требует readback, если response однозначный; critical DB/security/finance semantics желательно read back.
- при невозможности exact verification → `NEEDS REVERIFY`, а не выдуманный VERIFIED.

**Definition of Done: `CODE PASS + CI PASS + MEMORY PASS`; production code/UI additionally requires `DEPLOY PASS`.**

## Source precedence

`latest OWNER decision → current GitHub code/schema/control plane → durable MASTER MEMORY → current governing docs → historical evidence`.
