# Company deletion: backup and durable audit

OWNER decision, 2026-11-01: **retain `deleted_entities` for audit** after permanent company deletion. This decision came directly from the current ERP audit conversation. The former service deleted these rows; migration `009_create_deleted_entities.sql` has no company foreign key, so retained rows remain available to the superadmin audit view. Their domain objects cannot be restored after the company itself is gone. Existing snapshots may contain personal data and remain superadmin-only; retention duration was not specified by this decision.

## Current guarded contract

`POST /superadmin/companies/{id}/delete` still requires superadmin role, checkbox, exact company name and `УДАЛИТЬ НАВСЕГДА`. Before the first tenant, storage or central mutation, the service requires:

1. Central migration `021_company_deletion_runs.sql` already applied. Runtime does not create its journal table.
2. A signed manifest for this exact company ID and database identifier under `PLANEX_COMPANY_DELETION_BACKUP_ROOT/manifests/company-<id>.json`. The root must exist outside the ERP repository and public document directory. `PLANEX_COMPANY_DELETION_BACKUP_HMAC_KEY` is a protected secret of at least 32 bytes, never stored in Git, logs, URLs or the manifest.
3. Fresh UTC `created_at_utc`, `restore_verified_at_utc` and `expires_at_utc`; the restore check must occur after backup creation and before expiry. A PASS restore receipt must identify the same company/database, exact database and storage archive SHA-256 hashes and verification time.
4. Nonempty database dump and storage archive files inside the protected root, with SHA-256 matching the signed manifest. The independent backup preparer must actually restore-test the dump and inspect the storage archive before producing the PASS receipt; the HMAC and file hashes prove integrity of that attestation, not the correctness of a preparer's actions.

An authorized operator prepares the archives and independent restore receipt in the protected root. An unsigned JSON manifest has fields `id` (32 lowercase hex), `company_id`, `db_identifier`, UTC times above, and `database_archive`, `storage_archive`, `restore_receipt` objects with relative `path` and lowercase `sha256`. The receipt JSON has `status: "PASS"`, `company_id`, `db_identifier`, `database_sha256`, `storage_sha256` and `verified_at_utc`. After independent restore QA, the CLI-only `scripts/company_deletion_seal_backup_manifest.php <unsigned-json-path>` signs and seals the manifest once using the protected environment key, then reruns the verifier. The script does not create, restore or delete production data. It refuses to overwrite an existing company manifest.

A company without a valid database identifier is blocked by this version of the guard; no empty-database exception is inferred. The OWNER must define a separate backup contract for such a company before its deletion can be enabled.

`company_deletion_runs` stores the actor, company/database identity, one-time backup ID and hashes, stage, status and safe error code without secrets. Its unique company and backup IDs stop automatic replay. A failed run remains failed; **there is no automatic destructive retry**. An operator reviews the journal, source state and backup before any separately authorized recovery. Tenant cleanup, storage removal and central deletion are still separate irreversible resources; the journal records the last committed stage rather than claiming distributed rollback. The central company deletion and `central_deleted` journal transition commit in one transaction. Prior `deleted_entities` rows are retained and their count is checked afterward.

## Release boundary

This change prepares code and LOCAL disposable-schema tests only. Production central migration `021`, secret/root provisioning, backup creation, any real company deletion and recovery each require task-specific OWNER authorization. Deploying code without the migration and protected backup material makes the deletion endpoint fail closed before any destructive step. The historical `docs/ai/E14_E15_FINAL_ARCHITECTURE_REPORT.md` wording that called the old flow “with backup” was inaccurate.
