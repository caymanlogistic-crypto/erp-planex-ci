#!/usr/bin/env python3
"""Deterministic changed-path classifier shared by local preflight and GitHub CI."""
from __future__ import annotations
import argparse, fnmatch, json, re, subprocess, sys
from pathlib import Path

SCOPE_PATTERNS = {
    "document_attachments": r"^(app/Service/RouteExecutor[^/]*\.php$|public/assets/js/route-executor-review-workflow\.js$|scripts/registry_document_(?:attachment_recovery|inventory_readonly_probe)\.php$|tests/document_(?:attachment|inventory)|tests/route_executor_combined_mysql_test\.php$|tools/document_attachment[^/]*\.(?:py|mjs)$|\.github/workflows/(?:document_attachment|document_inventory|registry_document_inventory))",
    "php": r"\.php$",
    "js": r"\.(js|mjs)$",
    "python": r"^tools/.*\.py$",
    "finance": r"^(app/(Service/(?:Finance|BankFinanceService\.php$|BankStatement[^/]*\.php$|HardenedBankStatementImapImporter\.php$)|Http/Controllers/Company/(Finance|BankFinance|Invoice)|Http/Routes/company_(finance|bank)|View/(pages|partials)/company_(finance|bank|invoice))|tests/finance_|database/migrations-local/0(6|7|8|9)[0-9]_.*|scripts/(p41_|p80_|p9[0-9]_|finance_direct))",
    "security": r"^(app/Service/(AccessControlService|UserSyncService|CompanyDeletionService)\.php$|app/Http/Controllers/AuthController\.php$|app/Http/Controllers/Superadmin/|app/Http/Routes/superadmin\.php$)",
    "linear": r"^(app/(Service/Linear|Http/Controllers/Company/LinearTrip|Http/Routes/company_linear|View/(pages|partials)/company_linear)|tests/(linear_|p1[345]_linear|stage2_route)|database/migrations-local/058_|scripts/p39_)",
    "migrations": r"^(database/|scripts/.*migrat|scripts/p[0-9]+_apply_)",
    "migration_normalization": r"^(database/migrations-local/(057_create_crew_drivers|058_create_linear_route_points)\.sql|scripts/p40_reconcile_migration_names\.php)$",
    "tenant_schema": r"^(database/migrations-local/|app/Service/LocalMigrationService\.php$|app/Support/company_database\.php$|app/Http/Controllers/Superadmin/CompanyActions/create_submit\.php$|tools/database/|tests/tenant_schema_provisioning_contract_test\.php$|docs/database/|\.github/workflows/ci\.yml$)",
    "ui_lab": r"^(app/Http/Controllers/Superadmin/UiPatternLabController\.php$|app/Http/Routes/superadmin\.php$|app/View/pages/ui_pattern_lab\.php$|app/View/components/(button|input|page_header|status_badge|table)\.php$|public/assets/css/(ui-pattern-lab|erp-ui|app|app-base|workspace-benchmark)\.css$|tests/ui_pattern_lab_contract_test\.php$|\.github/ui-pattern-lab/ui_pattern_lab_visual\.js$)",
    "approved_ui": r"^(app/(View|Http/Controllers)/.*\.php$|public/assets/(css/.*\.css$|js/.*\.(?:js|mjs)$)|docs/ui/.*|\.agents/skills/planex-ui-standard/|tools/(?:ui_standard|ui_style_guard|design_lab)\.py$|tools/(?:(?:ui_parity|registry_runtime_gate|registry_visual_gate|registry_matrix)\.mjs|ci_registry_runtime\.sh)$|tests/(fixtures/(?:company_registry_|drivers_registry_).*|.*_browser_test\.mjs$|registry_.*|ui_style_guard_test\.py$|helpers/(?:registry_.*|ui_appearance).*))",
    "memory": r"^(docs/memory/|tools/(memory_integrity_check|active_registry_coverage_check)\.py$|tests/(memory_integrity_checker|active_registry_coverage_checker)_test\.py$)",
    "control_plane": r"^(\.github/(?:workflows/|p07/)|\.agents/skills/(?:planex-design-lab|planex-ui-standard)/|AGENTS\.md$|docs/(?:DESIGN_LAB|GITHUB_CONTROL_PLANE|LOCAL_PREFLIGHT_PUBLISHING)\.md$|\.github/pull_request_template\.md$|tools/(?:runner/|sentinelx/|(?:agent_preflight|ci_scope|design_lab|local_publish|ui_style_guard|ui_standard)\.py$|(?:(?:ui_parity|registry_runtime_gate|registry_visual_gate|registry_matrix)\.mjs|ci_registry_runtime\.sh)$)|tests/(?:(?:design_lab_contract|agent_deploy_policy_contract|runner_helpers_contract|sentinelx_hardening|local_publish|ui_style_guard)_test\.py$|(?:agent_preflight|ci_scope)_test\.py$))",
}
CRITICAL_PATH_SCOPES = ("finance", "security", "migrations", "tenant_schema", "memory", "control_plane")
KNOWN_CRITICAL_PATHS = (
    re.compile(r"^\.github/workflows/"),
    re.compile(r"^\.github/p07/"),
    re.compile(r"^database/"),
    re.compile(r"^tools/database/"),
    re.compile(r"^docs/database/"),
    re.compile(r"^app/Service/LocalMigrationService\.php$"),
    re.compile(r"^app/Support/company_database\.php$"),
    re.compile(r"^AGENTS\.md$"),
    re.compile(r"^docs/GITHUB_CONTROL_PLANE\.md$"),
    re.compile(r"^docs/DESIGN_LAB\.md$"),
    re.compile(r"^\.agents/skills/planex-design-lab/"),
    re.compile(r"^\.github/pull_request_template\.md$"),
    re.compile(r"^tools/(agent_preflight|ci_scope|design_lab)\.py$"),
    re.compile(r"^tools/runner/"),
    re.compile(r"^tools/sentinelx/"),
    re.compile(r"^tests/(agent_preflight|ci_scope)_test\.py$"),
    re.compile(r"^tests/design_lab_contract_test\.py$"),
    re.compile(r"^tests/runner_helpers_contract_test\.py$"),
    re.compile(r"^tests/sentinelx_hardening_test\.py$"),
    re.compile(r"^docs/memory/"),
    re.compile(r"^tools/(memory_integrity_check|active_registry_coverage_check)\.py$"),
    re.compile(r"^tests/(memory_integrity_checker|active_registry_coverage_checker)_test\.py$"),
)

