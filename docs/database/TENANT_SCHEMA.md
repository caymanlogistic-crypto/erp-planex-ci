# ERP PLANEX — Tenant Physical Schema

> GENERATED FILE. Do not maintain physical columns manually. Regenerate with `php tools/database/generate_schema_docs.php --write` against a disposable MySQL instance.

**Physical source of truth:** `database/migrations-local/*.sql` + `app/Service/LocalMigrationService.php`.

**Schema fingerprint:** `dc181daddd43c45a02a9c0ae080db7163780e4bad54d6fc805222d2db1a1b57a`

Foreign keys below are **DB-enforced physical relations only**. Logical/application relations are intentionally not inferred: if the database has no FK, this catalog will not invent one. Use owning application code/Notion semantic records for logical relations.

Introducing migration is not inferred from final `information_schema`, because later migrations can rename/rebuild objects. Use Git history/migration history when provenance is needed.

## Domain index

- **Access / Security**: `entity_access_grants`, `legacy_access_projections`, `users`
- **CRM**: `client_contacts`, `clients`, `contractor_assignment_history`, `contractor_contacts`, `contractor_tax_history`, `contractors`, `entity_assignments`, `responsible_assignment_history`
- **Documents**: `document_number_sequences`, `document_template_versions`, `document_templates`, `document_types`, `documents`, `generated_document_requests`, `generated_documents`, `trip_document_events`, `trip_document_incoming`, `trip_document_mail`, `trip_document_mailboxes`, `trip_document_previews`, `trip_document_requests`, `waste_trip_closing_documents`, `waste_trip_completion_documents`
- **Finance**: `bank_accounts`, `bank_daily_balances`, `bank_import_balances`, `bank_import_transactions`, `bank_statement_imports`, `bank_statement_settings`, `bank_transactions`, `finance_audit_log`, `finance_cash_flow_center_dds_categories`, `finance_cash_flow_centers`, `finance_cash_resolutions`, `finance_cash_route_receipts`, `finance_closing_counterparties`, `finance_closing_document_exceptions`, `finance_closing_document_links`, `finance_closing_document_manual_statuses`, `finance_closing_document_requirement_exceptions`, `finance_dds_categories`, `finance_employee_invoice_payments`, `finance_employee_money_accounts`, `finance_employee_movements`, `finance_employee_personal_expenses`, `finance_invoice_links`, `finance_invoices`, `finance_matching_results`, `finance_matching_rules`, `finance_money_accounts`, `finance_obligations`, `finance_operation_allocations`, `finance_operations`
- **Operations / Routes**: `cargo_types`, `linear_route_financial_terms`, `linear_route_payments`, `linear_route_points`, `linear_route_principals`, `linear_route_service_payments`, `linear_route_services`, `linear_routes`, `production_calendar_days`, `production_calendar_years`
- **Other tenant domain**: `entity_responsibilities`, `feo_import_changes`, `feo_imports`, `feo_location_overrides`, `feo_placement_block_requests`, `feo_placement_blocks`, `feo_placement_current`, `feo_request_cost_block_requests`, `feo_request_cost_blocks`, `feo_request_utilizer_overrides`, `feo_requests`, `feo_share_import_requests`, `feo_share_import_totals`, `feo_transfer_batches`, `feo_transfer_items`, `feo_transfer_outbox`, `feo_utilizer_aliases`, `feo_utilizer_match_rules`, `feo_utilizers`, `feo_weight_batches`, `feo_weight_items`, `geocoding_settings`, `max_bots`, `max_chats`, `max_event_deliveries`, `max_event_rules`, `max_message_logs`, `route_executor_contract_mails`, `route_executor_review_cards`, `route_executor_review_sends`, `warehouse_stock_issue_exceptions`, `waste_trip_audit_log`, `waste_trip_carrier_payments`, `waste_trip_closing_finance_links`, `waste_trip_closing_reviews`, `waste_trip_completion_items`, `waste_trip_completion_reviews`, `waste_trip_customer_payments`, `waste_trip_general_payments`, `waste_trip_namespace_state`, `waste_trip_registration_journal`, `waste_trip_requests`, `waste_trip_start_statistics`, `waste_trips`
- **System / Migration metadata**: `schema_migrations`
- **Transport**: `crew_drivers`, `crews`, `driver_phones`, `driver_vehicle_blocks`, `drivers`, `vehicle_sets`, `vehicle_units`

## Detailed generated catalog

Each linked file below is generated from the same disposable-MySQL `information_schema` snapshot. Columns, NULL/defaults, PK/UNIQUE/indexes and physical FKs are maintained there, not manually in Notion.

