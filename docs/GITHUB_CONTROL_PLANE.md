# ERP PLANEX GitHub Control Plane

## Canonical repository

- Repository: `planexerp/erp-planex`
- Canonical integration branch: `chatgpt/production-stabilization-20260802`
- Feature work must be performed on separate `feature/...`, `chatgpt/...`, `fix/...`, or `hotfix/...` branches and integrated through pull requests.
- The legacy repository `caymanlogistic-crypto/erp-planex` is a read-only insurance copy until explicit OWNER approval says otherwise.

## CI policy

CI may run on pull requests, pushes, and explicit manual dispatch where supported. Safe CI itself must not deploy production or apply production database migrations.

Safe CI workflows are limited to GitHub-hosted runner checks, local tests, and local/containerized test databases. Production SSH or production database access is not part of safe CI.

## Production deploy policy

Production target is `/erpv2` under `/home/s/spugovxsim/planexp`.

`ERPv2 Controlled Deploy` remains the only workflow that performs the actual `/erpv2` deployment. It is `workflow_dispatch` only and requires an exact 40-character source SHA plus the explicit `DEPLOY_ERPV2` confirmation input.

Agents are authorized to deploy their own merged ERP changes without requiring the OWNER to open GitHub for each release, but only through the guarded agent deploy request path described below.

A merge by itself does not deploy. A CI success by itself does not deploy. The agent must perform a separate explicit deploy-request action after the merged canonical SHA has passed canonical push CI.

### Agent-driven deploy request

Dedicated deploy request ref: `deploy/erpv2-agent`.

Guard workflow: `.github/workflows/erpv2_agent_deploy_request.yml`.

Allowed sequence:

1. Agent creates a feature/fix branch.
2. Agent opens a PR to `chatgpt/production-stabilization-20260802`.
3. Safe PR CI passes.
4. Agent reviews the diff/evidence and merges the PR.
5. Agent waits for `ERP PLANEX CI` to pass on the exact canonical push SHA.
6. Agent verifies that the SHA is still the current canonical HEAD.
7. Agent advances `deploy/erpv2-agent` to that exact canonical HEAD using a non-force ref update.
8. `ERPv2 Agent Deploy Request` runs only for that ref and only for the repository owner actor.
9. The guard rechecks exact canonical HEAD and successful canonical `ERP PLANEX CI` for the same SHA.
10. The guard dispatches `ERPv2 Controlled Deploy` with the exact SHA and `DEPLOY_ERPV2`.
11. The controlled deploy performs its existing backup, artifact, marker, HTTP, database fingerprint, legacy `/erp` fingerprint and runtime-preservation safeguards.
12. Agent verifies deploy evidence and reports the deployed SHA.

The deploy request ref must never be force-moved backwards. Do not update it before canonical CI has passed. Do not use another branch as a production deploy trigger.

Legacy `/erp` is forbidden: never modify, deploy, migrate, delete, or repurpose it.

## Production database migration policy

Agent-driven code deploy authorization does **not** authorize production database migrations automatically.

Every workflow capable of changing production database state, applying a migration, seeding production data, or synchronizing production state remains `workflow_dispatch` only and requires its explicit confirmation input.

Production migrations must never run automatically after CI or deploy. They require separate task-specific authorization when needed.

## Required GitHub Actions secret names

The active workflows reference these secret names:

- `ERPV2_SSH_PRIVATE_KEY`
- `ERPV2_SSH_KNOWN_HOSTS`
- `ERPV2_SSH_HOST`
- `ERPV2_SSH_PORT`
- `ERPV2_SSH_USER`
- `P11_RUNTIME_CREDENTIALS_B64`

Secret values must never be committed to the repository, printed to logs, pasted into issues/PRs, or sent in chat.

Some historical runtime workflows contain a legacy fallback that can recover encrypted runtime credentials from branch `p11-audit-v2` using the SSH private key. This is compatibility behavior, not the preferred secret-management model. The preferred model is an explicitly configured `P11_RUNTIME_CREDENTIALS_B64` repository secret.

## Production read-only verification

Playwright/HTTP verification may access production only when the workflow has been reviewed as read-only. A workflow is not read-only if it performs POST/mutation actions, applies migrations, seeds data, changes production files, or otherwise changes persistent production state.

## Release model

1. Create a feature branch.
2. Open a PR to `chatgpt/production-stabilization-20260802`.
3. Run safe CI.
4. Review and merge.
5. Wait for successful canonical `ERP PLANEX CI` on the exact merged SHA.
6. If the task is intended for production, explicitly advance `deploy/erpv2-agent` to that exact current canonical SHA.
7. Verify `ERPv2 Agent Deploy Request` and downstream `ERPv2 Controlled Deploy` evidence.
8. Run any required production database migration only as a separate explicitly authorized operation.
9. Run read-only runtime verification when appropriate.

## Emergency rule

Never bypass the control plane. Never force-push or rewrite canonical history or the deploy request ref as an emergency shortcut. Preserve `/erp`, `/erpv2` runtime data, secrets, and backups according to the deployment safeguards.
