# ERP PLANEX Memory Integrity Contract

Notion is the semantic-memory system. GitHub does **not** mirror it. This directory contains only a small deterministic manifest for critical, automation-checkable contracts.

## Path-aware freshness

For an implementation record with `verified_sha=S` and `owning_paths=P`, the checker evaluates the relevant Git diff between `S` and the requested head. An older SHA is valid provenance when no owning path changed. A relevant owning-path diff means the record needs re-verification; a critical record that still says `VERIFIED` fails closed.

This deliberately avoids the false-positive rule “HEAD moved, therefore all memory is stale.”

## What is checked

`tools/memory_integrity_check.py` validates:

- real 40-hex Git SHA and commit existence for verified implementation records;
- changed owning paths after verification;
- critical `VERIFIED` records with relevant drift;
- duplicate canonical IDs;
- missing critical evidence;
- obvious orphan critical implementation records;
- fragile closed migration ranges such as `042–076`;
- OWNER decision provenance and prohibition on Git SHA as OWNER provenance;
- expressible active-policy key conflicts;
- `SUPERSEDED` records leaking into active navigation.

## Commands

```bash
python3 tests/memory_integrity_checker_test.py
python3 tools/memory_integrity_check.py docs/memory/critical_records.json --head HEAD
```

The first command proves both core cases: old SHA + unrelated change = PASS; old SHA + owning-path change = reverify/fail.

## Ownership

The full semantic truth remains in `ERP PLANEX — MASTER MEMORY`. Critical Notion records store `Canonical ID`, `Semantic Class`, `Truth Status`, `Owning Paths`, evidence and appropriate provenance. The Git manifest contains only the minimum fields needed for deterministic integrity checks.
