#!/usr/bin/env python3
"""Deterministic coverage validator for the ERP PLANEX active registry snapshot."""
from __future__ import annotations

import argparse
import json
import sys
from collections import Counter
from pathlib import Path

REQUIRED_REGISTRIES = {"module", "screen", "route", "service", "business_rule", "data_entity", "integration", "test_contract"}
REQUIRED_FIELDS = {"canonical_id", "semantic_class", "truth_status", "machine_ownership_id"}
HEALTH_METRICS = {
    "missing_governance_fields", "duplicate_canonical_id", "unresolved_machine_ownership",
    "critical_needs_reverify", "critical_stale_verified", "critical_legacy_only_verified",
    "missing_critical_evidence",
}


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def validate(inventory: dict, ownership: dict) -> list[str]:
    errors: list[str] = []
    owners = ownership.get("owners")
    if not isinstance(owners, list):
        return ["ownership owners must be a list"]
    owner_ids = [str(x.get("id", "")).strip() for x in owners]
    for owner_id, count in Counter(owner_ids).items():
        if not owner_id:
            errors.append("machine ownership id is missing")
        elif count > 1:
            errors.append(f"duplicate machine ownership id: {owner_id}")
    for item in owners:
        if not isinstance(item.get("owning_paths"), list) or not item.get("owning_paths"):
            errors.append(f"owner {item.get('id','<missing>')} has no owning_paths")

    declared_registries = inventory.get("required_registry_keys")
    if set(declared_registries or []) != REQUIRED_REGISTRIES:
        errors.append("required_registry_keys do not match the canonical eight active registries")
    if set(inventory.get("required_governance_fields") or []) != REQUIRED_FIELDS:
        errors.append("required_governance_fields do not match the normative lifecycle model")
    if set(inventory.get("health_metrics") or []) != HEALTH_METRICS:
        errors.append("health_metrics do not match the hard-condition set")

    registries = inventory.get("registries")
    if not isinstance(registries, list):
        return errors + ["registries must be a list"]
    keys = [str(x.get("key", "")).strip() for x in registries]
    if set(keys) != REQUIRED_REGISTRIES or len(keys) != len(REQUIRED_REGISTRIES):
        errors.append(f"registry coverage mismatch: {keys}")
    source_ids = [str(x.get("data_source_id", "")).strip() for x in registries]
    if any(not x for x in source_ids) or len(source_ids) != len(set(source_ids)):
        errors.append("registry data_source_id values must be unique and non-empty")

    total = 0
    known_owners = set(owner_ids)
    for registry in registries:
        key = str(registry.get("key", "<missing>"))
        count = registry.get("catalog_records")
        if not isinstance(count, int) or count <= 0:
            errors.append(f"{key}: catalog_records must be a positive integer")
        else:
            total += count
        health = registry.get("health")
        if not isinstance(health, dict) or set(health) != HEALTH_METRICS:
            errors.append(f"{key}: incomplete health metric set")
        else:
            for metric, value in health.items():
                if value != 0:
                    errors.append(f"{key}: {metric}={value}, expected 0")
        ids = registry.get("machine_ownership_ids")
        if not isinstance(ids, list) or not ids:
            errors.append(f"{key}: machine_ownership_ids must be non-empty")
        else:
            unknown = sorted(set(ids) - known_owners)
            if unknown:
                errors.append(f"{key}: unresolved machine ownership ids: {unknown}")

    if total != 179:
        errors.append(f"catalog record total={total}, expected 179")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("inventory", type=Path)
    parser.add_argument("ownership", type=Path)
    parser.add_argument("--json", action="store_true", dest="as_json")
    args = parser.parse_args()
    try:
        errors = validate(load(args.inventory), load(args.ownership))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"active-registry-coverage: ERROR: {exc}", file=sys.stderr)
        return 2
    result = {"pass": not errors, "errors": errors}
    if args.as_json:
        print(json.dumps(result, ensure_ascii=False, indent=2, sort_keys=True))
    else:
        for error in errors:
            print(f"FAIL {error}")
        print("ACTIVE_REGISTRY_COVERAGE_PASS" if not errors else "ACTIVE_REGISTRY_COVERAGE_FAIL")
    return 0 if not errors else 1


if __name__ == "__main__":
    raise SystemExit(main())
