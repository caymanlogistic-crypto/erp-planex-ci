#!/usr/bin/env python3
"""Fail-closed local pre-push gate with deterministic automatic task classification."""
from __future__ import annotations
import argparse, importlib.util, json, os, re, shutil, subprocess, sys, tempfile
from collections import Counter
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("ci_scope",ROOT/"tools/ci_scope.py")
if not spec or not spec.loader: raise RuntimeError("unable to load tools/ci_scope.py")
ci=importlib.util.module_from_spec(spec); spec.loader.exec_module(ci)
FAST_ALLOWED=re.compile(r"^(?:app/View/pages/[A-Za-z0-9_./-]+\.php|public/assets/(?:css|js)/[A-Za-z0-9_./-]+|tests/[A-Za-z0-9_]+_ui_contract_test\.php)$")
FAST_FILE_LIMIT=6; FAST_LINE_LIMIT=120
TENANT="TENANT_SCHEMA_PROVISIONING_AND_GENERATED_DRIFT"
PYTHON=sys.executable
def configured_tool(env_name,name):
    configured=os.environ.get(env_name)
    if configured:
        path=Path(configured)
        return str(path.resolve()) if path.is_file() else None
    return shutil.which(name)
PHP=configured_tool("PLANEX_PHP","php")
NODE=configured_tool("PLANEX_NODE","node")
GIT=configured_tool("PLANEX_GIT","git")
MYSQL=configured_tool("PLANEX_MYSQL","mysql")
TOOLS={"php":PHP,"node":NODE,"git":GIT,"mysql":MYSQL}
REMOTE_REQUIRED_ALLOWLIST={TENANT:frozenset({"PDO_MYSQL_AVAILABLE","MYSQL_SERVER_REACHABLE"})}
CAPABILITY_KEYS=("PHP_AVAILABLE","PDO_MYSQL_AVAILABLE","MYSQL_CLIENT_AVAILABLE","MYSQL_SERVER_REACHABLE")
FINANCE_TESTS=["tests/finance_invoice_ui_contract_test.php","tests/finance_manual_facts_regression_test.php","tests/finance_employee_personal_expense_event_regression_test.php","tests/finance_invoice_cash_employee_regression_test.php","tests/finance_cash_employee_invoice_lifecycle_regression_test.php","tests/finance_carrier_payables_regression_test.php"]
MAX_TESTS=["tests/max_integration_contract_test.php","tests/max_messaging_133_production_migration_contract_test.php","tests/max_messaging_production_acceptance_contract_test.php","tests/agent_db_migration_request_contract_test.php","tests/max_integration_mysql_test.php"]
FEO_TRANSFER_TESTS=["tests/"+name+".php" for name in (
    "feo_legacy_import_sync_mysql_test", "feo_legacy_xa_mysql_test", "feo_legacy_weight_bridge_xa_mysql_test", "feo_legacy_sync_configuration_test", "feo_placement_legacy_sync_mysql_test", "feo_placement_legacy_sync_xa_mysql_test", "feo_xlsx_parser_format_test", "feo_import_modal_regression_test", "feo_requests_contract_test",
    "feo_transfers_contract_test", "feo_transfers_mysql_test", "feo_legacy_transfer_mirror_mysql_test", "feo_transfers_access_mysql_test",
    "feo_transfers_139_production_migration_contract_test", "feo_transfers_139_migration_mysql_test",
    "feo_transfers_activation_contract_test", "feo_transfers_activation_mysql_test",
    "feo_share_statistics_contract_test", "feo_share_statistics_mysql_test",
    "cargo_balance_statistics_contract_test", "cargo_balance_statistics_mysql_test",
)]
WASTE_TESTS=["tests/"+name+".php" for name in (
    "waste_trip_incident_service_test", "waste_trip_legacy_readonly_probe_contract_test",
    "waste_trip_legacy_linked_contract_test", "waste_trip_owner_layout_contract_test",
    "waste_trip_scheduler_contract_test", "waste_trips_runtime_diagnostic_contract_test",
    "waste_trip_244_readonly_probe_contract_test", "waste_trips_130_production_migration_contract_test",
    "waste_trips_134_production_migration_contract_test", "waste_trips_135_production_migration_contract_test",
    "waste_trip_customer_payment_regression_test", "waste_trip_carrier_finance_regression_test",
    "waste_trip_regression_test", "waste_trip_manager_membership_regression_test",
    "waste_trip_legacy_mapping_test", "waste_trip_synchronization_mysql_test",
    "waste_trips_137_production_migration_contract_test", "waste_trips_137_migration_mysql_test",
    "waste_trips_138_production_migration_contract_test", "waste_trips_138_migration_mysql_test",
)]
DOCGEN_TESTS=["tests/"+name+".php" for name in (
    "document_generator_navigation_test", "document_generator_regression_test",
    "document_generator_concurrency_test", "document_generator_129_production_migration_contract_test",
    "document_generator_template_v2_production_contract_test", "document_generator_template_v3_production_contract_test",
    "document_generator_builtin_seed_resilience_test", "document_generator_deploy_artifact_test",
)]
class PreflightEnvironmentError(RuntimeError): pass

