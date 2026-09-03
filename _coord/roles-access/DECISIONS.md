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

---

## D-017 — Existing `company_users` becomes global identity authority

**Problem**

At START_HEAD authentication has two incompatible company-user paths:

- `company_owner` authenticates against central `company_users`;
- logist/senior_logist authentication scans every tenant `users` table and becomes ambiguous if the same login exists in several companies.

At the same time `UserSyncService` already treats central `company_users` as the central registry for ordinary users, while company-owner CRUD creates only the central row.

**Decision**

Do **not** create another parallel global-account table. After cutover the existing central `company_users` row is the one global authentication/profile identity.

Its legacy columns `company_id`, `role` and `status` remain compatibility/migration fields only and must cease to be the authority for current company access.

New central `company_memberships` rows provide company-specific state:

- `company_id`;
- global `user_id` → `company_users.id`;
- `role_id`;
- membership status;
- personal page/data overrides;
- mapping to a tenant-local actor projection when legacy tenant code/FKs require it.

Unique business membership invariant: `(company_id, user_id)`.

Authentication stays on the existing `/login` mechanism but is changed to authenticate `company_users` once and then resolve one of that account's active memberships. Tenant `users` tables are no longer authentication authorities.

**Migration safety**

Existing central logins are already treated as globally unique by current owner/superadmin create flows. Migration/preflight must nevertheless detect duplicate/conflicting normalized central logins and tenant-only drift; it must block rather than guess identity merges.

**Agents affected**

02, 04, 07.

---

## D-018 — Tenant `users` is a technical actor projection after cutover

**Problem**

Tenant business schema contains real references to local `users.id` (creator/ownership/audit relations; Waste Trip has an explicit FK). Current company-owner rows may have no tenant `users` row at all because owner creation writes only central `company_users`, and current reconciliation intentionally skips `company_owner`.

Replacing every local actor reference with a cross-database global ID would be a broad unrelated schema rewrite and would destabilize existing ERP modules.

**Decision**

Keep tenant `users` as a compatibility **actor projection**, not as a second account or authentication source.

Each normal company membership that needs to act in tenant business operations has a stable `tenant_user_id` mapping. Existing local rows are reused where trustworthy login/company reconciliation proves the mapping. Missing leader actor projections are created/backfilled by the controlled migration/synchronization path before creator/assignment semantics rely on them.

The projection may cache legacy display/profile fields required by existing tenant code, but:

- password/login data there is not an authentication authority after cutover;
- role/status there is not an authorization authority after cutover;
- the canonical global profile remains central `company_users`;
- the canonical company role/status remains `company_memberships`;
- no second independently managed company account is created.

Session/runtime must distinguish identifiers explicitly (`global_user_id`, `membership_id`, `tenant_user_id`) instead of overloading one ambiguous `user_id`. Legacy `user_id` compatibility may temporarily point to `tenant_user_id` only where old tenant business code requires it; security decisions must use membership/resolver state.

**Reason**

This preserves existing tenant FKs and creator/audit semantics while enabling one global account in several companies and ensuring a Руководитель can also be the concrete creator for assignment rules.

**Agents affected**

02, 04, 05, 07.
