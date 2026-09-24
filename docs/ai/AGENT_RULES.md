# ERP PLANEX — agent rules pointer

The canonical GitHub-side agent contract is `AGENTS.md`. The canonical semantic-memory contract is Notion `ERP PLANEX — MASTER MEMORY` → `21 — MEMORY GOVERNANCE & INTEGRITY CONTRACT`.

This file intentionally does not duplicate those full rule sets.

Minimum non-negotiable reminders:

- canonical repo: `caymanlogistic-crypto/erp-planex-ci`; canonical branch: `chatgpt/production-stabilization-20260802`;
- feature branch → PR → relevant aggregate CI → merge;
- cache `START_HEAD`, branch/PR IDs and source/page identifiers; no repeated unchanged-state reads;
- create Windows tasks through `local\New-PlanexTask.ps1` and use the pinned `PLANEX_*` toolchain exported by `Enter-Dev.ps1`;
- use Windows Git for Windows worktrees, the isolated-DB wrapper for mutable tests and the browser wrapper for Playwright; WSL is not a fallback for a Windows `.git` pointer;
- discover paths before reading them (`rg --files`/`Test-Path`), and separate intentional red TDD evidence from environment failures;
- no direct canonical writes, verification PRs, empty/probe/checkpoint commits or duplicate CI;
- executable workflow is implementation truth; README/handoffs never outrank it;
- no production DB mutation without separate task-specific OWNER authorization;
- never touch legacy `/erp`;
- no stale critical `VERIFIED`; path-aware owning-path drift requires reverify/`NEEDS_REVERIFY`;
- critical FINANCE/DB/SECURITY/CONTROL PLANE Notion writes require direct readback;
- completion requires `CODE PASS + CI PASS + MEMORY PASS`.