POSIX_XA_TESTS=frozenset({"tests/feo_legacy_xa_mysql_test.php","tests/feo_legacy_weight_bridge_xa_mysql_test.php","tests/feo_placement_legacy_sync_xa_mysql_test.php"})
POSIX_XA_ENV=("APP_ENV","PLANEX_SCHEMA_DB_HOST","PLANEX_SCHEMA_DB_PORT","PLANEX_SCHEMA_DB_USER",
              "PLANEX_SCHEMA_DB_PASSWORD","PLANEX_TEST_FIXED_DB","PLANEX_TEST_FIXED_DB_ALLOWLIST",
              "PLANEX_TEST_DB_LEASE","PLANEX_TEST_LEGACY_USER","PLANEX_TEST_LEGACY_PASSWORD")

def local_posix_xa(cmd,env):
    if os.name!="nt" or len(cmd)!=2 or cmd[0]!="php" or cmd[1] not in POSIX_XA_TESTS:
        return cmd,env
    if os.environ.get("PLANEX_LOCAL_POSIX_XA")!="wsl":
        return cmd,env
    host=os.environ.get("PLANEX_LOCAL_POSIX_DB_HOST","")
    port=os.environ.get("PLANEX_LOCAL_POSIX_DB_PORT","")
    distro=os.environ.get("PLANEX_LOCAL_POSIX_DISTRO","Ubuntu")
    if not host or not port.isdigit() or not distro or shutil.which("wsl.exe") is None:
        raise PreflightEnvironmentError("local POSIX XA bridge is incomplete")
    drive,tail=os.path.splitdrive(str(ROOT))
    if not drive or not re.fullmatch(r"[A-Za-z]:",drive):
        raise PreflightEnvironmentError("local POSIX XA requires a Windows drive worktree")
    linux_root=f"/mnt/{drive[0].lower()}{tail.replace(chr(92),'/')}"
    forwarded=(env or os.environ).copy()
    forwarded["PLANEX_SCHEMA_DB_HOST"]=host
    forwarded["PLANEX_SCHEMA_DB_PORT"]=port
    names=set(filter(None,forwarded.get("WSLENV","").split(":")))
    names.update(key for key in POSIX_XA_ENV if key in forwarded)
    forwarded["WSLENV"]=":".join(sorted(names))
    return ["wsl.exe","-d",distro,"--cd",linux_root,"--exec","php",cmd[1]],forwarded

def exact_command(cmd):
    resolved=TOOLS.get(cmd[0])
    if cmd[0] in TOOLS:
        if not resolved: raise PreflightEnvironmentError(f"required executable missing: {cmd[0]}")
        return [resolved,*cmd[1:]]
    return cmd
