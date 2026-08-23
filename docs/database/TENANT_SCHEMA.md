# ERP PLANEX — Tenant Physical Schema

> GENERATED FILE. Do not maintain physical columns manually. Regenerate with `php tools/database/generate_schema_docs.php --write` against a disposable MySQL instance.

**Physical source of truth:** `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `ff6c294f140a67e18ef0bd111c5527cf60c6e892c2e7863c7e7edda32667d681`

Foreign keys below are **DB-enforced physical relations only**. Logical/application relations are intentionally not inferred: if the database has no FK, this catalog will not invent one. Use owning application code/Notion semantic records for logical relations.

Introducing migration is not inferred from final `information_schema`, because later migrations can rename/rebuild objects. Use Git history/migration history when provenance is needed.

## Domain index

- **Access / Security**: `entity_access_grants`, `users`
- **CRM**: `client_contacts`, `clients`, `contractor_assignment_history`, `contractor_contacts`, `contractor_tax_history`, `contractors`, `responsible_assignment_history`
- **Documents**: `document_types`, `documents`
- **Finance**: `bank_accounts`, `bank_daily_balances`, `bank_import_balances`, `bank_import_transactions`, `bank_statement_imports`, `bank_statement_settings`, `bank_transactions`, `finance_audit_log`, `finance_cash_flow_center_dds_categories`, `finance_cash_flow_centers`, `finance_cash_resolutions`, `finance_cash_route_receipts`, `finance_closing_counterparties`, `finance_closing_document_exceptions`, `finance_closing_document_links`, `finance_dds_categories`, `finance_employee_invoice_payments`, `finance_employee_money_accounts`, `finance_employee_movements`, `finance_employee_personal_expenses`, `finance_invoice_links`, `finance_invoices`, `finance_matching_results`, `finance_matching_rules`, `finance_money_accounts`, `finance_obligations`, `finance_operation_allocations`, `finance_operations`
- **Operations / Routes**: `cargo_types`, `linear_route_financial_terms`, `linear_route_payments`, `linear_route_points`, `linear_route_principals`, `linear_route_service_payments`, `linear_route_services`, `linear_routes`, `production_calendar_days`, `production_calendar_years`
- **System / Migration metadata**: `schema_migrations`
- **Transport**: `crew_drivers`, `crews`, `driver_phones`, `driver_vehicle_blocks`, `drivers`, `vehicle_sets`, `vehicle_units`

## Detailed generated catalog

Each linked file below is generated from the same disposable-MySQL `information_schema` snapshot. Columns, NULL/defaults, PK/UNIQUE/indexes and physical FKs are maintained there, not manually in Notion.

- [Access / Security](TENANT_SCHEMA_ACCESS_SECURITY.md): `entity_access_grants`, `users`
- [CRM](TENANT_SCHEMA_CRM.md): `client_contacts`, `clients`, `contractor_assignment_history`, `contractor_contacts`, `contractor_tax_history`, `contractors`, `responsible_assignment_history`
- [Documents](TENANT_SCHEMA_DOCUMENTS.md): `document_types`, `documents`
- [Finance — part 1](TENANT_SCHEMA_FINANCE_01.md): `bank_accounts`, `bank_daily_balances`, `bank_import_balances`, `bank_import_transactions`, `bank_statement_imports`, `bank_statement_settings`, `bank_transactions`, `finance_audit_log`, `finance_cash_flow_center_dds_categories`, `finance_cash_flow_centers`
- [Finance — part 2](TENANT_SCHEMA_FINANCE_02.md): `finance_cash_resolutions`, `finance_cash_route_receipts`, `finance_closing_counterparties`, `finance_closing_document_exceptions`, `finance_closing_document_links`, `finance_dds_categories`, `finance_employee_invoice_payments`
- [Finance — part 3](TENANT_SCHEMA_FINANCE_03.md): `finance_employee_money_accounts`, `finance_employee_movements`, `finance_employee_personal_expenses`, `finance_invoice_links`, `finance_invoices`, `finance_matching_results`
- [Finance — part 4](TENANT_SCHEMA_FINANCE_04.md): `finance_matching_rules`, `finance_money_accounts`, `finance_obligations`, `finance_operation_allocations`
- [Finance — part 5](TENANT_SCHEMA_FINANCE_05.md): `finance_operations`
- [Operations / Routes — part 1](TENANT_SCHEMA_OPERATIONS_ROUTES_01.md): `cargo_types`, `linear_route_financial_terms`, `linear_route_payments`, `linear_route_points`, `linear_route_principals`, `linear_route_service_payments`
- [Operations / Routes — part 2](TENANT_SCHEMA_OPERATIONS_ROUTES_02.md): `linear_route_services`, `linear_routes`, `production_calendar_days`, `production_calendar_years`
- [System / Migration metadata](TENANT_SCHEMA_SYSTEM_MIGRATION_METADATA.md): `schema_migrations`
- [Transport](TENANT_SCHEMA_TRANSPORT.md): `crew_drivers`, `crews`, `driver_phones`, `driver_vehicle_blocks`, `drivers`, `vehicle_sets`, `vehicle_units`
