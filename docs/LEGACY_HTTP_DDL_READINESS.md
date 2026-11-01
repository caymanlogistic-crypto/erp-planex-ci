# Legacy HTTP DDL readiness

This diagnostic gates removal of request-time schema creation from one selected HTTP
route family at a time. It does not apply migrations or inspect business rows.
The selected family must be ready on every active tenant. A missing check means
that family's runtime patch cannot yet be released; it does not classify an
unselected family as defective.

| Scope | Reachable fallback owners | Journal prerequisites | Physical checks |
| --- | --- | --- | --- |
| `logists` | `LogistActions/*` through `LogistController` and `company_logists.php` | 001, 008, 009, 010 | Tenant DB connection; `users.id/created_by_user_id/created_by_role/position`; `entity_access_grants` |
| `clients` | `ClientService`, `ClientContactService` through company client routes | 002, 007, 008, 009, 024, 025, 031 | `clients`, `documents`, `entity_access_grants`, `client_contacts`, `document_types`; document creator/type columns |
| `transport` | `company_crews_legacy.php`, `company_vehicles.php`, `company_driver_vehicle_blocks_legacy.php`, Contractor/RouteExecutor/ResponsibleAssignment/VehicleSet actions and Contractor/ContractorContact/Driver/VehicleSet services | 003, 004, 005, 006, 007, 008, 009, 012, 016, 017, 018, 024, 025 | `contractors`, `drivers`, `vehicle_units`, `crews`, `documents`, `entity_access_grants`, `contractor_contacts`, `vehicle_sets`, `driver_vehicle_blocks`, `document_types`; creator/type columns |
| `linear` | `LinearTripActions/create_submit.php` and `LinearTripController::ensureLegacyPaymentDueCompatibility()` | 007, 008, 024, 025, 040, 041, 059, 060, 061 | `documents`, `document_types`, `linear_route_financial_terms`, `linear_route_payments`; both `payment_due_type` columns nullable |

Migration 005 creates `vehicles`; migration 016 renames it to
`vehicle_units`, which the active transport routes use. The 008 creator columns
and 010 user position column are separate numbered changes. The linear route
handler can issue an `ALTER TABLE` when either legacy due-type column is
nonnullable, so journal presence alone is insufficient.

The guarded workflow requires the exact current canonical source SHA and exact
deployed marker, connects to each active tenant database, starts read-only
transactions, and emits only the selected scope and aggregate counts. Missing
tenant DB connection fails the run before a READY result. Run this workflow only
for a route family whose DDL removal patch is ready for release.
