# ERP PLANEX GitHub Control Plane

## Canonical repository

- Repository: `planexerp/erp-planex`
- Canonical integration branch: `chatgpt/production-stabilization-20260802`
- Feature work must be performed on separate `feature/...`, `chatgpt/...`, `fix/...`, or `hotfix/...` branches and integrated through pull requests.
- The legacy repository `caymanlogistic-crypto/erp-planex` is a read-only insurance copy until explicit OWNER approval says otherwise.

## CI policy

CI may run on pull requests, pushes, and explicit manual dispatch where supported. CI must not have a downstream trigger that automatically deploys production or applies production database migrations.

Safe CI workflows are limited to GitHub-hosted runner checks, local tests, and local/containerized test databases. Production SSH or production database access is not part of safe CI.

## Production deploy policy

Production target is `/erpv2` under `/home/s/spugovxsim/planexp`.

`ERPv2 Controlled Deploy` is manual-only. It must be started explicitly with `workflow_dispatch`, an exact 40-character source SHA, and its explicit confirmation input.

There must be no `push -> deploy`, `merge -> deploy`, or `CI success -> deploy` chain.

Legacy `/erp` is forbidden: never modify, deploy, migrate, delete, or repurpose it.

## Production database migration policy

Every workflow capable of changing production database state, applying a migration, seeding production data, or synchronizing production state must be `workflow_dispatch` only and require an explicit confirmation input.

Production migrations must never run automatically after CI or deploy.

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
5. Production remains unchanged after merge.
6. When OWNER separately approves a release, run the manual controlled deploy for the exact approved SHA.
7. Run any required production database migration as a separate explicit manual operation.
8. Run read-only runtime verification after the relevant credentials and deployment state are confirmed.

## Emergency rule

Never bypass the control plane or trigger a production-changing workflow without explicit OWNER approval. Never force-push or rewrite canonical history as an emergency shortcut. Preserve `/erp`, `/erpv2` runtime data, secrets, and backups according to the deployment safeguards.
