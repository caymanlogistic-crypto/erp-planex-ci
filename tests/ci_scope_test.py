#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ci_scope", ROOT / "tools/ci_scope.py")
assert spec and spec.loader
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


def yes(path, scope):
    assert m.classify([path])[scope], (path, scope)


# 1-6 basic owning scopes.
yes("app/View/pages/company_test.php", "php")
yes("app/Service/Finance/Foo.php", "finance")
yes("app/Service/Linear/Foo.php", "linear")
yes("database/migrations-local/099_test.sql", "migrations")
yes("database/migrations-local/099_test.sql", "tenant_schema")
yes(".github/workflows/foo.yml", "control_plane")

# Memory + new preflight tooling scope.
yes("docs/memory/critical_records.json", "memory")
yes("tools/agent_preflight.py", "control_plane")
yes("tests/agent_preflight_test.py", "control_plane")

# Fail-closed known critical families.
for path in [
    "database/migrations-local/100_x.sql",
    ".github/workflows/ci.yml",
    "tools/database/generate_schema_docs.php",
    "tools/agent_preflight.py",
    "AGENTS.md",
    "docs/GITHUB_CONTROL_PLANE.md",
]:
    assert m.critical_path([path]), path

# Exact preserved legacy classifier families + additive memory/control_plane.
expected = {
    "php",
    "js",
    "python",
    "finance",
    "linear",
    "migrations",
    "migration_normalization",
    "tenant_schema",
    "ui_lab",
    "memory",
    "control_plane",
}
assert set(m.SCOPE_PATTERNS) == expected

# Critical regression: ci.yml must remain tenant_schema. Never weaken this to avoid local capability limits.
assert m.classify([".github/workflows/ci.yml"])["tenant_schema"] is True

# Deterministic same input -> same result.
paths = ["app/Service/Finance/Foo.php", "tests/finance_x.php"]
assert m.classify(paths) == m.classify(list(paths))

print("ci_scope_test: PASS")
