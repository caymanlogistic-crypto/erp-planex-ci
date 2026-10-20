#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import re
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

# AUTO selects the least expensive safe class deterministically.
assert p.resolve_class("AUTO", ["public/assets/css/x.css"], [("M", "public/assets/css/x.css")], 10) == "FAST"
assert p.resolve_class("AUTO", ["app/Service/ClientService.php"], [("M", "app/Service/ClientService.php")], 10) == "STANDARD"
assert p.resolve_class("AUTO", ["tools/local_publish.py"], [("M", "tools/local_publish.py")], 10) == "CRITICAL"
assert p.resolve_class("CRITICAL", ["public/assets/css/x.css"], [("M", "public/assets/css/x.css")], 1) == "CRITICAL"

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
assert '"tests/agent_deploy_policy_contract_test.py"' in (ROOT / "tools/agent_preflight.py").read_text(encoding="utf-8")

# Every UI publication route must execute the shared browser and source guard.
assert "APPROVED_UI_CONTRACTS" in p.plan("FAST", ["public/assets/css/x.css"], p.ci.classify(["public/assets/css/x.css"]))
assert "APPROVED_UI_CONTRACTS" in p.plan("STANDARD", ["app/View/pages/list.php"], p.ci.classify(["app/View/pages/list.php"]))
for workflow in ["ci.yml", "erpv2_fast_release.yml"]:
    workflow_source = (ROOT / ".github/workflows" / workflow).read_text(encoding="utf-8")
    assert "tools/ui_style_guard.py --base" in workflow_source
    assert "node tests/ui_standard_gallery_browser_test.mjs" in workflow_source
    assert "node tests/ui_standard_lab_browser_test.mjs" in workflow_source
    assert 'run: bash tools/ci_registry_runtime.sh' in workflow_source
    assert workflow_source.index("run: php tests/entrypoint_service_load_test.php") < workflow_source.index("- name: Setup Node for approved UI browser QA")

# Real execution ordering: a failed production startup gate prevents the costly
# visual matrix, and remains a failed required UI group. No real DB is needed.
original_run, original_exe = p.run, p.exe
try:
    commands = []
    p.exe = lambda name: None
    p.run = lambda command, env=None: (commands.append(command) or 1)
    startup_result = {}
    p.approved_ui_checks("base", "head", startup_result)
    assert commands == [["php", "tests/entrypoint_service_load_test.php"]]
    assert startup_result == {"APPROVED_UI_CONTRACTS": "FAIL"}
finally:
    p.run, p.exe = original_run, original_exe

# The same registered full runtime gate must be mandatory locally, not CI-only.
try:
    commands=[]
    p.exe=lambda name: None
    p.run=lambda command,env=None:(commands.append(command) or 0)
    result={};p.approved_ui_checks('base','head',result)
    assert ['node','tools/registry_runtime_gate.mjs'] in commands
    assert result['APPROVED_UI_CONTRACTS']=='PASS'
    p.run=lambda command,env=None: 1 if command==['node','tools/registry_runtime_gate.mjs'] else 0
    result={};p.approved_ui_checks('base','head',result)
    assert result['APPROVED_UI_CONTRACTS']=='FAIL'
finally:
    p.run,p.exe=original_run,original_exe

# Tenant scope plans the real canonical integration contract.
tenant_scopes = p.ci.classify(["database/migrations-local/100_x.sql"])
tenant_plan = p.plan("CRITICAL", ["database/migrations-local/100_x.sql"], tenant_scopes)
assert "TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT" in tenant_plan
assert "MAX_REGRESSIONS" in tenant_plan
assert "WASTE_TRIPS_REGRESSIONS" in tenant_plan
assert "DOCUMENT_GENERATOR_REGRESSIONS" in tenant_plan
for path, check in [
    ("app/Service/MaxEventNotificationService.php", "MAX_REGRESSIONS"),
    ("tests/waste_trip_carrier_finance_regression_test.php", "WASTE_TRIPS_REGRESSIONS"),
    ("app/Http/Routes/company_max_messaging.php", "ROLES_ROUTE_REGISTRY"),
    ("app/Service/WasteTripService.php", "DOCUMENT_GENERATOR_REGRESSIONS"),
    ("tests/document_generator_regression_v4_test.php", "DOCUMENT_GENERATOR_REGRESSIONS"),
]:
    assert check in p.plan("CRITICAL", [path], p.ci.classify([path]))
assert "tests/max_integration_mysql_test.php" in p.MAX_TESTS
assert "tests/waste_trip_carrier_finance_regression_test.php" in p.WASTE_TESTS

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

