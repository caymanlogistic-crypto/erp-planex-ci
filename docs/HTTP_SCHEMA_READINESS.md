# HTTP schema readiness for parties and transport

Ordinary company HTTP requests do not provision tenant schema. `applyLocalMigrations()` is intentionally a no-op outside CLI maintenance and the explicit superadmin company provisioning endpoint. The old client, contractor, driver, vehicle-set and contact services nevertheless contained direct `CREATE`, `ALTER` or `RENAME` fallbacks after failed reads. Contractor crew/full-create actions and vehicle-set create also had direct fallbacks. Those paths are reachable from active company routes.

The affected services and actions now issue read-only prerequisite queries and fail closed when a required table or column is absent. Existing local migration files remain the source of schema changes through the controlled provisioning/migration path. No business table is silently created or renamed by opening a form or submitting a normal CRUD request.

Before releasing this runtime change, run the guarded production read-only `clients` and `transport` scopes of `scripts/legacy_http_schema_readiness_probe.php` from the separate diagnostic task. A missing prerequisite is a migration/provisioning blocker, not permission to re-enable HTTP DDL. The isolated MySQL contract `tests/service_http_schema_readonly_mysql_test.php` covers missing-schema failure, successful reads on a provisioned fixture and unchanged business rows.