def git_changed_files(base: str, head: str) -> list[str]:
    cp = subprocess.run(["git","diff","--name-only","--diff-filter=ACMR",f"{base}..{head}"], text=True, capture_output=True)
    if cp.returncode:
        raise RuntimeError(cp.stderr.strip() or "git diff failed")
    return [x for x in cp.stdout.splitlines() if x]

def classify(paths: list[str], full_scan: bool=False) -> dict[str,bool]:
    out = {k: bool(full_scan) for k in SCOPE_PATTERNS}
    if not full_scan:
        for key, pat in SCOPE_PATTERNS.items():
            rx = re.compile(pat)
            out[key] = any(rx.search(p) for p in paths)
    # The catalog owns additional source -> required contracts routing in both CI and LOCAL.
    catalog=json.loads((Path(__file__).resolve().parents[1]/'docs/ui/approved-components.json').read_text(encoding='utf-8'))
    sources=[source for page in catalog['pages'] for source in [page.get('view'),page.get('asset_owner'),page.get('browser_test'),page.get('runtime_browser_test'),*page.get('required_contracts',[]),*page.get('contract_sources',[])] if source]
    out['approved_ui'] = out['approved_ui'] or any(fnmatch.fnmatchcase(path,source) for path in paths for source in sources)
    return out

def critical_path(paths: list[str]) -> bool:
    scopes = classify(paths)
    return any(scopes[name] for name in CRITICAL_PATH_SCOPES) or any(
        rx.search(p) for p in paths for rx in KNOWN_CRITICAL_PATHS
    )

def main() -> int:
    ap=argparse.ArgumentParser()
    ap.add_argument("--base")
    ap.add_argument("--head")
    ap.add_argument("--format",choices=["json","text"],default="text")
    ap.add_argument("--github-output")
    ap.add_argument("--full-scan",action="store_true")
    ns=ap.parse_args()
    try:
        if ns.full_scan:
            cp=subprocess.run(["git","ls-files"],text=True,capture_output=True,check=True)
            files=[x for x in cp.stdout.splitlines() if x]
        else:
            if not ns.base or not ns.head:
                raise RuntimeError("--base and --head are required unless --full-scan is used")
            files=git_changed_files(ns.base,ns.head)
        scopes=classify(files,ns.full_scan)
        payload={"changed_files":files,"scopes":scopes,"full_scan":ns.full_scan,"critical_path":critical_path(files)}
        if ns.github_output:
            with open(ns.github_output,"a",encoding="utf-8") as fh:
                fh.write(f"full_scan={'true' if ns.full_scan else 'false'}\n")
                for k,v in scopes.items(): fh.write(f"{k}={'true' if v else 'false'}\n")
            Path("/tmp/changed_files").write_text("\n".join(files)+("\n" if files else ""),encoding="utf-8")
        print(json.dumps(payload,sort_keys=True) if ns.format=="json" else "\n".join(f"{k}={str(v).lower()}" for k,v in scopes.items()))
        return 0
    except (RuntimeError, subprocess.CalledProcessError) as exc:
        print(f"CI_SCOPE_ENV_ERROR: {exc}",file=sys.stderr)
        return 2
if __name__=="__main__": raise SystemExit(main())
