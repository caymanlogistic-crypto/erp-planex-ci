"""Shared LOCAL/CI execution of attachment preservation and finite recovery tests."""
from __future__ import annotations
import os
from pathlib import Path
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
PHP_TESTS=(
    'tests/document_attachment_materialization_test.php',
    'tests/route_executor_combined_mysql_test.php',
    'tests/document_attachment_recovery_mysql_test.php',
)
BROWSER_TEST='tests/document_attachment_pipeline_browser_test.mjs'

def main():
    env=os.environ.copy()
    env['APP_ENV']='test'
    env.pop('PLANEX_SKIP_MYSQL',None)
    php=env.get('PLANEX_PHP')
    node=env.get('PLANEX_NODE')
    if not php or not node:
        raise RuntimeError('Pinned PHP and Node are required')
    for path in (*PHP_TESTS,BROWSER_TEST):
        if not (ROOT/path).is_file(): raise RuntimeError('Required attachment test missing: '+path)
        subprocess.run([node if path.endswith('.mjs') else php,path],cwd=ROOT,env=env,check=True)
    subprocess.run([sys.executable,'tests/document_attachment_control_plane_test.py'],cwd=ROOT,env=env,check=True)
    print('DOCUMENT_ATTACHMENT_REQUIRED_CHECKS_PASS')
    return 0

if __name__=='__main__': raise SystemExit(main())
