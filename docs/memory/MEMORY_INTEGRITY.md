# ERP PLANEX Memory Integrity Contract

Notion is the semantic-memory system. GitHub does **not** mirror it. This directory contains deterministic machine contracts needed to validate the critical implementation subset, machine ownership and full direct-registry coverage.

## Normative lifecycle

`Semantic Class` and `Truth Status` are the normative lifecycle fields. Legacy `Freshness` / `Class` properties are compatibility-only and must not be used to infer current authority. Every active direct-registry record has a non-empty `Canonical ID` and resolvable `Machine Ownership ID`.

## Path-aware freshness

For an implementation record with `verified_sha=S` and `owning_paths=P`, the checker evaluates the relevant Git diff between `S` and the requested head. An older SHA is valid provenance when no owning path changed. A relevant owning-path diff means the record needs re-verification; a critical record that still says `VERIFIED` fails closed.

This deliberately avoids the false-positive rule “HEAD moved, therefore all memory is stale.”

## Machine ownership

`docs/memory/machine_ownership.json` is the repository-side vocabulary of machine ownership IDs. It maps each stable ID to future-proof owning paths. Registry records refer to the ID; they do not copy executable predicates or implementation detail into Notion.

## Active registry coverage

`docs/memory/active_registry_inventory.json` is a deterministic post-remediation snapshot for the eight direct active registries: Module, Screen, Route, Service, Business Rule, Data Entity, Integration and Test/Contract. `tools/active_registry_coverage_check.py` fails if a registry is omitted, ownership is unresolved, governance fields are incomplete in the snapshot, any hard-condition health metric is non-zero, or the expected catalog total drifts.

This snapshot/checker is not a substitute for the independent live Notion readback required by the Governance Contract. Live enumeration must still reach `has_more=false` and critical/machine-sensitive records require direct fetch.

## What is checked

`tools/memory_integrity_check.py` validates:

- real 40-hex Git SHA and commit existence for verified implementation records;
- changed owning paths after verification;
- critical `VERIFIED` records with relevant drift;
- critical `NEEDS_REVERIFY` at terminal state;
- legacy implementation incorrectly left `VERIFIED`;
- duplicate canonical IDs;
- missing or unresolved machine ownership for active records;
- missing critical evidence;
- obvious orphan critical implementation records;
- fragile closed migration ranges such as `042–076`;
- OWNER decision provenance and prohibition on Git SHA as OWNER provenance;
- expressible active-policy key conflicts;
- `SUPERSEDED` records leaking into active navigation.

## Commands

```bash
python3 tests/memory_integrity_checker_test.py
python3 tests/active_registry_coverage_checker_test.py
python3 tools/memory_integrity_check.py docs/memory/critical_records.json --ownership-manifest docs/memory/machine_ownership.json --head HEAD
python3 tools/active_registry_coverage_check.py docs/memory/active_registry_inventory.json docs/memory/machine_ownership.json
```

## Ownership

The full semantic truth remains in `ERP PLANEX — MASTER MEMORY`. Git stores only deterministic control data needed for automated validation. The live Notion layer remains authoritative for semantic records and is independently read back before `MEMORY PASS`.
