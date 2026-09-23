# ERP PLANEX GitHub Control Plane

## Canonical boundaries

- Repository: `caymanlogistic-crypto/erp-planex-ci`
- Canonical branch: `chatgpt/production-stabilization-20260802`
- Production target: `/erpv2`
- Legacy `/erp`: never changed
- Previous canonical `planexerp/erp-planex`: read-only insurance; do not push
- Old `caymanlogistic-crypto/erp-planex`: read-only insurance; not canonical

## Authority

Executable workflows and their focused contract tests are implementation truth. This document records stable control-plane responsibilities and intentionally avoids copying volatile `if` predicates into multiple places.

## Canonical write protection

Agents never write file content directly to canonical. Create a feature branch first; canonical changes enter through PR merge.

Forbidden: placeholder/probe writes, empty/checkpoint commits, dummy files and temporary verification PRs.

## Release classes

- **FAST:** presentation/view/CSS/text/local UI only, handled by guarded `ERPv2 FAST Release` when its executable whitelist accepts the PR.
- **STANDARD:** controller/service/business behavior without production DB mutation.
- **CRITICAL:** finance settlement/matching/debt, security/access, DB/schema, financial integrations, control plane or destructive operations.

## Pre-push engineering gate

`tools/ci_scope.py` is the reusable deterministic changed-path classifier used by local preflight and the main CI scope step. `tools/agent_preflight.py` resolves `AUTO` to the least expensive safe class and executes the path-owned deterministic checks before the first feature-branch push.

Stable lifecycle:

`START_HEAD → WORK_BRANCH → LOCAL_PREFLIGHT_PASS → LOCAL_PUBLISH_PASS → FEATURE_HEAD → PR → CI aggregate → MERGED_SHA → MEMORY PASS → optional deploy`.

GitHub Actions remains an independent verification layer. Agents aggregate all required terminal results for one feature SHA before remediation; a failed aggregate is fixed as one local batch and must pass local preflight again before a corrective push. Normal remote push budget is one initial push, with at most one corrective push for STANDARD/CRITICAL before a `PREFLIGHT_COVERAGE_DEFECT` investigation is required.


The normal OWNER workflow is fail-closed and LOCAL: all pre-push application, DB, browser, memory and control-plane checks run on Windows/local WSL. Missing capability is repaired locally or blocks publication.

`tools/local_publish.py` is the only normal publication path. A machine-wide queue serializes canonical refresh and task-branch push while development remains parallel. The publisher merges non-conflicting canonical movement, runs one final exact-candidate preflight, rechecks canonical, refuses non-fast-forward publication and records local evidence. SentinelX and the persistent remote runner remain historical recovery/infrastructure assets outside the normal task route.

Mutable local tests use a unique disposable schema and restricted per-run user. Local browser/dev servers use task-owned dynamic ports and PID/start-time/command/worktree identity. Shared slot 2 and port 8016 are legacy compatibility only and are not allocated to new task scripts.

The privileged FAST `pull_request_target` boundary is intentionally **not** refactored to execute PR-head helper code before validation/checkout. Its exact-base, whitelist, size, deletion and UI-presence checks stay non-PR-controlled and are parity-tested against local FAST policy.

## Standard agent-driven production deploy

Canonical implementation: `.github/workflows/erpv2_agent_deploy_request.yml` plus `tests/agent_deploy_request_contract_test.php`.

Stable semantics:

1. request comes from the dedicated `deploy/erpv2-agent` ref;
2. requested commit must equal exact current canonical HEAD;
3. successful canonical `ERP PLANEX CI` is obtained/waited for the same SHA;
4. failures, timeouts and SHA/HEAD drift fail closed;
5. canonical HEAD is rechecked immediately before dispatch;
6. exact-SHA `ERPv2 Controlled Deploy` is dispatched.

**Technical integration actor identity is not the current standard request gate.** If this implementation changes, update the executable workflow/test first and then reverify affected semantic-memory records; do not preserve an obsolete README/Notion copy.

The guarded workflow already performs exact-SHA/CI/HEAD machine checks. Agents should not manually repeat those same checks several times before request dispatch.

## Single production write engine

`.github/workflows/erpv2_controlled_deploy.yml` is the standard `/erpv2` production write engine. It is exact-SHA `workflow_dispatch` and proves production marker/artifact/runtime safety evidence. FAST and standard request mechanisms gate/dispatch it; they do not replicate SSH deployment logic.

Merge != deploy. Deploy != migration. A docs/memory-only canonical merge does not justify a repeat application deploy when the production runtime tree did not change.

## Production database policy

FAST/STANDARD code deploy authorization never authorizes production DB mutation. Migration, seed, reconciliation, backfill, destructive SQL or other production data/schema write requires a separate task-specific OWNER authorization. Production migrations never run automatically from normal CI/deploy.

## CI discipline

Local preflight and GitHub CI use the same path classifier, but CI remains a separate recheck. Architecture guard, dynamic identifier safety and control-plane safety stay independent always-on checks in the main CI. Path-owned critical workflows remain separate where they already exist; this pre-push flow does not reduce them.

`Memory Integrity` is a deterministic critical-memory contract: it runs focused checker tests and path-aware verification when critical owning paths or memory-governance tooling change.

## Emergency rule

Never bypass the control plane, force-push canonical/deploy refs, touch legacy `/erp`, or use production DB writes as a shortcut.