def caprun(cmd,env=None): return subprocess.run(exact_command(cmd),cwd=ROOT,env=env,text=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
def run(cmd,env=None):
    cmd,env=local_posix_xa(cmd,env)
    command=exact_command(cmd); print("RUN:"," ".join(command)); return subprocess.run(command,cwd=ROOT,env=env,text=True).returncode
def need(path):
    if not (ROOT/path).is_file(): raise PreflightEnvironmentError(f"required file missing: {path}")
def exe(name):
    if name in TOOLS and TOOLS[name] is None: raise PreflightEnvironmentError(f"required executable missing: {name}")
    if name not in TOOLS and shutil.which(name) is None: raise PreflightEnvironmentError(f"required executable missing: {name}")
def detect_capabilities():
    php=PHP is not None; pdo=False; server=False
    if php:
        pdo=caprun(["php","-r",'exit(extension_loaded("pdo_mysql") ? 0 : 1);']).returncode==0
        if pdo:
            probe='$h=getenv("PLANEX_SCHEMA_DB_HOST")?:"127.0.0.1";$p=(int)(getenv("PLANEX_SCHEMA_DB_PORT")?:3306);$u=getenv("PLANEX_SCHEMA_DB_USER")?:"planex_ci";$w=getenv("PLANEX_SCHEMA_DB_PASSWORD")?:"planex_ci";try{$x=new PDO("mysql:host={$h};port={$p};charset=utf8mb4",$u,$w,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$x->query("SELECT 1");exit(0);}catch(Throwable $e){exit(1);}'
            server=caprun(["php","-r",probe],os.environ.copy()).returncode==0
    return {"PHP_AVAILABLE":php,"PDO_MYSQL_AVAILABLE":pdo,"MYSQL_CLIENT_AVAILABLE":MYSQL is not None,"MYSQL_SERVER_REACHABLE":server}

def diff_meta(base,head):
    a=caprun(["git","diff","--name-status",f"{base}..{head}"]); n=caprun(["git","diff","--numstat",f"{base}..{head}"])
    if a.returncode or n.returncode: raise PreflightEnvironmentError((a.stderr or n.stderr).strip() or "git diff failed")
    rows=[(x.split("\t")[0],x.split("\t")[-1]) for x in a.stdout.splitlines() if x]
    lines=sum(int(v) for line in n.stdout.splitlines() for v in line.split("\t")[:2] if v.isdigit())
    return rows,lines

def plan(cls,paths,scopes):
    if cls=="FAST":
        return ["FAST_SCOPE_POLICY","CHANGED_PHP_SYNTAX","CHANGED_JS_SYNTAX","CHEAP_UI_CONTRACTS","APPROVED_UI_CONTRACTS"] + (["DOCUMENT_ATTACHMENT_REGRESSIONS"] if scopes["document_attachments"] else [])
    out=[]
    mapping=[("php","CHANGED_PHP_SYNTAX"),("js","CHANGED_JS_SYNTAX"),("python","CHANGED_PYTHON_SYNTAX"),("finance","FINANCE_REGRESSIONS"),("linear","LINEAR_REGRESSION"),("ui_lab","UI_LAB_CONTRACT"),("migrations","MIGRATION_INTEGRITY"),("migration_normalization","MIGRATION_NORMALIZATION"),("tenant_schema",TENANT)]
    for scope,check in mapping:
        if scopes[scope]: out.append(check)
    if scopes["approved_ui"]: out.append("APPROVED_UI_CONTRACTS")
    if scopes["document_attachments"]: out.append("DOCUMENT_ATTACHMENT_REGRESSIONS")
    out += ["DYNAMIC_IDENTIFIER_SAFETY","ARCHITECTURE_GUARD"]
    if scopes["tenant_schema"] or any(re.search(r"(?:/Feo(?:Transfer|Request|Location|LegacyImport|Import|Xlsx)|/YandexGeocoding|/feo_(?:transfers|legacy|import|xlsx)|/company_feo_(?:transfers|requests)|/feo-transfers|/MaxEventCatalog)", p) for p in paths):
        out.append("FEO_TRANSFER_REGRESSIONS")
    # These domains consume the complete tenant migration chain. A new unrelated
    # migration must still exercise their integration fixtures before publication.
    if scopes["tenant_schema"] or any(re.search(r"(?:/Max|/max_|/company_max|/max-messaging)", p) for p in paths):
        out.append("MAX_REGRESSIONS")
    if scopes["tenant_schema"] or any(re.search(r"(?:/WasteTrip|/waste_trip|/company_waste_trip)", p) for p in paths):
        out.append("WASTE_TRIPS_REGRESSIONS")
    # Document generation consumes WasteTripService and its tenant schema.
    if scopes["tenant_schema"] or any(re.search(r"(?:/WasteTrip|/waste_trip|/Document|/document_generator|/company_document_generator|resources/document-templates/)", p) for p in paths):
        out.append("DOCUMENT_GENERATOR_REGRESSIONS")
    if any(p.startswith("app/Http/Routes/") or p.startswith("tests/roles_access_") for p in paths):
        out.append("ROLES_ROUTE_REGISTRY")
    if any(re.search(r"(?:EmployeeMail|employee_mail|central_016|016_create_employee_mail|roles-access\.js|ServiceKeysController|company_service_keys|service-keys\.js|ReportEmailRecipient|report_email_recipient|TripCompletionController|trip-completion-review\.js|trip_review_browser)", p) for p in paths):
        out.append("EMPLOYEE_MAIL_REGRESSIONS")
    if scopes["tenant_schema"] or any(re.search(r"(?:TripDocument|DocumentMail|trip_document|trip-documents|document_mail|EmployeeMail|MaxEvent)",p) for p in paths):
        out.append("TRIP_DOCUMENT_REGRESSIONS")
    if scopes["control_plane"]: out.append("CONTROL_PLANE_PREFLIGHT_CONTRACTS")
    if cls=="CRITICAL" and scopes["memory"]: out += ["MEMORY_INTEGRITY_CONTRACTS","ACTIVE_REGISTRY_CONTRACTS"]
    return out

def check_execution_classes(planned,caps):
    if not caps.get("PHP_AVAILABLE"): raise PreflightEnvironmentError("missing non-remote-allowlisted capabilities: PHP_AVAILABLE")
    local=[]; remote=[]; missing=set()
    for check in planned:
        allowed=REMOTE_REQUIRED_ALLOWLIST.get(check)
        absent={c for c in allowed or () if not caps.get(c,False)}
        if absent: remote.append(check); missing|=absent
        else: local.append(check)
    return local,remote,sorted(missing)

def mark(results,check,rc):
    if check not in results or results[check]=="PASS": results[check]="PASS" if rc==0 else "FAIL"
def cmdcheck(results,check,cmd,env=None): mark(results,check,run(cmd,env))
def lint(paths,ext,cmd,check,results):
    failed=False
    for p in paths:
        if p.endswith(ext) and (ROOT/p).is_file(): failed |= run(cmd(p))!=0
    mark(results,check,1 if failed else 0)
def migration_integrity(results):
    failed=False
    for d in [ROOT/"database/migrations",ROOT/"database/migrations-local"]:
        nums=[]
        if d.exists():
            for p in d.glob("*.sql"):
                m=re.match(r"(\d+)_",p.name)
                if m: nums.append(int(m.group(1)))
        dup=[n for n,c in Counter(nums).items() if c>1]; print(f"{d.relative_to(ROOT)}: duplicates={dup}"); failed|=bool(dup)
    mark(results,"MIGRATION_INTEGRITY",int(failed))
def fast_policy(rows,lines,results):
    fail=None
    if not 1<=len(rows)<=FAST_FILE_LIMIT: fail=f"FAST requires 1..{FAST_FILE_LIMIT} changed files, got {len(rows)}"
    ui=False
    for st,name in rows:
        if not FAST_ALLOWED.fullmatch(name): fail=fail or f"non-FAST path: {name}"
        if st.startswith("D"): fail=fail or f"FAST may not delete files: {name}"
        ui |= name.startswith("app/View/pages/") or name.startswith("public/assets/")
    if not ui: fail=fail or "FAST requires at least one UI file"
    if lines>FAST_LINE_LIMIT: fail=fail or f"FAST change too large: {lines} changed lines"
    if fail: print("FAST_SCOPE_FAIL="+fail)
    mark(results,"FAST_SCOPE_POLICY",1 if fail else 0)
def fast_eligible(rows,lines):
    if not 1<=len(rows)<=FAST_FILE_LIMIT or lines>FAST_LINE_LIMIT: return False
    if any(st.startswith("D") or not FAST_ALLOWED.fullmatch(name) for st,name in rows): return False
    return any(name.startswith("app/View/pages/") or name.startswith("public/assets/") for _,name in rows)
def resolve_class(requested,paths,rows,lines):
    if requested != "AUTO": return requested
    if ci.critical_path(paths): return "CRITICAL"
    if fast_eligible(rows,lines): return "FAST"
    return "STANDARD"
def cheap_ui(results):
    tests=sorted(ROOT.glob("tests/*_ui_contract_test.php")); failed=not tests
    for p in tests: failed |= run(["php",str(p.relative_to(ROOT))])!=0
    mark(results,"CHEAP_UI_CONTRACTS",int(failed))
def migrations_normalized(results):
    checks=[(ROOT/"database/migrations-local/057_create_crew_drivers.sql").is_file(),(ROOT/"database/migrations-local/058_create_linear_route_points.sql").is_file(),not (ROOT/"database/migrations-local/029_create_crew_drivers.sql").exists(),not (ROOT/"database/migrations-local/052_create_linear_route_points.sql").exists()]
    s=(ROOT/"scripts/p40_reconcile_migration_names.php").read_text(encoding="utf-8") if (ROOT/"scripts/p40_reconcile_migration_names.php").is_file() else ""
    checks += [bool(re.search(r"029_create_crew_drivers\.sql.*057_create_crew_drivers\.sql",s)),bool(re.search(r"052_create_linear_route_points\.sql.*058_create_linear_route_points\.sql",s))]
    mark(results,"MIGRATION_NORMALIZATION",0 if all(checks) else 1)
def tenant_check(results):
    need("tests/tenant_schema_provisioning_contract_test.php"); need("tools/database/generate_schema_docs.php")
    rc=run(["php","tests/tenant_schema_provisioning_contract_test.php"])
    if rc: mark(results,TENANT,rc); return
    with tempfile.TemporaryDirectory(prefix="planex-schema-") as td:
        env=os.environ.copy(); env["PLANEX_SCHEMA_CANDIDATE_DIR"]=td; rc=run(["php","tools/database/generate_schema_docs.php","--check"],env)
        mark(results,TENANT,rc)
        if rc:
            print("GENERATED_SCHEMA_DRIFT:"); cand=Path(td); names=[]
            for f in cand.rglob("*"):
                if f.is_file():
                    rel=f.relative_to(cand); committed=ROOT/"docs/database"/rel
                    if not committed.is_file() or committed.read_bytes()!=f.read_bytes(): names.append(str(Path("docs/database")/rel))
            for x in sorted(names or ["<generator reported drift; no comparable candidate emitted>"]): print(x)
def memory_checks(head,results):
    for p in ["docs/memory/critical_records.json","docs/memory/machine_ownership.json","tools/memory_integrity_check.py","tests/memory_integrity_checker_test.py"]: need(p)
    cmdcheck(results,"MEMORY_INTEGRITY_CONTRACTS",[PYTHON,"tests/memory_integrity_checker_test.py"])
    cmdcheck(results,"MEMORY_INTEGRITY_CONTRACTS",[PYTHON,"tools/memory_integrity_check.py","docs/memory/critical_records.json","--ownership-manifest","docs/memory/machine_ownership.json","--head",head])
def registry_checks(results):
    for p in ["docs/memory/active_registry_inventory.json","docs/memory/machine_ownership.json","tools/active_registry_coverage_check.py","tests/active_registry_coverage_checker_test.py"]: need(p)
    cmdcheck(results,"ACTIVE_REGISTRY_CONTRACTS",[PYTHON,"tests/active_registry_coverage_checker_test.py"])
    cmdcheck(results,"ACTIVE_REGISTRY_CONTRACTS",[PYTHON,"tools/active_registry_coverage_check.py","docs/memory/active_registry_inventory.json","docs/memory/machine_ownership.json"])
def approved_ui_checks(base,head,results):
    exe("php"); exe("node")
    # Fixtures can autoload classes missing from the real production manifest.
    # Reject startup failures before spending time on visual browser matrices.
    need("tests/entrypoint_service_load_test.php")
    cmdcheck(results,"APPROVED_UI_CONTRACTS",["php","tests/entrypoint_service_load_test.php"])
    if results["APPROVED_UI_CONTRACTS"] == "FAIL": return
    for test in ["tests/registry_component_contract_test.py", "tests/ui_standard_catalog_test.py", "tests/ui_style_guard_test.py"]:
        need(test); cmdcheck(results,"APPROVED_UI_CONTRACTS",[PYTHON,test])
    cmdcheck(results,"APPROVED_UI_CONTRACTS",[PYTHON,"tools/ui_standard.py","check"])
    cmdcheck(results,"APPROVED_UI_CONTRACTS",[PYTHON,"tools/ui_style_guard.py","--base",base,"--head",head])
    catalog=json.loads((ROOT/"docs/ui/approved-components.json").read_text(encoding="utf-8"))
    # Run actual routes/controllers/services/layout + bounded role/GET interactions.
    # The trusted LOCAL preflight wrapper supplies a fresh restricted disposable DB.
    for test in ('tests/registry_runtime_gate_test.mjs','tools/registry_runtime_gate.mjs'):
        need(test); cmdcheck(results,'APPROVED_UI_CONTRACTS',['node',test])
    for test in ["tests/ui_standard_gallery_browser_test.mjs","tests/ui_standard_lab_browser_test.mjs"]:
        need(test); cmdcheck(results,"APPROVED_UI_CONTRACTS",["node",test])
    for test in ('tests/registry_visual_gate_test.mjs','tools/registry_visual_gate.mjs'):
        need(test); cmdcheck(results,'APPROVED_UI_CONTRACTS',['node',test])

def execute_local_required(cls,head,paths,scopes,rows,lines,required,base):
    results={}; want=set(required)
    if "APPROVED_UI_CONTRACTS" in want: approved_ui_checks(base,head,results)
    if cls=="FAST":
        exe("php"); fast_policy(rows,lines,results)
        if "CHANGED_PHP_SYNTAX" in want: lint(paths,".php",lambda p:["php","-l",p],"CHANGED_PHP_SYNTAX",results)
        else: mark(results,"CHANGED_PHP_SYNTAX",0)
        if "CHANGED_JS_SYNTAX" in want:
            if any(p.endswith((".js",".mjs")) for p in paths): exe("node")
            failed=any(run(["node","--check",p])!=0 for p in paths if p.endswith((".js",".mjs")) and (ROOT/p).is_file()); mark(results,"CHANGED_JS_SYNTAX",int(failed))
        else: mark(results,"CHANGED_JS_SYNTAX",0)
        cheap_ui(results); return results
    if "CHANGED_PHP_SYNTAX" in want: exe("php"); lint(paths,".php",lambda p:["php","-l",p],"CHANGED_PHP_SYNTAX",results)
    if "CHANGED_JS_SYNTAX" in want: exe("node"); lint(paths,".js",lambda p:["node","--check",p],"CHANGED_JS_SYNTAX",results); lint(paths,".mjs",lambda p:["node","--check",p],"CHANGED_JS_SYNTAX",results)
    if "CHANGED_PYTHON_SYNTAX" in want:
        syntax_probe = "import pathlib,sys; compile(pathlib.Path(sys.argv[1]).read_bytes(), sys.argv[1], 'exec')"
        lint([p for p in paths if p.startswith("tools/")], ".py", lambda p:[PYTHON,"-c",syntax_probe,p], "CHANGED_PYTHON_SYNTAX", results)
    if "FINANCE_REGRESSIONS" in want:
        exe("php"); [need(x) for x in FINANCE_TESTS]
        for t in FINANCE_TESTS: cmdcheck(results,"FINANCE_REGRESSIONS",["php",t])
        need(".github/p93/P93_carrier_payables_visual.js"); exe("node"); cmdcheck(results,"FINANCE_REGRESSIONS",["node","--check",".github/p93/P93_carrier_payables_visual.js"])
    if "LINEAR_REGRESSION" in want: need("tests/linear_route_point_dual_operation_test.php"); cmdcheck(results,"LINEAR_REGRESSION",["php","tests/linear_route_point_dual_operation_test.php"])
    if "UI_LAB_CONTRACT" in want: need("tests/ui_pattern_lab_contract_test.php"); cmdcheck(results,"UI_LAB_CONTRACT",["php","tests/ui_pattern_lab_contract_test.php"])
    if "MIGRATION_INTEGRITY" in want: migration_integrity(results)
    if "MIGRATION_NORMALIZATION" in want: migrations_normalized(results)
    if TENANT in want: tenant_check(results)
    for check, tests in [("FEO_TRANSFER_REGRESSIONS",FEO_TRANSFER_TESTS),("MAX_REGRESSIONS",MAX_TESTS),("WASTE_TRIPS_REGRESSIONS",WASTE_TESTS),("DOCUMENT_GENERATOR_REGRESSIONS",DOCGEN_TESTS)]:
        if check in want:
            env=os.environ.copy(); env["APP_ENV"]="test"
            # Never let a caller's skip switch turn required MySQL coverage green.
            env.pop("PLANEX_SKIP_MYSQL",None)
            if check == "DOCUMENT_GENERATOR_REGRESSIONS": env["PLANEX_DOCGEN_REQUIRE_RUNTIME"]="1"
            for t in tests:
                need(t)
                if os.name == "nt" and t in POSIX_XA_TESTS:
                    need("tools/wsl_posix_php_test.py")
                    cmdcheck(results,check,[PYTHON,"tools/wsl_posix_php_test.py",t],env)
                else:
                    cmdcheck(results,check,["php",t],env)
    if "ROLES_ROUTE_REGISTRY" in want:
        cmdcheck(results,"ROLES_ROUTE_REGISTRY",[PYTHON,"tests/roles_access_route_registry_test.py"])
    if "EMPLOYEE_MAIL_REGRESSIONS" in want:
        env = os.environ.copy(); env["APP_ENV"] = "test"
        env.pop("PLANEX_SKIP_MYSQL", None)
        for t in ["tests/employee_mail_contract_test.php", "tests/employee_mail_profiles_mysql_test.php", "tests/employee_mail_migration_mysql_test.php", "tests/employee_mail_default_019_migration_contract_test.php", "tests/report_email_recipients_mysql_test.php", "tests/trip_completion_contract_test.php"]:
            need(t); cmdcheck(results,"EMPLOYEE_MAIL_REGRESSIONS",["php",t],env)
        cmdcheck(results,"EMPLOYEE_MAIL_REGRESSIONS",["node","tests/employee_mail_browser_test.mjs"],env)
        cmdcheck(results,"EMPLOYEE_MAIL_REGRESSIONS",["node","tests/trip_review_browser_test.mjs"],env)
    if "TRIP_DOCUMENT_REGRESSIONS" in want:
        env=os.environ.copy();env["APP_ENV"]="test"
        for t in ["tests/document_mail_transport_test.php","tests/trip_document_collection_mysql_test.php","tests/trip_document_140_production_migration_contract_test.php","tests/trip_document_140_migration_mysql_test.php"]:
            need(t);cmdcheck(results,"TRIP_DOCUMENT_REGRESSIONS",["php",t],env)
    if "DOCUMENT_ATTACHMENT_REGRESSIONS" in want:
        need("tools/document_attachment_checks.py")
        cmdcheck(results,"DOCUMENT_ATTACHMENT_REGRESSIONS",[PYTHON,"tools/document_attachment_checks.py"])
    if "DYNAMIC_IDENTIFIER_SAFETY" in want: need("tools/dynamic_table_safety_audit.py"); cmdcheck(results,"DYNAMIC_IDENTIFIER_SAFETY",[PYTHON,"tools/dynamic_table_safety_audit.py"])
    if "ARCHITECTURE_GUARD" in want: need("tools/architecture_guard.php"); cmdcheck(results,"ARCHITECTURE_GUARD",["php","tools/architecture_guard.php"])
    if "CONTROL_PLANE_PREFLIGHT_CONTRACTS" in want:
        tests=["tests/ci_scope_test.py","tests/agent_preflight_test.py","tests/agent_deploy_policy_contract_test.py","tests/design_lab_contract_test.py","tests/local_publish_test.py","tests/runner_helpers_contract_test.py"]
        if any(p.startswith("tools/sentinelx/") or p=="tests/sentinelx_hardening_test.py" for p in paths): tests.append("tests/sentinelx_hardening_test.py")
        for t in tests: need(t); cmdcheck(results,"CONTROL_PLANE_PREFLIGHT_CONTRACTS",[PYTHON,t])
        for t in ["tests/agent_deploy_request_contract_test.php","tests/agent_db_migration_request_contract_test.php"]: need(t); cmdcheck(results,"CONTROL_PLANE_PREFLIGHT_CONTRACTS",["php",t])
    if "MEMORY_INTEGRITY_CONTRACTS" in want: memory_checks(head,results)
    if "ACTIVE_REGISTRY_CONTRACTS" in want: registry_checks(results)
    missing=sorted(want-results.keys())
    if missing: raise PreflightEnvironmentError("required checks were not executed: "+",".join(missing))
    return results

def jsonish(v): return json.dumps(v,sort_keys=True)
def revok(rev):
    if caprun(["git","cat-file","-e",f"{rev}^{{commit}}"]).returncode: raise PreflightEnvironmentError(f"invalid git revision: {rev}")
def main():
    a=argparse.ArgumentParser(description=__doc__); a.add_argument("--class",dest="cls",choices=["AUTO","FAST","STANDARD","CRITICAL"],default="AUTO"); a.add_argument("--base",required=True); a.add_argument("--head",required=True); a.add_argument("--plan",action="store_true"); x=a.parse_args()
    try:
        revok(x.base); revok(x.head); paths=ci.git_changed_files(x.base,x.head); scopes=ci.classify(paths); rows,lines=diff_meta(x.base,x.head); requested=x.cls; resolved=resolve_class(requested,paths,rows,lines); planned=plan(resolved,paths,scopes); caps=detect_capabilities()
        for k,v in [("REQUESTED_CLASS",requested),("CLASS",resolved),("BASE",x.base),("HEAD",x.head),("CHANGED_FILES",paths),("DETECTED_SCOPES",[k for k,v in scopes.items() if v]),("CHECKS_PLANNED",planned),("CAPABILITIES",caps)]: print(f"{k}={jsonish(v) if not isinstance(v,str) else v}")
        if resolved=="STANDARD" and ci.critical_path(paths):
            print("LOCAL_REQUIRED_CHECKS=[]\nLOCAL_REQUIRED_RESULTS=[]\nREMOTE_REQUIRED_CHECKS=[]\nMISSING_CAPABILITIES=[]\nCLASS_ESCALATION_REQUIRED=CRITICAL\nCHECKS_EXECUTED=[]\nLOCAL_PREFLIGHT_FAIL"); return 1
        local,remote,missing=check_execution_classes(planned,caps); print("LOCAL_REQUIRED_CHECKS="+jsonish(local)); print("REMOTE_REQUIRED_CHECKS="+jsonish(remote)); print("MISSING_CAPABILITIES="+jsonish(missing))
        if x.plan: print("LOCAL_REQUIRED_RESULTS=[]\nCHECKS_EXECUTED=[]\nPREFLIGHT_PLAN_ONLY"); return 0
        if remote:
            print("LOCAL_REQUIRED_RESULTS=[]")
            print("CHECKS_EXECUTED=[]")
            print("LOCAL_PREFLIGHT_REMOTE_GATES_FORBIDDEN")
            return 2
        results=execute_local_required(resolved,x.head,paths,scopes,rows,lines,local,x.base); ordered=[{"check":n,"result":results[n]} for n in local]; print("LOCAL_REQUIRED_RESULTS="+jsonish(ordered)); print("CHECKS_EXECUTED="+jsonish(local))
        if any(value != "PASS" for value in results.values()): print("LOCAL_PREFLIGHT_FAIL"); return 1
        print("LOCAL_PREFLIGHT_PASS"); return 0
    except PreflightEnvironmentError as e: print(f"PREFLIGHT_ENV_REASON={e}\nLOCAL_PREFLIGHT_ENV_ERROR"); return 2
    except (OSError,subprocess.SubprocessError,RuntimeError,ValueError) as e: print(f"PREFLIGHT_ENV_REASON={type(e).__name__}: {e}\nLOCAL_PREFLIGHT_ENV_ERROR"); return 2
if __name__=="__main__": raise SystemExit(main())