# Contract 6: normal LOCAL execution must fail closed when any remote gate would be required.
source = (ROOT / "tools/agent_preflight.py").read_text(encoding="utf-8")
assert 'configured_tool("PLANEX_PHP","php")' in source
assert 'configured_tool("PLANEX_PYTHON"' not in source  # current interpreter is authoritative
assert 'configured_tool("PLANEX_NODE","node")' in source
assert 'configured_tool("PLANEX_GIT","git")' in source
assert 'configured_tool("PLANEX_MYSQL","mysql")' in source
assert 'command=exact_command(cmd)' in source
assert 'LOCAL_PREFLIGHT_REMOTE_GATES_FORBIDDEN' in source
assert 'print("LOCAL_PREFLIGHT_PASS_WITH_REMOTE_GATES")' not in source
assert source.index('if remote:') < source.index('results=execute_local_required')

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
# CI must provide the same guarded test environment as local preflight.
max_step = ci_text.split("- name: MAX messaging regressions", 1)[1].split("- name:", 1)[0]
assert "APP_ENV: test" in max_step
assert "PLANEX_TEST_FIXED_DB_ALLOWLIST=spugovxsim_test2" in max_step
assert set(re.findall(r"php (tests/[a-z0-9_]+\.php)", max_step)) == set(p.MAX_TESTS)
waste_steps = (ROOT / ".github/workflows/waste_trips_regression.yml").read_text(encoding="utf-8").split("- name: Static target contracts", 1)[1]
assert set(re.findall(r"php (tests/[a-z0-9_]+\.php)", waste_steps)) == set(p.WASTE_TESTS)
docgen_steps = (ROOT / ".github/workflows/document_generator_regression.yml").read_text(encoding="utf-8")
assert set(re.findall(r"php (tests/[a-z0-9_]+\.php)", docgen_steps)) == set(p.DOCGEN_TESTS)
assert 'env["PLANEX_DOCGEN_REQUIRE_RUNTIME"]="1"' in source
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
assert "runner_helpers_contract_test.py" in source
assert "local_publish_test.py" in source
assert "design_lab_contract_test.py" in source
assert 'p.startswith("tools/sentinelx/")' in source
assert '"--head"' in source
assert '"tools/active_registry_coverage_check.py","docs/memory/active_registry_inventory.json","docs/memory/machine_ownership.json"' in source

# PREFLIGHT_COVERAGE_DEFECT regression: deploy policy must track centralized CI classifier and current lifecycle.
policy = (ROOT / ".github/workflows/erpv2_agent_deploy_policy.yml").read_text(encoding="utf-8")
assert "python3 tools/ci_scope.py" in policy
assert '--github-output "$GITHUB_OUTPUT"' in policy
assert "Control-plane preflight regression contracts" in policy
assert "START_HEAD → WORK_BRANCH → LOCAL_PREFLIGHT_PASS → LOCAL_PUBLISH_PASS → FEATURE_HEAD → PR_NUMBER → CI_AGGREGATE_RESULT → MERGED_SHA → LIVE_NOTION_MEMORY_PASS → optional DEPLOY_RESULT" in policy
assert "Observe all required workflows for one `FEATURE_HEAD` as one aggregate gate" in policy
assert "Normal development is LOCAL:" in policy
assert "SentinelX and the persistent remote runner are outside the normal PLANEX task route" in policy
assert "Parallel LOCAL agents / shared-machine safety" in policy
assert "PREFLIGHT_COVERAGE_DEFECT" in policy
assert "missing incremental CI safeguard: git diff --name-only --diff-filter=ACMR HEAD^1 HEAD" not in policy

agents = (ROOT / "AGENTS.md").read_text(encoding="utf-8")
for token in [
    "Do not re-fetch canonical HEAD after ordinary edits, tests or Notion writes",
    "Repeat search/fetch is forbidden without ambiguity, drift or a state-changing event",
    "No placeholder/probe/temp/dummy files, empty/checkpoint commits or temporary verification PRs",
    "Do not duplicate a regression already triggered by GitHub",
    "Observe all required workflows for one `FEATURE_HEAD` as one aggregate gate",
    "Normal development is LOCAL:",
    "SentinelX and the persistent remote runner are outside the normal PLANEX task route",
    "Parallel LOCAL agents / shared-machine safety",
    "Technical integration actor identity is not the current standard request gate",
    "local\\New-PlanexTask.ps1",
    "local\\Test-LocalReadiness.ps1",
    "local\\Invoke-LocalBrowserTest.ps1",
    "Windows worktrees use Windows Git",
    "MEMORY drift/integrity is checked before publication and also at task start",
    "Discover files with `rg --files`",
]:
    assert token in agents, token



for path in ["app/Service/FeoLegacyImportSync.php", "app/Service/FeoXlsxParser.php", "app/View/pages/company_feo_requests.php", "scripts/feo_legacy_sync_configure.php", "tests/feo_import_browser_test.mjs"]:
    assert "FEO_TRANSFER_REGRESSIONS" in p.plan("CRITICAL", [path], p.ci.classify([path]))
assert "tests/feo_legacy_import_sync_mysql_test.php" in p.FEO_TRANSFER_TESTS
assert "tests/feo_placement_legacy_sync_mysql_test.php" in p.FEO_TRANSFER_TESTS
assert "tests/feo_placement_legacy_sync_xa_mysql_test.php" in p.FEO_TRANSFER_TESTS
assert "tests/feo_xlsx_parser_format_test.php" in p.FEO_TRANSFER_TESTS
print("agent_preflight_test: PASS")

# Attachment retention is required even for a small eligible JS correction.
attachment_path="public/assets/js/route-executor-review-workflow.js"
attachment_scopes=p.ci.classify([attachment_path])
for cls in ("FAST","STANDARD","CRITICAL"):
    assert "DOCUMENT_ATTACHMENT_REGRESSIONS" in p.plan(cls,[attachment_path],attachment_scopes)
