# ERP PLANEX GitHub Control Plane

## Canonical repository

- Repository: `planexerp/erp-planex`
- Canonical branch: `chatgpt/production-stabilization-20260802`
- Feature/fix work uses a separate branch and PR.
- `caymanlogistic-crypto/erp-planex` is read-only insurance.
- Production target: `/home/s/spugovxsim/planexp/public_html/erpv2`.
- Legacy `/erp` must never be changed.

## Canonical write protection contract

Agents must never perform file-content writes directly against canonical. Before any `create_file` / `update_file` / `delete_file` / equivalent connector mutation, a feature/fix branch must already exist and the write call must explicitly target it. Omitted branch arguments are unsafe. Canonical changes only through PR merge.

Placeholder/probe writes, empty commits, zero-change release checkpoints and temporary verification PRs are prohibited. A transient failed job should be rerun directly when appropriate.

## Adaptive CI policy

CI cost must match change risk.

- `ERP PLANEX CI`: pull requests to canonical + canonical pushes only. Feature-branch pushes do not start duplicate main CI.
- Heavy domain regressions are PR-only and path-scoped.
- `concurrency: cancel-in-progress` cancels obsolete PR runs.
- Safe CI never deploys production or mutates production DB.

Task classes:
- **FAST** — presentation/view/CSS/text/local UI only. Target for a several-line microchange is ~1 minute wall-clock when runners are free; this target does not waive safety gates.
- **STANDARD** — controller/service/business behavior without production DB mutation.
- **CRITICAL** — settlement/matching/debt, security/access, DB/schema, financial integrations, control plane, destructive operations.

FAST execution should normally be: `read exact view + cheap contract → one feature change → PR → cheap CI → merge → exact HEAD → deploy ref → commit-status evidence → minimal memory delta`.

## Agent-driven production deploy

`ERPv2 Controlled Deploy` remains the only workflow that actually writes `/erpv2`. It is `workflow_dispatch` only and accepts exact `source_sha` + `DEPLOY_ERPV2`.

Dedicated request ref: `deploy/erpv2-agent`.
Request guard: `.github/workflows/erpv2_agent_deploy_request.yml`.

Optimized sequence:
1. Branch → PR.
2. Path-targeted PR CI passes.
3. Merge into canonical.
4. Agent confirms exact current canonical HEAD once.
5. If intended for production, immediately non-force advance `deploy/erpv2-agent` to exact HEAD. Do not manually poll canonical CI first.
6. `ERPv2 Agent Deploy Request` waits for exact-SHA canonical `ERP PLANEX CI`, fails closed on failure/timeout, then rechecks HEAD.
7. Guard dispatches `ERPv2 Controlled Deploy` exact SHA.
8. Controlled Deploy performs backup/artifact/deploy/built-in verification and publishes commit status context `erpv2/deploy` on that exact SHA.
9. Agent reads exact-SHA combined commit status. `erpv2/deploy=success` is routine DEPLOY PASS evidence; no public-web or workflow archaeology is needed.

The deploy request ref must never be force-moved backwards. If canonical HEAD changes while waiting, stale SHA must not deploy.

## Routine postdeploy evidence

`erpv2/deploy=success` is published only after the existing Controlled Deploy safeguards complete successfully:
- exact deployed marker SHA;
- exact artifact SHA;
- `/erpv2/login` HTTP 200;
- production DB fingerprint unchanged;
- legacy `/erp` fingerprint unchanged;
- `.env` unchanged;
- runtime directories preserved;
- migrations were not run.

The JSON artifact remains retained for deeper review. Expensive browser/runtime acceptances P44/P46/P47/P49/P50/P93 are task-specific manual tools for relevant STANDARD/CRITICAL risk, not default FAST fan-out.

## FAST tool discipline

When a primary connector works, do not try `gh`, `git ls-remote`, public web, container or HTTP fallback for the same operation. Do not reread stable deploy workflows/runbooks in every FAST task. Use known IDs and exact files. Typical CI observation is one initial status read and one final read, not repeated second-level polling.

## FAST Memory policy

For ordinary `FAST + SCREEN/UI`, memory delta is intentionally small: affected Screen record, affected cheap Test/Contract record only if changed, and one handoff. Do not churn `00 — START HERE`, `01 — CURRENT STATE`, Module/Service/Business Rules or broad Health merely because canonical SHA advanced. GitHub is always the source of exact current HEAD.

## Production database policy

Agent-driven code/UI deploy authorization does not authorize production DB changes. Migrations, seeds, reconciliation, backfills, destructive SQL or other production data/schema writes remain separate manual-only operations requiring task-specific OWNER approval.

Production migrations must never run automatically after CI or deploy.

## Required GitHub Actions secrets

- `ERPV2_SSH_PRIVATE_KEY`
- `ERPV2_SSH_KNOWN_HOSTS`
- `ERPV2_SSH_HOST`
- `ERPV2_SSH_PORT`
- `ERPV2_SSH_USER`
- `P11_RUNTIME_CREDENTIALS_B64`

Never print or commit secret values.

## Emergency rule

Never bypass the control plane, force-push canonical/deploy ref, touch legacy `/erp`, or use production DB writes as a shortcut.