- [Access / Security](TENANT_SCHEMA_ACCESS_SECURITY.md): `entity_access_grants`, `legacy_access_projections`, `users`
- [CRM](TENANT_SCHEMA_CRM.md): `client_contacts`, `clients`, `contractor_assignment_history`, `contractor_contacts`, `contractor_tax_history`, `contractors`, `entity_assignments`, `responsible_assignment_history`
- [Documents — part 1](TENANT_SCHEMA_DOCUMENTS_01.md): `document_number_sequences`, `document_template_versions`, `document_templates`, `document_types`, `documents`, `generated_document_requests`, `generated_documents`, `trip_document_events`
- [Documents — part 2](TENANT_SCHEMA_DOCUMENTS_02.md): `trip_document_incoming`, `trip_document_mail`, `trip_document_mailboxes`, `trip_document_previews`, `trip_document_requests`, `waste_trip_closing_documents`, `waste_trip_completion_documents`
- [Finance — part 1](TENANT_SCHEMA_FINANCE_01.md): `bank_accounts`, `bank_daily_balances`, `bank_import_balances`, `bank_import_transactions`, `bank_statement_imports`, `bank_statement_settings`, `bank_transactions`, `finance_audit_log`, `finance_cash_flow_center_dds_categories`, `finance_cash_flow_centers`
- [Finance — part 2](TENANT_SCHEMA_FINANCE_02.md): `finance_cash_resolutions`, `finance_cash_route_receipts`, `finance_closing_counterparties`, `finance_closing_document_exceptions`, `finance_closing_document_links`, `finance_closing_document_manual_statuses`, `finance_closing_document_requirement_exceptions`, `finance_dds_categories`
- [Finance — part 3](TENANT_SCHEMA_FINANCE_03.md): `finance_employee_invoice_payments`, `finance_employee_money_accounts`, `finance_employee_movements`, `finance_employee_personal_expenses`, `finance_invoice_links`
- [Finance — part 4](TENANT_SCHEMA_FINANCE_04.md): `finance_invoices`, `finance_matching_results`, `finance_matching_rules`, `finance_money_accounts`, `finance_obligations`
- [Finance — part 5](TENANT_SCHEMA_FINANCE_05.md): `finance_operation_allocations`, `finance_operations`
- [Operations / Routes — part 1](TENANT_SCHEMA_OPERATIONS_ROUTES_01.md): `cargo_types`, `linear_route_financial_terms`, `linear_route_payments`, `linear_route_points`, `linear_route_principals`, `linear_route_service_payments`
- [Operations / Routes — part 2](TENANT_SCHEMA_OPERATIONS_ROUTES_02.md): `linear_route_services`, `linear_routes`, `production_calendar_days`, `production_calendar_years`
- [Other tenant domain — part 1](TENANT_SCHEMA_OTHER_TENANT_DOMAIN_01.md): `entity_responsibilities`, `feo_import_changes`, `feo_imports`, `feo_location_overrides`, `feo_placement_block_requests`, `feo_placement_blocks`, `feo_placement_current`, `feo_request_cost_block_requests`, `feo_request_cost_blocks`, `feo_request_utilizer_overrides`
- [Other tenant domain — part 2](TENANT_SCHEMA_OTHER_TENANT_DOMAIN_02.md): `feo_requests`, `feo_share_import_requests`, `feo_share_import_totals`, `feo_transfer_batches`, `feo_transfer_items`, `feo_transfer_outbox`, `feo_utilizer_aliases`, `feo_utilizer_match_rules`
- [Other tenant domain — part 3](TENANT_SCHEMA_OTHER_TENANT_DOMAIN_03.md): `feo_utilizers`, `feo_weight_batches`, `feo_weight_items`, `geocoding_settings`, `max_bots`, `max_chats`, `max_event_deliveries`, `max_event_rules`, `max_message_logs`
- [Other tenant domain — part 4](TENANT_SCHEMA_OTHER_TENANT_DOMAIN_04.md): `route_executor_contract_mails`, `route_executor_review_cards`, `route_executor_review_sends`, `warehouse_stock_issue_exceptions`, `waste_trip_audit_log`, `waste_trip_carrier_payments`, `waste_trip_closing_finance_links`, `waste_trip_closing_reviews`, `waste_trip_completion_items`
- [Other tenant domain — part 5](TENANT_SCHEMA_OTHER_TENANT_DOMAIN_05.md): `waste_trip_completion_reviews`, `waste_trip_customer_payments`, `waste_trip_general_payments`, `waste_trip_namespace_state`, `waste_trip_registration_journal`, `waste_trip_requests`, `waste_trip_start_statistics`
- [Other tenant domain — part 6](TENANT_SCHEMA_OTHER_TENANT_DOMAIN_06.md): `waste_trips`
- [System / Migration metadata](TENANT_SCHEMA_SYSTEM_MIGRATION_METADATA.md): `schema_migrations`
- [Transport](TENANT_SCHEMA_TRANSPORT.md): `crew_drivers`, `crews`, `driver_phones`, `driver_vehicle_blocks`, `drivers`, `vehicle_sets`, `vehicle_units`
