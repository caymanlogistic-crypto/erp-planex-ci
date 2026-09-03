# ERP PLANEX — Roles & Access DECISIONS

All decisions below are Tech Lead integration decisions derived from the OWNER Implementation Contract and read-only discovery at `START_HEAD=8b1b4a027eadbe41626f28fcf3c12db338a31b0a`.

## D-001 — Identity and membership boundary

**Decision:** target identity model is one global authentication identity with zero or more company memberships. Role/status/overrides/assignments belong to `(user_id, company_id)`, not to the global identity.

**Reason:** current auth flow duplicates/branches identity semantics and cannot represent one account with independent roles in multiple companies safely.

**Constraint:** authentication/password recovery remains on the existing canonical authentication mechanism; this project does not create a second login system.

---

## D-002 — Protected company leader

**Decision:** `Руководитель` is represented by a stable system role key/flag, not only a display name.

**Invariant:** a company must always retain at least one active leader membership. Backend must reject disable/role-change/removal that would remove the last active leader.

**Invariant:** leader cannot be restricted by personal page/data overrides.

---

## D-003 — superadmin remains outside company roles

**Decision:** existing `superadmin` remains a separate technical system mechanism and is not inserted into company role tables/membership assignments.

**Consequences:** it does not appear in role constructor, normal employee assignment lists or object assignments.

---

## D-004 — One effective-access resolver

**Decision:** there will be one canonical resolver for protected company requests.

Order of evaluation:

1. authenticated global user;
2. active company context;
3. company membership;
4. membership active status;
5. technical superadmin handling through existing system boundary;
6. protected leader override;
7. current role;
8. role page permission;
9. personal page override;
10. server-owned endpoint→page mapping;
11. role data-scope when regulated;
12. personal data-scope override;
13. assignment/creator enforcement for `ONLY_OWN`;
14. unknown protected state → deny.

**Important:** role/page permissions are read from current persistence on each protected request or through an invalidation-safe request-scoped path. A login-time permission snapshot must not make revocation wait for re-login.

---

## D-005 — One page registry

**Decision:** one canonical PageRegistry owns stable `page_key`, menu/group metadata and protected endpoint mapping.

**Consequences:**

- admin page matrix is generated from this registry;
- navigation is generated/filtered from the same registry;
- server endpoints accept only server-registered page keys;
- client-supplied arbitrary `page_key` can never authorize an endpoint;
- protected endpoint with missing mapping is fail-closed;
- rename/regroup does not change `page_key`.

---

## D-006 — Shared endpoint semantics

**Decision:** a shared endpoint may register an OR-set of allowed page keys, but the set is defined server-side for that exact operation.

Page authorization does not bypass data-scope. Entity-level checks run after page-level allow.

---

## D-007 — Regulated data-scope is intentionally narrow

Only these types receive `ALL / ONLY_OWN / NONE`:

- Client;
- Contractor;
- canonical single Vehicle;
- Driver;
- Linear Trip;
- Waste Trip.

FEO remains page-only in this version. There is no universal finance scope and no VehicleSet scope.

---

## D-008 — Canonical Vehicle technical identity

**Discovery result before any schema change:**

- canonical tenant table: `vehicle_units`;
- primary key: `id`;
- canonical user-facing route family: `app/Http/Routes/company_vehicles.php` (`company_vehicles*` behavior);
- existing grant/entity technical identifier: `vehicle_unit`;
- migration history starts from legacy table name `vehicles` and runtime migration renames/normalizes it to `vehicle_units`;
- VehicleSet / crew / driver-vehicle blocks are separate structures and are not a second `ТС` scope.

**Decision:** new access/assignment logic must use `vehicle_units` + `vehicle_unit`. Do not introduce `entity_type='vehicle'`, a new Vehicle table or a parallel identifier.

---

## D-009 — Assignments are many-to-many and current-state ownership

**Decision:** Client/Contractor/Vehicle/Driver `ONLY_OWN` is determined by explicit current assignments, not immutable creator ownership.

- many users may be assigned to one object;
- new object created by a concrete company user auto-assigns that same user, including a leader;
- superadmin/system/import without a trustworthy company-user creator creates no synthetic assignment;
- changing scope/page/role/status does not delete assignments;
- only a leader may mutate assignments;
- no assignment propagation Contractor↔Vehicle↔Driver.

---

## D-010 — Trips use creator scope, not assignments

Linear Trip and Waste Trip `ONLY_OWN` = created by the current membership user according to their canonical creator field/relationship.

Trips do not appear in assignment management and receive no manual assignment table.

---

## D-011 — Historical access grants are migration input, not a second runtime ACL

Existing ownership/grant structures may be used only when they are trustworthy evidence for BEFORE state or initial assignments.

After cutover there must not be two competing runtime authorization engines.

---

## D-012 — Finance owner-only hard gates must be retired where page access is explicit

Current code/memory contains historical `company_owner`-only finance guards. The OWNER contract supersedes that behavior for explicitly registered/allowed finance pages.

**Decision:** a normal company role with explicit access to a finance page must not be denied only because its legacy role code is not `company_owner`.

**Constraint:** this does not create a universal finance data-scope. Each protected finance page/action must be mapped in PageRegistry and use normal tenant isolation.

---

## D-013 — Schema location and migration ownership

**Central DB** stores global identity/company-membership/role access structures that must be resolvable before or while selecting tenant context.

**Tenant DB** stores tenant-native object assignments/data where the canonical entity lives, unless Agent 07 proves a safer existing central relation is already canonical.

Final table placement must be proven by Agent 07 against onboarding/migration/runtime code before writing migrations.

Only Agent 07 owns numbered migration files.

Reserved migration starts:

- central: `013` (current chain ends at `012_add_company_bank_requisites.sql`);
- tenant/local: `130` (current chain ends at `129_document_generator_auto_number_and_bank_inn.sql`).

---

## D-014 — UI contract

Single company admin section: `Роли и доступы` with tabs `Роли | Пользователи | Закрепления`.

- Role and User editing: one wide ERP modal, one final Save action, sticky header/footer, inner scrolling.
- Assignments: checkbox saves immediately, rollback UI state on error, no global Save.
- Existing object card/modal: leader assignment block is embedded in the same modal; no modal-over-modal.
- UI uses current PLANEX dense table/modal language, not a new admin UI kit.

---

## D-015 — Migration acceptance gate

Migration must produce comparable BEFORE and AFTER access snapshots for every existing `(user, company)` and for:

- page keys;
- Client;
- Contractor;
- Vehicle;
- Driver;
- Linear Trip;
- Waste Trip.

Any unexplained expansion or restriction is a migration failure.

No historical assignment may be invented merely to satisfy the new model.

---

## D-016 — Production gate

No production migration/deploy is permitted by this coordination phase.

The implementation is not ready for OWNER final review until all three are proven:

- CODE PASS;
- CI PASS;
- MIGRATION BEFORE↔AFTER PASS.
