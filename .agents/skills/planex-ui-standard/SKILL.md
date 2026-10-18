---
name: planex-ui-standard
description: "Use for ERP PLANEX requests to standardize a page, build a new page with approved components, or extend OWNER-approved visual standards. Covers component discovery, migration from local CSS, browser acceptance, and catalog registration. Do not use for iterative Design Lab prototyping."
---

# PLANEX UI standardization

Use when the OWNER says «стандартизируй страницу», asks to bring a page to agreed styles, or asks to extend agreed UI components. Follow the normal ERP task/worktree/publication rules in `AGENTS.md`.

## Standardize a page

1. Run `python tools/ui_standard.py list`; inspect the applicable family with `show <id>` and read its `canon` file. For a tabular registry, use `registry` and `docs/ui/REGISTRY_CANON.md`. Do not force registry markup onto a different page type. If no approved family covers the page, reuse applicable approved components from `docs/ui/DESIGN_STANDARD.md` and Notion `09A — DESIGN SYSTEM — START HERE`, then record uncovered roles. A new visual pattern needs an explicit OWNER decision.
2. Inspect the actual page and its styles: identify every header, tab, toolbar, control, table/card and state, plus local CSS/JS selectors that currently own appearance or behavior. Map each shared role to a catalog component. Preserve business logic, data, accessibility and existing actions.
3. Use the canonical partial, classes, stylesheet and behavior source from the family. Remove or scope legacy local declarations that still override a shared role. A class name alone is not proof of reuse: inspect computed styles and geometry. Page CSS may contain only page-specific column geometry, semantic statuses and unique actions.
4. Register an adapted page with `python tools/ui_standard.py register page <definition.json>`; the definition needs `id`, `family`, `route`, `view`, a real `browser_test` path, and explicit `sticky`/`reference_select` booleans for a registry. Run `python tools/ui_standard.py check`; the registry contract reads the catalog automatically.
5. Run focused functional/browser tests through the local wrappers. At 390, 1280 and 1920 CSS px check the outer frame, toolbar-to-table contact (0 px gap), text/control dimensions, clipping and page overflow. Check 3840 px for bounded width. Exercise long/empty values, hover, focus, keyboard, sticky scroll and page-specific states. Compare with the family's production reference. Add a browser regression for a discrepancy that escaped existing checks. Do not report completion from a green static contract alone.

## Extend the standard

When an approved component does not cover a needed role, determine whether it is a variant of an existing family or a new family. Obtain an explicit OWNER decision for new visual values; a screenshot or existing local CSS alone does not grant approval. Put shared appearance in the family's canonical stylesheet and shared behavior in its canonical JS. Document exact values, states, responsive behavior, scope and approval in its `canon` file. Add a browser test that checks the rendered role, including existing family reference pages. Register the family/component with `python tools/ui_standard.py register family|component <definition.json>`, then run `check` and focused browser tests. `register` rejects an unapproved component and a selector absent from the shared stylesheet.

The catalog `docs/ui/approved-components.json` is the machine-readable index for discovery and coverage, not a second copy of CSS values. The canonical CSS and family `canon` file own those values. Update impacted Notion memory only after the normal merge, following `AGENTS.md`.
