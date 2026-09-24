# Workflow

## Start

Use the existing ERP task worktree and a stable TASK_ID. `New-DesignLab.ps1` is idempotent with `-Resume`. Use the built-in browser for visual review and keep annotations scoped to the sandbox tab.

Before changing an override, complete `parity-checklist.md`: compare the sandbox with the current ERP page at the relevant desktop/mobile viewports and run the key interactions. The starting copy must be 1:1 in visual presentation and user-visible behavior. Fix baseline gaps before treating any difference as a requested redesign. Safety blocks for production writes, production network/API access, and external navigation remain mandatory; simulate only their visible outcome locally.

## Iterate

Translate each OWNER correction into a small override. Preserve notes that explain intent, not implementation chatter. A checkpoint is an immutable candidate: `v001`, `v002`, and so on. Include a screenshot when it materially reduces ambiguity.

Before showing a completed correction or creating a checkpoint, complete the applicable `ui-quality-checklist.md` items. Reuse ERP components/tokens, keep one grid and aligned edges, make equivalent controls identical, use correct checkbox/radio semantics, and verify help/error/action placement plus empty, filled, long-content, hover, focus, selected, disabled and error states. Check agreed desktop/mobile widths and keyboard use; no overlap, clipping, accidental horizontal scroll or visibly uneven repeated rows is acceptable.

This is a quick browser check inside the iteration loop, not a reason to run full preflight after every correction.

## Accept and implement

Acceptance names one version. Read `acceptance.json`, compare its hashes with the selected version, then port only that agreed behavior to normal ERP source files. Remove temporary selectors/workarounds that are unnecessary in the real page. Run focused tests while implementing and the normal final publication sequence once.
