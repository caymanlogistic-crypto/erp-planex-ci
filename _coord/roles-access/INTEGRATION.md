# ERP PLANEX — Roles & Access Integration Ledger

## Baseline
- START_HEAD: `8b1b4a027eadbe41626f28fcf3c12db338a31b0a`
- canonical branch: `chatgpt/production-stabilization-20260802`
- integration branch: `chatgpt/roles-access-integration-20260903`
- task class: CRITICAL

## Integration policy
No stream is accepted on a chat claim alone. For every stream Tech Lead must verify TASK → REPORT → diff → tests → ownership → Implementation Contract before integration.

No production deploy/migration is allowed by this integration cycle without separate OWNER authorization.

## Accepted streams
### Agent 01 — Discovery
- Status: ACCEPTED (read-only coordination result)
- Task: `_coord/roles-access/tasks/agent-01.md`
- Report: `_coord/roles-access/reports/agent-01.md`
- Code commit: NONE
- DB/migrations: NONE
- Result: ownership seams, canonical Vehicle mapping, auth/menu/finance/migration risks established.

## Pending streams
- Agent 02 — Access Core — NOT STARTED
- Agent 03 — Page Registry / Navigation / Endpoint Mapping — NOT STARTED
- Agent 04 — Membership / Roles / Users — NOT STARTED
- Agent 05 — Data Scope / Assignments — NOT STARTED
- Agent 06 — UI/UX — WAITING BACKEND CONTRACTS
- Agent 07 — Migration / BEFORE↔AFTER / Regression — WAITING SCHEMA CONTRACTS

## Rejected streams
NONE.

## Order of integration
1. Agent 04 persistence/service contracts + Agent 07 approved schema migration implementation must be reconciled without bypassing Agent 07 migration ownership.
2. Agent 03 PageRegistry contract.
3. Agent 02 EffectiveAccessResolver wired to membership + PageRegistry contracts.
4. Agent 05 data-scope/assignments wired after resolver decisions.
5. Integration fix pass: retire remaining hard-coded business-role/finance gates in mapped protected surfaces.
6. Agent 06 UI over stable backend contracts.
7. Agent 07 final migration parity + full regression after integrated code.

The exact commit order may change if reports prove a dependency, but ownership must not.

## Shared/conflicting places
- `app/View/layouts/main.php`: Agent 03 only.
- Access resolver core: Agent 02 only.
- PageRegistry/endpoint mapping: Agent 03 only.
- Membership/role persistence services: Agent 04 only.
- Entity scope/assignment runtime: Agent 05 only.
- Numbered migrations: Agent 07 only.
- Existing object modal UI additions: Agent 06, but backend entity access remains Agent 05.

## Migration order
Reserved:
- central `013+` — Agent 07 only;
- tenant/local `130+` — Agent 07 only.

Migration names/files: NONE YET.
Production migration: FORBIDDEN pending OWNER authorization and final acceptance.

## Tech Lead integration fixes
NONE YET.

## Local preflight
NOT RUN.

## CI
NOT RUN.

## BEFORE ↔ AFTER
NOT RUN.

## Final integration HEAD
Coordination-only branch at this stage; update STATUS after every accepted implementation commit.

## Auditor evidence required before final acceptance
- exact diff from START_HEAD;
- remaining hard-coded role-check scanner;
- registry coverage of protected routes/actions;
- page/menu/backend single-source evidence;
- no-relogin revocation test;
- cross-company isolation tests;
- regulated entity leak tests including search/autocomplete/counters/JSON/API/direct IDs;
- leader invariants;
- finance explicit-page role test;
- Vehicle `vehicle_unit` evidence;
- migration BEFORE/AFTER artifacts + comparator output;
- UI screenshots/behavior including no nested modal;
- local preflight and GitHub CI references.
