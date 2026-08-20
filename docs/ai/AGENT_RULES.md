# ERP PLANEX — правила агента

Актуально на 2026-08-20. Это техническое дополнение к `AGENTS.md`, а не отдельный bootstrap checklist.

## Bootstrap / context budget

Notion default bootstrap: `ERP PLANEX — MASTER MEMORY` → `24 — AGENT BOOTSTRAP MANIFEST` **один раз**, затем только task-owned records по direct IDs/routing.

Exact canonical HEAD всегда читать из GitHub. `01 — CURRENT STATE`, `11 — AGENT BOOTSTRAP PROTOCOL`, `22 — DEPLOY & NOTION LIMITS RUNBOOK`, `23 — CHANGE PLAYBOOKS`, handoffs и historical reports читать только когда конкретная задача требует их контекста.

Запрещён старый broad-start pattern вида `README → AGENTS → control-plane docs → handoff → project state → current task → decisions → documentation index` для каждой локальной задачи. Governing docs открываются по необходимости; `AGENTS.md` остаётся repository enforcement source.

Для UI дополнительно читать `docs/ui/DESIGN_STANDARD.md` только когда меняется системный UI/design contract, а не для каждой локальной текстовой/CSS правки.

## Git / branch discipline

- Repository: `planexerp/erp-planex`.
- Canonical branch: `chatgpt/production-stabilization-20260802`.
- Development path: `feature branch → PR → required gates → merge`.
- Merge by itself never deploys.
- Old `caymanlogistic-crypto/erp-planex` is read-only insurance only.
- Не использовать old Pxx/recovery branches как development/deploy source.
- Direct canonical connector writes, force-push, placeholder/probe files и technical CI commits запрещены.

## Production discipline

- Active runtime: `/erpv2`; legacy `/erp` запрещено менять.
- `ERPv2 Controlled Deploy` остаётся единственным standard production write engine.
- FAST использует guarded FAST release path; STANDARD/CRITICAL code deploy используют guarded `deploy/erpv2-agent` path согласно `AGENTS.md` / Notion `22`.
- Exact-SHA production evidence обязательно.
- Standard deploy не выполняет migrations.
- Production migrations/reconciliation/seeds/backfills/destructive SQL = отдельная task-specific OWNER-authorized operation.

## Schema / tenant rules

- Central DB и `erp_company_{id}` имеют разные обязанности.
- Schema change только numbered migration.
- Local migration numbers уникальны.
- Нельзя выполнять migration DDL из UI/controller/temp web code.
- Tenant isolation и `entity_access_grants` нельзя ослаблять ради convenience.

## Protected domain rules

- Route executor = contractor + driver(s) + vehicle set через `driver_vehicle_blocks` + `crews`.
- Использовать `driver_vehicle_blocks.vehicle_set_id`, не `vehicle_id`.
- Finance = `company_owner` only, включая AJAX/JSON/POST endpoints.
- `storage/companies/*` — persistent user data.

## UI lock

ERP PLANEX: desktop-first, minimum 1440px, table-first, compact industrial density, strict borders/small radii, brown/copper system. Generic SaaS/AdminLTE/Bootstrap restyle запрещён без прямой UI-задачи OWNER.

## Acceptance / memory

Required gates выбираются по фактическому impact set и Test/Contract routing; green соседние домены не переаудируются без drift evidence.

Notion Memory Pass обязателен, но targeted: обновляются только реально затронутые durable records. `01` и handoff — только при durable current-state/continuation change. Exact live HEAD не зеркалируется в broad memory pages.

## Source precedence

`latest OWNER decision → current GitHub code/schema/control plane → durable MASTER MEMORY → current governing docs → historical evidence`.
