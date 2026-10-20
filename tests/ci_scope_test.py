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


# Basic owning scopes.
yes("app/View/pages/company_test.php", "php")
yes("app/Service/Finance/Foo.php", "finance")
yes("app/Service/BankFinanceService.php", "finance")
yes("app/Service/AccessControlService.php", "security")
yes("app/Service/Linear/Foo.php", "linear")
yes("database/migrations-local/099_test.sql", "migrations")
yes("database/migrations-local/099_test.sql", "tenant_schema")
yes(".github/workflows/foo.yml", "control_plane")
yes(".github/p07/P07_deploy_erpv2.sh", "control_plane")

# Memory + preflight tooling scope.
yes("docs/memory/critical_records.json", "memory")
yes("tools/agent_preflight.py", "control_plane")
yes("tools/local_publish.py", "control_plane")
yes("tests/local_publish_test.py", "control_plane")
yes("tests/agent_preflight_test.py", "control_plane")
yes("tools/runner/helpers/push-task", "control_plane")
yes("tests/runner_helpers_contract_test.py", "control_plane")
yes("tools/sentinelx/verify_pin.py", "control_plane")
yes("tests/sentinelx_hardening_test.py", "control_plane")

# Fail-closed known CRITICAL families.
for path in [
    "app/Service/FinanceMatchingRuleService.php",
    "app/Service/BankFinanceService.php",
    "app/Service/BankStatementXlsxParser.php",
    "app/Service/AccessControlService.php",
    "app/Http/Controllers/Superadmin/CompaniesController.php",
    "app/Service/CompanyDeletionService.php",
    "database/migrations-local/100_x.sql",
    ".github/workflows/ci.yml",
    ".github/p07/P07_deploy_erpv2.sh",
    "tools/database/generate_schema_docs.php",
    "tools/agent_preflight.py",
    "tools/local_publish.py",
    "tests/local_publish_test.py",
    "tools/runner/manifest.json",
    "tests/runner_helpers_contract_test.py",
    "tools/sentinelx/manifest.json",
    "tests/sentinelx_hardening_test.py",
    "AGENTS.md",
    "docs/GITHUB_CONTROL_PLANE.md",
    "docs/memory/critical_records.json",
    "tools/memory_integrity_check.py",
    "tests/active_registry_coverage_checker_test.py",
]:
    assert m.critical_path([path]), path

# A normal non-finance/non-security service is not escalated by path alone.
assert not m.critical_path(["app/Service/ClientService.php"])

# Critical families are centralized as scopes so future members inherit fail-closed escalation.
assert set(m.CRITICAL_PATH_SCOPES) == {
    "finance",
    "security",
    "migrations",
    "tenant_schema",
    "memory",
    "control_plane",
}

# Memory governance is a first-class CRITICAL family.
for path in [
    "docs/memory/machine_ownership.json",
    "tools/active_registry_coverage_check.py",
    "tests/memory_integrity_checker_test.py",
]:
    assert m.classify([path])["memory"], path
    assert m.critical_path([path]), path

# Exact preserved legacy classifier families + additive security/memory/control_plane.
expected = {
    "document_attachments",
    "php",
    "js",
    "python",
    "finance",
    "security",
    "linear",
    "migrations",
    "migration_normalization",
    "tenant_schema",
    "ui_lab",
    "approved_ui",
    "memory",
    "control_plane",
}
assert set(m.SCOPE_PATTERNS) == expected

for path in [
    "public/assets/css/registry-components.css",
    "app/View/pages/company_waste_trips.php",
    "docs/ui/approved-components.json",
    ".agents/skills/planex-ui-standard/SKILL.md",
    "tools/ui_standard.py",
    "tools/ui_style_guard.py",
    "tools/ui_parity.mjs",
    "tools/ci_registry_runtime.sh",
    "tests/helpers/registry_behavior_contract.mjs",
    "tests/fixtures/company_registry_runtime.php",
    "tests/fixtures/company_registry_expectations.mjs",
    "app/View/components/other.php",
    "app/View/layouts/company.php",
]:
    yes(path, "approved_ui")

assert m.critical_path(["tools/ui_style_guard.py"])
assert m.critical_path(["tools/ui_parity.mjs"])
assert m.critical_path(["tools/ci_registry_runtime.sh"])

for path in [
    "tools/design_lab.py",
    "tests/design_lab_contract_test.py",
    "docs/DESIGN_LAB.md",
    ".agents/skills/planex-design-lab/SKILL.md",
]:
    assert m.classify([path])["control_plane"], path
    assert m.critical_path([path]), path

# Critical regression: ci.yml must remain tenant_schema.
assert m.classify([".github/workflows/ci.yml"])["tenant_schema"] is True

# Deterministic same input -> same result.
paths = ["app/Service/Finance/Foo.php", "tests/finance_x.php"]
assert m.classify(paths) == m.classify(list(paths))

print("ci_scope_test: PASS")
