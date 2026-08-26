#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import pathlib
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request

CANONICAL = os.environ.get('CANONICAL_BRANCH', 'chatgpt/production-stabilization-20260802')
REPO = os.environ['REPOSITORY']
API = os.environ.get('API_URL', 'https://api.github.com').rstrip('/')
TOKEN = os.environ['GH_TOKEN']
DEPLOY_WORKFLOW = 'erpv2_controlled_deploy.yml'
MANIFEST = pathlib.Path('.github/agent_db_migration_request.json')
EXPECTED_MIGRATION = '123'
EXPECTED_MIGRATION_WORKFLOW = 'feo_123_production_migration.yml'
EXPECTED_MIGRATION_CONFIRM = 'APPLY_FEO_123'


def sh(*args: str) -> str:
    return subprocess.check_output(args, text=True).strip()


def api(method: str, path: str, payload: dict | None = None) -> tuple[int, dict | None]:
    body = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(
        f'{API}/repos/{REPO}/{path.lstrip("/")}', data=body, method=method,
        headers={
            'Authorization': f'Bearer {TOKEN}',
            'Accept': 'application/vnd.github+json',
            'X-GitHub-Api-Version': '2022-11-28',
            'Content-Type': 'application/json',
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            raw = response.read()
            return response.status, json.loads(raw) if raw else None
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode(errors='replace')
        raise RuntimeError(f'GitHub API {method} {path} failed: HTTP {exc.code}: {detail[:500]}') from exc


def canonical_sha() -> str:
    sh('git', 'fetch', '--no-tags', 'origin', f'+refs/heads/{CANONICAL}:refs/remotes/origin/{CANONICAL}')
    return sh('git', 'rev-parse', f'refs/remotes/origin/{CANONICAL}')


def require_same_canonical(source: str, stage: str) -> None:
    actual = canonical_sha()
    if actual != source:
        raise RuntimeError(f'canonical HEAD changed before {stage}: expected {source}, got {actual}')


def workflow_runs(source: str, event: str) -> list[dict]:
    _, data = api('GET', f'actions/runs?head_sha={source}&event={event}&per_page=100')
    return list((data or {}).get('workflow_runs', []))


def wait_ci(source: str) -> int:
    for _ in range(90):
        rows = [r for r in workflow_runs(source, 'push') if r.get('path') == '.github/workflows/ci.yml' and r.get('head_branch') == CANONICAL]
        if rows:
            run = max(rows, key=lambda r: int(r.get('run_number', 0)))
            if run.get('status') == 'completed':
                if run.get('conclusion') != 'success':
                    raise RuntimeError(f'exact canonical CI failed: run={run.get("id")} conclusion={run.get("conclusion")}')
                print(f'ERP_PLANEX_CI=PASS run={run.get("id")}')
                return int(run['id'])
        time.sleep(10)
    raise RuntimeError('timed out waiting for exact canonical push CI')


def baseline(source: str, workflow: str) -> int:
    rows = [r for r in workflow_runs(source, 'workflow_dispatch') if r.get('path') == f'.github/workflows/{workflow}']
    return max([int(r.get('id', 0)) for r in rows] or [0])


def dispatch(workflow: str, inputs: dict[str, str]) -> None:
    code, _ = api('POST', f'actions/workflows/{workflow}/dispatches', {'ref': CANONICAL, 'inputs': inputs})
    if code != 204:
        raise RuntimeError(f'unexpected dispatch HTTP status for {workflow}: {code}')


def wait_dispatched(source: str, workflow: str, previous_id: int, label: str) -> int:
    for _ in range(120):
        rows = [r for r in workflow_runs(source, 'workflow_dispatch') if r.get('path') == f'.github/workflows/{workflow}' and int(r.get('id', 0)) > previous_id]
        if rows:
            run = max(rows, key=lambda r: int(r.get('id', 0)))
            if run.get('status') == 'completed':
                if run.get('conclusion') != 'success':
                    raise RuntimeError(f'{label} failed: run={run.get("id")} conclusion={run.get("conclusion")}')
                print(f'{label}=PASS run={run.get("id")}')
                return int(run['id'])
        time.sleep(10)
    raise RuntimeError(f'timed out waiting for {label}')


def validate_event() -> None:
    event_path = pathlib.Path(os.environ.get('GITHUB_EVENT_PATH', ''))
    if not event_path.is_file():
        raise RuntimeError('GITHUB_EVENT_PATH is missing')
    event = json.loads(event_path.read_text(encoding='utf-8'))
    run = event.get('workflow_run') or {}
    owner = REPO.split('/', 1)[0]
    if int(run.get('id', 0)) != 32979801695:
        raise RuntimeError('unexpected bootstrap run id')
    if int(run.get('run_attempt', 0)) < 3:
        raise RuntimeError('bootstrap requires run attempt >= 3')
    if run.get('conclusion') != 'success':
        raise RuntimeError('bootstrap source workflow did not succeed')
    if ((run.get('triggering_actor') or {}).get('login')) != owner:
        raise RuntimeError('bootstrap rerun was not triggered by repository owner')


def validate_manifest() -> dict:
    data = json.loads(MANIFEST.read_text(encoding='utf-8'))
    if data.get('migration_id') != EXPECTED_MIGRATION:
        raise RuntimeError('release bootstrap is scoped to migration 123')
    if data.get('workflow') != EXPECTED_MIGRATION_WORKFLOW:
        raise RuntimeError('unexpected migration workflow')
    if data.get('confirmation_value') != EXPECTED_MIGRATION_CONFIRM:
        raise RuntimeError('unexpected migration confirmation')
    for key in ('source_sha_input', 'confirmation_input'):
        if not re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]*', str(data.get(key, ''))):
            raise RuntimeError(f'invalid migration input name: {key}')
    target = pathlib.Path('.github/workflows') / data['workflow']
    text = target.read_text(encoding='utf-8')
    if '\n  workflow_dispatch:' not in text:
        raise RuntimeError('migration target must remain manual-only')
    for token in ('\n  push:', '\n  pull_request:', '\n  workflow_run:', '\n  schedule:'):
        if token in text:
            raise RuntimeError(f'automatic migration trigger forbidden: {token.strip()}')
    matches = list(pathlib.Path('database/migrations-local').glob('123_*.sql'))
    if len(matches) != 1:
        raise RuntimeError(f'expected exactly one migration 123 SQL, found {len(matches)}')
    return data


def live_acceptance() -> None:
    req = urllib.request.Request('https://plan-ex.ru/erpv2/login', headers={'User-Agent': 'ERP-PLANEX-release-bootstrap/1'})
    with urllib.request.urlopen(req, timeout=30) as response:
        if response.status != 200:
            raise RuntimeError(f'live login acceptance returned HTTP {response.status}')
    print('ERPV2_LIVE_HTTP=PASS')


def main() -> int:
    validate_event()
    source = sh('git', 'rev-parse', 'HEAD')
    if not re.fullmatch(r'[0-9a-f]{40}', source):
        raise RuntimeError('invalid source SHA')
    require_same_canonical(source, 'CI gate')
    wait_ci(source)
    manifest = validate_manifest()

    require_same_canonical(source, 'migration dispatch')
    migration_baseline = baseline(source, manifest['workflow'])
    dispatch(manifest['workflow'], {
        manifest['source_sha_input']: source,
        manifest['confirmation_input']: manifest['confirmation_value'],
    })
    wait_dispatched(source, manifest['workflow'], migration_baseline, 'MIGRATION_123')

    require_same_canonical(source, 'deploy dispatch')
    deploy_baseline = baseline(source, DEPLOY_WORKFLOW)
    dispatch(DEPLOY_WORKFLOW, {'source_sha': source, 'confirm_deploy': 'DEPLOY_ERPV2'})
    wait_dispatched(source, DEPLOY_WORKFLOW, deploy_baseline, 'CONTROLLED_DEPLOY')
    live_acceptance()
    print(f'ERPV2_RELEASE_BOOTSTRAP=PASS source_sha={source}')
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f'ERPV2_RELEASE_BOOTSTRAP=FAIL: {exc}', file=sys.stderr)
        raise
