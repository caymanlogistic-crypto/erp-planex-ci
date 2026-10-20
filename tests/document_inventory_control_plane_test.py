"""Run the actual CI production-trigger guard locally before diagnostic publication."""
import contextlib
import io
from pathlib import Path
import re
import textwrap

root = Path(__file__).resolve().parents[1]
ci = (root / '.github/workflows/ci.yml').read_text(encoding='utf-8')
section = ci.split('- name: Control-plane guard', 1)[1].split('\n      - name:', 1)[0]
code = textwrap.dedent(re.search(r"python3 - <<'PY'\n(.*?)\n\s*PY", section, re.S)[1])
with contextlib.redirect_stdout(io.StringIO()):
    try:
        exec(compile(code, 'actual-ci-production-trigger-guard', 'exec'))
    except SystemExit as result:
        assert result.code == 0, 'Production-trigger guard rejected the workflow'
diagnostic = (root / '.github/workflows/registry_document_inventory_readonly_diagnostic.yml').read_text()
regression = (root / '.github/workflows/document_inventory_readonly_regression.yml').read_text()
assert '\n  workflow_dispatch:' in diagnostic
assert not any('\n  '+trigger+':' in diagnostic for trigger in ('push','pull_request','schedule','workflow_run'))
assert 'ERPV2_SSH_' not in regression and 'public_html/erpv2' not in regression
assert 'php tests/document_inventory_readonly_mysql_test.php' in regression
assert 'openssl cms -encrypt -binary -aes256' in diagnostic
assert 'tee ' not in diagnostic
print('DOCUMENT_INVENTORY_CONTROL_PLANE_PASS')
