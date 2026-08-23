#!/usr/bin/env python3
from __future__ import annotations

import copy
import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "tools" / "active_registry_coverage_check.py"

spec = importlib.util.spec_from_file_location("coverage_checker", SCRIPT)
assert spec and spec.loader
checker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checker)


def main() -> int:
    inventory = checker.load(ROOT / "docs" / "memory" / "active_registry_inventory.json")
    ownership = checker.load(ROOT / "docs" / "memory" / "machine_ownership.json")
    assert checker.validate(inventory, ownership) == []

    broken = copy.deepcopy(inventory)
    broken["registries"] = broken["registries"][:-1]
    assert any("registry coverage mismatch" in e for e in checker.validate(broken, ownership))

    broken = copy.deepcopy(inventory)
    broken["registries"][0]["health"]["missing_governance_fields"] = 1
    assert any("missing_governance_fields=1" in e for e in checker.validate(broken, ownership))

    broken = copy.deepcopy(inventory)
    broken["registries"][0]["machine_ownership_ids"].append("unknown.owner")
    assert any("unresolved machine ownership" in e for e in checker.validate(broken, ownership))

    broken = copy.deepcopy(ownership)
    broken["owners"].append(dict(broken["owners"][0]))
    assert any("duplicate machine ownership id" in e for e in checker.validate(inventory, broken))

    print("active_registry_coverage_checker_test: PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
