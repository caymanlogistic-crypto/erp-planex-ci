---
name: planex-design-lab
description: "Use for PLANEX requests containing РЕЖИМ РАЗРАБОТКА, ПЕСОЧНИЦА, or iterative UI/function prototyping before implementation. Creates or resumes a safe localhost-only versioned preview, applies one correction at a time outside Git, and converts only an OWNER-accepted checkpoint into normal ERP implementation. Do not use for a direct one-shot code change or production verification."
---

# PLANEX Design Lab

OWNER 2026-10-18: gallery, sandbox and implementation must reuse the same approved UI components. New sessions freeze visual assets and SHA-256 with checkpoints/acceptance; use `verify --against-worktree` to detect source drift. An accepted version is available read-only at the session URL with `?version=accepted`, even while workspace overrides change. Before implementation acceptance, run `node tools/ui_parity.mjs --reference-url <localhost sandbox> --candidate-url <localhost ERP> --output <task evidence>` on identical synthetic fixtures and exercise page-specific states. The tool compares the frozen accepted version. New component appearance belongs in the shared component after explicit OWNER approval, never in parallel page styles. For approved examples, create a task-owned `ui-standard` preset using the pinned Python `tools/design_lab.py create` command, then use the normal `Start-DesignLab.ps1` wrapper.

1. Read `docs/DESIGN_LAB.md` and `references/workflow.md`. Read `references/data-safety.md` when a real-shaped snapshot or data source is requested.
2. Create or resume the task-owned session with `local/New-DesignLab.ps1`; default to `synthetic` data. Start it with `local/Start-DesignLab.ps1` and open the returned localhost URL.
3. Before any design correction, verify the initial copy against the current ERP page using `parity-checklist.md`. It must match 1:1 visually and in available user behavior. Synthetic values may differ, but data shape and UI-relevant states must match. Only production writes, production DB/API/network access, and external navigation may differ; simulate their visible result locally when needed.
4. Change only the session's `workspace/overrides.css`, `workspace/overrides.js`, and `workspace/notes.md` during exploration. Make one requested correction at a time and verify it in the browser. Do not edit ERP source merely to preview an idea.
5. Before showing a completed correction to OWNER or saving a checkpoint, complete the applicable items in `ui-quality-checklist.md`. Forms must use one alignment grid, consistent control dimensions/spacing/type, correct checkbox/radio semantics, stable help/error/action placement, visible focus and keyboard behavior, and complete responsive/content/state coverage. Compare adjacent fields and repeated rows; do not present visibly uneven work.
6. Save meaningful variants with `local/Save-DesignLabVersion.ps1`. Do not checkpoint every keystroke.
7. Do not run full preflight, create commits/PRs, deploy, or write Notion during the iterative loop.
8. When OWNER says `ВНЕДРЯЙ`, require an explicit accepted version and create `acceptance.json` with `local/Approve-DesignLabVersion.ps1`. Implement that exact version in ERP source, then use the normal PLANEX test/publication/memory route once.
9. Stop only the task-owned server through `local/Stop-DesignLab.ps1`; never kill by image/name or touch another task's session.

The sandbox is a design acceptance aid, not production evidence. Never claim ERP completion until accepted changes are implemented and the normal checks pass.
