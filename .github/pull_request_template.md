## Scope

Describe the smallest intended change and the affected ERP area.

## Validation

- [ ] Focused tests / safe CI are green for this change.
- [ ] No production `/erpv2` deploy was triggered by this PR/merge.
- [ ] No production DB migration/reconciliation was executed without separate OWNER approval.
- [ ] Legacy `/erp` was not changed.

## MEMORY IMPACT

Select all that apply:

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

### Memory update

Before final task completion after merge:

- [ ] Canonical HEAD/status checked.
- [ ] Relevant Notion MASTER MEMORY records updated with current semantics.
- [ ] Affected structured records have correct `Freshness`, `Verified SHA`, `Verified Date`, and `Evidence`.
- [ ] `MEMORY HEALTH REGISTER DB` updated; anything not actually reverified is `NEEDS REVERIFY`.
- [ ] `07 — AGENT HANDOFFS` contains the task delta, `MEMORY IMPACT`, and `MEMORY PASS`.

`MEMORY PASS: PENDING` until the post-merge memory update is complete.

For genuinely read-only/no-durable-state work, `MEMORY IMPACT: NONE` is valid and no Notion write is required.
