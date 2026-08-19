# ERP PLANEX — Finance / accounting handoff

Updated: 2026-08-19.

Focused entry point for finance/accounting work. Read with `AGENTS.md`, `HANDOFF_FOR_NEW_AGENT.md`, `PROJECT_STATE.md`, `CURRENT_TASK.md`, `DECISIONS.md` and current code/schema.

## Scope and access

Finance is tenant data and remains restricted to `company_owner`. Do not broaden access during UI/refactor work. `logist`/`senior_logist` denial and tenant isolation are security regression gates.

Current code contains bank accounts/statements, bank XLSX import, legacy cash-related code, invoices, payments/operations, allocations/actions/history, cash-flow categories/reports/settings and trip-linked financial data. Inspect the actual current route/controller/view set before each change.

## Latest product direction

OWNER direction is to simplify the finance model around obligations, invoices and actual bank/cash receipt/payment events rather than preserving a separate conceptual "Касса" subsystem for its own sake. Legacy `FinanceCash*` code may still exist and is evidence of current implementation, not proof that the product concept must remain unchanged. Do not delete or migrate it without an explicit scoped implementation task.

## Primary code to inspect

- `app/Http/Controllers/Company/BankFinanceController.php`
- `app/Http/Controllers/Company/BankFinanceActions/`
- `app/Http/Controllers/Company/FinanceCashController.php` and `FinanceCashActions/` when legacy cash behavior is relevant
- other `Finance*` controllers/actions
- finance services under `app/Service/`
- `BankStatementXlsxParser.php`
- `BankStatementSettingsService.php`
- finance views/assets/routes
- `app/Support/entrypoint_dependencies.php`
- finance migrations/tables/tests

Do not assume service autoloading. Web runtime dependencies must be explicit where the application requires them.

## Established finance invariants

- Finance access: `company_owner` only.
- Tenant isolation and fail-closed lookup behavior are mandatory.
- Financial history must remain auditable.
- Imported bank rows, matches and allocations must not be silently rewritten by UI work.
- Money is decimal numeric data. Never strip decimal separators from persisted money.
- Production finance rows must not be mutated to manufacture tests.

## Data / DB discipline

ERP is multi-tenant. Central DB contains global/control data; operational company data lives in `erp_company_{id}`. Identify DB ownership before schema work.

Any schema change requires a numbered migration. Standard deploy does not run migrations. Production migration/reconciliation is a separate manual-only operation requiring explicit OWNER approval. Never run ad-hoc ALTER/CREATE from controller/temp web code.

## Bank statement import

Before modifying import preserve:

1. source row identity/deduplication;
2. amount sign/direction semantics;
3. account/counterparty/INN extraction;
4. fail-closed behavior for malformed input;
5. repeated-import safety;
6. absence of hidden production side effects.

## History / actions

Preserve the security contract:

- same-tenant existing/empty operation -> 200;
- nonexistent operation -> 404;
- foreign-tenant operation -> 404/no leakage;
- unauthorized logistics role -> 403.

Test persisted state after allocation/cancel/history/action changes, not only rendering.

## UI rules

Use ERP PLANEX visual language: desktop-first, table-first, compact industrial density, brown/copper accent, small radii, strict alignment. Reuse system patterns. Visual-only work must not silently alter business semantics.

## Development / production contract

Repository: `planexerp/erp-planex`.
Canonical/default branch: `chatgpt/production-stabilization-20260802`.

Engineering path:

`branch -> PR -> safe CI -> merge`

Merge never deploys.

Production `/erpv2` deploy is a separate manual exact-SHA operation requiring explicit OWNER approval. Standard deploy does not run migrations. Any production finance schema/data migration is a separate manual-only OWNER-approved operation.

Never report a commit/green CI/merge as production READY. Report repository state and production state separately.

## First step for a finance task

Inspect only the affected finance flow and produce a compact map `screen -> route -> controller/action -> service -> tables -> tests`, plus relevant security/data invariants. Do not spend context rebuilding the whole ERP map unless actual drift is found.
