# ERP PLANEX GitHub Control Plane

## Canonical boundaries

- Repository: `planexerp/erp-planex`
- Canonical branch: `chatgpt/production-stabilization-20260802`
- Production target: `/erpv2`
- Legacy `/erp`: never changed
- Old repository: read-only insurance

## Authority

Executable workflows and their focused contract tests are implementation truth. This document records stable control-plane responsibilities and intentionally avoids copying volatile `if` predicates into multiple places.

## Canonical write protection

Agents never write file content directly to canonical. Create a feature branch first; canonical changes enter through PR merge.

Forbidden: placeholder/probe writes, empty/checkpoint commits, dummy files and temporary verification PRs.

## Release classes

- **FAST:** presentation/view/CSS/text/local UI only, handled by guarded `ERPv2 FAST Release` when its executable whitelist accepts the PR.
- **STANDARD:** controller/service/business behavior without production DB mutation.
- **CRITICAL:** finance settlement/matching/debt, security/access, DB/schema, financial integrations, control plane or destructive operations.

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

Merge != deploy. Deploy != migration.

## Production database policy

FAST/STANDARD code deploy authorization never authorizes production DB mutation. Migration, seed, reconciliation, backfill, destructive SQL or other production data/schema write requires a separate task-specific OWNER authorization. Production migrations never run automatically from normal CI/deploy.

## CI discipline

Required checks should be observed as an aggregate gate; inspect individual jobs only after aggregate failure. Do not manually duplicate path-triggered regressions.

`Memory Integrity` is a deterministic critical-memory contract: it runs focused checker tests and path-aware verification when critical owning paths or memory-governance tooling change.

## Emergency rule

Never bypass the control plane, force-push canonical/deploy refs, touch legacy `/erp`, or use production DB writes as a shortcut.
