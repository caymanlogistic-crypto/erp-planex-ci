## Scope

Describe the smallest intended change and affected ERP area.

## CHANGE CLASS

- [ ] FAST — presentation/view/CSS/text only; no route/business/DB/security/integration semantics
- [ ] STANDARD — controller/service/business behavior; no production DB mutation
- [ ] CRITICAL — settlement/matching/debt, security/access, DB/schema, financial integration, control plane or destructive work

Why this class:

Expected path-triggered CI/contracts:

## Validation

- [ ] Focused checks and all path-triggered required CI are green.
- [ ] No empty/technical commit or zero-change PR was created merely to retrigger CI/release.
- [ ] No temporary verification PR/workflow was created for routine deployment evidence.
- [ ] No production DB migration/reconciliation was executed without separate OWNER approval.
- [ ] Legacy `/erp` was not changed.

## MEMORY IMPACT

Select only what actually changed:

- [ ] NONE
- [ ] MODULE
- [ ] SCREEN
- [ ] DATABASE
- [ ] BUSINESS_RULE
- [ ] STATE_MACHINE
- [ ] UI
- [ ] SECURITY
- [ ] INTEGRATION
- [ ] FLOW
- [ ] AUTOMATION
- [ ] DECISION

### Final Memory Pass

Perform one final delta update after merge rather than repeated mid-task writes:

- [ ] Canonical HEAD/status checked once after merge.
- [ ] Only affected Notion records updated with current semantics/evidence.
- [ ] `MEMORY HEALTH` updated only for affected areas; unverifiable state is `NEEDS REVERIFY`.
- [ ] One short handoff records the task delta and `MEMORY PASS`.

`MEMORY PASS: PENDING` until the post-merge delta is complete.

For read-only/no-durable-state work, `MEMORY IMPACT: NONE` is valid and no Notion write is required.
