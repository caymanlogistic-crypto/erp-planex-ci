# ERP PLANEX GitHub Control Plane

## Canonical repository

- Repository: `planexerp/erp-planex`
- Canonical branch: `chatgpt/production-stabilization-20260802`
- `caymanlogistic-crypto/erp-planex` is read-only insurance.
- Production target: `/home/s/spugovxsim/planexp/public_html/erpv2`.
- Legacy `/erp` must never be changed.

## Canonical write protection contract

Agents must never perform file-content writes directly against canonical. Before any `create_file` / `update_file` / `delete_file` mutation, a feature branch must exist and the write must explicitly target it. Canonical changes only through PR merge.

Placeholder/probe writes, empty commits, zero-change release checkpoints and temporary verification PRs are prohibited.

## Adaptive release policy

- **FAST** — presentation/view/CSS/text/local UI only.
- **STANDARD** — controller/service/business behavior without production DB mutation.
- **CRITICAL** — settlement/matching/debt, security/access, DB/schema, financial integrations, control plane, destructive operations.

Several-line FAST changes target ~1 minute wall-clock when runners are free without waiving safety.

## ERPv2 FAST Release

Workflow: `.github/workflows/erpv2_fast_release.yml`.

FAST convention:
- branch must be `fast/<slug>`;
- PR must target canonical;
- PR title must start `FAST:`;
- PR must be same-repository, non-draft and triggered by repository OWNER;
- max 6 changed files and max 120 changed lines;
- allowed files only: `app/View/pages/**.php`, `public/assets/css/**`, `public/assets/js/**`, `tests/*_ui_contract_test.php`;
- deletions are not allowed;
- at least one actual UI file is required.

The workflow uses `pull_request_target`, therefore its definition comes from canonical. It validates scope **before checkout of PR head**. PR checkout uses `persist-credentials: false`. Production SSH secrets are not present in this workflow.

Guarded sequence inside one FAST gate:
1. Verify event base SHA equals exact current canonical HEAD.
2. Verify owner/same-repo/branch/title/scope/size whitelist.
3. Checkout exact PR head only after validation.
4. Lint changed PHP/JS only.
5. Run cheap `tests/*_ui_contract_test.php` contracts.
6. Reconfirm canonical base has not drifted.
7. Squash merge the exact expected PR head through GitHub merge API.
8. Verify returned merge SHA is exact canonical HEAD.
9. Publish `erpv2/fast-release=success` on exact merge SHA.
10. Dispatch the existing `ERPv2 Controlled Deploy` for that exact SHA with `DEPLOY_ERPV2`.

`ERPv2 FAST Release` never contains SSH secret references, P07 deployment engine calls, `/erpv2` server write commands, migrations or DB writes. It only gates, merges and dispatches the existing controlled deploy.

## FAST CI de-duplication

A correctly formed `fast/*` + `FAST:` PR is validated by `ERPv2 FAST Release`. The normal repository-wide `ERP PLANEX CI` runner job is skipped for that FAST PR. The squash merge commit keeps a `FAST:` title, so canonical push full-CI runner is also skipped. This removes duplicate cold-start/runtime cost.

FAST is fail-closed: if branch/title/scope/base/size/test conditions do not match, the special workflow does not merge or deploy; use the normal STANDARD path instead.

## Standard agent-driven production deploy

For non-FAST code/UI work, `deploy/erpv2-agent` + `.github/workflows/erpv2_agent_deploy_request.yml` remains active. The guard waits exact canonical `ERP PLANEX CI`, rechecks HEAD and dispatches `ERPv2 Controlled Deploy`.

## Single production write engine

`ERPv2 Controlled Deploy` remains the only workflow containing the actual `/erpv2` production write engine and SSH secrets. It remains `workflow_dispatch` exact-SHA and requires `DEPLOY_ERPV2`; FAST only dispatches it after the guarded merge.

Controlled Deploy proves:
- exact deployed marker SHA;
- exact artifact SHA;
- `/erpv2/login` HTTP 200;
- production DB fingerprint unchanged;
- legacy `/erp` fingerprint unchanged;
- `.env` unchanged;
- runtime directories preserved;
- migrations were not run.

It publishes exact-SHA commit status `erpv2/deploy`. `erpv2/deploy=success` is routine DEPLOY PASS evidence.

## Mandatory Notion Memory Gate

Notion is mandatory for material ERP work, including FAST. Performance optimization reduces memory breadth, not memory correctness.

FAST memory policy after successful deploy:
- mandatory write to the affected Screen record with current semantics/evidence/exact merged SHA;
- Test/Contract record write only if the cheap test changed;
- no `00`, `01`, module/service/rules/broad-health churn solely because HEAD advanced;
- no handoff required for a microchange when the Screen record contains the complete delta;
- target 1–3 relevant reads and 1–2 writes;
- MEMORY PASS may be reported only after a successful Notion write result; ambiguous responses require one direct readback.

## Production database policy

FAST/standard code deploy authorization does not authorize production DB changes. Migrations, seeds, reconciliation, backfills, destructive SQL or other production data/schema writes remain separate manual-only operations requiring task-specific OWNER approval.

Production migrations must never run automatically after CI or deploy.

## Required GitHub Actions secrets

Production secrets remain referenced only by controlled/manual production workflows:
- `ERPV2_SSH_PRIVATE_KEY`
- `ERPV2_SSH_KNOWN_HOSTS`
- `ERPV2_SSH_HOST`
- `ERPV2_SSH_PORT`
- `ERPV2_SSH_USER`
- `P11_RUNTIME_CREDENTIALS_B64`

Never print or commit secret values.

## Emergency rule

Never bypass the control plane, force-push canonical/deploy ref, touch legacy `/erp`, or use production DB writes as a shortcut.
