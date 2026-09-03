# ERP PLANEX — Roles & Access STATUS

## Baseline

- START_HEAD: `8b1b4a027eadbe41626f28fcf3c12db338a31b0a`
- canonical branch: `chatgpt/production-stabilization-20260802`
- integration branch: `chatgpt/roles-access-integration-20260903`
- current integration HEAD: coordination-only branch; update after each accepted integration
- task class: `CRITICAL`
- MEMORY IMPACT: `YES` — auth/security, users/membership, roles/access, page registry, regulated data-scope, assignments, finance page gates, migrations/contracts.

## Этапы

- DONE — governance bootstrap `00 — START HERE` loaded once.
- DONE — START_HEAD captured once.
- DONE — relevant current Notion records inspected.
- DONE — read-only DISCOVERY / architecture pass.
- DONE — mandatory canonical Vehicle technical discovery before schema changes.
- DONE — migration chain numbers identified.
- IN PROGRESS — coordination package / ownership / decisions / agent tasks.
- BLOCKED — independent implementation agents cannot be spawned by the current chat runtime; no fake agent execution is permitted.
- NOT STARTED — code implementation streams 02–07.
- NOT STARTED — local preflight / CI / BEFORE↔AFTER / final review.

## Агенты

- Agent 01 → Discovery / architecture contract → `PASS` → read-only at START_HEAD → report: `_coord/roles-access/reports/agent-01.md`.
- Agent 02 → Access Core → `READY / NOT STARTED` → dedicated branch/worktree required.
- Agent 03 → Page Registry / Navigation / Endpoint Mapping → `READY / NOT STARTED` → dedicated branch/worktree required.
- Agent 04 → Membership / Roles / Users → `READY / NOT STARTED` → dedicated branch/worktree required.
- Agent 05 → Data Scope / Assignments → `READY / NOT STARTED` → dedicated branch/worktree required.
- Agent 06 → UI/UX → `WAITING BACKEND CONTRACTS` → dedicated branch/worktree required.
- Agent 07 → Migration / BEFORE↔AFTER / Regression → `WAITING SCHEMA CONTRACTS` → dedicated branch/worktree required; sole migration-chain owner.

## Ключевые решения

See `_coord/roles-access/DECISIONS.md`.

- D-001: one global identity + company membership.
- D-002: protected Руководитель identified by stable system key/flag.
- D-003: canonical Vehicle = `vehicle_units`, PK `id`, runtime entity type `vehicle_unit`.
- D-004: one PageRegistry + one EffectiveAccessResolver; protected requests fail closed.
- D-005: superadmin remains separate technical mechanism.
- D-006: migration numbers centrally assigned: central `013+`, tenant/local `130+`.
- D-007: old finance owner-only hidden gate must not override explicit new page access.

## Миграции

- Existing central chain ends at `012_add_company_bank_requisites.sql`.
- Reserved next central number: `013`.
- Existing tenant/local chain ends at `129_document_generator_auto_number_and_bank_inn.sql`.
- Reserved next tenant/local number: `130`.
- New migration files: `NONE`.
- Owner: Agent 07 only. Other streams must submit schema requirements, not create competing migration files.
- Production migration: `FORBIDDEN` without separate OWNER authorization.

## Тесты

- Discovery/static inspection: PASS for architectural mapping only.
- Vehicle canonical mapping: PASS.
- Runtime tests: NOT RUN.
- Local executable preflight: NOT RUN.
- GitHub CI: NOT RUN.
- BEFORE↔AFTER: NOT RUN.

## Риски / blockers

1. Current runtime has no independent coding-subagent spawn capability. Agent 02–07 cannot honestly be claimed as executed here.
2. Current File Library adapter exposes the business Implementation Contract as searchable/clickable text but truncates the raw full-file read; an exact no-rewrite repository mirror must be completed before implementation agents are allowed to start.
3. Current authorization is fragmented between session role checks, hard-coded route/controller guards, layout/menu branches and legacy grants.
4. Current AuthController identity flow is incompatible with target multi-company membership and must be migrated without breaking authentication.
5. Finance has historical `company_owner` gates that conflict with the new OWNER contract.
6. Legacy `entity_access_grants` must not remain a second runtime ACL after cutover.

## Следующий шаг

1. Finish exact working copy of the business Implementation Contract in `_coord/roles-access/00_IMPLEMENTATION_CONTRACT.md`.
2. Freeze OWNERSHIP and TASK files.
3. Dispatch Agent 02–07 only in an environment that can run genuinely independent coding agents/worktrees.
4. Integrate only after TASK→REPORT review.
