# Workflow

## Start

Use the existing ERP task worktree and a stable TASK_ID. `New-DesignLab.ps1` is idempotent with `-Resume`. Use the built-in browser for visual review and keep annotations scoped to the sandbox tab.

## Iterate

Translate each OWNER correction into a small override. Preserve notes that explain intent, not implementation chatter. A checkpoint is an immutable candidate: `v001`, `v002`, and so on. Include a screenshot when it materially reduces ambiguity.

## Accept and implement

Acceptance names one version. Read `acceptance.json`, compare its hashes with the selected version, then port only that agreed behavior to normal ERP source files. Remove temporary selectors/workarounds that are unnecessary in the real page. Run focused tests while implementing and the normal final publication sequence once.
