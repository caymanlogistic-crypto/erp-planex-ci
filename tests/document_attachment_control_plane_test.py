"""Test actual production guard and shared fail-closed attachment selection."""
import importlib.util
from pathlib import Path
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
subprocess.run([sys.executable,'tests/document_inventory_control_plane_test.py'],cwd=ROOT,check=True)
spec=importlib.util.spec_from_file_location('scope',ROOT/'tools/ci_scope.py')
scope=importlib.util.module_from_spec(spec);spec.loader.exec_module(scope)
for path in ['app/Service/RouteExecutorReviewMaterializationService.php','public/assets/js/route-executor-review-workflow.js','scripts/registry_document_attachment_recovery.php','tests/document_attachment_pipeline_browser_test.mjs']:
    assert scope.classify([path])['document_attachments'],path
assert not scope.classify(['docs/unrelated.md'])['document_attachments']
production=(ROOT/'.github/workflows/document_attachment_recovery_production.yml').read_text()
automatic=(ROOT/'.github/workflows/document_attachment_regression.yml').read_text()
assert '\n  workflow_dispatch:' in production
assert not any('\n  '+x+':' in production for x in ('push','pull_request','schedule','workflow_run'))
assert 'DOC_RECOVERY_20261020_KEY' in production
assert 'openssl cms -encrypt -binary -aes256' in production
assert 'sha256sum "$RUNNER_TEMP/recovery.json"' in production
assert 'PLANEX_ATTACHMENT_RECOVERY_CHECK_DIGEST' in production
assert 'ERPV2_SSH_' not in automatic and 'public_html/erpv2' not in automatic
assert 'python3 tools/document_attachment_checks.py' in automatic
preflight=(ROOT/'tools/agent_preflight.py').read_text()
assert 'DOCUMENT_ATTACHMENT_REGRESSIONS' in preflight
assert 'tools/document_attachment_checks.py' in preflight
print('DOCUMENT_ATTACHMENT_CONTROL_PLANE_PASS')
