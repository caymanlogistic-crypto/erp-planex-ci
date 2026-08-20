# ERP PLANEX GitHub Control Plane

## Canonical repository

- Repository: `planexerp/erp-planex`
- Canonical branch: `chatgpt/production-stabilization-20260802`
- Feature/fix work uses a separate branch and PR.
- `caymanlogistic-crypto/erp-planex` is read-only insurance.
- Production target: `/home/s/spugovxsim/planexp/public_html/erpv2`.
- Legacy `/erp` must never be changed.

## Adaptive CI policy

CI cost must match change risk.

- `ERP PLANEX CI`: pull requests to canonical + canonical pushes only. Feature-branch pushes do not start a duplicate main-CI run.
- Heavy domain regressions (matching, employee money, obligations, production calendar, etc.) are PR-only and path-scoped. They run only when their implementation/test/workflow surface is touched.
- `concurrency: cancel-in-progress` cancels obsolete PR runs after a newer commit.
- Workflow/control-plane changes still trigger the relevant policy checks.
- Safe CI never deploys production or mutates production DB.

Task classes:
- **FAST** — presentation/view/CSS/text/local UI only, no business/DB/security/route semantics. Main CI + cheap path-relevant UI contract.
- **STANDARD** — controller/service/business behavior without production DB mutation. Main CI + owning path-triggered regression(s).
- **CRITICAL** — settlement/matching/debt, security/access, DB/schema, financial integrations, control plane, destructive operations. Full relevant gates.

Never create an **empty commit** or zero-change PR merely to retrigger CI/release. If a specific failed job is transient, rerun the failed job/run rather than replaying every successful workflow. Do not create a temporary verification PR/workflow for a routine deploy.

## Agent-driven production deploy

`ERPv2 Controlled Deploy` remains the only workflow that actually writes `/erpv2`. It is `workflow_dispatch` only and accepts exact `source_sha` + `DEPLOY_ERPV2`.

Dedicated request ref: `deploy/erpv2-agent`.
Request guard: `.github/workflows/erpv2_agent_deploy_request.yml`.

A merge by itself does not deploy. A CI success by itself does not deploy.

Optimized sequence:
1. Branch → PR.
2. Path-targeted PR CI passes.
3. Merge into canonical.
4. Agent confirms exact current canonical HEAD.
5. If intended for production, agent may immediately non-force advance `deploy/erpv2-agent` to that exact HEAD. The agent does **not** manually poll/wait for canonical CI first.
6. `ERPv2 Agent Deploy Request` validates the request SHA equals current canonical HEAD and then waits for the exact-SHA canonical `ERP PLANEX CI` push run.
7. CI failure/timeout fails closed. On success, guard immediately rechecks canonical HEAD.
8. Guard dispatches `ERPv2 Controlled Deploy` for that exact SHA.
9. Controlled Deploy performs backup/artifact/deploy and built-in postdeploy evidence.

The request ref must never be force-moved backwards. If canonical HEAD changes while the request is waiting, the guard must fail closed; do not deploy the stale SHA.

## Routine postdeploy evidence

A normal code/UI release does not need another verification PR. `ERPv2 Controlled Deploy` already proves:
- exact deployed marker SHA;
- exact artifact SHA;
- `/erpv2/login` HTTP 200;
- production DB fingerprint unchanged;
- legacy `/erp` fingerprint unchanged;
- `.env` unchanged;
- runtime directories preserved;
- migrations were not run.

Expensive browser/runtime acceptances `P44`, `P46`, `P47`, `P49`, `P50` runtime and `P93` are task-specific manual tools. Run them for STANDARD/CRITICAL risk when their protected surface is relevant; they must not automatically fan out after every ordinary deploy.

## Production database policy

Agent-driven code/UI deploy authorization does not authorize production DB changes. Migrations, seeds, reconciliation, backfills, destructive SQL or other production data/schema writes remain separate manual-only operations requiring task-specific OWNER approval.

Production migrations must never run automatically after CI or deploy.

## Required GitHub Actions secrets

- `ERPV2_SSH_PRIVATE_KEY`
- `ERPV2_SSH_KNOWN_HOSTS`
- `ERPV2_SSH_HOST`
- `ERPV2_SSH_PORT`
- `ERPV2_SSH_USER`
- `P11_RUNTIME_CREDENTIALS_B64` (preferred runtime credential path; legacy encrypted fallback exists in historical runtime tooling)

Never print or commit secret values.

## Agent efficiency rules

- Read relevant GitHub/Notion evidence once; do not repeatedly reconstruct the same state.
- Prefer one meaningful commit/push after focused checks instead of push-on-every-micro-edit.
- Check Actions in aggregate; avoid 4–8 second polling loops.
- A failed unrelated historical contract is a test-boundary problem, not a reason to run every suite repeatedly.
- Business regressions protect business state/behavior. Presentation copy/layout belongs in cheap UI contract tests.

## Emergency rule

Never bypass the control plane, force-push canonical/deploy ref, touch legacy `/erp`, or use production DB writes as a shortcut.
