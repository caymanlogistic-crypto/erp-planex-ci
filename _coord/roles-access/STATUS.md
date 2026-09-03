# ERP PLANEX — Roles & Access STATUS

## Baseline

- START_HEAD: `8b1b4a027eadbe41626f28fcf3c12db338a31b0a`
- canonical branch: `chatgpt/production-stabilization-20260802`
- integration branch: `chatgpt/roles-access-integration-20260903`
- current integration HEAD before this STATUS update: `e4ca493be78d84817ead4d8560625f3bf446a130`
- task class: `CRITICAL`
- MEMORY IMPACT: `YES` — auth/security, users/membership, roles/access, page registry, regulated data-scope, assignments, finance page gates, migrations/contracts.

## Этапы

- DONE — governance bootstrap `00 — START HERE` loaded once.
- DONE — START_HEAD captured once.
- DONE — relevant current Notion records inspected.
- DONE — read-only DISCOVERY / architecture pass.
- DONE — mandatory canonical Vehicle technical discovery before schema changes.
- DONE — full OWNER Implementation Contract mirrored in `_coord/roles-access/00_IMPLEMENTATION_CONTRACT.md`, sections 1–99 verified.
- DONE — OWNERSHIP and DECISIONS frozen for implementation start.
- DONE — TASK files Agent 01–07 created.
- DONE — INTEGRATION ledger created.
- DONE — dedicated implementation branches Agent 02–07 created from exact START_HEAD.
- BLOCKED — independent coding-subagent execution is not available in this chat runtime; no fake agent execution will be claimed. Any implementation performed here must be transparently identified as sequential Tech Lead execution under the frozen stream ownership, and independent auditor review remains mandatory.
- NOT STARTED — implementation code streams 02–07.
- NOT STARTED — local preflight / CI / BEFORE↔AFTER / final review.

## Агенты / потоки

- Agent 01 → Discovery / architecture contract → `PASS / ACCEPTED` → read-only at START_HEAD → report: `_coord/roles-access/reports/agent-01.md`.
- Agent 02 → Access Core → `READY / NOT STARTED` → branch `chatgpt/roles-access-agent-02-access-core-20260903`.
- Agent 03 → Page Registry / Navigation / Endpoint Mapping → `READY / NOT STARTED` → branch `chatgpt/roles-access-agent-03-page-registry-20260903`.
- Agent 04 → Membership / Roles / Users → `READY / NOT STARTED` → branch `chatgpt/roles-access-agent-04-membership-users-20260903`.
- Agent 05 → Data Scope / Assignments → `READY / NOT STARTED` → branch `chatgpt/roles-access-agent-05-data-scope-20260903`.
- Agent 06 → UI/UX → `WAITING BACKEND CONTRACTS` → branch `chatgpt/roles-access-agent-06-uiux-20260903`.
- Agent 07 → Migration / BEFORE↔AFTER / Regression → `WAITING SCHEMA CONTRACTS` → branch `chatgpt/roles-access-agent-07-migration-regression-20260903`; sole migration-chain owner.

All branches above were created from exact `START_HEAD=8b1b4a027eadbe41626f28fcf3c12db338a31b0a`.

## Ключевые решения

See `_coord/roles-access/DECISIONS.md`.

- D-001: one global identity + company membership.
- D-002: protected Руководитель identified by stable system key/flag.
- D-003: superadmin stays outside company roles.
- D-004: one EffectiveAccessResolver, fail-closed, current permissions on protected requests.
- D-005/D-006: one PageRegistry + server-owned endpoint mapping/shared OR-set.
- D-007: regulated data-scope only for six approved domains.
- D-008: canonical Vehicle = `vehicle_units`, PK `id`, runtime entity type `vehicle_unit`.
- D-009/D-010: reference objects use many-to-many assignments; trips use creator scope.
- D-011: legacy grants are migration evidence/input, not second runtime ACL.
- D-012: old finance owner-only hidden gate must not override explicit new page access.
- D-013: migration ownership centralized; central starts `013`, tenant/local `130`.
- D-014: one compact PLANEX-style roles/access UI; no nested modal.
- D-015/D-016: BEFORE↔AFTER mandatory; no production action before CODE+CI+MIGRATION gates and OWNER approval.

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
- Runtime implementation tests: NOT RUN.
- Local executable preflight: NOT RUN.
- GitHub CI: NOT RUN.
- BEFORE↔AFTER: NOT RUN.

## Риски / blockers

1. No genuine independent coding-subagent spawn capability in this runtime. Independent audit is therefore a hard final requirement, not optional evidence.
2. Current authorization is fragmented between session role checks, hard-coded route/controller guards, layout/menu branches and legacy grants.
3. Current AuthController identity flow is incompatible with target multi-company membership and must be migrated without creating a second authentication mechanism.
4. Finance has historical `company_owner` gates that conflict with explicit new page access.
5. Legacy `entity_access_grants` must not remain a second runtime ACL after cutover.
6. `_coord` is coordination evidence and must not accidentally become a production artifact/merge payload unless explicitly handled according to repository policy.

## Следующий шаг

1. Execute backend implementation streams under frozen ownership, beginning with membership/schema contract, PageRegistry and resolver seams.
2. Agent 07 remains sole numbered migration owner after schema requirements are reconciled.
3. UI starts only after backend contracts stabilize.
4. Every implementation stream must produce REPORT evidence before integration.
5. Final output must be `_coord/roles-access/FINAL_REVIEW.md` written for independent auditor verification, with all failures/limitations disclosed.
