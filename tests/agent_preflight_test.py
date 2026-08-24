#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("agent_preflight", ROOT / "tools/agent_preflight.py")
assert spec and spec.loader
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


def fast_ok(files, lines=1, deleted=False):
    return (
        1 <= len(files) <= p.FAST_FILE_LIMIT
        and all(p.FAST_ALLOWED.fullmatch(x) for x in files)
        and not deleted
        and lines <= p.FAST_LINE_LIMIT
        and any(x.startswith("app/View/pages/") or x.startswith("public/assets/") for x in files)
    )


def caps(*, php=True, pdo=True, mysql_client=True, server=True):
    return {
        "PHP_AVAILABLE": php,
        "PDO_MYSQL_AVAILABLE": pdo,
        "MYSQL_CLIENT_AVAILABLE": mysql_client,
        "MYSQL_SERVER_REACHABLE": server,
    }


# Capability detector contract exposes the required stable keys.
assert tuple(p.CAPABILITY_KEYS) == (
    "PHP_AVAILABLE",
    "PDO_MYSQL_AVAILABLE",
    "MYSQL_CLIENT_AVAILABLE",
    "MYSQL_SERVER_REACHABLE",
)

# Original acceptance scenarios 7-11: FAST policy.
assert fast_ok(["app/View/pages/company_x.php"])
assert not fast_ok(["app/Service/Foo.php"])
assert not fast_ok([f"app/View/pages/x{i}.php" for i in range(7)])
assert not fast_ok(["public/assets/css/x.css"], 121)
assert not fast_ok(["public/assets/css/x.css"], 1, True)

# STANDARD critical escalation remains fail-closed.
assert p.ci.critical_path([".github/workflows/ci.yml"])
assert p.ci.critical_path(["database/migrations-local/100_x.sql"])
assert p.ci.critical_path(["tools/agent_preflight.py"])
assert p.ci.critical_path(["AGENTS.md"])

# CRITICAL includes STANDARD global safety checks.
scopes = p.ci.classify(["tools/agent_preflight.py"])
planned = p.plan("CRITICAL", ["tools/agent_preflight.py"], scopes)
assert "DYNAMIC_IDENTIFIER_SAFETY" in planned
assert "ARCHITECTURE_GUARD" in planned
assert "CONTROL_PLANE_PREFLIGHT_CONTRACTS" in planned

# Tenant scope plans the real canonical integration contract.
tenant_scopes = p.ci.classify(["database/migrations-local/100_x.sql"])
tenant_plan = p.plan("CRITICAL", ["database/migrations-local/100_x.sql"], tenant_scopes)
assert "TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT" in tenant_plan

# Capability-aware contract 1: real MySQL capability => tenant is LOCAL_REQUIRED.
local, remote, missing = p.check_execution_classes(tenant_plan, caps())
assert "TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT" in local
assert "TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT" not in remote
assert missing == []

# Capability-aware contract 2/3: missing PDO/server => tenant is mandatory REMOTE_REQUIRED, never optional.
for capability_state in [
    caps(pdo=False, server=False),
    caps(pdo=True, server=False),
]:
    local, remote, missing = p.check_execution_classes(tenant_plan, capability_state)
    assert "TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT" not in local
    assert remote == ["TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT"]
    assert missing
    assert set(local) | set(remote) == set(tenant_plan)

# Missing mysql CLI alone does not demote a working PDO/server contract.
local, remote, missing = p.check_execution_classes(tenant_plan, caps(mysql_client=False))
assert "TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT" in local
assert not remote and not missing

# Contract 5: non-allowlisted missing baseline capability is ENV_ERROR.
try:
    p.check_execution_classes(tenant_plan, caps(php=False, pdo=False, server=False))
except p.PreflightEnvironmentError:
    pass
else:
    raise AssertionError("missing PHP must be LOCAL_PREFLIGHT_ENV_ERROR, not REMOTE_REQUIRED")

# Contract 6: fail state cannot become PASS_WITH_REMOTE_GATES (main checks local results first).
source = (ROOT / "tools/agent_preflight.py").read_text(encoding="utf-8")
assert 'if any(value != "PASS" for value in results.values())' in source
assert source.index('print("LOCAL_PREFLIGHT_FAIL")') < source.index('print("LOCAL_PREFLIGHT_PASS_WITH_REMOTE_GATES")')

# Contract 8: Memory Integrity is never allowlisted REMOTE_REQUIRED.
assert "MEMORY_INTEGRITY_CONTRACTS" not in p.REMOTE_REQUIRED_ALLOWLIST
assert "ACTIVE_REGISTRY_CONTRACTS" not in p.REMOTE_REQUIRED_ALLOWLIST

# No arbitrary demotion: canonical allowlist is exact and tiny.
assert set(p.REMOTE_REQUIRED_ALLOWLIST) == {"TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT"}
assert p.REMOTE_REQUIRED_ALLOWLIST["TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT"] == frozenset(
    {"PDO_MYSQL_AVAILABLE", "MYSQL_SERVER_REACHABLE"}
)

# Plan is deterministic.
assert p.plan("CRITICAL", ["tools/agent_preflight.py"], scopes) == p.plan(
    "CRITICAL", ["tools/agent_preflight.py"], scopes
)

# FAST privileged workflow parity + boundary remains intact.
fast = (ROOT / ".github/workflows/erpv2_fast_release.yml").read_text(encoding="utf-8")
assert "pull_request_target:" in fast
assert "1 <= len(files) <= 6" in fast
assert "changed_lines <= 120" in fast
assert "row.get('status') != 'removed'" in fast
assert "has_ui = has_ui or name.startswith('app/View/pages/') or name.startswith('public/assets/')" in fast
assert p.FAST_FILE_LIMIT == 6 and p.FAST_LINE_LIMIT == 120
for path in [
    "app/View/pages/x.php",
    "public/assets/css/x.css",
    "public/assets/js/x.js",
    "tests/x_ui_contract_test.php",
]:
    assert p.FAST_ALLOWED.fullmatch(path)
for path in ["app/Service/X.php", "database/migrations-local/100_x.sql"]:
    assert not p.FAST_ALLOWED.fullmatch(path)
assert fast.index("Validate exact base and FAST scope before checkout") < fast.index(
    "Checkout exact FAST head after scope validation"
)
assert "tools/ci_scope.py" not in fast.split("Checkout exact FAST head after scope validation")[0]

# Contract 4/10: REMOTE tenant gate remains path-triggered and existing commands remain intact in GitHub CI.
ci_text = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
for token in [
    "tests/tenant_schema_provisioning_contract_test.php",
    "PLANEX_SCHEMA_CANDIDATE_DIR=/tmp/planex-tenant-schema-docs php tools/database/generate_schema_docs.php --check",
    "steps.changes.outputs.tenant_schema == 'true'",
]:
    assert token in ci_text, token
assert p.ci.classify([".github/workflows/ci.yml"])["tenant_schema"] is True

# Existing memory ordering semantics remain delegated to canonical checker contracts.
assert "memory_integrity_checker_test.py" in source
assert "active_registry_coverage_checker_test.py" in source
assert '"--head"' in source
assert '"tools/active_registry_coverage_check.py","docs/memory/active_registry_inventory.json","docs/memory/machine_ownership.json"' in source

print("agent_preflight_test: PASS")
